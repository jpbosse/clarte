<?php

namespace Clarte;

/**
 * Journalisation simple, horodatee, vers fichier + sortie standard optionnelle.
 */
class Logger
{
    private string $logFile;
    private bool $echoToConsole;
    private array $stepTimers = [];

    public function __construct(string $logFile, bool $echoToConsole = true)
    {
        $this->logFile = $logFile;
        $this->echoToConsole = $echoToConsole;

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->write('=== Nouvelle analyse demarree ===', 'INFO');
    }

    public function info(string $message): void
    {
        $this->write($message, 'INFO');
    }

    public function warning(string $message): void
    {
        $this->write($message, 'WARN');
    }

    public function error(string $message): void
    {
        $this->write($message, 'ERROR');
    }

    public function debug(string $message): void
    {
        $this->write($message, 'DEBUG');
    }

    public function startStep(string $name): void
    {
        $this->stepTimers[$name] = microtime(true);
        $this->info("Debut de l'etape : {$name}");
    }

    public function endStep(string $name): float
    {
        $start = $this->stepTimers[$name] ?? microtime(true);
        $duration = microtime(true) - $start;
        $this->info(sprintf("Fin de l'etape : %s (%.2fs)", $name, $duration));
        return $duration;
    }

    private function write(string $message, string $level): void
    {
        $line = sprintf('[%s] [%s] %s', date('Y-m-d H:i:s'), $level, $message);
        file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND);

        if ($this->echoToConsole && PHP_SAPI === 'cli') {
            $colors = [
                'INFO'  => "\033[0;36m",
                'WARN'  => "\033[0;33m",
                'ERROR' => "\033[0;31m",
                'DEBUG' => "\033[0;90m",
            ];
            $reset = "\033[0m";
            $color = $colors[$level] ?? '';
            fwrite(STDOUT, $color . $line . $reset . PHP_EOL);
        }
    }
}
