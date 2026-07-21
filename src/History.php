<?php

namespace Clarte;

/**
 * Conserve un instantane (snapshot) de chaque analyse pour permettre
 * la comparaison entre deux executions (aujourd'hui / hier / semaine
 * derniere) : score gagne/perdu, nouvelles alertes, alertes corrigees.
 */
class History
{
    private string $path;
    private bool $enabled;
    private int $keepLast;

    public function __construct(string $path, bool $enabled, int $keepLast = 30)
    {
        $this->path = rtrim($path, '/');
        $this->enabled = $enabled;
        $this->keepLast = $keepLast;

        if ($this->enabled && !is_dir($this->path)) {
            @mkdir($this->path, 0777, true);
        }
    }

    public function save(array $summary, array $fileResults): string
    {
        if (!$this->enabled) {
            return '';
        }

        $snapshot = [
            'date' => date('c'),
            'global_score' => $summary['global_score'],
            'scores' => $summary['scores'],
            'issues_by_severity' => $summary['issues_by_severity'],
            'total_issues' => $summary['total_issues'],
            'issues_by_file' => array_map(
                fn($r) => $this->countIssues($r),
                $fileResults
            ),
        ];

        $filename = $this->path . '/' . date('Y-m-d_His') . '.json';
        @file_put_contents($filename, json_encode($snapshot, JSON_PRETTY_PRINT));
        $this->pruneOldEntries();

        return $filename;
    }

    private function countIssues(array $fileResult): int
    {
        $total = 0;
        foreach (['security', 'performance', 'architecture', 'quality', 'documentation'] as $section) {
            $total += count($fileResult['issues'][$section] ?? []);
        }
        return $total;
    }

    private function pruneOldEntries(): void
    {
        $files = glob($this->path . '/*.json');
        if (count($files) <= $this->keepLast) {
            return;
        }
        sort($files);
        $toDelete = array_slice($files, 0, count($files) - $this->keepLast);
        foreach ($toDelete as $file) {
            unlink($file);
        }
    }

    public function getPrevious(): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        $files = glob($this->path . '/*.json');
        if (count($files) < 2) {
            return null;
        }
        sort($files);
        $previousFile = $files[count($files) - 2];
        return json_decode(file_get_contents($previousFile), true);
    }

    public function compare(array $current, ?array $previous): ?array
    {
        if ($previous === null) {
            return null;
        }

        $scoreDiff = round($current['global_score'] - $previous['global_score'], 1);
        $issuesDiff = $current['total_issues'] - $previous['total_issues'];

        $newFiles = array_diff_key($current['issues_by_file'] ?? [], $previous['issues_by_file'] ?? []);
        $worsenedFiles = [];
        foreach ($current['issues_by_file'] ?? [] as $file => $count) {
            $prevCount = $previous['issues_by_file'][$file] ?? 0;
            if ($count > $prevCount) {
                $worsenedFiles[$file] = $count - $prevCount;
            }
        }

        return [
            'previous_date' => $previous['date'] ?? null,
            'score_diff'    => $scoreDiff,
            'issues_diff'   => $issuesDiff,
            'new_files_with_issues' => array_keys($newFiles),
            'worsened_files' => $worsenedFiles,
        ];
    }

    public function all(): array
    {
        if (!$this->enabled) {
            return [];
        }
        $files = glob($this->path . '/*.json');
        sort($files);
        return array_map(fn($f) => json_decode(file_get_contents($f), true), $files);
    }
}
