<?php

namespace Clarte;

/**
 * Orchestre l'ensemble du pipeline d'analyse : scan -> analyse statique
 * par fichier (+ IA optionnelle) -> agregation -> synthese -> export.
 * C'est la classe "chef d'orchestre", volontairement fine : chaque
 * responsabilite reelle est deleguee a une classe dediee (SRP).
 */
class AnalysisEngine
{
    private array $config;
    private Logger $logger;
    private Cache $cache;
    private Scanner $scanner;
    private TokenEstimator $tokenEstimator;
    private Truncator $truncator;
    private SecurityAnalyzer $securityAnalyzer;
    private PerformanceAnalyzer $performanceAnalyzer;
    private ArchitectureAnalyzer $architectureAnalyzer;
    private QualityAnalyzer $qualityAnalyzer;
    private DocumentationAnalyzer $documentationAnalyzer;
    private DependencyAnalyzer $dependencyAnalyzer;
    private Statistics $statistics;
    private PromptBuilder $promptBuilder;
    private GithubModel $aiModel;
    private SummaryBuilder $summaryBuilder;
    private History $history;
    private GraphBuilder $graphBuilder;
    private HtmlReport $htmlReport;
    private Exporter $exporter;

    private int $aiCallsCount = 0;
    private int $aiTokensCount = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logger = new Logger($config['log_file']);
        $this->cache = new Cache($config['cache']['path'], $config['cache']['enabled']);
        $this->scanner = new Scanner($config, $this->logger);
        $this->tokenEstimator = new TokenEstimator();
        $this->truncator = new Truncator($config['truncate']['head_lines'], $config['truncate']['tail_lines']);
        $this->securityAnalyzer = new SecurityAnalyzer();
        $this->performanceAnalyzer = new PerformanceAnalyzer();
        $this->architectureAnalyzer = new ArchitectureAnalyzer($config['thresholds']);
        $this->qualityAnalyzer = new QualityAnalyzer();
        $this->documentationAnalyzer = new DocumentationAnalyzer();
        $this->dependencyAnalyzer = new DependencyAnalyzer();
        $this->statistics = new Statistics();
        $this->promptBuilder = new PromptBuilder();
        $this->aiModel = new GithubModel($config['ai'], $this->logger);
        $this->summaryBuilder = new SummaryBuilder();
        $this->history = new History($config['history']['path'], $config['history']['enabled'], $config['history']['keep_last']);
        $this->graphBuilder = new GraphBuilder();
        $this->htmlReport = new HtmlReport();
        $this->exporter = new Exporter();
    }

    public function run(string $projectPath, bool $useAi = false): array
    {
        $this->logger->startStep('Scan du projet');
        $files = $this->scanner->scan($projectPath);
        $this->logger->endStep('Scan du projet');

        $progress = new ProgressBar(count($files), 'Analyse');
        $fileResults = [];

        $this->logger->startStep('Analyse des fichiers');
        foreach ($files as $file) {
            $progress->advance($file['relative']);
            $fileResults[$file['relative']] = $this->analyzeFile($file, $useAi);
        }
        $progress->finish();
        $this->logger->endStep('Analyse des fichiers');

        $statistics = $this->statistics->build($files, $fileResults, $projectPath);
        $dependencyResult = $this->dependencyAnalyzer->analyze($projectPath);
        $summary = $this->summaryBuilder->build($fileResults, $statistics, $dependencyResult);

        $previousSnapshot = $this->history->getPrevious();
        $this->history->save($summary, $fileResults);
        $comparison = $this->history->compare(
            ['global_score' => $summary['global_score'], 'total_issues' => $summary['total_issues'], 'issues_by_file' => array_map(fn($r) => $this->countIssues($r), $fileResults)],
            $previousSnapshot
        );

        $graphs = [
            'languages' => $this->graphBuilder->languageDistribution($statistics),
            'severity'  => $this->graphBuilder->severityDistribution($summary),
            'scores'    => $this->graphBuilder->scoresBySection($summary),
            'sizes'     => $this->graphBuilder->fileSizeDistribution($statistics),
        ];

        $projectName = basename(rtrim($projectPath, '/'));
        $outputDir = $this->config['output']['dir'];
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->logger->startStep('Generation des rapports');

        if ($this->config['output']['html']) {
            $html = $this->htmlReport->render($statistics, $summary, $fileResults, $dependencyResult, $comparison, $graphs, $projectName);
            file_put_contents($outputDir . '/rapport.html', $html);
        }
        if ($this->config['output']['json']) {
            $this->exporter->exportJson(compact('statistics', 'summary', 'fileResults', 'dependencyResult'), $outputDir . '/rapport.json');
        }
        if ($this->config['output']['markdown']) {
            $this->exporter->exportMarkdown($summary, $statistics, $fileResults, $projectName, $outputDir . '/rapport.md');
        }
        if ($this->config['output']['csv']) {
            $this->exporter->exportCsv($statistics, $outputDir . '/rapport.csv');
        }

        $this->logger->endStep('Generation des rapports');

        $this->logger->info(sprintf(
            'Analyse terminee : %d fichiers, score global %s/100, %d appels IA (%d tokens estimes)',
            count($files), $summary['global_score'], $this->aiCallsCount, $this->aiTokensCount
        ));

        return [
            'statistics' => $statistics,
            'summary'    => $summary,
            'ai_calls'   => $this->aiCallsCount,
            'ai_tokens'  => $this->aiTokensCount,
            'output_dir' => $outputDir,
        ];
    }

    private function analyzeFile(array $file, bool $useAi): array
    {
        $content = @file_get_contents($file['path']);
        if ($content === false) {
            $this->logger->warning("Fichier illisible ou encodage incorrect : {$file['relative']}");
            return $this->emptyResult($file);
        }

        // encodage : on force en UTF-8 valide pour eviter de casser le JSON du rapport
        if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        } elseif (!function_exists('mb_check_encoding')) {
            $content = @iconv('UTF-8', 'UTF-8//IGNORE', $content) ?: $content;
        }

        $hash = $this->cache->fileHash($file['path']);
        $cached = $this->cache->get($file['relative'], $hash);
        if ($cached !== null) {
            return $cached;
        }

        $lines = substr_count($content, "\n") + 1;

        $security = $this->securityAnalyzer->analyze($content, $file['lang']);
        $performance = $this->performanceAnalyzer->analyze($content, $file['lang']);
        $architecture = $this->architectureAnalyzer->analyze($content, $file['lang']);
        $quality = $this->qualityAnalyzer->analyze($content, $file['lang']);
        $documentation = $this->documentationAnalyzer->analyze($content, $file['lang']);

        $ai = null;
        if ($useAi && $this->aiModel->isConfigured()) {
            $truncated = $this->truncator->truncate($content);
            $prompt = $this->promptBuilder->build($file['relative'], $file['lang'], $truncated['content'], array_merge($security, $performance, $architecture, $quality));
            $this->aiTokensCount += $this->tokenEstimator->estimate($prompt);
            $this->aiCallsCount++;

            $response = $this->aiModel->analyze($prompt);
            if ($response['success']) {
                $ai = $response['data'];
            } else {
                $this->logger->warning("Analyse IA echouee pour {$file['relative']} : {$response['error']}");
            }
        }

        // Les fichiers Markdown sont conserves en clair pour etre lisibles
        // directement dans le rapport (README, docs, changelog...), a la
        // maniere de GitHub. Plafonne pour ne pas alourdir le rapport HTML.
        $markdownContent = null;
        if ($file['lang'] === 'Markdown' && $file['size'] <= 120_000) {
            $markdownContent = $content;
        }

        $result = [
            'lang'   => $file['lang'],
            'lines'  => $lines,
            'size'   => $file['size'],
            'markdown' => $markdownContent,
            'scores' => [
                'security'      => $this->securityAnalyzer->score($security),
                'performance'   => $this->performanceAnalyzer->score($performance),
                'architecture'  => $this->architectureAnalyzer->score($architecture),
                'quality'       => $this->qualityAnalyzer->score($quality),
                'documentation' => $this->documentationAnalyzer->score($documentation, max(1, preg_match_all('/function\s+\w+\s*\(/', $content))),
            ],
            'issues' => [
                'security'      => $security,
                'performance'   => $performance,
                'architecture'  => $architecture,
                'quality'       => $quality,
                'documentation' => $documentation,
            ],
            'ai' => $ai,
        ];

        $this->cache->set($file['relative'], $hash, $result);

        return $result;
    }

    private function emptyResult(array $file): array
    {
        return [
            'lang' => $file['lang'], 'lines' => 0, 'size' => $file['size'],
            'scores' => ['security' => 10, 'performance' => 10, 'architecture' => 10, 'quality' => 10, 'documentation' => 10],
            'issues' => ['security' => [], 'performance' => [], 'architecture' => [], 'quality' => [], 'documentation' => []],
            'ai' => null,
        ];
    }

    private function countIssues(array $fileResult): int
    {
        $total = 0;
        foreach (['security', 'performance', 'architecture', 'quality', 'documentation'] as $section) {
            $total += count($fileResult['issues'][$section] ?? []);
        }
        return $total;
    }
}
