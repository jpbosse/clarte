<?php

namespace Clarte;

/**
 * Construit la synthese executive : score global, points forts,
 * top des priorites (les problemes a corriger en premier pour le
 * meilleur gain de qualite), et checklist "avant mise en production".
 */
class SummaryBuilder
{
    public function build(array $fileResults, array $statistics, array $dependencyResult): array
    {
        $allIssues = [];
        $scoresBySection = ['quality' => [], 'security' => [], 'performance' => [], 'architecture' => [], 'documentation' => []];

        foreach ($fileResults as $relative => $result) {
            foreach (['security', 'performance', 'architecture', 'quality', 'documentation'] as $section) {
                foreach ($result['issues'][$section] ?? [] as $issue) {
                    $allIssues[] = $issue + ['file' => $relative, 'section' => $section];
                }
                if (isset($result['scores'][$section])) {
                    $scoresBySection[$section][] = $result['scores'][$section];
                }
            }
        }

        $averageScores = [];
        foreach ($scoresBySection as $section => $scores) {
            $averageScores[$section] = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 10.0;
        }

        $globalScore = round(
            ($averageScores['quality'] * 0.25)
            + ($averageScores['security'] * 0.30)
            + ($averageScores['performance'] * 0.15)
            + ($averageScores['architecture'] * 0.20)
            + ($averageScores['documentation'] * 0.10),
            1
        ) * 10; // ramene sur 100

        $priorities = $this->prioritize($allIssues);
        $hotFiles = $this->detectHotFiles($fileResults);
        $productionChecklist = $this->buildProductionChecklist($allIssues, $dependencyResult);

        $explainer = new ScoreExplainer();

        return [
            'global_score'    => min(100, max(0, $globalScore)),
            'scores'          => $averageScores,
            'top_priorities'  => array_slice($priorities, 0, 20),
            'hot_files'       => $hotFiles,
            'total_issues'    => count($allIssues),
            'issues_by_severity' => $this->countBySeverity($allIssues),
            'production_checklist' => $productionChecklist,
            'narrative'       => $this->buildNarrative($averageScores, $allIssues),
            // Transparence de la notation : methodologie + detail chiffre reel
            'methodology'      => $explainer->methodology(),
            'score_details'    => $explainer->explainScores($fileResults, $averageScores),
            'severity_legend'  => $explainer->severityLegend(),
        ];
    }

    private function prioritize(array $issues): array
    {
        $weight = ['critical' => 4, 'important' => 3, 'moderate' => 2, 'info' => 1];
        usort($issues, function ($a, $b) use ($weight) {
            return ($weight[$b['severity']] ?? 0) <=> ($weight[$a['severity']] ?? 0);
        });
        return $issues;
    }

    private function detectHotFiles(array $fileResults): array
    {
        $counts = [];
        foreach ($fileResults as $relative => $result) {
            $total = 0;
            foreach (['security', 'performance', 'architecture', 'quality', 'documentation'] as $section) {
                $total += count($result['issues'][$section] ?? []);
            }
            if ($total > 0) {
                $counts[] = ['file' => $relative, 'issues' => $total];
            }
        }
        usort($counts, fn($a, $b) => $b['issues'] <=> $a['issues']);
        return array_slice($counts, 0, 20);
    }

    private function countBySeverity(array $issues): array
    {
        $result = ['critical' => 0, 'important' => 0, 'moderate' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $sev = $issue['severity'] ?? 'info';
            $result[$sev] = ($result[$sev] ?? 0) + 1;
        }
        return $result;
    }

    private function buildProductionChecklist(array $allIssues, array $dependencyResult): array
    {
        $checklist = [];

        $debugIssues = array_filter($allIssues, fn($i) => ($i['rule'] ?? '') === 'debug_enabled');
        $checklist[] = [
            'label' => 'APP_DEBUG desactive',
            'ok'    => count($debugIssues) === 0,
        ];

        $secretIssues = array_filter($allIssues, fn($i) => in_array($i['rule'] ?? '', ['hardcoded_secret', 'aws_key', 'stripe_key'], true));
        $checklist[] = [
            'label' => 'Aucun secret/cle API en dur dans le code',
            'ok'    => count($secretIssues) === 0,
        ];

        $criticalCount = count(array_filter($allIssues, fn($i) => ($i['severity'] ?? '') === 'critical'));
        $checklist[] = [
            'label' => 'Aucune vulnerabilite critique detectee',
            'ok'    => $criticalCount === 0,
        ];

        $looseDeps = array_merge(
            $dependencyResult['composer']['warnings'] ?? [],
            $dependencyResult['npm']['warnings'] ?? []
        );
        $checklist[] = [
            'label' => 'Dependances avec contraintes de version maitrisees',
            'ok'    => count(array_filter($looseDeps, fn($w) => $w['severity'] === 'moderate')) === 0,
        ];

        return $checklist;
    }

    private function buildNarrative(array $scores, array $allIssues): string
    {
        $criticalCount = count(array_filter($allIssues, fn($i) => ($i['severity'] ?? '') === 'critical'));
        $importantCount = count(array_filter($allIssues, fn($i) => ($i['severity'] ?? '') === 'important'));

        $securityPart = $criticalCount > 0
            ? "la securite ({$criticalCount} vulnerabilite(s) critique(s) a traiter en priorite)"
            : "une securite globalement maitrisee";

        $qualityLevel = $scores['quality'] >= 8 ? 'bonne' : ($scores['quality'] >= 6 ? 'correcte' : 'a renforcer');

        return "Le projet presente une qualite de code {$qualityLevel} (score qualite : {$scores['quality']}/10). "
            . "Les principaux points d'attention concernent {$securityPart}, ainsi que {$importantCount} probleme(s) important(s) "
            . "d'architecture ou de performance repartis dans le projet. Les priorites sont detaillees ci-dessous.";
    }
}
