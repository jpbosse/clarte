<?php

namespace Clarte;

/**
 * Rend la notation transparente et verifiable.
 *
 * Principe directeur : un score n'a de valeur que si l'on peut expliquer
 * comment il a ete obtenu et ce qu'il faut corriger pour l'ameliorer.
 * Cette classe produit, pour chaque categorie :
 *   - la methodologie (comment le score est calcule),
 *   - le detail chiffre reel (ce qui a ete compte dans CE projet),
 *   - les limites connues de la mesure (ce que l'outil ne sait pas voir).
 */
class ScoreExplainer
{
    /**
     * Methodologie generale, affichee dans le rapport.
     */
    public function methodology(): array
    {
        return [
            'global' => [
                'title'   => 'Score global (/100)',
                'formula' => 'Moyenne ponderee des 5 scores de categorie, ramenee sur 100.',
                'detail'  => 'Qualite 25 % + Securite 30 % + Architecture 20 % + Performance 15 % + Documentation 10 %. '
                    . 'La securite pese le plus lourd car une vulnerabilite critique a un cout potentiel bien superieur '
                    . 'a un defaut de style. La documentation pese le moins car son absence ralentit la maintenance '
                    . 'sans casser le fonctionnement.',
                'limits'  => 'Une ponderation est toujours un choix editorial : elle est modifiable dans SummaryBuilder.php '
                    . 'si vos priorites different (ex: projet interne ou la documentation compte davantage).',
            ],
            'security' => [
                'title'   => 'Score securite (/10)',
                'formula' => 'Par fichier : 10 - (3 x critiques + 2 x importants + 1 x moyens), plancher a 0. Puis moyenne sur tous les fichiers.',
                'detail'  => 'Detection par motifs (expressions regulieres) de constructions a risque : eval(), appels systeme, '
                    . 'concatenation SQL, sortie non echappee, secrets en dur, uploads non valides, APP_DEBUG actif.',
                'limits'  => 'IMPORTANT : ceci n\'est PAS une analyse de flux de donnees (taint analysis). L\'outil voit qu\'une '
                    . 'construction risquee existe, pas si une donnee utilisateur l\'atteint reellement. Il y a donc des faux '
                    . 'positifs (code deja securise en amont) et des faux negatifs (vulnerabilite via un chemin indirect). '
                    . 'A completer par Psalm en mode taint-analysis ou PHPStan avec extension securite.',
            ],
            'quality' => [
                'title'   => 'Score qualite (/10)',
                'formula' => 'Par fichier : 10 - (1,5 x importants + 1 x moyens + 0,2 x informations), plancher a 0. Puis moyenne.',
                'detail'  => 'Compte les TODO/FIXME/HACK, les lignes dupliquees (3 occurrences ou plus d\'une meme ligne '
                    . 'significative de 25 caracteres minimum), les methodes privees jamais appelees dans leur propre fichier, '
                    . 'et les variables a nom d\'une seule lettre.',
                'limits'  => 'La duplication est mesuree ligne a ligne, pas par blocs semantiques : un vrai detecteur de clones '
                    . '(type PHPCPD) trouverait davantage. Le code mort n\'est cherche que dans le fichier courant, donc une '
                    . 'methode publique inutilisee ailleurs dans le projet n\'est pas detectee.',
            ],
            'architecture' => [
                'title'   => 'Score architecture (/10)',
                'formula' => 'Par fichier : 10 - (2 x importants + 1 x moyens + 0,3 x informations), plancher a 0. Puis moyenne.',
                'detail'  => 'Verifie les seuils configures dans config.php : taille des fichiers, taille des classes, '
                    . 'longueur des methodes, nombre de parametres, nombre de methodes par classe (God Object), '
                    . 'et requetes SQL directes dans un controleur (violation MVC).',
                'limits'  => 'Les seuils sont des conventions, pas des verites : une classe de 320 lignes n\'est pas '
                    . 'intrinsequement mauvaise. Ajustez-les dans config.php selon vos standards d\'equipe. '
                    . 'Le couplage entre classes et les dependances circulaires ne sont pas encore mesures.',
            ],
            'performance' => [
                'title'   => 'Score performance (/10)',
                'formula' => 'Par fichier : 10 - (2 x importants + 1 x moyens + 0,5 x informations), plancher a 0. Puis moyenne.',
                'detail'  => 'Recherche les requetes Eloquent/DB executees a l\'interieur d\'une boucle (N+1), '
                    . 'les boucles imbriquees sur 3 niveaux ou plus, et les vues Blade avec un nombre eleve d\'inclusions.',
                'limits'  => 'Aucune mesure d\'execution reelle n\'est faite : ce sont des indices statiques, pas des '
                    . 'temps mesures. Un N+1 signale peut etre inoffensif sur une collection de 3 elements. '
                    . 'Pour des chiffres reels, utilisez Laravel Telescope, Clockwork ou Blackfire.',
            ],
            'documentation' => [
                'title'   => 'Score documentation (/10)',
                'formula' => 'Par fichier PHP : (1 - methodes_sans_phpdoc / methodes_totales) x 10. Puis moyenne sur les fichiers.',
                'detail'  => 'Une methode est consideree documentee si un bloc /** ... */ la precede immediatement. '
                    . 'Les methodes magiques (__construct, __get...) sont exclues du calcul. Les fichiers non-PHP '
                    . 'obtiennent 10/10 par defaut car la regle ne s\'y applique pas.',
                'limits'  => 'C\'est une mesure de PRESENCE, pas de QUALITE : un PHPDoc vide ou obsolete compte comme '
                    . 'documente. A l\'inverse, un code auto-documente par des noms explicites est penalise alors qu\'il '
                    . 'peut etre parfaitement lisible. Un score bas signale une zone a examiner, pas une faute.',
            ],
        ];
    }

    /**
     * Calcule le detail chiffre reel pour ce projet, categorie par categorie.
     * C'est ce qui repond concretement a "pourquoi 5,2/10 ?".
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

        // Detail specifique a la documentation : le ratio qui produit le score.
        $explanations['documentation'] += $this->documentationBreakdown($fileResults);

        return $explanations;
    }

    /**
     * Pour la documentation, le score ne vient pas d'un decompte de penalites
     * mais d'un ratio. On expose donc les deux nombres qui le composent, afin
     * que l'utilisateur puisse verifier lui-meme le calcul.
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
                ? "Sur {$phpFiles} fichiers PHP, {$totalUndocumented} methodes sans bloc PHPDoc ont ete relevees "
                    . "(15 au maximum sont listees par fichier pour ne pas noyer le rapport)."
                : 'Aucun fichier PHP analyse : le score documentaire est neutre.',
        ];
    }

    /**
     * Explique comment se lisent les niveaux de severite.
     */
    public function severityLegend(): array
    {
        return [
            ['level' => 'critical',  'label' => 'Critique',    'weight' => 'x3', 'meaning' => 'Exploitable ou destructeur. A corriger avant toute mise en production.'],
            ['level' => 'important', 'label' => 'Important',   'weight' => 'x2', 'meaning' => 'Risque reel ou dette structurelle lourde. A planifier rapidement.'],
            ['level' => 'moderate',  'label' => 'Moyen',       'weight' => 'x1', 'meaning' => 'Defaut de conception ou de performance. A traiter lors du prochain passage sur le fichier.'],
            ['level' => 'info',      'label' => 'Information', 'weight' => 'x0,2 a x0,5', 'meaning' => 'Signalement pour information. Souvent un choix de style ou un TODO assume.'],
        ];
    }
}
