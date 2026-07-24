<?php

namespace Clarte;

/**
 * Prépare les structures de données (labels/valeurs) consommées côté
 * client par le JavaScript embarqué du rapport HTML pour dessiner les
 * graphiques (canvas natif, sans librairie externe afin de garder le
 * rapport 100% autonome).
 */
class GraphBuilder
{
    public function languageDistribution(array $statistics): array
    {
        return [
            'labels' => array_keys($statistics['by_language']),
            'values' => array_values($statistics['by_language']),
        ];
    }

    public function severityDistribution(array $summary): array
    {
        return [
            'labels' => array_keys($summary['issues_by_severity']),
            'values' => array_values($summary['issues_by_severity']),
        ];
    }

    public function scoresBySection(array $summary): array
    {
        return [
            'labels' => array_keys($summary['scores']),
            'values' => array_values($summary['scores']),
        ];
    }

    public function fileSizeDistribution(array $statistics): array
    {
        $buckets = ['<1Ko' => 0, '1-10Ko' => 0, '10-50Ko' => 0, '50-200Ko' => 0, '>200Ko' => 0];
        foreach ($statistics['biggest_files'] as $file) {
            $kb = $file['size'] / 1024;
            if ($kb < 1) $buckets['<1Ko']++;
            elseif ($kb < 10) $buckets['1-10Ko']++;
            elseif ($kb < 50) $buckets['10-50Ko']++;
            elseif ($kb < 200) $buckets['50-200Ko']++;
            else $buckets['>200Ko']++;
        }
        return ['labels' => array_keys($buckets), 'values' => array_values($buckets)];
    }

    public function scoreEvolution(array $historyEntries): array
    {
        return [
            'labels' => array_map(fn($e) => date('d/m H:i', strtotime($e['date'])), $historyEntries),
            'values' => array_map(fn($e) => $e['global_score'], $historyEntries),
        ];
    }

    /**
     * Regroupe l'historique des analyses par JOUR CIVIL (pas par exécution
     * individuelle : plusieurs analyses le même jour ne comptent que pour
     * une seule case). Quand plusieurs snapshots existent le même jour,
     * conserve le DERNIER de la journée (l'état le plus a jour), sur le
     * meme principe qu'un contribution graph GitHub mais colore par
     * niveau de risque plutot que par volume d'activite.
     *
     * @param list<array> $historyEntries retour de History::all()
     * @return array{days: array<string, array{score:float, issues:int, runs:int}>, first_date: ?string, last_date: ?string}
     */
    public function calendarHeatmap(array $historyEntries): array
    {
        $days = [];
        foreach ($historyEntries as $entry) {
            $day = substr($entry['date'], 0, 10); // YYYY-MM-DD
            if (!isset($days[$day])) {
                $days[$day] = ['score' => $entry['global_score'], 'issues' => $entry['total_issues'], 'runs' => 0];
            }
            // Dernier snapshot du jour = etat retenu pour la case (les entrees
            // de History::all() sont deja triees chronologiquement).
            $days[$day]['score'] = $entry['global_score'];
            $days[$day]['issues'] = $entry['total_issues'];
            $days[$day]['runs']++;
        }

        if (empty($days)) {
            return ['days' => [], 'first_date' => null, 'last_date' => null];
        }

        $sortedDates = array_keys($days);
        sort($sortedDates);

        return [
            'days'       => $days,
            'first_date' => $sortedDates[0],
            'last_date'  => end($sortedDates),
        ];
    }
}
