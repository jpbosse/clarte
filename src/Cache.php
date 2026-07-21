<?php

namespace Clarte;

/**
 * Cache disque base sur le hash du contenu de chaque fichier analyse.
 * Permet la reprise après interruption et l'analyse incrémentale :
 * si un fichier n'a pas changé depuis la dernière analyse, son résultat
 * est réutilisé sans nouvel appel IA.
 */
class Cache
{
    private string $path;
    private bool $enabled;
    private array $index = [];
    private string $indexFile;

    public function __construct(string $path, bool $enabled = true)
    {
        $this->path = rtrim($path, '/');
        $this->enabled = $enabled;
        $this->indexFile = $this->path . '/index.json';

        if ($this->enabled) {
            if (!is_dir($this->path)) {
                @mkdir($this->path, 0777, true);
            }
            $this->loadIndex();
        }
    }

    private function loadIndex(): void
    {
        if (is_file($this->indexFile)) {
            $content = file_get_contents($this->indexFile);
            $this->index = json_decode($content, true) ?: [];
        }
    }

    private function saveIndex(): void
    {
        file_put_contents($this->indexFile, json_encode($this->index, JSON_PRETTY_PRINT));
    }

    public function fileHash(string $filePath): string
    {
        return hash_file('xxh3', $filePath) ?: hash_file('crc32b', $filePath);
    }

    /**
     * Retourne le résultat en cache pour ce fichier si son hash n'a pas
     * change, sinon null.
     */
    public function get(string $relativePath, string $currentHash): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $entry = $this->index[$relativePath] ?? null;
        if (!$entry || $entry['hash'] !== $currentHash) {
            return null;
        }

        $cacheFile = $this->path . '/' . $entry['cache_id'] . '.json';
        if (!is_file($cacheFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($cacheFile), true);
        return $data ?: null;
    }

    public function set(string $relativePath, string $currentHash, array $result): void
    {
        if (!$this->enabled) {
            return;
        }

        $cacheId = substr(md5($relativePath), 0, 20);
        $this->index[$relativePath] = [
            'hash'     => $currentHash,
            'cache_id' => $cacheId,
            'updated'  => date('c'),
        ];

        file_put_contents($this->path . '/' . $cacheId . '.json', json_encode($result));
        $this->saveIndex();
    }

    public function clear(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        foreach (glob($this->path . '/*.json') as $file) {
            unlink($file);
        }
        $this->index = [];
    }
}
