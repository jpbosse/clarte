<?php

namespace Clarte;

/**
 * Export des résultats dans différents formats (JSON, Markdown, CSV).
 */
class Exporter
{
    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    public function exportJson(array $data, string $path): void
    {
        $result = @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($result === false) {
            $this->warn("Export JSON impossible (permissions ou espace disque) : {$path}");
        }
    }

    public function exportMarkdown(array $summary, array $statistics, array $fileResults, string $projectName, string $path): void
    {
        $lines = [];
        $lines[] = "# Rapport d'audit — {$projectName}";
        $lines[] = '';
        $lines[] = "Genere le " . date('d/m/Y H:i:s');
        $lines[] = '';
        $lines[] = "## Synthèse";
        $lines[] = '';
        $lines[] = $summary['narrative'];
        $lines[] = '';
        $lines[] = "**Score global : {$summary['global_score']}/100**";
        $lines[] = '';
        $lines[] = "| Catégorie | Score |";
        $lines[] = "|---|---|";
        foreach ($summary['scores'] as $section => $score) {
            $lines[] = "| " . ucfirst($section) . " | {$score}/10 |";
        }
        $lines[] = '';
        $lines[] = "## Statistiques";
        $lines[] = '';
        $lines[] = "- Fichiers analyses : {$statistics['total_files']}";
        $lines[] = "- Taille totale : {$statistics['total_size_human']}";
        $lines[] = "- Lignes de code : {$statistics['total_lines']}";
        $lines[] = '';
        $lines[] = "## Top priorités";
        $lines[] = '';
        foreach (array_slice($summary['top_priorities'], 0, 20) as $issue) {
            $lines[] = "- [{$issue['severity']}] {$issue['file']}:{$issue['line']} — {$issue['message']}";
        }

        $result = @file_put_contents($path, implode("\n", $lines));
        if ($result === false) {
            $this->warn("Export Markdown impossible (permissions ou espace disque) : {$path}");
        }
    }

    public function exportCsv(array $statistics, string $path): void
    {
        $fh = @fopen($path, 'w');
        if ($fh === false) {
            $this->warn("Export CSV impossible, ouverture du fichier refusée (permissions ou espace disque) : {$path}");
            return;
        }

        fputcsv($fh, ['Metrique', 'Valeur']);
        fputcsv($fh, ['Fichiers', $statistics['total_files']]);
        fputcsv($fh, ['Dossiers', $statistics['total_folders']]);
        fputcsv($fh, ['Taille totale', $statistics['total_size_human']]);
        fputcsv($fh, ['Lignes de code', $statistics['total_lines']]);
        fputcsv($fh, []);
        fputcsv($fh, ['Langage', 'Nombre de fichiers']);
        foreach ($statistics['by_language'] as $lang => $count) {
            fputcsv($fh, [$lang, $count]);
        }
        fclose($fh);
    }

    private function warn(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        } else {
            @trigger_error($message, E_USER_WARNING);
        }
    }
}
