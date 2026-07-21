<?php

namespace Clarte;

/**
 * Evalue la couverture documentaire : presence de PHPDoc sur les classes
 * et methodes publiques, densite de commentaires, presence d'un README
 * au niveau du projet.
 */
class DocumentationAnalyzer
{
    public function analyze(string $content, string $lang): array
    {
        $issues = [];
        if ($lang !== 'PHP') {
            return $issues;
        }

        if (preg_match_all('/^\s*(?:public\s+)?function\s+(\w+)\s*\(/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                [$methodName, $offset] = $match;
                if (str_starts_with($methodName, '__')) {
                    continue; // magic methods : PHPDoc optionnel
                }
                $before = substr($content, max(0, $offset - 300), 300);
                $hasDocBlock = (bool) preg_match('/\/\*\*[^*]*\*+(?:[^\/*][^*]*\*+)*\/\s*$/s', $before);
                if (!$hasDocBlock) {
                    $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $issues[] = [
                        'severity' => 'info',
                        'message'  => "Methode {$methodName}() sans bloc PHPDoc",
                        'line'     => $line,
                    ];
                }
            }
        }

        return array_slice($issues, 0, 15);
    }

    public function projectHasReadme(string $projectPath): bool
    {
        foreach (['README.md', 'README.MD', 'Readme.md', 'readme.md'] as $candidate) {
            if (is_file(rtrim($projectPath, '/') . '/' . $candidate)) {
                return true;
            }
        }
        return false;
    }

    public function score(array $issues, int $totalMethods = 0): float
    {
        if ($totalMethods === 0) {
            return 10.0;
        }
        $undocumented = count($issues);
        $ratio = 1 - ($undocumented / max(1, $totalMethods));
        return round(max(0, min(10, $ratio * 10)), 1);
    }
}
