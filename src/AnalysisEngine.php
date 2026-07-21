<?php

namespace Clarte;

/**
 * Orchestre l'ensemble du pipeline d'analyse : scan -> analyse statique
 * par fichier (+ IA optionnelle) -> agrégation -> synthèse -> export.
 * C'est la classe "chef d'orchestre", volontairement fine : chaque
 * responsabilité réelle est déléguée à une classe dédiée (SRP).
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
    private RelationshipAnalyzer $relationshipAnalyzer;
    private DependencyAnalyzer $dependencyAnalyzer;
    private GitDiffResolver $gitDiffResolver;
    private Statistics $statistics;
    private PromptBuilder $promptBuilder;
    private GithubModel $aiModel;
    private SummaryBuilder $summaryBuilder;
    private History $history;
    private GraphBuilder $graphBuilder;
    private RelationshipGraphBuilder $relationshipGraphBuilder;
    private HtmlReport $htmlReport;
    private Exporter $exporter;
    private PdfExporter $pdfExporter;
    private ProjectRulesLoader $projectRulesLoader;
    private array $projectRules;

    private int $aiCallsCount = 0;
    private int $aiTokensCount = 0;

    public function __construct(array $config)
    {
        $this->logger = new Logger($config['log_file']);

        $this->projectRulesLoader = new ProjectRulesLoader($this->logger);
        $this->projectRules = $this->projectRulesLoader->load($config['project_path'] ?? null);

        // Fusion des surcharges projet AVANT d'instancier Scanner/ArchitectureAnalyzer,
        // qui figent la configuration au moment de leur construction.
        if (!empty($this->projectRules['thresholds'])) {
            $config['thresholds'] = array_replace($config['thresholds'], $this->projectRules['thresholds']);
        }
        if (!empty($this->projectRules['excluded_dirs'])) {
            $config['excluded_dirs'] = array_merge($config['excluded_dirs'], $this->projectRules['excluded_dirs']);
        }
        if (!empty($this->projectRules['excluded_files'])) {
            $config['excluded_files'] = array_merge($config['excluded_files'], $this->projectRules['excluded_files']);
        }

        $this->config = $config;
        $this->cache = new Cache($config['cache']['path'], $config['cache']['enabled']);
        $this->scanner = new Scanner($config, $this->logger);
        $this->tokenEstimator = new TokenEstimator();
        $this->truncator = new Truncator($config['truncate']['head_lines'], $config['truncate']['tail_lines']);
        $this->securityAnalyzer = new SecurityAnalyzer();
        $this->performanceAnalyzer = new PerformanceAnalyzer();
        $this->architectureAnalyzer = new ArchitectureAnalyzer($config['thresholds']);
        $this->qualityAnalyzer = new QualityAnalyzer();
        $this->documentationAnalyzer = new DocumentationAnalyzer();
        $this->relationshipAnalyzer = new RelationshipAnalyzer();
        $this->dependencyAnalyzer = new DependencyAnalyzer($config['dependencies'] ?? [], $config['cache']['path']);
        $this->gitDiffResolver = new GitDiffResolver();
        $this->statistics = new Statistics();
        $this->promptBuilder = new PromptBuilder();
        $this->aiModel = new GithubModel($config['ai'], $this->logger);
        $this->summaryBuilder = new SummaryBuilder();
        $this->history = new History($config['history']['path'], $config['history']['enabled'], $config['history']['keep_last']);
        $this->graphBuilder = new GraphBuilder();
        $this->relationshipGraphBuilder = new RelationshipGraphBuilder();
        $this->htmlReport = new HtmlReport();
        $this->exporter = new Exporter($this->logger);
        $this->pdfExporter = new PdfExporter($this->logger);
    }

    public function run(string $projectPath, bool $useAi = false, bool $diffOnly = false, ?string $diffBase = null): array
    {
        $this->logger->startStep('Scan du projet');
        $files = $this->scanner->scan($projectPath);
        $this->logger->endStep('Scan du projet');

        $isPartial = false;
        $partialInfo = ['active' => false];

        if ($diffOnly) {
            $changed = $this->gitDiffResolver->changedFiles($projectPath, $diffBase);
            if ($changed === null) {
                $this->logger->warning(
                    "Mode --diff demande mais impossible de determiner les fichiers modifiés "
                    . "(projet hors depot Git, Git absent, ou référence invalide) : analyse complète effectuée à la place."
                );
            } else {
                $changedSet = array_flip($changed);
                $files = array_values(array_filter($files, fn($f) => isset($changedSet[$f['relative']])));
                $isPartial = true;
                $partialInfo = ['active' => true, 'base' => $diffBase, 'files_analyzed' => count($files)];
                $this->logger->info(sprintf(
                    'Mode --diff actif (%s) : %d fichier(s) modifie(s) retenus pour analyse.',
                    $diffBase ? "vs {$diffBase}" : 'working tree',
                    count($files)
                ));
            }
        }

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
        $summary['partial_analysis'] = $partialInfo;

        $comparison = null;
        if (!$isPartial) {
            $previousSnapshot = $this->history->getPrevious();
            $this->history->save($summary, $fileResults);
            $comparison = $this->history->compare(
                ['global_score' => $summary['global_score'], 'total_issues' => $summary['total_issues'], 'issues_by_file' => array_map(fn($r) => $this->countIssues($r), $fileResults)],
                $previousSnapshot
            );
        } else {
            $this->logger->info("Mode --diff : historique non mis à jour (score partiel, non comparable à une analyse complète).");
        }

        $relationships = array_map(fn($r) => $r['relationship'] ?? null, $fileResults);
        $relationshipGraph = $this->relationshipGraphBuilder->build($relationships, $fileResults);

        $graphs = [
            'languages'    => $this->graphBuilder->languageDistribution($statistics),
            'severity'     => $this->graphBuilder->severityDistribution($summary),
            'scores'       => $this->graphBuilder->scoresBySection($summary),
            'sizes'        => $this->graphBuilder->fileSizeDistribution($statistics),
            'relationships' => $relationshipGraph,
        ];

        $projectName = basename(rtrim($projectPath, '/'));
        $outputDir = $this->config['output']['dir'];
        if (!is_dir($outputDir) && !@mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            $this->logger->warning("Impossible de créer le dossier de sortie (permissions ou espace disque) : {$outputDir}. Aucun rapport ne pourra être écrit.");
        }

        $this->logger->startStep('Generation des rapports');

        $pdfResult = null;
        if ($this->config['output']['html']) {
            $html = $this->htmlReport->render($statistics, $summary, $fileResults, $dependencyResult, $comparison, $graphs, $projectName);
            $htmlPath = $outputDir . '/rapport.html';
            if (@file_put_contents($htmlPath, $html) === false) {
                $this->logger->warning("Écriture du rapport HTML impossible (permissions ou espace disque) : {$htmlPath}");
            }

            if (($this->config['output']['pdf'] ?? false) && is_file($htmlPath)) {
                $pdfResult = $this->pdfExporter->export($htmlPath, $outputDir . '/rapport.pdf');
            }
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
            'Analyse terminée : %d fichiers, score global %s/100, %d appels IA (%d tokens estimés)',
            count($files), $summary['global_score'], $this->aiCallsCount, $this->aiTokensCount
        ));

        return [
            'statistics' => $statistics,
            'summary'    => $summary,
            'ai_calls'   => $this->aiCallsCount,
            'ai_tokens'  => $this->aiTokensCount,
            'output_dir' => $outputDir,
            'pdf_result' => $pdfResult,
        ];
    }

    /**
     * Applique les surcharges de .clarte-rules.php à une liste d'issues :
     * retire celles dont la règle est désactivée, ajuste la sévérité de
     * celles concernées par un override. Ne fait rien si aucune règle
     * personnalisée n'est chargée (comportement inchangé par défaut).
     */
    private function applyProjectRules(array $issues): array
    {
        $disabled = $this->projectRules['disabled_rules'];
        $overrides = $this->projectRules['severity_overrides'];

        if (empty($disabled) && empty($overrides)) {
            return $issues;
        }

        $result = [];
        foreach ($issues as $issue) {
            $rule = $issue['rule'] ?? null;
            if ($rule !== null && in_array($rule, $disabled, true)) {
                continue;
            }
            if ($rule !== null && isset($overrides[$rule])) {
                $issue['severity'] = $overrides[$rule];
            }
            $result[] = $issue;
        }
        return $result;
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

        $security = $this->applyProjectRules($this->securityAnalyzer->analyze($content, $file['lang']));
        $performance = $this->applyProjectRules($this->performanceAnalyzer->analyze($content, $file['lang']));
        $architecture = $this->applyProjectRules($this->architectureAnalyzer->analyze($content, $file['lang']));
        $quality = $this->applyProjectRules($this->qualityAnalyzer->analyze($content, $file['lang']));
        $documentation = $this->applyProjectRules($this->documentationAnalyzer->analyze($content, $file['lang']));

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
                $this->logger->warning("Analyse IA échouée pour {$file['relative']} : {$response['error']}");
            }
        }

        // Les fichiers Markdown sont conservés en clair pour être lisibles
        // directement dans le rapport (README, docs, changelog...), à la
        // manière de GitHub. Plafonne pour ne pas alourdir le rapport HTML.
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
            'relationship' => $this->relationshipAnalyzer->analyze($content, $file['relative'], $file['lang']),
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
