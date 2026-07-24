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
 * Extrait aussi les methodes publiques declarees et les "signaux
 * d'usage" (instanciations, appels statiques, appels de methode,
 * callables de route) necessaires a DeadCodeAnalyzer pour reperer les
 * classes/methodes jamais referencees ailleurs dans le projet.
 *
 * Sert de base a l'organigramme du rapport (RelationshipGraphBuilder)
 * et a la detection de code mort inter-fichiers (DeadCodeAnalyzer).
 */
class RelationshipAnalyzer
{
    /** Methodes magiques PHP : jamais appelees par un nom litteral, donc exclues de la detection de code mort. */
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__call', '__callStatic', '__get', '__set',
        '__isset', '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize',
        '__toString', '__invoke', '__set_state', '__clone', '__debugInfo',
    ];

    /**
     * Noms de methodes appelees par le framework via convention/reflexion
     * plutot que par un appel de code litteral (ex: hooks Laravel, cycle
     * de vie). Volontairement exclues de la detection de code mort pour
     * eviter des faux positifs massifs.
     */
    private const FRAMEWORK_HOOKS = [
        'handle', 'boot', 'register', 'rules', 'authorize', 'messages', 'attributes',
        'withValidator', 'middleware', 'viaQueues', 'via', 'toMail', 'toArray',
        'toDatabase', 'broadcastOn', 'broadcastAs', 'shouldQueue', 'failed', 'retryUntil',
        'tags', 'backoff', 'up', 'down', 'run', 'signature', 'description', 'schedule',
        'render', 'report', 'shouldReport', 'context', 'unauthenticated',
    ];

    public function analyze(string $content, string $relativePath, string $lang): ?array
    {
        if ($lang !== 'PHP') {
            return null;
        }

        $namespace = $this->extractNamespace($content);
        $classInfo = $this->extractClassDeclaration($content);

        // Meme sans classe (ex: fichiers de routes web.php/api.php, scripts
        // procéduraux), on remonte les signaux d'usage : un fichier de routes
        // est souvent la seule trace textuelle qu'une methode de controleur
        // est bien appelee, indispensable a DeadCodeAnalyzer.
        if ($classInfo === null) {
            return [
                'fqcn'             => null,
                'short_name'       => null,
                'file'             => $relativePath,
                'is_abstract'      => false,
                'is_interface'     => false,
                'is_trait'         => false,
                'is_test_class'    => false,
                'extends'          => null,
                'implements'       => [],
                'uses'             => [],
                'entry_point_type' => null,
                'public_methods'   => [],
                'usage'            => $this->extractUsageSignals($content),
            ];
        }

        $fqcn = $namespace !== null ? $namespace . '\\' . $classInfo['name'] : $classInfo['name'];
        $uses = $this->extractLocalUses($content, $namespace);
        $isTestClass = $this->isTestClass($relativePath, $classInfo, $content);

        return [
            'fqcn'             => $fqcn,
            'short_name'       => $classInfo['name'],
            'file'             => $relativePath,
            'is_abstract'      => $classInfo['is_abstract'],
            'is_interface'     => $classInfo['is_interface'],
            'is_trait'         => $classInfo['is_trait'],
            'is_test_class'    => $isTestClass,
            'extends'          => $classInfo['extends'],
            'implements'       => $classInfo['implements'],
            'uses'             => $uses,
            'entry_point_type' => $this->detectEntryPoint($relativePath, $classInfo, $content),
            'public_methods'   => $isTestClass ? [] : $this->extractPublicMethods($content),
            'usage'            => $this->extractUsageSignals($content),
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

    /**
     * Une classe de test (PHPUnit/Pest) n'est jamais appelee par un nom
     * litteral dans le code : le framework de test invoque ses methodes
     * par reflexion, sur la seule base de leur nom/annotation. Detectee
     * ici pour etre totalement exclue de la recherche de methodes mortes
     * (sinon, chaque methode de test serait signalee a tort).
     */
    private function isTestClass(string $relativePath, array $classInfo, string $content): bool
    {
        $path = str_replace('\\', '/', $relativePath);
        if (str_contains($path, '/tests/') || str_contains($path, '/Tests/')) {
            return true;
        }
        if (str_ends_with($classInfo['name'], 'Test')) {
            return true;
        }
        if (str_contains($content, 'PHPUnit\\Framework\\TestCase') || ($classInfo['extends'] === 'TestCase')) {
            return true;
        }
        return false;
    }

    /**
     * @return list<array{name:string, line:int}>
     */
    private function extractPublicMethods(string $content): array
    {
        preg_match_all('/\bpublic\s+(?:static\s+)?function\s+(\w+)\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE);

        $methods = [];
        foreach ($matches[1] as [$name, $offset]) {
            if (in_array($name, self::MAGIC_METHODS, true) || in_array($name, self::FRAMEWORK_HOOKS, true)) {
                continue;
            }
            $methods[] = ['name' => $name, 'line' => substr_count($content, "\n", 0, $offset) + 1];
        }
        return $methods;
    }

    /**
     * Signaux d'usage textuels bruts (avant resolution inter-fichiers, qui
     * se fait au niveau projet dans DeadCodeAnalyzer) : classes
     * instanciees, classes appelees statiquement, noms de methode appeles,
     * et callables de route au format Laravel (tableau ou chaine "@").
     *
     * @return array{instantiated: list<string>, static_called: list<string>, method_calls: list<string>, route_callables: list<string>}
     */
    private function extractUsageSignals(string $content): array
    {
        preg_match_all('/\bnew\s+([A-Z]\w*)\s*\(/', $content, $m1);
        preg_match_all('/\b([A-Z]\w*)\s*::/', $content, $m2);
        preg_match_all('/->\s*(\w+)\s*\(/', $content, $m3);
        preg_match_all('/::\s*(\w+)\s*\(/', $content, $m4);
        // Callables de route Laravel : [Controller::class, 'method'] ou 'Controller@method'
        preg_match_all('/\[\s*[\w\\\\]+::class\s*,\s*[\'"](\w+)[\'"]\s*\]/', $content, $m5);
        preg_match_all('/[\'"][\w\\\\]+@(\w+)[\'"]/', $content, $m6);

        return [
            'instantiated'    => array_values(array_unique($m1[1])),
            'static_called'   => array_values(array_unique($m2[1])),
            'method_calls'    => array_values(array_unique(array_merge($m3[1], $m4[1]))),
            'route_callables' => array_values(array_unique(array_merge($m5[1], $m6[1]))),
        ];
    }
}
