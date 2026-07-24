# Clarté

> *Clarté : qualité de ce qui est facilement intelligible, précis et net.*

Clarté éclaire votre code : l'outil décompose un projet PHP / Laravel /
Blade / JavaScript en cinq dimensions (sécurité, qualité, architecture,
performance, documentation), explique chaque note de manière vérifiable,
et restitue le tout dans un rapport interactif limpide.

Outil professionnel d'analyse statique et assistée par IA pour projets
PHP / Laravel / Blade / JavaScript / Vue. Génère un rapport HTML unique,
autonome et interactif (dashboard, recherche, filtres, graphiques,
thème clair/sombre), comparable dans l'esprit à SonarQube ou CodeClimate,
tout en restant léger et sans dépendance lourde.

## Installation

```bash
git clone <votre-repo> Clarte
cd Clarte
```

Aucune dépendance externe (Composer) n'est strictement nécessaire pour la
v1 : un autoloader PSR-4 minimal suffit. Si vous préférez utiliser Composer
(recommandé pour la maintenabilité à long terme) :

```bash
composer install
```

Sinon, copiez le fichier `vendor/autoload.php` fourni dans le paquet
(autoload manuel, sans dépendance tierce).

**Prérequis** : PHP >= 8.1, extensions `json`, `mbstring` (recommandée,
un mode dégradé sans elle est prévu), `curl` (nécessaire uniquement si
vous activez l'analyse IA ou le scan de vulnérabilités OSV.dev).

## Utilisation

```bash
php clarte.php /chemin/vers/votre/projet
```

Options :

| Option | Effet |
|---|---|
| `--ai` | Active l'analyse assistée par IA (voir section IA ci-dessous) |
| `--no-cache` | Ignore le cache et réanalyse tous les fichiers |
| `--ci` | Mode CI/CD : code de sortie 2 si des problèmes critiques sont détectés |
| `--diff` | Analyse uniquement les fichiers modifiés (indexés, non indexés, nouveaux) par rapport à HEAD |
| `--diff=<ref>` | Analyse uniquement les fichiers qui diffèrent entre `<ref>` (ex: `origin/main`) et HEAD — idéal en CI sur une PR |
| `--pdf` | Génère aussi `reports/rapport.pdf` (Chrome/Chromium recommandé ; wkhtmltopdf en repli produit un PDF incomplet, voir limites ci-dessous) |
| `--parallel` | Analyse en parallèle sur plusieurs processus PHP (auto-détecte le nombre de cœurs). Incompatible avec `--ai` |
| `--parallel=N` | Analyse en parallèle sur N processus précisément |
| `--config=chemin.php` | Utilise un fichier de configuration alternatif |

Le rapport est généré dans `reports/rapport.html` (+ `.json`, `.md`, `.csv`).
Ouvrez simplement `rapport.html` dans un navigateur : aucune connexion
Internet n'est requise, tout est embarqué (CSS + JS).

### Mode `--diff` : analyse incrémentale

Plutôt que de ré-analyser tout le projet, `--diff` cible uniquement ce qui
a changé — utile avant un commit, ou en CI pour ne juger que les fichiers
d'une PR :

```bash
# Avant un commit : que vais-je committer ?
php clarte.php /chemin/projet --diff

# En CI, sur une pull request vers main
php clarte.php /chemin/projet --diff=origin/main --ci
```

Le projet cible doit être un dépôt Git ; sinon (ou si la référence donnée
n'existe pas), Clarté se replie automatiquement sur une analyse complète
en le signalant clairement, plutôt que d'échouer silencieusement. Le score
d'une analyse `--diff` porte uniquement sur le sous-ensemble analysé (une
bannière l'indique dans le rapport) et n'est pas enregistré dans
l'historique, pour ne pas fausser les comparaisons avec les analyses
complètes.

## Activer l'analyse IA

L'IA est **désactivée par défaut**. Pour l'activer :

1. Passez `ai.enabled` à `true` dans `config.php`.
2. Définissez la variable d'environnement contenant votre token
   (`GITHUB_MODELS_TOKEN` par défaut, configurable via `ai.token_env_var`).
   Le token n'est jamais stocké en dur dans le code.
3. Lancez avec l'option `--ai` :

```bash
GITHUB_MODELS_TOKEN=xxxxx php clarte.php /chemin/projet --ai
```

L'endpoint par défaut cible GitHub Models, mais `config.php` accepte tout
endpoint compatible « chat completions » (OpenAI-like). Le client gère
automatiquement le délai entre appels (`ai.delay_ms`), les tentatives avec
backoff exponentiel en cas de code 429/5xx, et le timeout.

## Architecture du projet

```
Clarte/
├── clarte.php                # Point d'entrée CLI
├── config.php                 # Configuration (extensions, seuils, IA, exports...)
├── composer.json
├── cache/                     # Cache incrémental (hash de contenu par fichier)
├── reports/
│   ├── rapport.html
│   ├── rapport.json
│   ├── rapport.md
│   ├── rapport.csv
│   ├── logs.txt
│   └── history/                # Snapshots pour comparaison entre analyses
└── src/
    ├── Logger.php               # Journalisation horodatée
    ├── Cache.php                 # Cache / reprise après interruption
    ├── ProgressBar.php           # Barre de progression CLI avec ETA
    ├── Scanner.php               # Parcours du projet, exclusions
    ├── TokenEstimator.php        # Estimation du nombre de tokens
    ├── Truncator.php              # Troncature intelligente des gros fichiers
    ├── SecurityAnalyzer.php      # Audit sécurité (regex ciblées)
    ├── PerformanceAnalyzer.php   # N+1, boucles imbriquées, requêtes en boucle
    ├── ArchitectureAnalyzer.php  # God Object, classes/méthodes trop longues
    ├── QualityAnalyzer.php       # TODO/FIXME, duplication, code mort local
    ├── DocumentationAnalyzer.php # Couverture PHPDoc
    ├── DependencyAnalyzer.php    # Composer / npm (contraintes de version)
    ├── VulnerabilityScanner.php  # Scan CVE réelles via OSV.dev
    ├── GitDiffResolver.php       # Résolution des fichiers modifiés (--diff)
    ├── ProjectRulesLoader.php    # Règles personnalisées par projet
    ├── PdfExporter.php           # Export PDF (Chrome/Chromium, repli wkhtmltopdf)
    ├── Statistics.php            # Agrégation des statistiques globales
    ├── PromptBuilder.php         # Construction du prompt IA par fichier
    ├── GithubModel.php           # Client API IA (rate limit + retry)
    ├── SummaryBuilder.php        # Score global, priorités, checklist prod
    ├── ScoreExplainer.php        # Transparence : méthodologie, calcul réel, limites
    ├── History.php                # Historique et comparaison entre analyses
    ├── GraphBuilder.php           # Données pour les graphiques du rapport
    ├── HtmlReport.php             # Génération du rapport HTML autonome
    ├── Exporter.php               # Export JSON / Markdown / CSV
    └── AnalysisEngine.php         # Orchestrateur du pipeline complet
```

Chaque classe a une responsabilité unique (SRP). L'orchestrateur
`AnalysisEngine` ne contient aucune logique d'analyse : il délègue à
chaque classe spécialisée, ce qui rend le projet testable et extensible
(ajouter un nouvel analyseur = ajouter une classe + un appel dans
`AnalysisEngine::analyzeFile()`).

## Règles personnalisables par projet

Pour utiliser Clarté sur plusieurs projets aux conventions différentes
sans toucher à `config.php` (partagé entre tous les projets), placez un
fichier `.clarte-rules.php` **à la racine du projet analysé** (pas dans
le dossier de Clarté) :

```php
<?php
return [
    // Désactive complètement certaines règles pour ce projet
    'disabled_rules' => ['todo_fixme', 'poor_naming'],

    // Reclasse la sévérité d'une règle (n'affecte que ce projet)
    'severity_overrides' => ['missing_phpdoc' => 'info'],

    // Seuils d'architecture propres à ce projet (fusionnés avec ceux de config.php)
    'thresholds' => ['method_max_lines' => 80],

    // Exclusions supplémentaires, en plus de celles de config.php
    'excluded_dirs'  => ['legacy'],
    'excluded_files' => ['*.generated.php'],
];
```

Toutes les clés sont optionnelles. Les identifiants de règle valides pour
`disabled_rules`/`severity_overrides` correspondent au champ `rule` de
chaque problème dans `reports/rapport.json` (ex: `sql_concat`, `eval`,
`hardcoded_secret`, `query_in_loop`, `nested_loops`, `class_too_long`,
`method_too_long`, `god_class`, `too_many_params`, `todo_fixme`,
`poor_naming`, `duplicated_line`, `unused_private_method`,
`missing_phpdoc`, et les autres règles de `SecurityAnalyzer`).

Si le fichier est absent, mal formé (ne retourne pas un tableau), ou lève
une erreur PHP, Clarté l'ignore proprement et revient aux réglages par
défaut, avec un avertissement explicite dans les logs — jamais d'échec
silencieux ni de crash.

**Note de sécurité** : ce fichier est exécuté comme du code PHP normal
(au même titre que `composer.json` ou `.php-cs-fixer.php`). Ne pointez
Clarté que sur des projets dont vous maîtrisez le contenu.

**Limite connue** : comme pour les seuils de `config.php`, une
modification de `.clarte-rules.php` n'est prise en compte que pour les
fichiers dont le contenu a changé depuis la dernière analyse (le cache
est indexé par hash de contenu, pas par configuration). Utilisez
`--no-cache` juste après avoir modifié `.clarte-rules.php` pour forcer
une réanalyse complète.

## Transparence de la notation

Chaque carte de score du tableau de bord porte une icône (i). Un clic ouvre
une explication en trois volets :

1. **Comment la note est calculée** : la formule exacte et ce qui est mesuré.
2. **Le calcul pour votre projet** : le décompte réel des problèmes par
   sévérité, les fichiers qui pèsent le plus sur la note, le ratio précis
   (ex : documentation = méthodes documentées / méthodes totales).
3. **Les limites de la mesure** : ce que l'outil ne sait PAS voir, et vers
   quels outils complémentaires se tourner (Psalm, PHPStan, Blackfire...).

Principe directeur : une note qui ne peut pas s'expliquer ne vaut rien.

## Documents Markdown façon GitHub

La section « Documents (README...) » du rapport liste tous les fichiers
Markdown du projet (README, CHANGELOG, docs/...) et les affiche rendus
comme sur GitHub : titres, listes, tableaux, blocs de code, citations,
liens. Le README s'ouvre par défaut. Le rendu échappe intégralement le
HTML source : un document piégé ne peut pas exécuter de code dans le
rapport.

À ne pas confondre avec la section « Documentation (code) », qui évalue
la couverture en commentaires PHPDoc du code source lui-même.

## Analyse parallèle

Sur un gros projet et une machine multi-cœurs, `--parallel` répartit
l'analyse sur plusieurs processus PHP indépendants :

```bash
php clarte.php /chemin/projet --parallel          # auto-detecte le nombre de coeurs
php clarte.php /chemin/projet --parallel=4        # force 4 processus
```

### Comment ça marche

Le processus principal découpe la liste des fichiers en lots égaux, et
relance `clarte.php` lui-même pour chaque lot, en mode « worker » caché
(`--worker-batch=...`) — chaque worker analyse uniquement ses fichiers et
écrit son résultat dans un fichier temporaire, sans générer de rapport ni
écrire dans le cache. Le processus principal attend que tous les workers
terminent, fusionne leurs résultats, puis poursuit normalement (dépendances,
organigramme, rapport...) — y compris l'**écriture du cache, faite une
seule fois, de façon centralisée**, pour éviter que plusieurs processus
n'écrasent `cache/index.json` en même temps.

Approche basée sur des processus séparés (`proc_open`), pas sur
`pcntl_fork` : `pcntl` n'est pas toujours installé (souvent absent des
hébergements mutualisés, absent sur Windows), alors que `proc_open` est
disponible partout où PHP CLI l'est.

### Robustesse

Si le lancement des workers échoue entièrement (environnement restreint,
etc.), l'outil se replie automatiquement sur une analyse séquentielle
classique, avec un avertissement — jamais d'échec silencieux. Si
certains workers échouent isolément, seuls **leurs** fichiers sont
réanalysés en séquentiel par le processus principal pour compléter le
résultat ; les fichiers déjà traités par les workers qui ont réussi ne
sont pas repris.

### Limite connue

**Incompatible avec `--ai`** : le rate-limiting des appels IA
(`GithubModel`) est pensé pour un seul processus. Combiner `--parallel`
et `--ai` désactive automatiquement le mode parallèle (avec un
avertissement) plutôt que de risquer de dépasser le débit prévu auprès
du fournisseur d'IA.

## Export PDF

`PdfExporter` s'appuie sur un outil externe déjà présent sur la machine :
**Chrome/Chromium en priorité** (moteur JS moderne complet, nécessaire car
le rapport est une petite application JS), avec repli sur `wkhtmltopdf` si
Chrome est absent.

Attention : `wkhtmltopdf` embarque un moteur JavaScript trop ancien pour
exécuter les scripts du rapport (ES6+) — le PDF produit dans ce cas ne
contiendra que le tableau de bord (les sections Statistiques/Sécurité/
Performance/Fichiers et les graphiques resteront vides), avec un
avertissement explicite affiché pour ne pas laisser croire à tort que le
PDF est complet.

Activer avec `--pdf` ou `output.pdf => true` dans `config.php`. Si aucun
outil n'est trouvé, l'analyse continue normalement (le HTML reste généré)
avec un message expliquant comment installer Chrome/Chromium.

## Organigramme du projet

La section « Organigramme » du rapport cartographie les classes PHP du
projet, groupées par dossier, reliées par héritage (`extends`),
implémentation (`implements`) et import local (`use`). Chaque classe est
colorée selon son niveau de risque (basé sur les problèmes déjà détectés
par les autres analyseurs), et les **points d'entrée** — contrôleurs
HTTP, commandes CLI, jobs de file d'attente, middlewares, form requests —
sont marqués d'un badge ⚡, puisque ce sont les endroits où des données
externes pénètrent dans l'application, donc prioritaires pour une
relecture de sécurité.

Pensée pour une **lecture posée** plutôt qu'un coup d'œil : cliquer une
classe isole ses connexions directes (le reste s'estompe), un filtre
permet d'afficher ou masquer les imports simples (souvent nombreux et
moins structurants que l'héritage), et un zoom permet de naviguer un
projet avec beaucoup de classes.

Limite assumée : extraction par regex (pas un vrai parseur PHP), comme le
reste de l'outil. Une classe déclarée sur plusieurs lignes de façon
inhabituelle, ou un `extends`/`implements` vers une classe ambiguë (même
nom court dans plusieurs namespaces du projet), peut ne pas être reliée
dans le graphe — dans le doute, l'outil ne trace pas de lien plutôt que
d'en tracer un faux.

## Historique du score (heatmap calendaire)

Le tableau de bord affiche, sous la comparaison avec la dernière analyse,
un historique visuel façon « contribution graph » GitHub : une case par
jour où une analyse a eu lieu, colorée selon le score obtenu ce jour-là
(vert = excellent, rouge = à surveiller), les jours sans analyse restant
vides plutôt que d'être interpolés à tort.

Si plusieurs analyses ont lieu le même jour, seule la **dernière** compte
pour la couleur de la case (l'état le plus à jour de la journée) ; le
survol de la souris indique le score exact, le nombre d'alertes, et le
nombre d'analyses effectuées ce jour-là.

**Limite assumée** : basée sur `reports/history/`, dont la profondeur est
limitée par `history.keep_last` dans `config.php` (30 exécutions par
défaut, pas 30 jours calendaires — si vous analysez plusieurs fois par
jour, l'historique visible en calendrier sera donc plus court). Augmentez
cette valeur si vous voulez un historique plus long. Nécessite au moins
2 jours d'analyse distincts pour s'afficher ; sinon, un message l'indique
simplement au lieu d'un graphique vide ou trompeur.

## Détection de code mort (inter-fichiers)

La section « Code mort » du rapport liste les **classes jamais
référencées ailleurs** dans le projet (aucun `new`, aucun appel statique,
aucun `extends`/`implements`/`use` d'une autre classe locale) et les
**méthodes publiques dont le nom n'apparaît jamais comme appel** nulle
part (`->methode(`, `::methode(`, ou callable de route Laravel du type
`[Controleur::class, 'methode']` / `'Controleur@methode'`).

Détection volontairement prudente, pour limiter les faux positifs plutôt
que de tout signaler :
- Les **points d'entrée** (contrôleurs HTTP, commandes CLI, jobs de file,
  listeners, middlewares, form requests) sont exclus entièrement — classe
  ET méthodes — car invoqués par la configuration du framework (routes,
  scheduler, conteneur de services), pas par un appel de code littéral
  facilement détectable par regex.
- Les **classes de test** (PHPUnit/Pest) sont exclues entièrement : le
  framework de test invoque les méthodes par réflexion sur leur nom,
  jamais par un appel littéral dans le code.
- Les **méthodes magiques** (`__construct`, `__toString`...) et les
  **hooks connus du framework** (`boot`, `handle`, `rules`...) sont
  exclus.
- Les **interfaces et traits** ne sont jamais signalés comme classe
  morte (une interface implémentée est déjà comptée comme référencée).

**Limite assumée** : comme le reste de l'outil, c'est une heuristique par
nom (regex), pas un vrai suivi d'exécution ni un système de types. Une
classe instanciée uniquement via un conteneur d'injection de dépendances
par son interface, ou une méthode appelée uniquement par un mécanisme
dynamique (`call_user_func`, `$object->{$variable}()`), peut échapper à
la détection dans un sens (faux négatif, plus sûr) ou être signalée à
tort si le nom n'apparaît vraiment nulle part ailleurs de façon
détectable (faux positif, plus rare avec les exclusions ci-dessus). Un
résultat ici est une piste à vérifier manuellement, jamais une
suppression automatique.

## Vérification des dépendances (CVE réelles)

`VulnerabilityScanner` interroge [OSV.dev](https://osv.dev) avec les
versions exactes lues dans `composer.lock`/`package-lock.json` (ou une
version approximative si le lockfile est absent, clairement signalée
comme telle), met en cache les détails 24h, et se dégrade sans erreur si
le réseau est indisponible. Réglages dans `config.php`
(`dependencies.osv_*`). Nécessite l'extension `curl` et un accès réseau
sortant vers `api.osv.dev`.

## Analyse de flux de données (taint analysis)

En complément des motifs à risque détectés par `SecurityAnalyzer` (qui
signale la simple *présence* d'un point sensible, sans savoir si son
argument est réellement dangereux), `TaintAnalyzer` trace précisément le
trajet d'une donnée : d'une **source** externe non fiable (`$_GET`,
`$_POST`, `$request->input()`...) jusqu'à un **point sensible** (requête
SQL brute, exécution système, `eval()`, inclusion de fichier,
`unserialize()`...), en tenant compte des fonctions qui neutralisent le
risque en chemin (`intval()`, `htmlspecialchars()`, `escapeshellarg()`,
cast `(int)`...).

Un problème remonté par `TaintAnalyzer` indique explicitement la variable
concernée et la ligne d'où provient la donnée — plus actionnable qu'une
simple alerte "ce type d'appel est risqué".

**Portée assumée** : suivi limité au corps d'**une seule fonction ou
méthode** à la fois (pas de suivi inter-procédural : si une fonction
transmet une donnée tainted à une autre fonction, le lien n'est pas
suivi), et analyse **séquentielle sans conscience des branches**
(if/else, boucles) — une donnée assainie dans une branche peut être
considérée à tort comme toujours assainie ensuite. Une variable non
signalée n'est donc pas forcément sûre (faux négatif possible) : c'est
le compromis assumé pour éviter de saturer le rapport de faux positifs.

## Limites connues (honnêtes) et pistes d'évolution

Cette version est fonctionnelle et testée de bout en bout, mais certaines
fonctionnalités ambitieuses demandent plus qu'une session de travail pour
être faites *sérieusement* plutôt que factices. Plutôt que de simuler ces
fonctionnalités, elles sont clairement identifiées ici :

- ~~**Détection de code mort inter-fichiers**~~ : implémentée (voir
  section « Détection de code mort » ci-dessous).
- ~~**Workers parallèles réels**~~ : implémentée (voir section « Analyse
  parallèle » ci-dessous).
- ~~**Graphe de dépendances entre classes**~~ : implémenté (voir section
  « Organigramme du projet » ci-dessus).
- ~~**Heatmap calendaire de l'évolution du risque**~~ : implémentée (voir
  section « Historique du score » ci-dessous).
- ~~**Analyse de flux de données (taint analysis)**~~ : implémentée
  (`TaintAnalyzer`, voir section dédiée ci-dessous), mais avec une portée
  volontairement limitée à l'intérieur d'une seule fonction/méthode (pas
  de suivi inter-procédural ni conscience des branches if/else). Pour une
  couverture exhaustive, complétez avec PHPStan + un plugin sécurité, ou
  Psalm en mode taint-analysis.
- ~~**Plugin éditeur**~~ : une extension VS Code simple (lecture du
  rapport, voir « Extension VS Code » ci-dessus) est implémentée.
  Restent en attente : le déclenchement automatique de l'analyse depuis
  l'éditeur (elle reste pilotée depuis le terminal pour l'instant), et
  un équivalent pour PhpStorm.

Aucune de ces limites n'empêche l'usage quotidien de l'outil : elles sont
documentées pour que vous sachiez précisément ce qui est solide
aujourd'hui et ce qui reste à construire.

## Extension VS Code

Un lecteur simple du rapport (`editor-extensions/vscode/`) affiche les
problèmes déjà détectés par Clarté directement dans l'éditeur — lignes
soulignées, panneau *Problems*, score dans la barre de statut — sans
lancer l'analyse elle-même (qui reste pilotée depuis le terminal comme
d'habitude). Voir le
[README dédié](editor-extensions/vscode/README.md) pour l'installation.

## Extensibilité

Pour ajouter un nouvel analyseur (ex: analyse Vue.js dédiée) :

1. Créez `src/VueAnalyzer.php` avec une méthode `analyze(string $content, string $lang): array`.
2. Instanciez-la dans `AnalysisEngine::__construct()`.
3. Appelez-la dans `AnalysisEngine::analyzeFile()` et ajoutez le résultat
   au tableau `issues`/`scores`.
4. Ajoutez une entrée dans le menu latéral et une fonction `renderCategory()`
   côté JS dans `HtmlReport::js()`.

## Qui développe Clarté ?

Clarté est développé par Jean-Pierre Bossé, retraité de la fonction publique
territoriale, basé à Soullans (Vendée). Ce projet est né d'une connaissance
de terrain, des besoins des petites structures, et d'une conviction : il
faut aider les développeurs avec un outil libre et bien conçu, qui peut
répondre aux besoins de 90 % des organisations publiques ou privées de
petite taille.

## Licence

MIT — à adapter selon vos besoins internes.
