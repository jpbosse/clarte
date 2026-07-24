<?php

namespace Clarte;

/**
 * Detecte le code potentiellement mort a l'echelle du PROJET (pas d'un
 * seul fichier) : classes jamais referencees ailleurs, methodes publiques
 * dont le nom n'apparait jamais comme appel nulle part dans le code.
 *
 * Fonctionne en deux temps, comme le reste de l'outil (regex, pas un vrai
 * parseur PHP) :
 *  1. Chaque fichier expose deja ses "signaux d'usage" bruts
 *     (RelationshipAnalyzer::extractUsageSignals) : classes instanciees,
 *     appelees statiquement, methodes appelees, callables de route.
 *  2. Ici, on agrege ces signaux sur l'ENSEMBLE du projet et on les
 *     confronte aux classes/methodes declarees, pour reperer ce qui
 *     n'apparait jamais nulle part ailleurs.
 *
 * Volontairement prudent (peu de faux positifs recherches activement,
 * quitte a rater du vrai code mort) :
 *  - Les classes qui sont des points d'entree (Controleurs, Commands,
 *    Jobs, Listeners, Middlewares, Form Requests) sont exclues : elles
 *    sont invoquees par la configuration du framework (routes, scheduler,
 *    conteneur de services), pas par un appel de code litteral.
 *  - Les classes de test sont totalement exclues (PHPUnit invoque par
 *    reflexion, jamais par appel litteral).
 *  - Les methodes magiques et les "hooks" connus du framework
 *    (boot, handle, rules...) sont exclus.
 *  - Les interfaces et traits ne sont pas signales comme "classe morte"
 *    (une interface est par nature reference via 'implements', deja
 *    capte par le graphe de relations).
 */
class DeadCodeAnalyzer
{
    /**
     * @param array<string, array|null> $relationships cle = chemin relatif, valeur = retour de RelationshipAnalyzer::analyze()
     * @return array{unused_classes: list<array>, unused_methods: list<array>}
     */
    public function analyze(array $relationships): array
    {
        $relationships = array_filter($relationships);
        if (empty($relationships)) {
            return ['unused_classes' => [], 'unused_methods' => []];
        }

        // Agregation des signaux d'usage sur tout le projet.
        $allInstantiated = [];
        $allStaticCalled = [];
        $allMethodCalls = [];
        $allRouteCallables = [];
        foreach ($relationships as $rel) {
            $usage = $rel['usage'] ?? [];
            foreach ($usage['instantiated'] ?? [] as $c) { $allInstantiated[$c] = true; }
            foreach ($usage['static_called'] ?? [] as $c) { $allStaticCalled[$c] = true; }
            foreach ($usage['method_calls'] ?? [] as $m) { $allMethodCalls[$m] = true; }
            foreach ($usage['route_callables'] ?? [] as $m) { $allRouteCallables[$m] = true; }
        }

        // Classes deja reliees via le graphe de relations (extends/implements/uses)
        // -- ce sont deja des references reelles, meme si le nom court n'apparait
        // pas dans une instanciation/appel statique textuel.
        $referencedViaRelations = [];
        foreach ($relationships as $rel) {
            if ($rel['extends'] !== null) { $referencedViaRelations[$rel['extends']] = true; }
            foreach ($rel['implements'] as $i) { $referencedViaRelations[$i] = true; }
            foreach ($rel['uses'] as $u) {
                $short = $this->shortName($u);
                $referencedViaRelations[$short] = true;
            }
        }

        $unusedClasses = [];
        $unusedMethods = [];

        foreach ($relationships as $rel) {
            if ($rel['fqcn'] === null || $rel['is_test_class'] || $rel['is_interface'] || $rel['is_trait']) {
                continue;
            }

            $name = $rel['short_name'];
            $isReferenced = isset($allInstantiated[$name])
                || isset($allStaticCalled[$name])
                || isset($referencedViaRelations[$name]);

            if (!$isReferenced && $rel['entry_point_type'] === null) {
                $unusedClasses[] = [
                    'class' => $rel['fqcn'],
                    'file'  => $rel['file'],
                ];
            }

            // Methodes publiques jamais appelees nulle part (nom seul, cross-fichiers).
            // Les classes points d'entree sont exclues ici aussi : leurs methodes
            // publiques (actions de controleur, handle() de job...) sont invoquees
            // par la configuration du framework (routes, scheduler...), rarement
            // par un appel de code litteral que la regex pourrait detecter.
            if ($rel['entry_point_type'] !== null) {
                continue;
            }
            foreach ($rel['public_methods'] ?? [] as $method) {
                $calledSomewhere = isset($allMethodCalls[$method['name']]) || isset($allRouteCallables[$method['name']]);
                if (!$calledSomewhere) {
                    $unusedMethods[] = [
                        'class'  => $rel['fqcn'],
                        'method' => $method['name'],
                        'file'   => $rel['file'],
                        'line'   => $method['line'],
                    ];
                }
            }
        }

        return ['unused_classes' => $unusedClasses, 'unused_methods' => $unusedMethods];
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
