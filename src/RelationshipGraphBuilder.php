<?php

namespace Clarte;

/**
 * Construit le graphe de classes (organigramme) a partir des relations
 * extraites par RelationshipAnalyzer et des resultats d'analyse par
 * fichier (pour colorer chaque noeud selon son niveau de risque).
 *
 * Resout les references extends/implements (souvent des noms courts,
 * ex: "Controller") vers les classes locales du projet quand c'est
 * possible, sans etre un vrai resolveur de namespace PHP -- limite
 * assumee, coherente avec le reste de l'outil.
 */
class RelationshipGraphBuilder
{
    /**
     * @param array<string, array> $relationships  cle = chemin relatif, valeur = retour de RelationshipAnalyzer::analyze()
     * @param array<string, array> $fileResults     cle = chemin relatif, valeur = resultat complet de l'analyse du fichier
     * @return array{nodes: list<array>, edges: list<array>, groups: list<string>, stats: array}
     */
    public function build(array $relationships, array $fileResults): array
    {
        $relationships = array_filter($relationships, fn($r) => $r !== null && $r['fqcn'] !== null);
        if (empty($relationships)) {
            return ['nodes' => [], 'edges' => [], 'groups' => [], 'stats' => ['total_classes' => 0, 'entry_points' => 0]];
        }

        // Index par nom court pour resoudre extends/implements ecrits sans namespace complet.
        $byShortName = [];
        foreach ($relationships as $rel) {
            $byShortName[$rel['short_name']][] = $rel['fqcn'];
        }
        // Index par FQCN pour resoudre les "uses" (imports complets).
        $byFqcn = [];
        foreach ($relationships as $rel) {
            $byFqcn[$rel['fqcn']] = $rel['fqcn'];
        }

        $nodes = [];
        $edges = [];
        $groups = [];
        $entryPointCount = 0;

        foreach ($relationships as $rel) {
            $group = $this->folderGroup($rel['file']);
            $groups[$group] = true;

            $issueCount = $this->countIssues($fileResults[$rel['file']] ?? null);
            $criticalCount = $this->countCritical($fileResults[$rel['file']] ?? null);

            if ($rel['entry_point_type'] !== null) {
                $entryPointCount++;
            }

            $nodes[] = [
                'id'               => $rel['fqcn'],
                'label'            => $rel['short_name'],
                'file'             => $rel['file'],
                'group'            => $group,
                'is_interface'     => $rel['is_interface'],
                'is_trait'         => $rel['is_trait'],
                'is_abstract'      => $rel['is_abstract'],
                'entry_point_type' => $rel['entry_point_type'],
                'issue_count'      => $issueCount,
                'critical_count'   => $criticalCount,
                'risk_level'       => $this->riskLevel($criticalCount, $issueCount),
            ];

            // Resolution extends -> FQCN local si possible
            if ($rel['extends'] !== null) {
                $target = $this->resolve($rel['extends'], $rel['uses'], $byShortName, $byFqcn);
                if ($target !== null && $target !== $rel['fqcn']) {
                    $edges[] = ['from' => $rel['fqcn'], 'to' => $target, 'type' => 'extends'];
                }
            }

            // Resolution implements -> FQCN local si possible
            foreach ($rel['implements'] as $interfaceName) {
                $target = $this->resolve($interfaceName, $rel['uses'], $byShortName, $byFqcn);
                if ($target !== null && $target !== $rel['fqcn']) {
                    $edges[] = ['from' => $rel['fqcn'], 'to' => $target, 'type' => 'implements'];
                }
            }

            // Relations "uses" (imports locaux) -> lien plus faible, filtrable cote UI
            foreach ($rel['uses'] as $useFqcn) {
                if (isset($byFqcn[$useFqcn]) && $useFqcn !== $rel['fqcn']) {
                    $edges[] = ['from' => $rel['fqcn'], 'to' => $useFqcn, 'type' => 'uses'];
                }
            }
        }

        // Deduplique les aretes identiques (ex: extends deja capte via uses)
        $seen = [];
        $dedupedEdges = [];
        foreach ($edges as $edge) {
            $key = $edge['from'] . '|' . $edge['to'] . '|' . $edge['type'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $dedupedEdges[] = $edge;
            }
        }

        ksort($groups);

        return [
            'nodes'  => $nodes,
            'edges'  => $dedupedEdges,
            'groups' => array_keys($groups),
            'stats'  => [
                'total_classes' => count($nodes),
                'entry_points'  => $entryPointCount,
            ],
        ];
    }

    /**
     * Regroupe par dossier "logique" : les 2 derniers segments du chemin
     * (ex: app/Http/Controllers/Admin/FooController.php -> Controllers/Admin),
     * pour rester lisible meme sur un projet avec beaucoup de sous-dossiers.
     */
    private function folderGroup(string $relativePath): string
    {
        $dir = dirname(str_replace('\\', '/', $relativePath));
        if ($dir === '.' || $dir === '') {
            return '(racine)';
        }
        $parts = explode('/', $dir);
        return implode('/', array_slice($parts, -2));
    }

    private function resolve(string $name, array $localUses, array $byShortName, array $byFqcn): ?string
    {
        // Deja un FQCN connu ?
        if (isset($byFqcn[$name])) {
            return $name;
        }
        // Trouve via un "use" local du fichier (import explicite -> non ambigu)
        foreach ($localUses as $use) {
            if (str_ends_with($use, '\\' . $name)) {
                return $use;
            }
        }
        // Sinon, resolution par nom court SI non ambigue (un seul candidat dans tout le projet)
        if (isset($byShortName[$name]) && count($byShortName[$name]) === 1) {
            return $byShortName[$name][0];
        }
        return null; // classe externe (vendor/framework) ou ambigue : pas de lien trace
    }

    private function countIssues(?array $fileResult): int
    {
        if ($fileResult === null) {
            return 0;
        }
        $total = 0;
        foreach ($fileResult['issues'] ?? [] as $section) {
            $total += count($section);
        }
        return $total;
    }

    private function countCritical(?array $fileResult): int
    {
        if ($fileResult === null) {
            return 0;
        }
        $count = 0;
        foreach ($fileResult['issues'] ?? [] as $section) {
            foreach ($section as $issue) {
                if (($issue['severity'] ?? '') === 'critical') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function riskLevel(int $critical, int $total): string
    {
        if ($critical > 0) {
            return 'critical';
        }
        if ($total >= 5) {
            return 'high';
        }
        if ($total >= 2) {
            return 'moderate';
        }
        if ($total >= 1) {
            return 'low';
        }
        return 'clean';
    }
}
