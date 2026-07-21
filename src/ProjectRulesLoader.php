<?php

namespace Clarte;

/**
 * Charge les surcharges de règles spécifiques à UN projet analysé, depuis
 * un fichier `.clarte-rules.php` placé à la racine de ce projet (pas dans
 * le dossier de Clarté lui-même). Permet d'utiliser le même outil sur
 * plusieurs projets aux conventions différentes sans toucher à la
 * configuration globale de Clarté.
 *
 * Format attendu (le fichier doit `return` un tableau) :
 *
 *   <?php
 *   return [
 *       'disabled_rules' => ['todo_fixme', 'poor_naming'],
 *       'severity_overrides' => ['missing_phpdoc' => 'info'],
 *       'excluded_dirs' => ['legacy', 'vendor-custom'],
 *       'excluded_files' => ['*.generated.php'],
 *       'thresholds' => ['method_max_lines' => 80],
 *   ];
 *
 * Note de sécurité : ce fichier est exécuté (`require`) comme du code PHP
 * normal, au même titre qu'un `composer.json` ou un `.php-cs-fixer.php`
 * du projet analysé. Il n'est chargé que si la personne l'a placé
 * elle-même dans un projet qu'elle a choisi d'analyser : ne jamais
 * pointer Clarté vers un projet dont on ne maîtrise pas le contenu.
 */
class ProjectRulesLoader
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function load(?string $projectPath): array
    {
        $defaults = $this->defaults();

        if ($projectPath === null) {
            return $defaults;
        }

        $file = rtrim($projectPath, '/') . '/.clarte-rules.php';
        if (!is_file($file)) {
            return $defaults;
        }

        try {
            $userRules = require $file;
        } catch (\Throwable $e) {
            $this->logger->warning(".clarte-rules.php present mais son exécution a échoué ({$e->getMessage()}) : règles par défaut utilisées.");
            return $defaults;
        }

        if (!is_array($userRules)) {
            $this->logger->warning(".clarte-rules.php present mais ne retourne pas un tableau : règles par défaut utilisées.");
            return $defaults;
        }

        $merged = array_replace($defaults, array_intersect_key($userRules, $defaults));

        $unknownKeys = array_diff(array_keys($userRules), array_keys($defaults));
        if (!empty($unknownKeys)) {
            $this->logger->warning('.clarte-rules.php contient des clés inconnues, ignorées : ' . implode(', ', $unknownKeys));
        }

        $this->logger->info(sprintf(
            '.clarte-rules.php chargé : %d règle(s) désactivée(s), %d surcharge(s) de sévérité, %d seuil(s) personnalise(s).',
            count($merged['disabled_rules']),
            count($merged['severity_overrides']),
            count($merged['thresholds'])
        ));

        return $merged;
    }

    private function defaults(): array
    {
        return [
            'disabled_rules'     => [],
            'severity_overrides' => [],
            'excluded_dirs'      => [],
            'excluded_files'     => [],
            'thresholds'         => [],
        ];
    }
}
