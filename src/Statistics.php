<?php

namespace Clarte;

/**
 * Agrege les statistiques globales du projet a partir de la liste des
 * fichiers scannes et des resultats d'analyse par fichier.
 */
class Statistics
{
    public function build(array $files, array $fileResults, string $projectPath): array
    {
        $totalSize = 0;
        $totalLines = 0;
        $byExtension = [];
        $byLanguage = [];
        $byFolder = [];
        $biggestFiles = [];

        foreach ($files as $file) {
            $totalSize += $file['size'];
            $ext = $file['ext'] ?: 'sans extension';
            $byExtension[$ext] = ($byExtension[$ext] ?? 0) + 1;
            $byLanguage[$file['lang']] = ($byLanguage[$file['lang']] ?? 0) + 1;

            $folder = dirname($file['relative']);
            $folder = $folder === '.' ? '(racine)' : explode('/', $folder)[0];
            $byFolder[$folder] = ($byFolder[$folder] ?? 0) + 1;

            $lines = $fileResults[$file['relative']]['lines'] ?? 0;
            $totalLines += $lines;

            $biggestFiles[] = [
                'relative' => $file['relative'],
                'size'     => $file['size'],
                'lines'    => $lines,
            ];
        }

        usort($biggestFiles, fn($a, $b) => $b['size'] <=> $a['size']);

        arsort($byExtension);
        arsort($byLanguage);
        arsort($byFolder);

        return [
            'total_files'      => count($files),
            'total_folders'    => count($byFolder),
            'total_size_bytes' => $totalSize,
            'total_size_human' => $this->humanSize($totalSize),
            'average_size_human' => count($files) > 0 ? $this->humanSize((int) ($totalSize / count($files))) : '0 o',
            'total_lines'      => $totalLines,
            'by_extension'     => $byExtension,
            'by_language'      => $byLanguage,
            'by_folder'        => $byFolder,
            'biggest_files'    => array_slice($biggestFiles, 0, 20),
        ];
    }

    private function humanSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $i = 0;
        $value = $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return round($value, 1) . ' ' . $units[$i];
    }
}
