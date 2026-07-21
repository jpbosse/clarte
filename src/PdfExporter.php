<?php

namespace Clarte;

/**
 * Convertit le rapport HTML en PDF en s'appuyant sur un outil externe
 * deja present sur la machine, plutot que de reimplementer un moteur de
 * mise en page PDF maison (recommandation du README v1).
 *
 * Outils supportes, par ordre de preference :
 *  1. Chrome / Chromium en mode headless (--print-to-pdf) : moteur JS
 *     moderne complet, indispensable ici car le rapport HTML est une
 *     petite application JS (graphiques canvas, sections generees
 *     dynamiquement) qui utilise des syntaxes ES6+ (const, fonctions
 *     flechees, template literals).
 *  2. wkhtmltopdf, en repli SEULEMENT : son moteur WebKit embarque est
 *     trop ancien pour executer ce JS (erreur de syntaxe qui bloque tout
 *     le script). Le PDF produit sera donc incomplet — seul le contenu
 *     genere cote serveur (essentiellement le dashboard) apparaitra, les
 *     graphiques et les sections generees en JS (statistiques, securite,
 *     performance, liste des fichiers...) resteront vides. Un
 *     avertissement explicite est renvoye dans ce cas pour que la
 *     personne sache qu'il vaut mieux installer Chrome/Chromium plutot
 *     que de croire le PDF complet a tort.
 *
 * Si aucun des deux n'est trouve dans le PATH, la conversion est ignoree
 * proprement (le rapport HTML reste genere) et un message explique
 * comment installer l'un des deux outils.
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

        $chrome = $this->findBinary(['google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium', 'chrome']);
        if ($chrome !== null) {
            @unlink($pdfPath);
            $result = $this->runChromeHeadless($chrome, $htmlPath, $pdfPath);
            if ($result['success']) {
                return $result;
            }
            $this->logger->warning("Chrome/Chromium detecte ({$chrome}) mais la conversion a echoue : {$result['message']} — tentative avec wkhtmltopdf si disponible.");
        }

        $wkhtmltopdf = $this->findBinary(['wkhtmltopdf']);
        if ($wkhtmltopdf !== null) {
            @unlink($pdfPath);
            $result = $this->runWkhtmltopdf($wkhtmltopdf, $htmlPath, $pdfPath);
            if ($result['success']) {
                $warning = "PDF genere via wkhtmltopdf, mais son moteur JavaScript est trop ancien pour "
                    . "executer les scripts du rapport (graphiques, sections Statistiques/Securite/Performance/"
                    . "Fichiers generees dynamiquement). Le PDF ne contiendra probablement que le tableau de "
                    . "bord. Pour un PDF complet, installez Chrome ou Chromium.";
                $this->logger->warning($warning);
                $result['message'] = $warning;
            }
            return $result;
        }

        $message = "Export PDF ignore : aucun outil de conversion trouve (Chrome/Chromium ni wkhtmltopdf). "
            . "Installez de preference Chrome/Chromium pour un PDF complet, par exemple : "
            . "'sudo apt install chromium-browser' (ou 'sudo apt install wkhtmltopdf' en repli partiel). "
            . "Le rapport HTML reste disponible normalement.";
        $this->logger->warning($message);
        return ['success' => false, 'tool' => null, 'message' => $message];
    }

    private function runWkhtmltopdf(string $binary, string $htmlPath, string $pdfPath): array
    {
        $cmd = escapeshellarg($binary) . ' --quiet --print-media-type --enable-local-file-access '
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
            . ' --virtual-time-budget=4000'
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
