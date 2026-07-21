<?php

namespace Clarte;

/**
 * Barre de progression console avec estimation du temps restant (ETA).
 */
class ProgressBar
{
    private int $total;
    private int $current = 0;
    private float $startTime;
    private int $barWidth = 40;
    private string $label;

    public function __construct(int $total, string $label = 'Analyse')
    {
        $this->total = max(1, $total);
        $this->startTime = microtime(true);
        $this->label = $label;
    }

    public function advance(string $currentItem = ''): void
    {
        $this->current++;
        $this->render($currentItem);
    }

    private function render(string $currentItem): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $percent = $this->current / $this->total;
        $filled = (int) round($percent * $this->barWidth);
        $bar = str_repeat('#', $filled) . str_repeat('-', $this->barWidth - $filled);

        $elapsed = microtime(true) - $this->startTime;
        $rate = $this->current > 0 ? $elapsed / $this->current : 0;
        $remaining = $rate * ($this->total - $this->current);

        $eta = $this->formatDuration($remaining);
        $item = $currentItem !== '' ? ' | ' . $this->truncateItem($currentItem) : '';

        fwrite(STDOUT, sprintf(
            "\r%s [%s] %d/%d (%.1f%%) ETA: %s%s%s",
            $this->label,
            $bar,
            $this->current,
            $this->total,
            $percent * 100,
            $eta,
            $item,
            str_repeat(' ', 10) // efface les résidus de ligne précédente
        ));

        if ($this->current >= $this->total) {
            fwrite(STDOUT, PHP_EOL);
        }
    }

    private function truncateItem(string $item): string
    {
        return strlen($item) > 40 ? '...' . substr($item, -37) : $item;
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds', (int) $seconds);
        }
        $minutes = (int) floor($seconds / 60);
        $secs = (int) $seconds % 60;
        return sprintf('%dm%02ds', $minutes, $secs);
    }

    public function finish(): void
    {
        $this->current = $this->total;
        $this->render('terminé');
    }
}
