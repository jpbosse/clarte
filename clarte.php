#!/usr/bin/env php
<?php

/**
 * Clarté — analyseur professionnel de projets PHP / Laravel / Blade / JS
 *
 * Usage :
 *   php clarte.php <chemin_du_projet> [options]
 *
 * Options :
 *   --ai                 Active l'analyse assistee par IA (necessite un token,
 *                         voir config.php -> ai.token_env_var)
 *   --no-cache            Ignore le cache et reanalyse tout
 *   --ci                  Mode CI/CD : code de sortie non nul si des
 *                         problemes critiques sont detectes
 *   --config=chemin.php   Utilise un fichier de configuration alternatif
 *
 * Exemple :
 *   GITHUB_MODELS_TOKEN=xxxx php clarte.php /var/www/mon-projet --ai --ci
 */

require __DIR__ . '/vendor/autoload.php'; // genere par `composer install` (autoload PSR-4)

use Clarte\AnalysisEngine;
use Clarte\Cache;

$args = $argv;
array_shift($args);

$projectPath = null;
$useAi = false;
$noCache = false;
$ciMode = false;
$configPath = __DIR__ . '/config.php';

foreach ($args as $arg) {
    if ($arg === '--ai') {
        $useAi = true;
    } elseif ($arg === '--no-cache') {
        $noCache = true;
    } elseif ($arg === '--ci') {
        $ciMode = true;
    } elseif (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, 9);
    } elseif (!str_starts_with($arg, '--')) {
        $projectPath = $arg;
    }
}

if ($projectPath === null || !is_dir($projectPath)) {
    fwrite(STDERR, "Usage : php clarte.php <chemin_du_projet> [--ai] [--no-cache] [--ci] [--config=chemin.php]\n");
    exit(1);
}

$config = require $configPath;
$config['project_path'] = realpath($projectPath);

if ($noCache) {
    (new Cache($config['cache']['path'], true))->clear();
}

$engine = new AnalysisEngine($config);
$result = $engine->run($config['project_path'], $useAi);

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo " Analyse terminee" . PHP_EOL;
echo "========================================" . PHP_EOL;
echo " Score global      : {$result['summary']['global_score']}/100" . PHP_EOL;
echo " Fichiers analyses : {$result['statistics']['total_files']}" . PHP_EOL;
echo " Alertes critiques : {$result['summary']['issues_by_severity']['critical']}" . PHP_EOL;
echo " Rapport HTML      : {$result['output_dir']}/rapport.html" . PHP_EOL;
echo "========================================" . PHP_EOL;

if ($ciMode && $result['summary']['issues_by_severity']['critical'] > 0) {
    fwrite(STDERR, "\n[CI] Echec : des problemes critiques ont ete detectes.\n");
    exit(2);
}

exit(0);
