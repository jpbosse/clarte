<?php

namespace Clarte;

/**
 * Export des resultats dans differents formats (JSON, Markdown, CSV).
 * L'export PDF n'est pas inclus en v1 : voir README (roadmap v2) pour
 * l'approche recommandee (rendu HTML -> PDF via un moteur externe,
 * volontairement laisse hors scope pour garder l'outil sans dependance
 * lourde par defaut).
 */
class Exporter
{
    public function exportJson(array $data, string $path): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function exportMarkdown(array $summary, array $statistics, array $fileResults, string $projectName, string $path): void
    {
        $lines = [];
        $lines[] = "# Rapport d'audit — {$projectName}";
        $lines[] = '';
        $lines[] = "Genere le " . date('d/m/Y H:i:s');
        $lines[] = '';
        $lines[] = "## Synthese";
        $lines[] = '';
        $lines[] = $summary['narrative'];
        $lines[] = '';
        $lines[] = "**Score global : {$summary['global_score']}/100**";
        $lines[] = '';
        $lines[] = "| Categorie | Score |";
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
        $lines[] = "## Top priorites";
        $lines[] = '';
        foreach (array_slice($summary['top_priorities'], 0, 20) as $issue) {
            $lines[] = "- [{$issue['severity']}] {$issue['file']}:{$issue['line']} — {$issue['message']}";
        }

        file_put_contents($path, implode("\n", $lines));
    }

    public function exportCsv(array $statistics, string $path): void
    {
        $fh = fopen($path, 'w');
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
}
