<?php

namespace Clarte;

/**
 * Détection heuristique de motifs de performance à risque : requêtes en
 * boucle (N+1 Laravel), boucles imbriquées, chargements mémoire excessifs.
 */
class PerformanceAnalyzer
{
    public function analyze(string $content, string $lang): array
    {
        $issues = [];
        $lines = explode("\n", $content);

        if (in_array($lang, ['PHP', 'Blade'], true)) {
            $issues = array_merge($issues, $this->detectQueryInLoop($lines));
            $issues = array_merge($issues, $this->detectN1Laravel($content));
            $issues = array_merge($issues, $this->detectNestedLoops($lines));
        }

        if ($lang === 'Blade') {
            $issues = array_merge($issues, $this->detectHeavyBladeIncludes($lines));
        }

        return $issues;
    }

    private function detectQueryInLoop(array $lines): array
    {
        $issues = [];
        $inLoop = false;
        $loopStartLine = 0;
        $loopDepthBraces = 0;

        foreach ($lines as $i => $line) {
            if (preg_match('/\b(foreach|for|while)\s*\(/', $line)) {
                $inLoop = true;
                $loopStartLine = $i + 1;
                $loopDepthBraces = 0;
            }

            if ($inLoop) {
                $loopDepthBraces += substr_count($line, '{') - substr_count($line, '}');

                if (preg_match('/(::(where|find|get|first|create|update|delete)\s*\(|DB::(select|table|statement)\s*\()/', $line)) {
                    $issues[] = [
                        'rule'     => 'query_in_loop',
                        'severity' => 'moderate',
                        'message'  => "Requête Eloquent/DB exécutée à l'intérieur d'une boucle (risque N+1)",
                        'line'     => $i + 1,
                        'excerpt'  => trim($line),
                    ];
                }

                if ($loopDepthBraces <= 0 && $i + 1 > $loopStartLine) {
                    $inLoop = false;
                }
            }
        }

        return $issues;
    }

    private function detectN1Laravel(string $content): array
    {
        $issues = [];
        // relation Eloquent utilisée sans eager-loading visible (with())
        if (preg_match_all('/\$(\w+)->(\w+)(?:\(\))?->/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            // heuristique légère : simple signal, pas une preuve formelle
            if (!str_contains($content, '::with(') && !str_contains($content, '->with(') && count($matches[0]) > 3) {
                $issues[] = [
                    'rule'     => 'n1_missing_eager_load',
                    'severity' => 'info',
                    'message'  => "Accès fréquent à des relations sans eager-loading détecté (->with()) : vérifier le risque N+1",
                    'line'     => 1,
                    'excerpt'  => '',
                ];
            }
        }
        return $issues;
    }

    private function detectNestedLoops(array $lines): array
    {
        $issues = [];
        $depth = 0;
        $loopLineStack = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/\b(foreach|for|while)\s*\(/', $line)) {
                $depth++;
                $loopLineStack[] = $i + 1;
                if ($depth >= 3) {
                    $issues[] = [
                        'rule'     => 'nested_loops',
                        'severity' => 'moderate',
                        'message'  => 'Boucles imbriquées sur 3 niveaux ou plus : complexité et coût potentiellement élevés',
                        'line'     => $i + 1,
                        'excerpt'  => trim($line),
                    ];
                }
            }
            $closeCount = substr_count($line, '}');
            for ($c = 0; $c < $closeCount && $depth > 0; $c++) {
                $depth--;
                array_pop($loopLineStack);
            }
        }

        return $issues;
    }

    private function detectHeavyBladeIncludes(array $lines): array
    {
        $issues = [];
        $includeCount = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/@(include|component)\s*\(/', $line)) {
                $includeCount++;
                if ($includeCount > 15) {
                    $issues[] = [
                        'rule'     => 'heavy_blade_includes',
                        'severity' => 'info',
                        'message'  => 'Nombre élevé de @include/@component dans une seule vue : envisager une décomposition',
                        'line'     => $i + 1,
                        'excerpt'  => trim($line),
                    ];
                    break;
                }
            }
        }
        return $issues;
    }

    public function score(array $issues): float
    {
        $weights = ['critical' => 3, 'important' => 2, 'moderate' => 1, 'info' => 0.5];
        $penalty = 0;
        foreach ($issues as $issue) {
            $penalty += $weights[$issue['severity']] ?? 0.5;
        }
        return max(0, 10 - min(10, $penalty));
    }
}
