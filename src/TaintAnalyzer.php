<?php

namespace Clarte;

/**
 * Suivi heuristique de flux de donnees (taint analysis) : repere quand
 * une donnee provenant d'une entree externe (source) atteint un point
 * dangereux (sink) sans passer par une fonction connue pour neutraliser
 * le risque (sanitizer).
 *
 * Complementaire de SecurityAnalyzer, pas un remplacement : SecurityAnalyzer
 * signale la simple PRESENCE d'un sink dangereux (large filet, quitte a
 * inclure des cas ou l'argument est en realite une constante sure).
 * TaintAnalyzer va plus loin en tracant precisement d'ou vient la donnee
 * et confirme qu'elle est bien issue d'une source externe -- moins de
 * faux positifs sur CE point precis, mais volontairement moins large.
 *
 * Portee assumee et limitee (honnete plutot qu'ambitieuse a tort) :
 *  - Analyse REGEX, pas un vrai parseur PHP avec arbre syntaxique.
 *  - Porte de chaque analyse limitee au corps d'UNE SEULE fonction/methode
 *    (pas de suivi inter-procedural : si une fonction A recoit une donnee
 *    tainted et la passe a une fonction B, le lien A->B n'est PAS suivi).
 *  - Suivi sequentiel ligne par ligne, sans comprehension des branches
 *    (if/else, boucles) : une variable assainie dans une branche peut
 *    etre consideree a tort comme toujours assainie ensuite.
 *  - Une variable non detectee comme tainted n'est pas forcement sure
 *    (faux negatif possible) ; c'est le compromis assume de cette
 *    heuristique plutot que de saturer le rapport de faux positifs.
 */
class TaintAnalyzer
{
    /** Motifs marquant une expression comme provenant d'une entree externe non fiable. */
    private const SOURCE_PATTERNS = [
        '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[/',
        '/\brequest\(\)\s*->\s*(?:input|get|query|all|post|cookie|header)\s*\(/',
        '/\$request\s*->\s*(?:input|get|query|all|post|cookie|header)\s*\(/',
        '/\bRequest::\s*(?:input|get|query|all|post|cookie|header)\s*\(/',
        '/\$argv\s*\[/',
        '/\bfile_get_contents\s*\(\s*[\'"]php:\/\/input[\'"]/',
    ];

    /** Fonctions/casts qui neutralisent le risque quand elles enveloppent directement la variable. */
    private const SANITIZERS = [
        'intval', 'floatval', 'boolval', 'htmlspecialchars', 'htmlentities',
        'strip_tags', 'escapeshellarg', 'escapeshellcmd', 'filter_var', 'addslashes',
        'basename', 'realpath',
    ];
    private const SANITIZER_CASTS = ['(int)', '(float)', '(bool)', '(integer)', '(double)', '(boolean)'];

    /**
     * Points dangereux, groupes par categorie. Chaque motif capture
     * implicitement "le reste de la ligne apres l'appel" comme zone de
     * recherche de variable tainted (limite : analyse mono-ligne).
     */
    private const SINKS = [
        'taint_sql_injection' => [
            'pattern'  => '/(?:->\s*(?:whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw)|DB::(?:raw|statement|unprepared))\s*\(/',
            'severity' => 'critical',
            'label'    => 'injection SQL',
        ],
        'taint_command_injection' => [
            'pattern'  => '/\b(?:exec|shell_exec|system|passthru|proc_open|popen)\s*\(/',
            'severity' => 'critical',
            'label'    => 'injection de commande systeme',
        ],
        'taint_code_execution' => [
            'pattern'  => '/\b(?:eval|assert|create_function)\s*\(/',
            'severity' => 'critical',
            'label'    => 'execution de code arbitraire',
        ],
        'taint_file_operation' => [
            'pattern'  => '/\b(?:include|include_once|require|require_once|file_get_contents|file_put_contents|fopen|unlink|readfile)\s*\(?\s*/',
            'severity' => 'important',
            'label'    => 'manipulation de fichier (LFI/traversee de repertoire)',
        ],
        'taint_unserialize' => [
            'pattern'  => '/\bunserialize\s*\(/',
            'severity' => 'critical',
            'label'    => 'deserialisation (object injection)',
        ],
    ];

    public function analyze(string $content, string $lang): array
    {
        if ($lang !== 'PHP') {
            return [];
        }

        $issues = [];
        foreach ($this->extractFunctionBodies($content) as [$body, $startLine]) {
            $issues = array_merge($issues, $this->analyzeFunctionBody($body, $startLine));
        }
        return $issues;
    }

