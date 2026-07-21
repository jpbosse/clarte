<?php

namespace Clarte;

/**
 * Analyse des dependances declarees (composer.json / package.json).
 *
 * Depuis v1.1 : interroge reellement OSV.dev (base de vulnerabilites
 * publiques, alimentee entre autres par les GitHub Security Advisories)
 * via VulnerabilityScanner, en plus des heuristiques locales (contraintes
 * de version trop larges, paquets sensibles a surveiller).
 */
class DependencyAnalyzer
{
    private const SENSITIVE_PACKAGES = [
        'laravel/framework', 'symfony/symfony', 'guzzlehttp/guzzle',
        'lodash', 'express', 'axios', 'jquery',
    ];

    private VulnerabilityScanner $scanner;

    public function __construct(array $config, string $cacheBaseDir)
    {
        $this->scanner = new VulnerabilityScanner($config, $cacheBaseDir);
    }

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
            return ['found' => false, 'packages' => [], 'warnings' => [], 'osv' => ['scanned' => false, 'skipped_reason' => null, 'findings' => []]];
        }

        $data = json_decode(file_get_contents($file), true) ?: [];
        $packages = array_merge($data['require'] ?? [], $data['require-dev'] ?? []);
        $warnings = $this->buildWarnings($packages);
        $osv = $this->scanner->scan($packages, 'composer', $projectPath);

        return ['found' => true, 'packages' => $packages, 'warnings' => $warnings, 'osv' => $osv];
    }

    private function analyzeNpm(string $projectPath): array
    {
        $file = rtrim($projectPath, '/') . '/package.json';
        if (!is_file($file)) {
            return ['found' => false, 'packages' => [], 'warnings' => [], 'osv' => ['scanned' => false, 'skipped_reason' => null, 'findings' => []]];
        }

        $data = json_decode(file_get_contents($file), true) ?: [];
        $packages = array_merge($data['dependencies'] ?? [], $data['devDependencies'] ?? []);
        $warnings = $this->buildWarnings($packages);
        $osv = $this->scanner->scan($packages, 'npm', $projectPath);

        return ['found' => true, 'packages' => $packages, 'warnings' => $warnings, 'osv' => $osv];
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
