<?php

namespace Clarte;

/**
 * Rend la notation transparente et vérifiable.
 *
 * Principe directeur : un score n'a de valeur que si l'on peut expliquer
 * comment il a été obtenu et ce qu'il faut corriger pour l'améliorer.
 * Cette classe produit, pour chaque catégorie :
 *   - la méthodologie (comment le score est calculé),
 *   - le détail chiffré réel (ce qui a été compté dans CE projet),
 *   - les limites connues de la mesure (ce que l'outil ne sait pas voir).
 */
class ScoreExplainer
{
    /**
     * Méthodologie générale, affichée dans le rapport.
     */
    public function methodology(): array
    {
        return [
            'global' => [
                'title'   => 'Score global (/100)',
                'formula' => 'Moyenne pondérée des 5 scores de catégorie, ramenée sur 100.',
                'détail'  => 'Qualité 25 % + Sécurité 30 % + Architecture 20 % + Performance 15 % + Documentation 10 %. '
                    . 'La sécurité pese le plus lourd car une vulnérabilité critique a un coût potentiel bien superieur '
                    . 'a un défaut de style. La documentation pese le moins car son absence ralentit la maintenance '
                    . 'sans casser le fonctionnement.',
                'limits'  => 'Une pondération est toujours un choix éditorial : elle est modifiable dans SummaryBuilder.php '
                    . 'si vos priorités different (ex: projet interne ou la documentation compte davantage).',
            ],
            'security' => [
                'title'   => 'Score sécurité (/10)',
                'formula' => 'Par fichier : 10 - (3 x critiques + 2 x importants + 1 x moyens), plancher a 0. Puis moyenne sur tous les fichiers.',
                'détail'  => 'Détection par motifs (expressions régulières) de constructions à risque : eval(), appels système, '
                    . 'concaténation SQL, sortie non échappée, secrets en dur, uploads non valides, APP_DEBUG actif.',
                'limits'  => 'IMPORTANT : ceci n\'est PAS une analyse de flux de données (taint analysis). L\'outil voit qu\'une '
                    . 'construction risquée existe, pas si une donnée utilisateur l\'atteint réellement. Il y a donc des faux '
                    . 'positifs (code déjà sécurisé en amont) et des faux négatifs (vulnérabilité via un chemin indirect). '
                    . 'A compléter par Psalm en mode taint-analysis ou PHPStan avec extension sécurité.',
            ],
            'quality' => [
                'title'   => 'Score qualité (/10)',
                'formula' => 'Par fichier : 10 - (1,5 x importants + 1 x moyens + 0,2 x informations), plancher a 0. Puis moyenne.',
                'détail'  => 'Compte les TODO/FIXME/HACK, les lignes dupliquées (3 occurrences ou plus d\'une même ligne '
                    . 'significative de 25 caractères minimum), les méthodes privées jamais appelées dans leur propre fichier, '
                    . 'et les variables a nom d\'une seule lettre.',
                'limits'  => 'La duplication est mesurée ligne à ligne, pas par blocs sémantiques : un vrai détecteur de clones '
                    . '(type PHPCPD) trouverait davantage. Le code mort n\'est cherche que dans le fichier courant, donc une '
                    . 'méthode publique inutilisée ailleurs dans le projet n\'est pas détectée.',
            ],
            'architecture' => [
                'title'   => 'Score architecture (/10)',
                'formula' => 'Par fichier : 10 - (2 x importants + 1 x moyens + 0,3 x informations), plancher a 0. Puis moyenne.',
                'détail'  => 'Vérifie les seuils configurés dans config.php : taille des fichiers, taille des classes, '
                    . 'longueur des méthodes, nombre de paramètres, nombre de méthodes par classe (God Object), '
                    . 'et requêtes SQL directes dans un contrôleur (violation MVC).',
                'limits'  => 'Les seuils sont des conventions, pas des vérités : une classe de 320 lignes n\'est pas '
                    . 'intrinsequement mauvaise. Ajustez-les dans config.php selon vos standards d\'équipe. '
                    . 'Le couplage entre classes et les dépendances circulaires ne sont pas encore mesures.',
            ],
            'performance' => [
                'title'   => 'Score performance (/10)',
                'formula' => 'Par fichier : 10 - (2 x importants + 1 x moyens + 0,5 x informations), plancher a 0. Puis moyenne.',
                'détail'  => 'Recherche les requêtes Eloquent/DB exécutées à l\'intérieur d\'une boucle (N+1), '
                    . 'les boucles imbriquées sur 3 niveaux ou plus, et les vues Blade avec un nombre élevé d\'inclusions.',
                'limits'  => 'Aucune mesure d\'exécution réelle n\'est faite : ce sont des indices statiques, pas des '
                    . 'temps mesures. Un N+1 signale peut être inoffensif sur une collection de 3 éléments. '
                    . 'Pour des chiffres réels, utilisez Laravel Telescope, Clockwork ou Blackfire.',
            ],
            'documentation' => [
                'title'   => 'Score documentation (/10)',
                'formula' => 'Par fichier PHP : (1 - methodes_sans_phpdoc / methodes_totales) x 10. Puis moyenne sur les fichiers.',
                'détail'  => 'Une méthode est considérée documentée si un bloc /** ... */ la précède immédiatement. '
                    . 'Les méthodes magiques (__construct, __get...) sont exclues du calcul. Les fichiers non-PHP '
                    . 'obtiennent 10/10 par défaut car la règle ne s\'y applique pas.',
                'limits'  => 'C\'est une mesure de PRÉSENCE, pas de QUALITÉ : un PHPDoc vide ou obsolète compte comme '
                    . 'documenté. À l\'inverse, un code auto-documenté par des noms explicites est pénalisé alors qu\'il '
                    . 'peut être parfaitement lisible. Un score bas signale une zone à examiner, pas une faute.',
            ],
        ];
    }

