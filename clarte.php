#!/usr/bin/env php
<?php

/**
 * Clarté — analyseur professionnel de projets PHP / Laravel / Blade / JS
 *
 * Usage :
 *   php clarte.php <chemin_du_projet> [options]
 *
 * Options :
 *   --ai                 Active l'analyse assistée par IA (nécessite un token,
 *                         voir config.php -> ai.token_env_var)
 *   --no-cache            Ignore le cache et réanalyse tout
 *   --ci                  Mode CI/CD : code de sortie non nul si des
 *                         problèmes critiques sont détectés
 *   --diff                 Analyse uniquement les fichiers modifiés (indexes,
 *                         non indexes, ou nouveaux) par rapport a HEAD.
 *                         Nécessite que <chemin_du_projet> soit un depot Git ;
 *                         à défaut, analyse complète effectuée avec un avertissement.
 *   --diff=<ref>            Analyse uniquement les fichiers qui diffèrent entre
 *                         <ref> (ex: main, origin/main) et HEAD. Utile en CI
 *                         pour analyser uniquement les changements d'une PR.
 *   --pdf                  Génère aussi reports/rapport.pdf à partir du HTML
 *                         (nécessite wkhtmltopdf ou Chrome/Chromium installé ;
 *                         ignoré proprement avec un avertissement sinon).
 *   --config=chemin.php   Utilise un fichier de configuration alternatif
 *
 * Exemple :
 *   GITHUB_MODELS_TOKEN=xxxx php clarte.php /var/www/mon-projet --ai --ci
 *   php clarte.php /var/www/mon-projet --diff              # avant un commit
 *   php clarte.php /var/www/mon-projet --diff=origin/main --ci   # en CI sur une PR
 */

require __DIR__ . '/vendor/autoload.php'; // génère par `composer install` (autoload PSR-4)

use Clarte\AnalysisEngine;
use Clarte\Cache;

$args = $argv;
array_shift($args);

$projectPath = null;
$useAi = false;
$noCache = false;
$ciMode = false;
$diffOnly = false;
$diffBase = null;
$pdf = false;
$configPath = __DIR__ . '/config.php';

foreach ($args as $arg) {
    if ($arg === '--ai') {
        $useAi = true;
    } elseif ($arg === '--no-cache') {
        $noCache = true;
    } elseif ($arg === '--ci') {
        $ciMode = true;
    } elseif ($arg === '--diff') {
        $diffOnly = true;
    } elseif (str_starts_with($arg, '--diff=')) {
        $diffOnly = true;
        $diffBase = substr($arg, 7);
    } elseif ($arg === '--pdf') {
        $pdf = true;
    } elseif (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, 9);
    } elseif (!str_starts_with($arg, '--')) {
        $projectPath = $arg;
    }
}

if ($projectPath === null || !is_dir($projectPath)) {
    fwrite(STDERR, "Usage : php clarte.php <chemin_du_projet> [--ai] [--no-cache] [--ci] [--diff | --diff=<ref>] [--pdf] [--config=chemin.php]\n");
    exit(1);
}

$config = require $configPath;
$config['project_path'] = realpath($projectPath);
if ($pdf) {
    $config['output']['pdf'] = true;
}

if ($noCache) {
    (new Cache($config['cache']['path'], true))->clear();
}

$engine = new AnalysisEngine($config);
$result = $engine->run($config['project_path'], $useAi, $diffOnly, $diffBase);

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo " Analyse terminée" . PHP_EOL;
echo "========================================" . PHP_EOL;
if ($result['summary']['partial_analysis']['active'] ?? false) {
    echo " Mode              : --diff (analyse partielle)" . PHP_EOL;
}
echo " Score global      : {$result['summary']['global_score']}/100" . PHP_EOL;
echo " Fichiers analyses : {$result['statistics']['total_files']}" . PHP_EOL;
echo " Alertes critiques : {$result['summary']['issues_by_severity']['critical']}" . PHP_EOL;
echo " Rapport HTML      : {$result['output_dir']}/rapport.html" . PHP_EOL;
if ($result['pdf_result'] !== null) {
    if ($result['pdf_result']['success']) {
        echo " Rapport PDF       : {$result['output_dir']}/rapport.pdf (via {$result['pdf_result']['tool']})" . PHP_EOL;
    } else {
        echo " Rapport PDF       : non généré — {$result['pdf_result']['message']}" . PHP_EOL;
    }
}
echo "========================================" . PHP_EOL;

if ($ciMode && $result['summary']['issues_by_severity']['critical'] > 0) {
    fwrite(STDERR, "\n[CI] Échec : des problèmes critiques ont été détectés.\n");
    exit(2);
}

exit(0);
