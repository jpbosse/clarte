# Clarté

> *Clarté : qualité de ce qui est facilement intelligible, précis et net.*

Clarté éclaire votre code : l'outil décompose un projet PHP / Laravel /
Blade / JavaScript en cinq dimensions (sécurité, qualité, architecture,
performance, documentation), explique chaque note de manière vérifiable,
et restitue le tout dans un rapport interactif limpide.


Outil professionnel d'analyse statique et assistee par IA pour projets
PHP / Laravel / Blade / JavaScript / Vue. Genere un rapport HTML unique,
autonome et interactif (dashboard, recherche, filtres, graphiques,
theme clair/sombre), comparable dans l'esprit a SonarQube ou CodeClimate,
tout en restant leger et sans dependance lourde.

## Installation

```bash
git clone <votre-repo> Clarte
cd Clarte
```

Aucune dependance externe (Composer) n'est strictement necessaire pour la
v1 : un autoloader PSR-4 minimal suffit. Si vous preferez utiliser Composer
(recommande pour la maintenabilite a long terme) :

```bash
composer install
```

Sinon, copiez le fichier `vendor/autoload.php` fourni dans le paquet
(autoload manuel, sans dependance tierce).

**Prerequis** : PHP >= 8.1, extensions `json`, `mbstring` (recommandee,
un mode degrade sans elle est prevu), `curl` (necessaire uniquement si
vous activez l'analyse IA).

## Utilisation

```bash
php clarte.php /chemin/vers/votre/projet
```

Options :

| Option | Effet |
|---|---|
| `--ai` | Active l'analyse assistee par IA (voir section IA ci-dessous) |
| `--no-cache` | Ignore le cache et reanalyse tous les fichiers |
| `--ci` | Mode CI/CD : code de sortie 2 si des problemes critiques sont detectes |
| `--diff` | Analyse uniquement les fichiers modifies (indexes, non indexes, nouveaux) par rapport a HEAD |
| `--diff=<ref>` | Analyse uniquement les fichiers qui different entre `<ref>` (ex: `origin/main`) et HEAD — ideal en CI sur une PR |
| `--pdf` | Genere aussi `reports/rapport.pdf` (Chrome/Chromium recommande ; wkhtmltopdf en repli produit un PDF incomplet, voir limites ci-dessous) |
| `--config=chemin.php` | Utilise un fichier de configuration alternatif |

Le rapport est genere dans `reports/rapport.html` (+ `.json`, `.md`, `.csv`).
Ouvrez simplement `rapport.html` dans un navigateur : aucune connexion
Internet n'est requise, tout est embarque (CSS + JS).

### Mode `--diff` : analyse incrementale

Plutot que de re-analyser tout le projet, `--diff` cible uniquement ce qui
a change — utile avant un commit, ou en CI pour ne juger que les fichiers
d'une PR :

```bash
# Avant un commit : que vais-je committer ?
php clarte.php /chemin/projet --diff

# En CI, sur une pull request vers main
php clarte.php /chemin/projet --diff=origin/main --ci
```

Le projet cible doit etre un depot Git ; sinon (ou si la reference donnee
n'existe pas), Clarté se replie automatiquement sur une analyse complete
en le signalant clairement, plutot que d'echouer silencieusement. Le score
d'une analyse `--diff` porte uniquement sur le sous-ensemble analyse (une
bannière l'indique dans le rapport) et n'est pas enregistre dans
l'historique, pour ne pas fausser les comparaisons avec les analyses
completes.

## Activer l'analyse IA

L'IA est **desactivee par defaut**. Pour l'activer :

1. Passez `ai.enabled` a `true` dans `config.php`.
2. Definissez la variable d'environnement contenant votre token
   (`GITHUB_MODELS_TOKEN` par defaut, configurable via `ai.token_env_var`).
   Le token n'est jamais stocke en dur dans le code.
3. Lancez avec l'option `--ai` :

```bash
GITHUB_MODELS_TOKEN=xxxxx php clarte.php /chemin/projet --ai
```

L'endpoint par defaut cible GitHub Models, mais `config.php` accepte tout
endpoint compatible "chat completions" (OpenAI-like). Le client gere
automatiquement le delai entre appels (`ai.delay_ms`), les tentatives avec
backoff exponentiel en cas de code 429/5xx, et le timeout.

## Architecture du projet

```
Clarte/
├── clarte.php               # Point d'entree CLI
├── config.php                # Configuration (extensions, seuils, IA, exports...)
├── composer.json
├── cache/                    # Cache incremental (hash de contenu par fichier)
├── reports/
│   ├── rapport.html
│   ├── rapport.json
│   ├── rapport.md
│   ├── rapport.csv
│   ├── logs.txt
│   └── history/               # Snapshots pour comparaison entre analyses
└── src/
    ├── Logger.php              # Journalisation horodatee
    ├── Cache.php                # Cache / reprise apres interruption
    ├── ProgressBar.php          # Barre de progression CLI avec ETA
    ├── Scanner.php              # Parcours du projet, exclusions
    ├── TokenEstimator.php       # Estimation du nombre de tokens
    ├── Truncator.php            # Troncature intelligente des gros fichiers
    ├── SecurityAnalyzer.php     # Audit securite (regex ciblees)
    ├── PerformanceAnalyzer.php  # N+1, boucles imbriquees, requetes en boucle
    ├── ArchitectureAnalyzer.php # God Object, classes/methodes trop longues
    ├── QualityAnalyzer.php      # TODO/FIXME, duplication, code mort local
    ├── DocumentationAnalyzer.php# Couverture PHPDoc
    ├── DependencyAnalyzer.php   # Composer / npm (contraintes de version)
    ├── Statistics.php           # Agregation des statistiques globales
    ├── PromptBuilder.php        # Construction du prompt IA par fichier
    ├── GithubModel.php          # Client API IA (rate limit + retry)
    ├── SummaryBuilder.php       # Score global, priorites, checklist prod
    ├── ScoreExplainer.php       # Transparence : methodologie, calcul reel, limites
    ├── History.php              # Historique et comparaison entre analyses
    ├── GraphBuilder.php         # Donnees pour les graphiques du rapport
    ├── HtmlReport.php           # Generation du rapport HTML autonome
    ├── Exporter.php             # Export JSON / Markdown / CSV
    └── AnalysisEngine.php       # Orchestrateur du pipeline complet
```

Chaque classe a une responsabilite unique (SRP). L'orchestrateur
`AnalysisEngine` ne contient aucune logique d'analyse : il delegue a
chaque classe specialisee, ce qui rend le projet testable et extensible
(ajouter un nouvel analyseur = ajouter une classe + un appel dans
`AnalysisEngine::analyzeFile()`).


## Regles personnalisables par projet

Pour utiliser Clarté sur plusieurs projets aux conventions differentes
sans toucher a `config.php` (partage entre tous les projets), placez un
fichier `.clarte-rules.php` **a la racine du projet analyse** (pas dans
le dossier de Clarté) :

```php
<?php
return [
    // Desactive completement certaines regles pour ce projet
    'disabled_rules' => ['todo_fixme', 'poor_naming'],

    // Reclasse la severite d'une regle (n'affecte que ce projet)
    'severity_overrides' => ['missing_phpdoc' => 'info'],

    // Seuils d'architecture propres a ce projet (fusionnes avec ceux de config.php)
    'thresholds' => ['method_max_lines' => 80],

    // Exclusions supplementaires, en plus de celles de config.php
    'excluded_dirs'  => ['legacy'],
    'excluded_files' => ['*.generated.php'],
];
```

Toutes les cles sont optionnelles. Les identifiants de regle valides pour
`disabled_rules`/`severity_overrides` correspondent au champ `rule` de
chaque probleme dans `reports/rapport.json` (ex: `sql_concat`, `eval`,
`hardcoded_secret`, `query_in_loop`, `nested_loops`, `class_too_long`,
`method_too_long`, `god_class`, `too_many_params`, `todo_fixme`,
`poor_naming`, `duplicated_line`, `unused_private_method`,
`missing_phpdoc`, et les autres regles de `SecurityAnalyzer`).

Si le fichier est absent, mal forme (ne retourne pas un tableau), ou leve
une erreur PHP, Clarté l'ignore proprement et revient aux reglages par
defaut, avec un avertissement explicite dans les logs — jamais d'echec
silencieux ni de crash.

**Note de securite** : ce fichier est execute comme du code PHP normal
(au meme titre que `composer.json` ou `.php-cs-fixer.php`). Ne pointez
Clarté qu'sur des projets dont vous maitrisez le contenu.

**Limite connue** : comme pour les seuils de `config.php`, une
modification de `.clarte-rules.php` n'est prise en compte que pour les
fichiers dont le contenu a change depuis la derniere analyse (le cache
est indexe par hash de contenu, pas par configuration). Utilisez
`--no-cache` juste apres avoir modifie `.clarte-rules.php` pour forcer
une reanalyse complete.

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

La section « Documents (.md) » du rapport liste tous les fichiers Markdown
du projet (README, CHANGELOG, docs/...) et les affiche rendus comme sur
GitHub : titres, listes, tableaux, blocs de code, citations, liens. Le
README s'ouvre par défaut. Chaque fichier .md dispose aussi d'un onglet
« Aperçu » dans la liste des fichiers. Le rendu échappe intégralement le
HTML source : un document piégé ne peut pas exécuter de code dans le rapport.

## Limites connues (honnêtes) et roadmap v2

Cette v1 est fonctionnelle et testee de bout en bout, mais certaines
fonctionnalites ambitieuses de votre cahier des charges initial demandent
plus qu'une session de travail pour etre faites *serieusement* plutot que
factices. Plutot que de simuler ces fonctionnalites, elles sont clairement
identifiees ici :

- ~~**Export PDF**~~ : implemente depuis v1.3. `PdfExporter` s'appuie sur
  un outil externe deja present sur la machine : **Chrome/Chromium en
  priorite** (moteur JS moderne complet, necessaire car le rapport est une
  petite application JS), avec repli sur `wkhtmltopdf` si Chrome est
  absent. Attention : `wkhtmltopdf` embarque un moteur JavaScript trop
  ancien pour executer les scripts du rapport (ES6+) — le PDF produit
  dans ce cas ne contiendra que le tableau de bord (les sections
  Statistiques/Securite/Performance/Fichiers et les graphiques resteront
  vides), avec un avertissement explicite affiche pour ne pas laisser
  croire a tort que le PDF est complet. Activer avec `--pdf` ou
  `output.pdf => true` dans `config.php`. Si aucun outil n'est trouve,
  l'analyse continue normalement (le HTML reste genere) avec un message
  expliquant comment installer Chrome/Chromium.
- ~~**Vulnerabilites de dependances (CVE reelles)**~~ : implemente depuis
  v1.1. `VulnerabilityScanner` interroge [OSV.dev](https://osv.dev) avec
  les versions exactes lues dans `composer.lock`/`package-lock.json`
  (ou une version approximative si le lockfile est absent), met en cache
  les details 24h, et se degrade sans erreur si le reseau est indisponible.
  Reglages dans `config.php` (`dependencies.osv_*`). Necessite l'extension
  `curl` et un acces reseau sortant vers `api.osv.dev`.
- **Workers paralleles reels** : le mode CI actuel est sequentiel. Un vrai
  pool de workers (via `pcntl_fork` ou plusieurs processus PHP CLI en
  parallele avec repartition de la file) est prevu en v2 pour les tres
  gros projets.
- **Heatmap de risque et graphe de dependances interactif avance** :
  les donnees necessaires existent deja (issues par dossier, imports),
  mais le rendu visuel avance (force-directed graph, heatmap calendaire)
  n'est pas encore implemente dans `HtmlReport`.
- **Analyse de flux de donnees (taint analysis)** : `SecurityAnalyzer`
  detecte des motifs a risque par expressions regulieres, pas un vrai
  suivi de flux source->sink. Pour une couverture exhaustive, completez
  avec PHPStan + un plugin securite, ou Psalm en mode taint-analysis.

Aucune de ces limites n'empeche l'usage quotidien de l'outil : elles
sont documentees pour que vous sachiez precisement ce qui est solide
aujourd'hui et ce qui reste a construire.

## Extensibilite

Pour ajouter un nouvel analyseur (ex: analyse Vue.js dediee) :

1. Creez `src/VueAnalyzer.php` avec une methode `analyze(string $content, string $lang): array`.
2. Instanciez-la dans `AnalysisEngine::__construct()`.
3. Appelez-la dans `AnalysisEngine::analyzeFile()` et ajoutez le resultat
   au tableau `issues`/`scores`.
4. Ajoutez une entree dans le menu lateral et une fonction `renderCategory()`
   cote JS dans `HtmlReport::js()`.

## Qui developpe Clarté ?

Clarté est developpe par Jean-Pierre Bossé, retraite de la fonction publique
territoriale, base a Soullans (Vendee). Ce projet est ne d'une connaissance
de terrain, des besoins des petites structures, et d'une conviction : il
faut aider les developpeurs avec un outil libre et bien concu, qui peut
repondre aux besoins de 90 % des organisations publiques ou privees de
petite taille.

## Licence

MIT — a adapter selon vos besoins internes.
