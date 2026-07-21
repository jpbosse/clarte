<?php

namespace Clarte;

/**
 * Charge les surcharges de regles specifiques a UN projet analyse, depuis
 * un fichier `.clarte-rules.php` place a la racine de ce projet (pas dans
 * le dossier de Clarté lui-meme). Permet d'utiliser le meme outil sur
 * plusieurs projets aux conventions differentes sans toucher a la
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
 * Note de securite : ce fichier est execute (`require`) comme du code PHP
 * normal, au meme titre qu'un `composer.json` ou un `.php-cs-fixer.php`
 * du projet analyse. Il n'est charge que si la personne l'a place
 * elle-meme dans un projet qu'elle a choisi d'analyser : ne jamais
 * pointer Clarté vers un projet dont on ne maitrise pas le contenu.
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
            $this->logger->warning(".clarte-rules.php present mais son execution a echoue ({$e->getMessage()}) : regles par defaut utilisees.");
            return $defaults;
        }

        if (!is_array($userRules)) {
            $this->logger->warning(".clarte-rules.php present mais ne retourne pas un tableau : regles par defaut utilisees.");
            return $defaults;
        }

        $merged = array_replace($defaults, array_intersect_key($userRules, $defaults));

        $unknownKeys = array_diff(array_keys($userRules), array_keys($defaults));
        if (!empty($unknownKeys)) {
            $this->logger->warning('.clarte-rules.php contient des cles inconnues, ignorees : ' . implode(', ', $unknownKeys));
        }

        $this->logger->info(sprintf(
            '.clarte-rules.php charge : %d regle(s) desactivee(s), %d surcharge(s) de severite, %d seuil(s) personnalise(s).',
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