    /**
     * Calculé le détail chiffré réel pour ce projet, catégorie par catégorie.
     * C'est ce qui répond concrètement à "pourquoi 5,2/10 ?".
     */
    public function explainScores(array $fileResults, array $averageScores): array
    {
        $explanations = [];

        foreach (['security', 'quality', 'architecture', 'performance', 'documentation'] as $section) {
            $counts = ['critical' => 0, 'important' => 0, 'moderate' => 0, 'info' => 0];
            $filesWithIssues = 0;
            $perfectFiles = 0;
            $worstFiles = [];

            foreach ($fileResults as $path => $result) {
                $issues = $result['issues'][$section] ?? [];
                foreach ($issues as $issue) {
                    $sev = $issue['severity'] ?? 'info';
                    $counts[$sev] = ($counts[$sev] ?? 0) + 1;
                }
                if (count($issues) > 0) {
                    $filesWithIssues++;
                    $worstFiles[] = ['file' => $path, 'count' => count($issues), 'score' => $result['scores'][$section] ?? 10];
                }
                if (($result['scores'][$section] ?? 10) >= 10) {
                    $perfectFiles++;
                }
            }

            usort($worstFiles, fn($a, $b) => $a['score'] <=> $b['score']);

            $explanations[$section] = [
                'score'             => $averageScores[$section] ?? 10,
                'issue_counts'      => $counts,
                'total_issues'      => array_sum($counts),
                'files_with_issues' => $filesWithIssues,
                'files_analyzed'    => count($fileResults),
                'perfect_files'     => $perfectFiles,
                'worst_files'       => array_slice($worstFiles, 0, 5),
            ];
        }

        // Détail spécifique à la documentation : le ratio qui produit le score.
        $explanations['documentation'] += $this->documentationBreakdown($fileResults);

        return $explanations;
    }

    /**
     * Pour la documentation, le score ne vient pas d'un décompte de pénalités
     * mais d'un ratio. On expose donc les deux nombres qui le composent, afin
     * que l'utilisateur puisse vérifier lui-même le calcul.
     */
    private function documentationBreakdown(array $fileResults): array
    {
        $phpFiles = 0;
        $totalUndocumented = 0;

        foreach ($fileResults as $result) {
            if (($result['lang'] ?? '') !== 'PHP') {
                continue;
            }
            $phpFiles++;
            $totalUndocumented += count($result['issues']['documentation'] ?? []);
        }

        return [
            'php_files'            => $phpFiles,
            'undocumented_methods' => $totalUndocumented,
            'note'                 => $phpFiles > 0
                ? "Sur {$phpFiles} fichiers PHP, {$totalUndocumented} méthodes sans bloc PHPDoc ont été relevées "
                    . "(15 au maximum sont listées par fichier pour ne pas noyer le rapport)."
                : 'Aucun fichier PHP analyse : le score documentaire est neutre.',
        ];
    }

    /**
     * Explique comment se lisent les niveaux de sévérité.
     */
    public function severityLegend(): array
    {
        return [
            ['level' => 'critical',  'label' => 'Critique',    'weight' => 'x3', 'meaning' => 'Exploitable ou destructeur. A corriger avant toute mise en production.'],
            ['level' => 'important', 'label' => 'Important',   'weight' => 'x2', 'meaning' => 'Risque réel ou dette structurelle lourde. A planifier rapidement.'],
            ['level' => 'moderate',  'label' => 'Moyen',       'weight' => 'x1', 'meaning' => 'Defaut de conception ou de performance. A traiter lors du prochain passage sur le fichier.'],
            ['level' => 'info',      'label' => 'Information', 'weight' => 'x0,2 a x0,5', 'meaning' => 'Signalement pour information. Souvent un choix de style ou un TODO assume.'],
        ];
    }
}
