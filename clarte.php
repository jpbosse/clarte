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
 *   --diff                 Analyse uniquement les fichiers modifies (indexes,
 *                         non indexes, ou nouveaux) par rapport a HEAD.
 *                         Necessite que <chemin_du_projet> soit un depot Git ;
 *                         a defaut, analyse complete effectuee avec un avertissement.
 *   --diff=<ref>            Analyse uniquement les fichiers qui different entre
 *                         <ref> (ex: main, origin/main) et HEAD. Utile en CI
 *                         pour analyser uniquement les changements d'une PR.
 *   --config=chemin.php   Utilise un fichier de configuration alternatif
 *
 * Exemple :
 *   GITHUB_MODELS_TOKEN=xxxx php clarte.php /var/www/mon-projet --ai --ci
 *   php clarte.php /var/www/mon-projet --diff              # avant un commit
 *   php clarte.php /var/www/mon-projet --diff=origin/main --ci   # en CI sur une PR
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
$diffOnly = false;
$diffBase = null;
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
    } elseif (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, 9);
    } elseif (!str_starts_with($arg, '--')) {
        $projectPath = $arg;
    }
}

if ($projectPath === null || !is_dir($projectPath)) {
    fwrite(STDERR, "Usage : php clarte.php <chemin_du_projet> [--ai] [--no-cache] [--ci] [--diff | --diff=<ref>] [--config=chemin.php]\n");
    exit(1);
}

$config = require $configPath;
$config['project_path'] = realpath($projectPath);

if ($noCache) {
    (new Cache($config['cache']['path'], true))->clear();
}

$engine = new AnalysisEngine($config);
$result = $engine->run($config['project_path'], $useAi, $diffOnly, $diffBase);

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo " Analyse terminee" . PHP_EOL;
echo "========================================" . PHP_EOL;
if ($result['summary']['partial_analysis']['active'] ?? false) {
    echo " Mode              : --diff (analyse partielle)" . PHP_EOL;
}
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
