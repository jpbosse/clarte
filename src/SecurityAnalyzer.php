<?php

namespace Clarte;

/**
 * Audit de sécurité base sur des heuristiques (regex) ciblées PHP/Laravel/JS.
 * Ce n'est pas un moteur d'analyse de flux de données (taint analysis) :
 * il s'agit de détection de motifs à risque, comme un premier filtre rapide,
 * à compléter par des outils dédiés (PHPStan + plugins sécurité, Psalm, etc.)
 * pour une couverture exhaustive. Voir README, section "Limites connues".
 */
class SecurityAnalyzer
{
    /** @var array<string, array{pattern:string, severity:string, message:string}> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            'sql_concat' => [
                'pattern'  => '/(?:->query|->select|->raw|->statement|mysqli_query|->exec|DB::select|DB::statement|DB::raw|DB::table)\s*\(\s*["\'][^"\']*["\']?\s*\.\s*\$/i',
                'severity' => 'critical',
                'message'  => "Requête SQL potentiellement construite par concaténation (risque d'injection SQL)",
            ],
            'eval' => [
                'pattern'  => '/\beval\s*\(/',
                'severity' => 'critical',
                'message'  => 'Usage de eval() : exécution de code dynamique dangereuse',
            ],
            'exec' => [
                'pattern'  => '/\b(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/',
                'severity' => 'critical',
                'message'  => "Appel système direct ({function}) : vérifier l'origine des arguments",
            ],
            'unserialize' => [
                'pattern'  => '/\bunserialize\s*\(/',
                'severity' => 'important',
                'message'  => "unserialize() sur une donnée potentiellement non fiable (risque d'objet injection)",
            ],
            'dynamic_include' => [
                'pattern'  => '/\b(include|require|include_once|require_once)\s*\(?\s*\$/',
                'severity' => 'important',
                'message'  => "Inclusion dynamique de fichier à partir d'une variable (risque LFI/RFI)",
            ],
            'xss_echo' => [
                'pattern'  => '/echo\s+\$_(GET|POST|REQUEST)\[/',
                'severity' => 'critical',
                'message'  => 'Sortie directe de donnée utilisateur sans échappement (risque XSS)',
            ],
            'blade_raw_echo' => [
                'pattern'  => '/\{!!\s*\$/',
                'severity' => 'important',
                'message'  => "Sortie Blade non échappée {!! !!} : vérifier que la donnée est fiable (risque XSS)",
            ],
            'mass_assignment' => [
                'pattern'  => '/::create\(\s*\$request->all\(\)\s*\)/',
                'severity' => 'important',
                'message'  => "Mass assignment via \$request->all() sans validation ni \$fillable/\$guarded stricts",
            ],
            'hardcoded_secret' => [
                'pattern'  => '/(api[_-]?key|secret|password|token)\s*=\s*["\'][A-Za-z0-9\/\+=_-]{12,}["\']/i',
                'severity' => 'critical',
                'message'  => 'Secret ou clé API potentiellement en dur dans le code source',
            ],
            'aws_key' => [
                'pattern'  => '/AKIA[0-9A-Z]{16}/',
                'severity' => 'critical',
                'message'  => "Clé d'accès AWS (motif AKIA...) détectée dans le code",
            ],
            'stripe_key' => [
                'pattern'  => '/(sk|pk)_(live|test)_[0-9a-zA-Z]{20,}/',
                'severity' => 'critical',
                'message'  => 'Clé API Stripe détectée dans le code',
            ],
            'debug_enabled' => [
                'pattern'  => '/APP_DEBUG\s*=\s*true/i',
                'severity' => 'important',
                'message'  => 'APP_DEBUG actif : à désactiver impérativement en production',
            ],
            'insecure_upload' => [
                'pattern'  => '/move_uploaded_file\s*\(/',
                'severity' => 'moderate',
                'message'  => "Upload de fichier : vérifier la validation d'extension, de mime-type et le renommage",
            ],
            'weak_csrf' => [
                'pattern'  => '/@csrf/i',
                'severity' => 'info',
                'ignore'   => true, // présence positive, non remontée comme problème
                'message'  => 'Protection CSRF présente',
            ],
        ];
    }

    /**
     * @return array<int, array{rule:string, severity:string, message:string, line:int}>
     */
    public function analyze(string $content, string $lang): array
    {
        $issues = [];

        if (!in_array($lang, ['PHP', 'Blade'], true)) {
            // certaines règles restent pertinentes en JS/Vue (secrets, eval)
            $applicable = ['eval', 'hardcoded_secret', 'aws_key', 'stripe_key'];
        } else {
            $applicable = null; // toutes les règles
        }

        $lines = explode("\n", $content);

        foreach ($this->rules as $key => $rule) {
            if (!empty($rule['ignore'])) {
                continue;
            }
            if ($applicable !== null && !in_array($key, $applicable, true)) {
                continue;
            }

            foreach ($lines as $lineNumber => $lineContent) {
                if (preg_match($rule['pattern'], $lineContent)) {
                    $issues[] = [
                        'rule'     => $key,
                        'severity' => $rule['severity'],
                        'message'  => $rule['message'],
                        'line'     => $lineNumber + 1,
                        'excerpt'  => trim($lineContent),
                    ];
                }
            }
        }

        return $issues;
    }

    public function score(array $issues): float
    {
        $weights = ['critical' => 3, 'important' => 2, 'moderate' => 1, 'info' => 0];
        $penalty = 0;
        foreach ($issues as $issue) {
            $penalty += $weights[$issue['severity']] ?? 1;
        }
        return max(0, 10 - min(10, $penalty));
    }
}
