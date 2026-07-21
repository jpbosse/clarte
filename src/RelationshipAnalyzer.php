<?php

namespace Clarte;

/**
 * Extrait, par heuristiques regex (pas un vrai parseur PHP -- meme limite
 * assumee que le reste de l'outil), la structure relationnelle d'un
 * fichier PHP : sa classe, ce dont elle herite, ce qu'elle implemente,
 * les classes locales qu'elle importe, et si elle constitue un
 * "point d'entree" (Controleur HTTP, commande CLI, job de file d'attente)
 * -- c'est-a-dire un endroit ou des donnees externes penetrent dans
 * l'application, donc prioritaire pour une relecture de securite.
 *
 * Sert de base a l'organigramme du rapport (RelationshipGraphBuilder).
 */
class RelationshipAnalyzer
{
    public function analyze(string $content, string $relativePath, string $lang): ?array
    {
        if ($lang !== 'PHP') {
            return null;
        }

        $namespace = $this->extractNamespace($content);
        $classInfo = $this->extractClassDeclaration($content);
        if ($classInfo === null) {
            return null;
        }

        $fqcn = $namespace !== null ? $namespace . '\\' . $classInfo['name'] : $classInfo['name'];
        $uses = $this->extractLocalUses($content, $namespace);

        return [
            'fqcn'             => $fqcn,
            'short_name'       => $classInfo['name'],
            'file'             => $relativePath,
            'is_abstract'      => $classInfo['is_abstract'],
            'is_interface'     => $classInfo['is_interface'],
            'is_trait'         => $classInfo['is_trait'],
            'extends'          => $classInfo['extends'],
            'implements'       => $classInfo['implements'],
            'uses'             => $uses,
            'entry_point_type' => $this->detectEntryPoint($relativePath, $classInfo, $content),
        ];
    }

    private function extractNamespace(string $content): ?string
    {
        if (preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @return array{name:string, extends:?string, implements:list<string>, is_abstract:bool, is_interface:bool, is_trait:bool}|null
     */
    private function extractClassDeclaration(string $content): ?array
    {
        // Capture class/interface/trait, avec heritage et interfaces sur la meme ligne
        // (cas le plus courant ; les declarations multi-lignes ne sont pas suivies,
        // limite assumee comme le reste de l'outil base sur regex).
        $pattern = '/\b(abstract\s+)?(class|interface|trait)\s+(\w+)'
            . '(?:\s+extends\s+([\w\\\\]+(?:\s*,\s*[\w\\\\]+)*))?'
            . '(?:\s+implements\s+([\w\\\\]+(?:\s*,\s*[\w\\\\]+)*))?/';

        if (!preg_match($pattern, $content, $m)) {
            return null;
        }

        $isInterface = ($m[2] === 'interface');
        $isTrait = ($m[2] === 'trait');
        $extends = null;
        $implements = [];

        if ($isInterface) {
            // Une interface peut "extends" plusieurs interfaces : traite comme implements
            // pour simplifier le graphe (meme sens de fleche "depend de").
            if (!empty($m[4])) {
                $implements = array_map('trim', explode(',', $m[4]));
            }
        } else {
            if (!empty($m[4])) {
                $extends = trim(explode(',', $m[4])[0]); // PHP n'autorise qu'un seul extends pour class
            }
            if (!empty($m[5])) {
                $implements = array_map('trim', explode(',', $m[5]));
            }
        }

        return [
            'name'         => $m[3],
            'extends'      => $extends,
            'implements'   => $implements,
            'is_abstract'  => !empty($m[1]),
            'is_interface' => $isInterface,
            'is_trait'     => $isTrait,
        ];
    }

    /**
     * Ne retient que les imports qui semblent locaux au projet (meme racine
     * de namespace que le fichier courant), pour ne pas polluer le graphe
     * avec des centaines de classes Laravel/vendor.
     *
     * @return list<string> Noms de classe complets (FQCN)
     */
    private function extractLocalUses(string $content, ?string $namespace): array
    {
        if ($namespace === null) {
            return [];
        }
        $root = explode('\\', $namespace)[0];

        preg_match_all('/^\s*use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?\s*;/m', $content, $matches);

        $uses = [];
        foreach ($matches[1] as $use) {
            if (str_starts_with($use, $root . '\\')) {
                $uses[] = $use;
            }
        }
        return array_values(array_unique($uses));
    }

    /**
     * Detecte si cette classe est un "point d'entree" : un endroit ou des
     * donnees externes (requete HTTP, argument CLI, payload de file
     * d'attente) penetrent dans l'application. Priorite naturelle de
     * lecture pour un audit de securite.
     */
    private function detectEntryPoint(string $relativePath, array $classInfo, string $content): ?string
    {
        $name = $classInfo['name'];
        $extends = $classInfo['extends'] ?? '';
        $implements = $classInfo['implements'];
        $path = str_replace('\\', '/', $relativePath);

        if (str_contains($path, '/Http/Controllers/') || str_ends_with($name, 'Controller')) {
            return 'http_controller';
        }
        if (str_contains($path, '/Console/Commands/') || $extends === 'Command'
            || str_contains($content, 'Illuminate\\Console\\Command')) {
            return 'cli_command';
        }
        if (in_array('ShouldQueue', $implements, true) || str_contains($path, '/Jobs/')) {
            return 'queue_job';
        }
        if (str_contains($path, '/Listeners/') || str_ends_with($name, 'Listener')) {
            return 'event_listener';
        }
        if (str_contains($path, '/Middleware/')) {
            return 'middleware';
        }
        if (str_ends_with($name, 'FormRequest') || str_contains($content, 'Illuminate\\Foundation\\Http\\FormRequest')) {
            return 'form_request';
        }

        return null;
    }
}
