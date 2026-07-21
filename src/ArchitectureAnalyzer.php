<?php

namespace Clarte;

/**
 * Analyse structurelle : taille des classes/méthodes, God Object,
 * nombre de paramètres, indices de couplage fort et de responsabilités
 * multiples (SRP). Analyse par regex sur la structure PHP, volontairement
 * simple plutôt que par AST complet, pour rester dépendance-free.
 */
class ArchitectureAnalyzer
{
    private array $thresholds;

    public function __construct(array $thresholds)
    {
        $this->thresholds = $thresholds;
    }

    public function analyze(string $content, string $lang): array
    {
        $issues = [];
        if ($lang !== 'PHP') {
            return $issues;
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($totalLines > $this->thresholds['file_max_lines']) {
            $issues[] = [
                'rule'     => 'file_too_long',
                'severity' => 'moderate',
                'message'  => "Fichier de {$totalLines} lignes : dépasse le seuil de {$this->thresholds['file_max_lines']} lignes",
                'line'     => 1,
            ];
        }

        // Détection des classes et de leur étendue (accolade ouvrante/fermante)
        if (preg_match_all('/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/m', $content, $classMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($classMatches[1] as $match) {
                [$className, $offset] = $match;
                $startLine = substr_count(substr($content, 0, $offset), "\n") + 1;
                $classBody = $this->extractBlock($content, $offset);
                $classLines = substr_count($classBody, "\n") + 1;
                $methodCount = preg_match_all('/function\s+\w+\s*\(/', $classBody);

                if ($classLines > $this->thresholds['class_max_lines']) {
                    $issues[] = [
                        'rule'     => 'class_too_long',
                        'severity' => 'moderate',
                        'message'  => "Classe {$className} : {$classLines} lignes (seuil : {$this->thresholds['class_max_lines']})",
                        'line'     => $startLine,
                    ];
                }

                if ($methodCount >= $this->thresholds['god_class_methods']) {
                    $issues[] = [
                        'rule'     => 'god_class',
                        'severity' => 'important',
                        'message'  => "Classe {$className} : {$methodCount} méthodes, potentiel God Object / God Controller",
                        'line'     => $startLine,
                    ];
                }
            }
        }

        // Détection des méthodes trop longues et avec trop de paramètres
        if (preg_match_all('/function\s+(\w+)\s*\(([^)]*)\)\s*(?::\s*\??\w+\s*)?\{/', $content, $methodMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($methodMatches[1] as $index => $match) {
                [$methodName, $offset] = $match;
                $params = $methodMatches[2][$index][0];
                $paramCount = trim($params) === '' ? 0 : count(explode(',', $params));

                $bodyOffset = strpos($content, '{', $offset);
                if ($bodyOffset === false) {
                    continue;
                }
                $methodBody = $this->extractBlock($content, $bodyOffset - strlen('function'));
                $methodLines = substr_count($methodBody, "\n") + 1;
                $startLine = substr_count(substr($content, 0, $offset), "\n") + 1;

                if ($methodLines > $this->thresholds['method_max_lines']) {
                    $issues[] = [
                        'rule'     => 'method_too_long',
                        'severity' => 'moderate',
                        'message'  => "Methode {$methodName}() : {$methodLines} lignes (seuil : {$this->thresholds['method_max_lines']})",
                        'line'     => $startLine,
                    ];
                }

                if ($paramCount > $this->thresholds['max_params']) {
                    $issues[] = [
                        'rule'     => 'too_many_params',
                        'severity' => 'info',
                        'message'  => "Methode {$methodName}() : {$paramCount} paramètres (seuil : {$this->thresholds['max_params']}), envisager un DTO/objet de valeur",
                        'line'     => $startLine,
                    ];
                }
            }
        }

        // Violation MVC simple : requêtes SQL directes dans un contrôleur
        if (str_contains($content, 'class') && preg_match('/class\s+\w*Controller/', $content)) {
            if (preg_match('/DB::(select|statement|table)\s*\(/', $content)) {
                $issues[] = [
                    'rule'     => 'db_query_in_controller',
                    'severity' => 'info',
                    'message'  => "Requête DB directe dans un contrôleur : envisager de déplacer la logique vers un Model/Repository/Service",
                    'line'     => 1,
                ];
            }
        }

        return $issues;
    }

    /**
     * Extrait le bloc {...} correspondant à la première accolade ouvrante
     * trouvée à partir de $fromOffset.
     */
    private function extractBlock(string $content, int $fromOffset): string
    {
        $start = strpos($content, '{', $fromOffset);
        if ($start === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($content);
        for ($i = $start; $i < $length; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }
        return substr($content, $start);
    }

    public function score(array $issues): float
    {
        $weights = ['critical' => 3, 'important' => 2, 'moderate' => 1, 'info' => 0.3];
        $penalty = 0;
        foreach ($issues as $issue) {
            $penalty += $weights[$issue['severity']] ?? 0.3;
        }
        return max(0, 10 - min(10, $penalty));
    }
}
