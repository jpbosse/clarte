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
| `--config=chemin.php` | Utilise un fichier de configuration alternatif |

Le rapport est genere dans `reports/rapport.html` (+ `.json`, `.md`, `.csv`).
Ouvrez simplement `rapport.html` dans un navigateur : aucune connexion
Internet n'est requise, tout est embarque (CSS + JS).

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

- **Export PDF** : non inclus en v1. Recommandation : generer le PDF a
  partir du HTML via un outil externe (`wkhtmltopdf`, Chrome headless
  `--print-to-pdf`, ou une lib comme Dompdf) plutot que de reimplementer un
  moteur de mise en page PDF maison.
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

## Licence

MIT — a adapter selon vos besoins internes.