    /**
     * @return list<array{0:string, 1:int}> Paires [corps de fonction, ligne de depart dans le fichier]
     */
    private function extractFunctionBodies(string $content): array
    {
        $bodies = [];
        if (!preg_match_all('/function\s+\w+\s*\([^)]*\)\s*(?::\s*\??[\w\\\\]+\s*)?\{/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $bodies;
        }
        foreach ($matches[0] as [$match, $offset]) {
            $braceOffset = $offset + strlen($match) - 1;
            $body = $this->extractBlock($content, $braceOffset);
            if ($body === '') {
                continue;
            }
            $startLine = substr_count(substr($content, 0, $braceOffset), "\n") + 1;
            $bodies[] = [$body, $startLine];
        }
        return $bodies;
    }

    private function extractBlock(string $content, int $braceOffset): string
    {
        $depth = 0;
        $length = strlen($content);
        for ($i = $braceOffset; $i < $length; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $braceOffset, $i - $braceOffset + 1);
                }
            }
        }
        return '';
    }

    /**
     * @return list<array{rule:string, severity:string, message:string, line:int}>
     */
    private function analyzeFunctionBody(string $body, int $startLine): array
    {
        $issues = [];
        /** @var array<string,int> $tainted nom de variable (sans $) => ligne d'origine (relative au fichier) */
        $tainted = [];

        $lines = explode("\n", $body);
        foreach ($lines as $idx => $line) {
            $lineNum = $startLine + $idx;

            // 1) Propagation / nouvelle source de taint via affectation simple "$var = ...;"
            if (preg_match('/\$(\w+)\s*=\s*(.+?);?\s*$/', $line, $m)) {
                $var = $m[1];
                $rhs = $m[2];

                if ($this->matchesAny($rhs, self::SOURCE_PATTERNS)) {
                    $tainted[$var] = $lineNum;
                } elseif ($this->isDirectlySanitized($rhs)) {
                    unset($tainted[$var]);
                } else {
                    $sourceVar = $this->firstTaintedVarIn($rhs, $tainted);
                    if ($sourceVar !== null) {
                        $tainted[$var] = $tainted[$sourceVar];
                    } elseif (isset($tainted[$var]) && trim($rhs) !== '') {
                        // Reaffectee a une valeur qui ne semble plus tainted : on leve le marquage.
                        unset($tainted[$var]);
                    }
                }
            }

            // 2) Detection d'un sink atteint par une variable tainted, sur cette meme ligne.
            if (empty($tainted)) {
                continue;
            }
            foreach (self::SINKS as $rule => $sink) {
                if (!preg_match($sink['pattern'], $line, $sinkMatch, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $argZone = substr($line, $sinkMatch[0][1]);
                foreach ($tainted as $varName => $sourceLine) {
                    if (!preg_match('/\$' . preg_quote($varName, '/') . '\b/', $argZone)) {
                        continue;
                    }
                    if ($this->isSanitizedAtSink($argZone, $varName)) {
                        continue;
                    }
                    $issues[] = [
                        'rule'     => $rule,
                        'severity' => $sink['severity'],
                        'message'  => "Donnee externe non assainie (variable \${$varName}, provenant de la ligne {$sourceLine}) atteint un point sensible : {$sink['label']}",
                        'line'     => $lineNum,
                    ];
                    break; // une alerte par sink/ligne suffit, evite le bruit si plusieurs vars tainted matchent
                }
            }
        }

        return $issues;
    }

    private function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    private function isDirectlySanitized(string $rhs): bool
    {
        foreach (self::SANITIZER_CASTS as $cast) {
            if (str_starts_with(ltrim($rhs), $cast)) {
                return true;
            }
        }
        foreach (self::SANITIZERS as $fn) {
            if (preg_match('/^\s*' . preg_quote($fn, '/') . '\s*\(/', $rhs)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,int> $tainted
     */
    private function firstTaintedVarIn(string $text, array $tainted): ?string
    {
        foreach (array_keys($tainted) as $varName) {
            if (preg_match('/\$' . preg_quote($varName, '/') . '\b/', $text)) {
                return $varName;
            }
        }
        return null;
    }

    private function isSanitizedAtSink(string $argZone, string $varName): bool
    {
        $varPattern = '\$' . preg_quote($varName, '/') . '\b';
        foreach (self::SANITIZERS as $fn) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(\s*' . $varPattern . '/', $argZone)) {
                return true;
            }
        }
        foreach (self::SANITIZER_CASTS as $cast) {
            if (preg_match('/' . preg_quote($cast, '/') . '\s*' . $varPattern . '/', $argZone)) {
                return true;
            }
        }
        return false;
    }
}
