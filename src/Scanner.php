<?php

namespace Clarte;

/**
 * Parcourt l'arborescence du projet, applique les exclusions et retourne
 * la liste des fichiers à analyser avec leurs métadonnées de base.
 */
class Scanner
{
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @return array<int, array{path:string, relative:string, ext:string, lang:string, size:int}>
     */
    public function scan(string $rootPath): array
    {
        $rootPath = rtrim($rootPath, '/');
        $files = [];
        $skippedDirs = 0;

        // Parcours manuel iteratif (pile), plutôt que RecursiveDirectoryIterator :
        // cela permet (1) de ne JAMAIS descendre dans un dossier exclu (storage,
        // vendor, node_modules...) et (2) de survivre a un dossier illisible
        // (permission refusée) sans interrompre toute l'analyse.
        $stack = [$rootPath];

        while (!empty($stack)) {
            $currentDir = array_pop($stack);

            if (!is_readable($currentDir)) {
                $this->logger->warning("Dossier ignoré (permission refusée) : {$currentDir}");
                $skippedDirs++;
                continue;
            }

            $entries = @scandir($currentDir);
            if ($entries === false) {
                $this->logger->warning("Dossier illisible, ignoré : {$currentDir}");
                $skippedDirs++;
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $fullPath = $currentDir . '/' . $entry;
                $relative = ltrim(substr($fullPath, strlen($rootPath)), '/');

                if ($this->isExcludedDir($relative)) {
                    continue;
                }

                // is_dir()/is_link() peuvent eux-mêmes échouer sur un lien
                // symbolique casse ou un montage inaccessible : on protège chaque appel.
                $isDir = @is_dir($fullPath) && !@is_link($fullPath);

                if ($isDir) {
                    $stack[] = $fullPath;
                    continue;
                }

                if ($this->isExcludedFile($entry)) {
                    continue;
                }

                $lang = $this->detectLanguage($entry);
                if ($lang === null) {
                    continue;
                }

                $size = @filesize($fullPath);
                if ($size === false) {
                    $this->logger->warning("Fichier illisible, ignoré : {$relative}");
                    continue;
                }

                $files[] = [
                    'path'     => $fullPath,
                    'relative' => $relative,
                    'ext'      => pathinfo($entry, PATHINFO_EXTENSION),
                    'lang'     => $lang,
                    'size'     => $size,
                    'mtime'    => @filemtime($fullPath) ?: 0,
                ];
            }
        }

        if ($skippedDirs > 0) {
            $this->logger->warning("{$skippedDirs} dossier(s) ignoré(s) pour cause de permissions ou d'erreur de lecture");
        }
        $this->logger->info(sprintf('Scan terminé : %d fichiers retenus dans %s', count($files), $rootPath));

        return $files;
    }

    private function detectLanguage(string $filename): ?string
    {
        // cas particulier : *.blade.php avant *.php
        if (str_ends_with($filename, '.blade.php')) {
            return $this->config['extensions']['blade.php'] ?? 'Blade';
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $this->config['extensions'][$ext] ?? null;
    }

    private function isExcludedDir(string $relativePath): bool
    {
        $pathParts = explode('/', $relativePath);

        foreach ($this->config['excluded_dirs'] as $excluded) {
            $excludedParts = explode('/', trim($excluded, '/'));
            $excludedLen = count($excludedParts);

            // On cherche une sous-sequence contigue de $pathParts qui correspond
            // exactement a $excludedParts (ex: 'public/build' ne doit exclure
            // QUE public/build, pas tout dossier nomme 'public').
            for ($i = 0; $i <= count($pathParts) - $excludedLen; $i++) {
                if (array_slice($pathParts, $i, $excludedLen) === $excludedParts) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isExcludedFile(string $filename): bool
    {
        foreach ($this->config['excluded_files'] as $pattern) {
            if (fnmatch($pattern, $filename)) {
                return true;
            }
        }
        return false;
    }
}
