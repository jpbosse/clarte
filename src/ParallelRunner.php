<?php

namespace Clarte;

/**
 * Orchestre plusieurs processus PHP CLI (via proc_open) pour paralleliser
 * l'analyse sur les machines multi-coeurs, sur de gros projets.
 *
 * Approche volontairement basee sur des processus separes (pas
 * pcntl_fork) : pcntl n'est pas toujours installe (souvent absent des
 * hebergements mutualises, absent sur Windows), alors que proc_open est
 * disponible partout ou PHP CLI l'est.
 *
 * Chaque worker est le meme script clarte.php, relance avec l'option
 * cachee --worker-batch=<fichier.json> (liste des chemins relatifs a
 * traiter) et --worker-output=<fichier.json> (ou ecrire le resultat). Le
 * worker ne fait QUE cette analyse partielle : pas de rapport, pas
 * d'ecriture de cache (voir AnalysisEngine::analyzeSubset), pas d'appel
 * IA (rate-limiting pense pour un seul processus).
 */
class ParallelRunner
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param list<string> $relativePaths Tous les fichiers a analyser (chemins relatifs)
     * @return array{results: array<string,array>, workers_used: int}|null null si l'execution parallele a echoue (repli sequentiel a faire cote appelant)
     */
    public function run(array $relativePaths, int $workerCount, string $projectPath, string $configPath): ?array
    {
        if (empty($relativePaths)) {
            return ['results' => [], 'workers_used' => 0];
        }

        $phpBinary = PHP_BINARY ?: 'php';
        $clartePhpPath = realpath(__DIR__ . '/../clarte.php');
        if ($clartePhpPath === false || !is_file($clartePhpPath)) {
            $this->logger->warning("Mode parallele : impossible de localiser clarte.php pour les workers. Repli sequentiel.");
            return null;
        }

        $workerCount = max(1, min($workerCount, count($relativePaths)));
        $chunks = $this->splitIntoChunks($relativePaths, $workerCount);

        $tmpDir = sys_get_temp_dir() . '/clarte-parallel-' . uniqid();
        if (!@mkdir($tmpDir, 0777, true)) {
            $this->logger->warning("Mode parallele : impossible de creer un dossier temporaire ({$tmpDir}). Repli sequentiel.");
            return null;
        }

        $processes = [];
        $outputFiles = [];

        foreach ($chunks as $i => $chunk) {
            $batchFile = "{$tmpDir}/batch_{$i}.json";
            $outputFile = "{$tmpDir}/output_{$i}.json";
            file_put_contents($batchFile, json_encode($chunk));
            $outputFiles[] = $outputFile;

            $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($clartePhpPath)
                . ' ' . escapeshellarg($projectPath)
                . ' --worker-batch=' . escapeshellarg($batchFile)
                . ' --worker-output=' . escapeshellarg($outputFile)
                . ' --config=' . escapeshellarg($configPath);

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if ($proc === false) {
                $this->logger->warning("Mode parallele : echec du lancement d'un worker. Repli sequentiel.");
                $this->cleanup($tmpDir);
                return null;
            }
            // Les pipes ne sont pas lus en continu (analyse courte, peu de sortie) ;
            // on les ferme immediatement pour eviter un blocage si le tampon se remplit,
            // et on recuperera stdout/stderr uniquement en cas d'echec (voir plus bas).
            fclose($pipes[1]);
            fclose($pipes[2]);
            $processes[] = ['proc' => $proc, 'index' => $i];
        }

        $this->logger->info(sprintf('Mode parallele actif : %d fichier(s) repartis sur %d worker(s).', count($relativePaths), $workerCount));

        $allResults = [];
        $failedWorkers = 0;

        foreach ($processes as $p) {
            $exitCode = proc_close($p['proc']);
            $outputFile = $outputFiles[$p['index']];

            if ($exitCode !== 0 || !is_file($outputFile)) {
                $failedWorkers++;
                $this->logger->warning("Worker #{$p['index']} termine avec une erreur (code {$exitCode}) : ses fichiers seront reanalyses sequentiellement.");
                continue;
            }

            $data = json_decode(file_get_contents($outputFile), true);
            if (!is_array($data)) {
                $failedWorkers++;
                $this->logger->warning("Worker #{$p['index']} : sortie illisible, ses fichiers seront reanalyses sequentiellement.");
                continue;
            }

            $allResults = array_merge($allResults, $data);
        }

        $this->cleanup($tmpDir);

        if ($failedWorkers > 0) {
            $this->logger->warning("{$failedWorkers} worker(s) sur {$workerCount} ont echoue : les fichiers manquants seront completes en sequentiel par le processus principal.");
        }

        return ['results' => $allResults, 'workers_used' => $workerCount];
    }

    /**
     * @param list<string> $items
     * @return list<list<string>>
     */
    private function splitIntoChunks(array $items, int $chunkCount): array
    {
        $chunks = array_fill(0, $chunkCount, []);
        foreach ($items as $i => $item) {
            $chunks[$i % $chunkCount][] = $item;
        }
        return array_values(array_filter($chunks, fn($c) => !empty($c)));
    }

    private function cleanup(string $tmpDir): void
    {
        foreach (glob($tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);
    }
}
