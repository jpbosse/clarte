<?php

namespace Clarte;

/**
 * Qualité générale du code : TODO/FIXME/HACK, code mort probable
 * (fonctions privées jamais appelées dans le même fichier - heuristique
 * locale, pas d'analyse cross-fichiers), duplication de blocs simples,
 * variables mal nommées.
 */
class QualityAnalyzer
{
    public function analyze(string $content, string $lang): array
    {
        $issues = [];
        $lines = explode("\n", $content);

        foreach ($lines as $i => $line) {
            if (preg_match('/\b(TODO|FIXME|HACK)\b\s*:?\s*(.*)/i', $line, $m)) {
                $issues[] = [
                    'rule'     => 'todo_fixme',
                    'severity' => 'info',
                    'message'  => strtoupper($m[1]) . ' : ' . trim($m[2] ?: '(sans description)'),
                    'line'     => $i + 1,
                ];
            }
        }

        if ($lang === 'PHP') {
            $issues = array_merge($issues, $this->detectPoorNaming($content));
            $issues = array_merge($issues, $this->detectDuplicatedLines($lines));
            $issues = array_merge($issues, $this->detectUnusedPrivateMethods($content));
        }

        return $issues;
    }

    private function detectPoorNaming(string $content): array
    {
        $issues = [];
        // variables à une seule lettre (hors compteurs de boucle usuels i, j, k)
        if (preg_match_all('/\$([a-hl-z])\b\s*=/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $seen = [];
            foreach ($matches[1] as $match) {
                [$varName, $offset] = $match;
                if (isset($seen[$varName])) {
                    continue;
                }
                $seen[$varName] = true;
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $issues[] = [
                    'rule'     => 'poor_naming',
                    'severity' => 'info',
                    'message'  => "Variable \${$varName} : nom peu explicite (une seule lettre)",
                    'line'     => $line,
                ];
                if (count($seen) >= 5) {
                    break; // evite de noyer le rapport
                }
            }
        }
        return $issues;
    }

    private function detectDuplicatedLines(array $lines): array
    {
        $issues = [];
        $normalized = [];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if (strlen($trimmed) < 25 || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue; // on ignore les lignes triviales/commentaires
            }
            $normalized[$trimmed][] = $i + 1;
        }

        foreach ($normalized as $text => $occurrences) {
            if (count($occurrences) >= 3) {
                $issues[] = [
                    'rule'     => 'duplicated_line',
                    'severity' => 'moderate',
                    'message'  => sprintf(
                        'Ligne dupliquée %d fois (lignes %s) : ' . mb_substr($text, 0, 60) . '...',
                        count($occurrences),
                        implode(', ', array_slice($occurrences, 0, 5))
                    ),
                    'line' => $occurrences[0],
                ];
            }
        }

        return array_slice($issues, 0, 10);
    }

    private function detectUnusedPrivateMethods(string $content): array
    {
        $issues = [];
        if (preg_match_all('/private\s+function\s+(\w+)\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                [$methodName, $offset] = $match;
                $callPattern = '/\$this->' . preg_quote($methodName, '/') . '\s*\(/';
                $definitionAndCalls = preg_match_all($callPattern, $content);
                if ($definitionAndCalls === 0) {
                    $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $issues[] = [
                        'rule'     => 'unused_private_method',
                        'severity' => 'moderate',
                        'message'  => "Methode privee {$methodName}() jamais appelée dans ce fichier (code potentiellement mort)",
                        'line'     => $line,
                    ];
                }
            }
        }
        return $issues;
    }

    public function score(array $issues): float
    {
        $weights = ['critical' => 3, 'important' => 1.5, 'moderate' => 1, 'info' => 0.2];
        $penalty = 0;
        foreach ($issues as $issue) {
            $penalty += $weights[$issue['severity']] ?? 0.2;
        }
        return max(0, 10 - min(10, $penalty));
    }
}
