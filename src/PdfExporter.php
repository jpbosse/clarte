<?php

namespace Clarte;

/**
 * Convertit le rapport HTML en PDF en s'appuyant sur un outil externe
 * deja present sur la machine, plutot que de reimplementer un moteur de
 * mise en page PDF maison (recommandation du README v1).
 *
 * Outils supportes, par ordre de preference :
 *  1. wkhtmltopdf   (le plus fidele au CSS du rapport)
 *  2. Chrome / Chromium en mode headless (--print-to-pdf), tres repandu
 *     puisque deja installe sur la plupart des postes de developpement.
 *
 * Si aucun des deux n'est trouve dans le PATH, la conversion est ignoree
 * proprement (le rapport HTML reste genere normalement) et un message
 * explique comment installer l'un des deux outils.
 */
class PdfExporter
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return array{success: bool, tool: ?string, message: ?string}
     */
    public function export(string $htmlPath, string $pdfPath): array
    {
        if (!is_file($htmlPath)) {
            return ['success' => false, 'tool' => null, 'message' => "Fichier HTML source introuvable : {$htmlPath}"];
        }

        $wkhtmltopdf = $this->findBinary(['wkhtmltopdf']);
        if ($wkhtmltopdf !== null) {
            return $this->runWkhtmltopdf($wkhtmltopdf, $htmlPath, $pdfPath);
        }

        $chrome = $this->findBinary(['google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium', 'chrome']);
        if ($chrome !== null) {
            return $this->runChromeHeadless($chrome, $htmlPath, $pdfPath);
        }

        $message = "Export PDF ignore : aucun outil de conversion trouve (wkhtmltopdf ni Chrome/Chromium). "
            . "Installez l'un des deux, par exemple : 'sudo apt install wkhtmltopdf' "
            . "ou 'sudo apt install chromium-browser'. Le rapport HTML reste disponible normalement.";
        $this->logger->warning($message);
        return ['success' => false, 'tool' => null, 'message' => $message];
    }

    private function runWkhtmltopdf(string $binary, string $htmlPath, string $pdfPath): array
    {
        $cmd = escapeshellarg($binary) . ' --quiet '
            . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !is_file($pdfPath)) {
            $message = "Echec de la conversion PDF via wkhtmltopdf : " . implode(' ', $output);
            $this->logger->warning($message);
            return ['success' => false, 'tool' => 'wkhtmltopdf', 'message' => $message];
        }

        return ['success' => true, 'tool' => 'wkhtmltopdf', 'message' => null];
    }

    private function runChromeHeadless(string $binary, string $htmlPath, string $pdfPath): array
    {
        $fileUrl = 'file://' . realpath($htmlPath);
        $cmd = escapeshellarg($binary)
            . ' --headless=new --disable-gpu --no-sandbox'
            . ' --print-to-pdf=' . escapeshellarg($pdfPath)
            . ' --no-pdf-header-footer'
            . ' ' . escapeshellarg($fileUrl) . ' 2>&1';
        exec($cmd, $output, $returnCode);

        // Certaines versions de Chrome renvoient un code 0 meme en cas d'echec partiel :
        // on verifie donc en plus la presence effective et la taille du fichier produit.
        if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
            $message = "Echec de la conversion PDF via Chrome/Chromium headless : " . implode(' ', $output);
            $this->logger->warning($message);
            return ['success' => false, 'tool' => 'chrome-headless', 'message' => $message];
        }

        return ['success' => true, 'tool' => 'chrome-headless', 'message' => null];
    }

    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $name) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($path !== '') {
                return $path;
            }
        }
        return null;
    }
}
