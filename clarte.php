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
 *   --parallel             Analyse en parallele sur plusieurs processus PHP
 *                         (auto-detecte le nombre de coeurs disponibles).
 *                         Incompatible avec --ai (rate-limiting pense pour
 *                         un seul processus : --ai prend le pas si les deux
 *                         sont donnes, avec un avertissement).
 *   --parallel=N            Analyse en parallele sur N processus precisement.
 *
 * Exemple :
 *   GITHUB_MODELS_TOKEN=xxxx php clarte.php /var/www/mon-projet --ai --ci
 *   php clarte.php /var/www/mon-projet --diff              # avant un commit
 *   php clarte.php /var/www/mon-projet --diff=origin/main --ci   # en CI sur une PR
 *   php clarte.php /var/www/mon-projet --parallel           # gros projet, plusieurs coeurs
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
$parallelWorkers = 0;
$workerBatch = null;
$workerOutput = null;

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
    } elseif ($arg === '--parallel') {
        $parallelWorkers = -1; // -1 = auto-detection du nombre de coeurs
    } elseif (str_starts_with($arg, '--parallel=')) {
        $parallelWorkers = max(1, (int) substr($arg, 11));
    } elseif (str_starts_with($arg, '--worker-batch=')) {
        $workerBatch = substr($arg, 15);
    } elseif (str_starts_with($arg, '--worker-output=')) {
        $workerOutput = substr($arg, 16);
    } elseif (!str_starts_with($arg, '--')) {
        $projectPath = $arg;
    }
}

// ---- Mode worker (usage interne, invoque par ParallelRunner) ----
// Un worker analyse uniquement le sous-ensemble de fichiers fourni, ecrit
// le resultat en JSON, et s'arrete la : pas de rapport, pas d'ecriture de
// cache (centralisee dans le processus principal), pas de sortie console
// normale. Ce mode n'est jamais destine a etre invoque directement par
// une personne.
if ($workerBatch !== null && $workerOutput !== null) {
    if ($projectPath === null || !is_dir($projectPath)) {
        fwrite(STDERR, "Mode worker : chemin de projet invalide.\n");
        exit(1);
    }
    $relativePaths = json_decode(file_get_contents($workerBatch), true);
    if (!is_array($relativePaths)) {
        fwrite(STDERR, "Mode worker : fichier de lot illisible ({$workerBatch}).\n");
        exit(1);
    }
    $config = require $configPath;
    $config['project_path'] = realpath($projectPath);
    $engine = new AnalysisEngine($config);
    $results = $engine->analyzeSubset($config['project_path'], $relativePaths);
    file_put_contents($workerOutput, json_encode($results));
    exit(0);
}

if ($projectPath === null || !is_dir($projectPath)) {
    fwrite(STDERR, "Usage : php clarte.php <chemin_du_projet> [--ai] [--no-cache] [--ci] [--diff | --diff=<ref>] [--pdf] [--parallel | --parallel=N] [--config=chemin.php]\n");
    exit(1);
}

$config = require $configPath;
$config['project_path'] = realpath($projectPath);
$config['config_path'] = realpath($configPath) ?: $configPath;
if ($pdf) {
    $config['output']['pdf'] = true;
}

if ($noCache) {
    (new Cache($config['cache']['path'], true))->clear();
}

if ($parallelWorkers !== 0 && $useAi) {
    fwrite(STDERR, "[AVERTISSEMENT] --parallel ignore : incompatible avec --ai (le rate-limiting des appels IA est pense pour un seul processus). Analyse sequentielle.\n");
    $parallelWorkers = 0;
}

$engine = new AnalysisEngine($config);
$result = $engine->run($config['project_path'], $useAi, $diffOnly, $diffBase, $parallelWorkers);

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
