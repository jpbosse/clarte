<?php

/**
 * Configuration de Clarté.
 *
 * Ce fichier retourne un tableau associatif. Vous pouvez le dupliquer en
 * config.local.php (non versionne) pour surcharger certaines valeurs.
 */

return [

    // ------------------------------------------------------------------
    // Cible de l'analyse
    // ------------------------------------------------------------------
    'project_path' => getcwd(),

    // Extensions analysees et leur "famille" logique
    'extensions' => [
        'php'   => 'PHP',
        'blade.php' => 'Blade',
        'vue'   => 'Vue',
        'js'    => 'JavaScript',
        'ts'    => 'TypeScript',
        'html'  => 'HTML',
        'css'   => 'CSS',
        'scss'  => 'SCSS',
        'json'  => 'JSON',
        'yaml'  => 'YAML',
        'yml'   => 'YAML',
        'xml'   => 'XML',
        'md'    => 'Markdown',
    ],

    // Dossiers systematiquement exclus
    'excluded_dirs' => [
        'vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache',
        'public/build', 'public/hot', '.idea', '.vscode', 'dist', 'build',
    ],

    // Fichiers exclus (glob simplifie)
    'excluded_files' => [
        '*.min.js', '*.min.css', '*-lock.json', 'composer.lock', 'package-lock.json',
    ],

    // Taille max analysee en clair (au-dela : troncature intelligente)
    'max_file_size_kb' => 200,

    // Troncature intelligente : nombre de lignes gardees en tete / en queue
    'truncate' => [
        'head_lines' => 300,
        'tail_lines' => 100,
    ],

    // ------------------------------------------------------------------
    // Seuils d'architecture / qualite (personnalisables par projet)
    // ------------------------------------------------------------------
    'thresholds' => [
        'class_max_lines'    => 300,
        'method_max_lines'   => 50,
        'file_max_lines'     => 500,
        'max_params'         => 5,
        'max_cyclomatic'     => 10,
        'god_class_methods'  => 20,
    ],

    // ------------------------------------------------------------------
    // Analyse assistee par IA (GitHub Models, compatible API "chat completions")
    // ------------------------------------------------------------------
    'ai' => [
        'enabled'        => false, // passer a true et renseigner un token pour activer
        'provider'       => 'github_models',
        'endpoint'       => 'https://models.inference.ai.azure.com/chat/completions',
        'model'          => 'gpt-4o-mini',
        'token_env_var'  => 'GITHUB_MODELS_TOKEN', // le token est lu depuis une variable d'environnement, jamais en dur
        'max_tokens'     => 800,
        'temperature'    => 0.2,
        'delay_ms'       => 1200,   // delai entre 2 appels pour respecter les quotas
        'max_retries'    => 3,
        'timeout_sec'    => 30,
    ],

    // ------------------------------------------------------------------
    // Cache / reprise apres interruption
    // ------------------------------------------------------------------
    'cache' => [
        'enabled' => true,
        'path'    => __DIR__ . '/cache',
    ],

    // ------------------------------------------------------------------
    // Historique des analyses
    // ------------------------------------------------------------------
    'history' => [
        'enabled'    => true,
        'path'       => __DIR__ . '/reports/history',
        'keep_last'  => 30,
    ],

    // ------------------------------------------------------------------
    // Sorties
    // ------------------------------------------------------------------
    'output' => [
        'dir'          => __DIR__ . '/reports',
        'html'         => true,
        'json'         => true,
        'markdown'     => true,
        'csv'          => true,
        // 'pdf' => false, // prevu en v2 (necessite une lib de rendu, voir README)
    ],

    'log_file' => __DIR__ . '/reports/logs.txt',
];
