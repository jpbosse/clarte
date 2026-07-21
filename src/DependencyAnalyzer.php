<?php

namespace Clarte;

/**
 * Analyse des dependances declarees (composer.json / package.json).
 *
 * Limite assumee (v1) : sans connexion a une base de vulnerabilites en
 * ligne (OSV.dev, GitHub Advisory Database...), l'outil ne peut PAS
 * affirmer qu'une version est vulnerable. Il se contente donc de :
 *   - lister les dependances et leurs contraintes de version,
 *   - signaler les contraintes trop larges ("*", pas de borne),
 *   - signaler les paquets connus pour necessiter une vigilance particuliere.
 * Le branchement sur une base de vulnerabilites reelle est documente en
 * roadmap v2 (voir README).
 */
class DependencyAnalyzer
{
    private const SENSITIVE_PACKAGES = [
        'laravel/framework', 'symfony/symfony', 'guzzlehttp/guzzle',
        'lodash', 'express', 'axios', 'jquery',
    ];

    public function analyze(string $projectPath): array
    {
        $result = [
            'composer' => $this->analyzeComposer($projectPath),
            'npm'      => $this->analyzeNpm($projectPath),
        ];
        return $result;
    }

    private function analyzeComposer(string $projectPath): array
    {
        $file = rtrim($projectPath, '/') . '/composer.json';
        if (!is_file($file)) {
            return ['found' => false, 'packages' => [], 'warnings' => []];
        }

        $data = json_decode(file_get_contents($file), true) ?: [];
        $packages = array_merge($data['require'] ?? [], $data['require-dev'] ?? []);
        $warnings = $this->buildWarnings($packages);

        return ['found' => true, 'packages' => $packages, 'warnings' => $warnings];
    }

    private function analyzeNpm(string $projectPath): array
    {
        $file = rtrim($projectPath, '/') . '/package.json';
        if (!is_file($file)) {
            return ['found' => false, 'packages' => [], 'warnings' => []];
        }

        $data = json_decode(file_get_contents($file), true) ?: [];
        $packages = array_merge($data['dependencies'] ?? [], $data['devDependencies'] ?? []);
        $warnings = $this->buildWarnings($packages);

        return ['found' => true, 'packages' => $packages, 'warnings' => $warnings];
    }

    private function buildWarnings(array $packages): array
    {
        $warnings = [];
        foreach ($packages as $name => $constraint) {
            if ($constraint === '*' || $constraint === 'latest') {
                $warnings[] = [
                    'severity' => 'moderate',
                    'message'  => "{$name} : contrainte de version non bornee ('{$constraint}'), risque de rupture ou de derive non maitrisee",
                ];
            }
            if (in_array($name, self::SENSITIVE_PACKAGES, true)) {
                $warnings[] = [
                    'severity' => 'info',
                    'message'  => "{$name} : dependance sensible, verifier manuellement la presence de CVE connues (OSV.dev, GitHub Advisories)",
                ];
            }
        }
        return $warnings;
    }
}
