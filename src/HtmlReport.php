<?php

namespace Clarte;

/**
 * Genere un rapport HTML unique, autonome (CSS + JS embarqués, aucune
 * dépendance externe / CDN), avec dashboard, navigation latérale,
 * recherche instantanée, filtres, accordeons par fichier, graphiques
 * en canvas natif et thème clair/sombre.
 */
class HtmlReport
{
    public function render(
        array $statistics,
        array $summary,
        array $fileResults,
        array $dependencyResult,
        ?array $comparison,
        array $graphs,
        string $projectName
    ): string {
        $filesJson = $this->jsonForScript($this->prepareFilesForJs($fileResults));
        $graphsJson = $this->jsonForScript($graphs);
        $statsJson = $this->jsonForScript($statistics);
        $summaryJson = $this->jsonForScript($summary);
        $comparisonJson = $this->jsonForScript($comparison);
        $generatedAt = date('d/m/Y H:i:s');

        $css = $this->css();
        $js = $this->js();

        $dashboardCards = $this->renderDashboardCards($summary, $statistics);
        $checklistHtml = $this->renderChecklist($summary['production_checklist']);
        $prioritiesHtml = $this->renderPriorities($summary['top_priorities']);
        $hotFilesHtml = $this->renderHotFiles($summary['hot_files']);
        $comparisonHtml = $comparison ? $this->renderComparison($comparison) : '';
        $partialBannerHtml = ($summary['partial_analysis']['active'] ?? false) ? $this->renderPartialBanner($summary['partial_analysis']) : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="fr" data-thème="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clarté — Rapport d'audit — {$projectName}</title>
<style>{$css}</style>
</head>
<body>
<div class="app">

  <aside class="sidebar">
    <div class="sidebar-brand">
      <span class="brand-icon">◆</span>
      <span class="brand-name">Clarté</span>
    </div>
    <nav class="sidebar-nav">
      <a href="#dashboard" class="nav-link active" data-section="dashboard">📊 Dashboard</a>
      <a href="#statistiques" class="nav-link" data-section="statistiques">📁 Statistiques</a>
      <a href="#architecture" class="nav-link" data-section="architecture">🏗 Architecture</a>
      <a href="#organigramme" class="nav-link" data-section="organigramme">🗺 Organigramme</a>
      <a href="#sécurité" class="nav-link" data-section="sécurité">🔒 Securite</a>
      <a href="#performance" class="nav-link" data-section="performance">⚡ Performance</a>
      <a href="#qualité" class="nav-link" data-section="qualité">🧹 Qualité</a>
      <a href="#documentation" class="nav-link" data-section="documentation">📚 Documentation (code)</a>
      <a href="#dépendances" class="nav-link" data-section="dépendances">📦 Dépendances</a>
      <a href="#documents" class="nav-link" data-section="documents">📖 Documents (README...)</a>
      <a href="#fichiers" class="nav-link" data-section="fichiers">🗂 Tous les fichiers</a>
      <a href="#synthèse" class="nav-link" data-section="synthèse">🎯 Synthèse &amp; priorités</a>
    </nav>
    <div class="sidebar-footer">
      <button id="thème-toggle" class="thème-toggle">🌙 / ☀️</button>
      <div class="generated-at">Genere le {$generatedAt}</div>
    </div>
  </aside>

  <main class="content">

    <header class="topbar">
      <div class="topbar-title">Audit — {$projectName}</div>
      <div class="topbar-search">
        <input type="text" id="global-search" placeholder="Rechercher un fichier, une fonction, un mot-clé...">
      </div>
    </header>

    <section id="dashboard" class="panel active">
      <h1>Tableau de bord</h1>
      {$partialBannerHtml}
      <p class="narrative">{$summary['narrative']}</p>
      <div class="cards-grid">
        {$dashboardCards}
      </div>
      <div class="charts-grid">
        <div class="chart-card"><h3>Repartition des langages</h3><canvas id="chart-languages" width="360" height="240"></canvas></div>
        <div class="chart-card"><h3>Repartition des alertes par sévérité</h3><canvas id="chart-severity" width="360" height="240"></canvas></div>
        <div class="chart-card"><h3>Scores moyens par catégorie</h3><canvas id="chart-scores" width="360" height="240"></canvas></div>
        <div class="chart-card"><h3>Taille des fichiers</h3><canvas id="chart-sizes" width="360" height="240"></canvas></div>
      </div>
      {$comparisonHtml}
    </section>

    <section id="statistiques" class="panel">
      <h1>Statistiques du projet</h1>
      <div id="stats-content"></div>
    </section>

    <section id="architecture" class="panel">
      <h1>Architecture</h1>
      <div id="architecture-content" class="issues-list"></div>
    </section>

    <section id="organigramme" class="panel">
      <h1>Organigramme du projet</h1>
      <p class="narrative">Carte des classes du projet, groupées par dossier, reliées par héritage (<code>extends</code>), implémentation (<code>implements</code>) et import (<code>use</code>). La couleur indique le niveau de risque de chaque classe (base sur les problèmes déjà détectés dans les autres sections). Pensé pour une lecture posée plutôt qu'un coup d'œil : cliquez une classe pour isoler ses connexions directes.</p>
      <div id="organigramme-content"></div>
    </section>

    <section id="sécurité" class="panel">
      <h1>Audit sécurité</h1>
      <div id="sécurité-content" class="issues-list"></div>
    </section>

    <section id="performance" class="panel">
      <h1>Audit performance</h1>
      <div id="performance-content" class="issues-list"></div>
    </section>

    <section id="qualité" class="panel">
      <h1>Qualité du code</h1>
      <div id="qualité-content" class="issues-list"></div>
    </section>

    <section id="documentation" class="panel">
      <h1>Documentation</h1>
      <p class="narrative">Cette section évalue si <strong>votre code lui-même</strong> est bien commenté (ex: chaque méthode a-t-elle un bloc PHPDoc expliquant son rôle). A ne pas confondre avec « Documents (.md) » ci-dessous, qui affiche le contenu de vos fichiers README/CHANGELOG.</p>
      <div id="documentation-content" class="issues-list"></div>
    </section>

    <section id="dépendances" class="panel">
      <h1>Dépendances</h1>
      <div id="dépendances-content"></div>
    </section>

    <section id="documents" class="panel">
      <h1>Documents du projet</h1>
      <p class="narrative">Les fichiers Markdown (README, documentation, changelog...) sont rendus ici comme sur GitHub. A ne pas confondre avec la section « Documentation » ci-dessus, qui note la qualité des commentaires dans le code lui-même.</p>
      <div class="doc-layout">
        <div class="doc-sidebar" id="doc-list"></div>
        <div class="doc-viewer markdown-body" id="doc-viewer">
          <p style="color:var(--text-muted)">Selectionnez un document dans la liste.</p>
        </div>
      </div>
    </section>

    <section id="fichiers" class="panel">
      <h1>Tous les fichiers</h1>
      <div class="filters" id="file-filters">
        <button class="filter-btn active" data-filter="all">Tous</button>
        <button class="filter-btn" data-filter="PHP">PHP</button>
        <button class="filter-btn" data-filter="Blade">Blade</button>
        <button class="filter-btn" data-filter="JavaScript">JS</button>
        <button class="filter-btn" data-filter="Vue">Vue</button>
        <button class="filter-btn" data-filter="CSS">CSS</button>
        <button class="filter-btn" data-filter="HTML">HTML</button>
        <button class="filter-btn" data-filter="critical-only">🔴 Erreurs critiques uniquement</button>
      </div>
      <div id="files-accordion"></div>
    </section>

    <section id="synthèse" class="panel">
      <h1>Synthèse &amp; plan d'action priorise</h1>
      <h2>Checklist avant mise en production</h2>
      <div class="checklist">{$checklistHtml}</div>
      <h2>Top priorités</h2>
      <div class="priorities-list">{$prioritiesHtml}</div>
      <h2>Fichiers "chauds" (concentration de dette technique)</h2>
      <div class="hot-files-list">{$hotFilesHtml}</div>
    </section>

  </main>
</div>

<div class="modal-overlay" id="explain-modal" role="dialog" aria-modal="true" aria-labelledby="explain-title">
  <div class="modal-box">
    <div class="modal-header">
      <h2 id="explain-title">Explication</h2>
      <button class="modal-close" id="explain-close" aria-label="Fermer">&times;</button>
    </div>
    <div class="modal-body" id="explain-body"></div>
  </div>
</div>

<script>
const REPORT_DATA = {
  files: {$filesJson},
  graphs: {$graphsJson},
  stats: {$statsJson},
  summary: {$summaryJson},
  dependencies: {$this->jsonForScript($dependencyResult)},
  comparison: {$comparisonJson}
};
{$js}
</script>
</body>
</html>
HTML;
    }

    /**
     * Encode en JSON de manière sûre à insérer dans un bloc <script>.
     *
     * Point critique : le code source analysé (JS, HTML, Blade...) peut
     * legitimement contenir la sous-chaîne "</script>" (ex: un fichier JS
     * qui manipule du HTML, un template Blade avec un tag script). Sans
     * précaution, cette sous-chaîne ferme prématurément la balise <script>
     * du rapport et casse tout le JavaScript qui suit (navigation, graphiques,
     * accordeons...). On NE PAS utiliser JSON_UNESCAPED_SLASHES (les "/" sont
     * donc échappés en "\/", ce qui neutralise "</script>" -> "<\/script>"),
     * et on ajoute une seconde barrière explicite au cas où.
     */
    private function jsonForScript(?array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Le contenu d'un fichier peut occasionnellement produire un
            // encodage invalide malgré la normalisation UTF-8 amont : on
            // dégradé proprement plutôt que de casser tout le rapport.
            return '{}';
        }
        // Filet de sécurité supplémentaire, insensible à la casse et aux espaces.
        $json = preg_replace('/<\/(script)/i', '<\\/$1', $json);
        $json = str_replace('<!--', '<\\!--', $json);
        return $json;
    }

    private function prepareFilesForJs(array $fileResults): array
    {
        $out = [];
        foreach ($fileResults as $relative => $result) {
            $out[] = [
                'path'   => $relative,
                'lang'   => $result['lang'],
                'lines'  => $result['lines'],
                'size'   => $result['size'],
                'scores' => $result['scores'],
                'issues' => $result['issues'],
                'ai'     => $result['ai'] ?? null,
                'markdown' => $result['markdown'] ?? null,
            ];
        }
        return $out;
    }

    private function scoreBadgeClass(float $score): string
    {
        if ($score >= 8) return 'badge-excellent';
        if ($score >= 6) return 'badge-warning';
        return 'badge-critical';
    }

    private function renderDashboardCards(array $summary, array $statistics): string
    {
        // Le 4e élément est la clé d'explication : si elle est présente, une
        // icône (i) cliquable apparaît et ouvre le détail de la notation.
        $cards = [
            ['Score global', $summary['global_score'] . '/100', $this->scoreBadgeClass($summary['global_score'] / 10), 'global'],
            ['Score qualité', $summary['scores']['quality'] . '/10', $this->scoreBadgeClass($summary['scores']['quality']), 'quality'],
            ['Score sécurité', $summary['scores']['security'] . '/10', $this->scoreBadgeClass($summary['scores']['security']), 'security'],
            ['Score performance', $summary['scores']['performance'] . '/10', $this->scoreBadgeClass($summary['scores']['performance']), 'performance'],
            ['Score architecture', $summary['scores']['architecture'] . '/10', $this->scoreBadgeClass($summary['scores']['architecture']), 'architecture'],
            ['Score documentation', $summary['scores']['documentation'] . '/10', $this->scoreBadgeClass($summary['scores']['documentation']), 'documentation'],
            ['Fichiers analyses', (string) $statistics['total_files'], '', null],
            ['Taille du projet', $statistics['total_size_human'], '', null],
            ['Lignes de code', number_format($statistics['total_lines'], 0, ',', ' '), '', null],
            ['Alertes totales', (string) $summary['total_issues'], '', 'severity'],
        ];

        $html = '';
        foreach ($cards as [$label, $value, $badgeClass, $explainKey]) {
            $infoIcon = $explainKey !== null
                ? "<button class=\"kpi-info\" data-explain=\"{$explainKey}\" title=\"Comment cette note est-elle calculée ?\" aria-label=\"Explication de la note {$label}\">i</button>"
                : '';
            $html .= "<div class=\"kpi-card {$badgeClass}\">"
                . "<div class=\"kpi-label\">{$label}{$infoIcon}</div>"
                . "<div class=\"kpi-value\">{$value}</div>"
                . "</div>";
        }
        return $html;
    }

    private function renderChecklist(array $checklist): string
    {
        $html = '';
        foreach ($checklist as $item) {
            $icon = $item['ok'] ? '✅' : '❌';
            $class = $item['ok'] ? 'check-ok' : 'check-fail';
            $html .= "<div class=\"checklist-item {$class}\"><span>{$icon}</span> {$item['label']}</div>";
        }
        return $html;
    }

    private function renderPriorities(array $priorities): string
    {
        if (empty($priorities)) {
            return '<p>Aucun problème majeur détecté. 🎉</p>';
        }
        $html = '';
        $severityBadge = ['critical' => '🔴 Critique', 'important' => '🟠 Important', 'moderate' => '🟡 Moyen', 'info' => '🔵 Information'];
        foreach ($priorities as $issue) {
            $badge = $severityBadge[$issue['severity']] ?? $issue['severity'];
            $file = htmlspecialchars($issue['file'] ?? '');
            $message = htmlspecialchars($issue['message'] ?? '');
            $line = $issue['line'] ?? '';
            $html .= "<div class=\"priority-item severity-{$issue['severity']}\"><span class=\"priority-badge\">{$badge}</span> <span class=\"priority-file\">{$file}:{$line}</span> — {$message}</div>";
        }
        return $html;
    }

    private function renderHotFiles(array $hotFiles): string
    {
        if (empty($hotFiles)) {
            return '<p>Aucun fichier ne concentre de problème particulier.</p>';
        }
        $html = '<table class="hot-files-table"><thead><tr><th>Fichier</th><th>Nombre de problèmes</th></tr></thead><tbody>';
        foreach ($hotFiles as $entry) {
            $file = htmlspecialchars($entry['file']);
            $html .= "<tr><td>{$file}</td><td>{$entry['issues']}</td></tr>";
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private function renderPartialBanner(array $partial): string
    {
        $base = $partial['base'] ?? null;
        $baseLabel = $base ? "par rapport a <code>{$this->esc($base)}</code>" : "non commités (working tree)";
        $count = $partial['files_analyzed'] ?? 0;

        return <<<HTML
<div class="partial-banner">
  ⚠️ <strong>Analyse partielle (mode --diff)</strong> — seuls les {$count} fichier(s) modifie(s) {$baseLabel} ont été analyses.
  Le score global et les statistiques ci-dessous ne portent donc que sur ce sous-ensemble, pas sur l'ensemble du projet.
  L'historique n'a pas été mis à jour pour cette exécution.
</div>
HTML;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function renderComparison(array $comparison): string
    {
        $scoreDiff = $comparison['score_diff'];
        $arrow = $scoreDiff > 0 ? '📈' : ($scoreDiff < 0 ? '📉' : '➡️');
        $sign = $scoreDiff > 0 ? '+' : '';
        $prevDate = $comparison['previous_date'] ? date('d/m/Y H:i', strtotime($comparison['previous_date'])) : 'N/A';

        return <<<HTML
<div class="comparison-box">
  <h3>Evolution depuis la dernière analyse ({$prevDate})</h3>
  <p>{$arrow} Score global : {$sign}{$scoreDiff} points — Alertes : {$comparison['issues_diff']}</p>
</div>
HTML;
    }

    private function css(): string
    {
        return <<<'CSS'
:root {
  --bg: #0f1117; --bg-secondary: #161923; --bg-card: #1c202c;
  --text: #e4e6eb; --text-muted: #9aa0ac; --border: #2a2f3d;
  --accent: #6366f1; --accent-light: #818cf8;
  --success: #22c55e; --warning: #f59e0b; --critical: #ef4444; --info: #3b82f6;
  --radius: 10px;
  --font: 'Segoe UI', system-ui, -apple-system, sans-serif;
}
html[data-thème="light"] {
  --bg: #f4f5f7; --bg-secondary: #ffffff; --bg-card: #ffffff;
  --text: #1a1d29; --text-muted: #6b7280; --border: #e5e7eb;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: var(--font); background: var(--bg); color: var(--text); transition: background .2s, color .2s; }
.app { display: flex; min-height: 100vh; }

.sidebar { width: 240px; background: var(--bg-secondary); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; }
.sidebar-brand { display: flex; align-items: center; gap: 8px; padding: 20px; font-weight: 700; font-size: 18px; }
.brand-icon { color: var(--accent); }
.sidebar-nav { display: flex; flex-direction: column; padding: 8px; gap: 2px; overflow-y: auto; }
.nav-link { color: var(--text-muted); text-decoration: none; padding: 10px 14px; border-radius: var(--radius); font-size: 14px; transition: .15s; }
.nav-link:hover { background: var(--bg-card); color: var(--text); }
.nav-link.active { background: var(--accent); color: #fff; }
.sidebar-footer { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.thème-toggle { width: 100%; padding: 8px; border-radius: var(--radius); border: 1px solid var(--border); background: var(--bg-card); color: var(--text); cursor: pointer; margin-bottom: 8px; }
.generated-at { font-size: 11px; color: var(--text-muted); }

.content { flex: 1; min-width: 0; }
.topbar { position: sticky; top: 0; z-index: 10; background: var(--bg-secondary); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; gap: 20px; }
.topbar-title { font-weight: 600; }
.topbar-search input { width: 320px; max-width: 40vw; padding: 8px 12px; border-radius: var(--radius); border: 1px solid var(--border); background: var(--bg); color: var(--text); }

.panel { display: none; padding: 24px; animation: fadein .25s; }
.panel.active { display: block; }
@keyframes fadein { from { opacity: 0; transform: translateY(4px);} to { opacity: 1; transform: translateY(0);} }

h1 { margin-top: 0; }
.narrative { color: var(--text-muted); max-width: 800px; line-height: 1.6; }

.cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; margin: 20px 0; }
.kpi-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; }
.kpi-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
.kpi-value { font-size: 24px; font-weight: 700; margin-top: 6px; }
.badge-excellent { border-left: 3px solid var(--success); }
.badge-warning { border-left: 3px solid var(--warning); }
.badge-critical { border-left: 3px solid var(--critical); }

.charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-top: 20px; }
.chart-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; }
.chart-card h3 { margin-top: 0; font-size: 14px; color: var(--text-muted); }
canvas { max-width: 100%; }

.filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.filter-btn { padding: 6px 14px; border-radius: 999px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text-muted); cursor: pointer; font-size: 13px; }
.filter-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

.file-item { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 8px; overflow: hidden; }
.file-header { padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
.file-header:hover { background: var(--bg-secondary); }
.file-name { font-weight: 500; }
.file-meta { font-size: 12px; color: var(--text-muted); }
.file-badges { display: flex; gap: 6px; }
.badge { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.badge.sev-critical { background: rgba(239,68,68,.15); color: var(--critical); }
.badge.sev-important { background: rgba(245,158,11,.15); color: var(--warning); }
.badge.sev-moderate { background: rgba(245,158,11,.1); color: var(--warning); }
.badge.sev-info { background: rgba(59,130,246,.15); color: var(--info); }
.file-body { display: none; padding: 0 16px 16px; border-top: 1px solid var(--border); }
.file-item.open .file-body { display: block; }
.file-tabs { display: flex; gap: 4px; margin: 12px 0; flex-wrap: wrap; }
.file-tab { padding: 4px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; background: var(--bg-secondary); color: var(--text-muted); }
.file-tab.active { background: var(--accent); color: #fff; }
.issue-line { padding: 6px 0; border-bottom: 1px dashed var(--border); font-size: 13px; }
.issue-line:last-child { border-bottom: none; }

.issues-list .issue-line { background: var(--bg-card); padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 6px; }

.checklist-item { padding: 8px 0; }
.check-fail { color: var(--critical); }
.check-ok { color: var(--success); }

.priority-item { padding: 8px 12px; border-radius: 8px; background: var(--bg-card); border: 1px solid var(--border); margin-bottom: 6px; font-size: 14px; }
.priority-badge { font-weight: 600; margin-right: 8px; }
.priority-file { color: var(--text-muted); font-family: monospace; }

.hot-files-table { width: 100%; border-collapse: collapse; }
.hot-files-table th, .hot-files-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--border); }

.comparison-box { margin-top: 20px; padding: 16px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); }
.partial-banner { margin-bottom: 16px; padding: 14px 16px; background: rgba(234, 179, 8, 0.12); border: 1px solid #eab308; border-radius: var(--radius); color: var(--text); }
.partial-banner code { background: rgba(0,0,0,0.15); padding: 1px 6px; border-radius: 4px; }

/* ---- Organigramme ---- */
.og-toolbar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; }
.og-search { flex: 1 1 220px; min-width: 160px; padding: 7px 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text); font-size: 13px; }
.og-toggle { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); cursor: pointer; white-space: nowrap; }
.og-text-btn { background: none; border: 1px solid var(--border); color: var(--text-muted); font-size: 12px; padding: 6px 10px; border-radius: 6px; cursor: pointer; white-space: nowrap; }
.og-text-btn:hover { background: var(--bg-card); color: var(--text); }
.og-zoom { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.og-zoom button { width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text); cursor: pointer; font-size: 16px; line-height: 1; }
.og-zoom button:hover { background: var(--accent); color: white; }
.og-zoom-level, #og-zoom-level { font-size: 13px; color: var(--text-muted); min-width: 40px; text-align: center; }
.og-legend-bar { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 10px; font-size: 12px; color: var(--text-muted); }
.og-legend-item { display: flex; align-items: center; gap: 5px; }
.og-legend-item i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
.og-stats { font-size: 13px; color: var(--text-muted); margin-bottom: 14px; }
.og-scroll-wrapper { overflow: auto; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); max-height: 75vh; padding: 4px; }
.og-canvas { position: relative; padding: 20px; transition: transform 0.15s ease; transform-origin: top left; }
.og-edges { position: absolute; top: 0; left: 0; pointer-events: none; overflow: visible; }
.og-groups { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; position: relative; }
.og-group { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px; width: 230px; flex-shrink: 0; }
.og-group-title { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--text); cursor: pointer; user-select: none; flex-wrap: wrap; }
.og-group-title:hover { color: var(--accent); }
.og-group-caret { color: var(--text-muted); width: 10px; display: inline-block; }
.og-group-name { text-transform: uppercase; letter-spacing: 0.03em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 140px; }
.og-group-count { font-weight: 400; color: var(--text-muted); }
.og-group-badges { display: flex; gap: 4px; flex-wrap: wrap; margin-left: auto; }
.og-group-badge { font-size: 10px; padding: 1px 6px; border-radius: 8px; font-weight: 600; white-space: nowrap; }
.og-group-badge-entry { background: rgba(234, 179, 8, 0.18); color: #eab308; }
.og-group-nodes { flex-direction: column; gap: 6px; margin-top: 8px; }
.og-node { background: var(--bg); border: 1px solid var(--border); border-left: 4px solid #94a3b8; border-radius: 6px; padding: 6px 10px; font-size: 12.5px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; transition: opacity 0.15s ease, transform 0.1s ease; }
.og-node:hover { transform: translateX(2px); border-color: var(--accent); }
.og-node-label { display: flex; align-items: center; gap: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.og-node-kind { font-size: 10px; color: var(--text-muted); font-style: italic; }
.og-badge { font-size: 11px; }
.og-node.og-selected { outline: 2px solid var(--accent); outline-offset: 1px; }
.og-node.og-dim { opacity: 0.25; }
.og-node.og-search-hit { outline: 2px solid #eab308; }
.og-node.og-search-miss { opacity: 0.2; }

/* ---- Impression / export PDF ----
   Le rapport est une SPA à onglets (un seul .panel visible à la fois).
   Un outil de conversion PDF (wkhtmltopdf, Chrome --print-to-pdf) ne
   clique pas dans la navigation : sans ces règles, seule la section
   active au chargement (le Dashboard) apparaitrait dans le PDF. On force
   ici TOUTES les sections à s'afficher, empilées, et on masque les
   éléments uniquement utiles à l'interface interactive (barre latérale,
   recherche, bouton de thème). */
@media print {
  :root { --bg: #ffffff; --bg-secondary: #ffffff; --bg-card: #ffffff; --text: #1a1d29; --text-muted: #6b7280; --border: #e5e7eb; --accent: #4f46e5; }
  .sidebar, .topbar-search, #thème-toggle { display: none !important; }
  .app { display: block; }
  .content { margin: 0; }
  .panel { display: block !important; page-break-before: always; animation: none; }
  .panel:first-of-type { page-break-before: avoid; }
  body { background: #ffffff; color: #1a1d29; }
  .cards-grid, .charts-grid { break-inside: avoid; }
}

table.stat-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
table.stat-table th, table.stat-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--border); font-size: 14px; }

/* ---- Icône (i) sur les cartes KPI ---- */
.kpi-label { display: flex; align-items: center; gap: 6px; justify-content: space-between; }
.kpi-info {
  width: 17px; height: 17px; min-width: 17px; border-radius: 50%;
  border: 1px solid var(--border); background: var(--bg-secondary); color: var(--text-muted);
  font-size: 11px; font-weight: 700; font-style: italic; line-height: 1;
  cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
  transition: .15s; padding: 0; font-family: Georgia, serif;
}
.kpi-info:hover { background: var(--accent); color: #fff; border-color: var(--accent); transform: scale(1.12); }

/* ---- Fenetre modale d'explication ---- */
.modal-overlay {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65);
  z-index: 1000; align-items: center; justify-content: center; padding: 20px;
  backdrop-filter: blur(2px);
}
.modal-overlay.open { display: flex; animation: fadein .18s; }
.modal-box {
  background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px;
  max-width: 720px; width: 100%; max-height: 85vh; display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px; border-bottom: 1px solid var(--border);
}
.modal-header h2 { margin: 0; font-size: 17px; }
.modal-close {
  background: none; border: none; color: var(--text-muted); font-size: 26px;
  cursor: pointer; line-height: 1; padding: 0 4px;
}
.modal-close:hover { color: var(--text); }
.modal-body { padding: 22px; overflow-y: auto; font-size: 14px; line-height: 1.65; }
.modal-body h3 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--accent-light); margin: 20px 0 8px; }
.modal-body h3:first-child { margin-top: 0; }
.modal-body p { margin: 0 0 12px; }
.og-modal-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 4px; }
.og-modal-file { color: var(--text-muted); font-size: 12.5px; }
.og-modal-entry { color: #eab308; font-size: 13px; }
.og-modal-list { list-style: none; padding: 0; margin: 0 0 12px; display: flex; flex-direction: column; gap: 6px; }
.og-modal-list li { font-size: 13px; padding: 6px 8px; background: var(--bg-card); border-radius: 6px; }
.og-modal-relation { color: var(--text-muted); font-style: italic; }
.modal-body .formula {
  background: var(--bg-secondary); border-left: 3px solid var(--accent);
  padding: 10px 14px; border-radius: 0 8px 8px 0; font-family: ui-monospace, monospace;
  font-size: 13px; margin-bottom: 14px;
}
.modal-body .limits {
  background: rgba(245,158,11,.08); border-left: 3px solid var(--warning);
  padding: 10px 14px; border-radius: 0 8px 8px 0; color: var(--text-muted); font-size: 13px;
}
.calc-table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
.calc-table th, .calc-table td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--border); font-size: 13px; }
.calc-table th { color: var(--text-muted); font-weight: 600; }
.calc-table td.num { text-align: right; font-family: ui-monospace, monospace; }

/* ---- Lecteur de documents Markdown ---- */
.doc-layout { display: grid; grid-template-columns: 260px 1fr; gap: 18px; margin-top: 18px; }
.doc-sidebar { border: 1px solid var(--border); border-radius: var(--radius); padding: 8px; background: var(--bg-card); align-self: start; max-height: 70vh; overflow-y: auto; }
.doc-item { padding: 9px 12px; border-radius: 8px; cursor: pointer; font-size: 13px; color: var(--text-muted); word-break: break-all; }
.doc-item:hover { background: var(--bg-secondary); color: var(--text); }
.doc-item.active { background: var(--accent); color: #fff; }
.doc-viewer { border: 1px solid var(--border); border-radius: var(--radius); padding: 28px 32px; background: var(--bg-card); min-height: 300px; overflow-x: auto; }

/* Rendu Markdown façon GitHub */
.markdown-body { line-height: 1.7; font-size: 15px; }
.markdown-body h1, .markdown-body h2 { border-bottom: 1px solid var(--border); padding-bottom: .3em; margin-top: 1.4em; }
.markdown-body h1 { font-size: 1.9em; } .markdown-body h2 { font-size: 1.45em; }
.markdown-body h3 { font-size: 1.2em; margin-top: 1.3em; }
.markdown-body h4 { font-size: 1em; margin-top: 1.2em; }
.markdown-body p { margin: 0 0 1em; }
.markdown-body ul, .markdown-body ol { padding-left: 1.9em; margin: 0 0 1em; }
.markdown-body li { margin: .3em 0; }
.markdown-body code {
  background: var(--bg-secondary); padding: .18em .42em; border-radius: 5px;
  font-family: ui-monospace, 'SF Mono', Consolas, monospace; font-size: .87em;
  border: 1px solid var(--border);
}
.markdown-body pre {
  background: var(--bg-secondary); padding: 15px 18px; border-radius: 8px;
  overflow-x: auto; border: 1px solid var(--border); margin: 0 0 1em;
}
.markdown-body pre code { background: none; border: none; padding: 0; font-size: .86em; line-height: 1.55; }
.markdown-body blockquote {
  border-left: 4px solid var(--border); margin: 0 0 1em; padding: .2em 0 .2em 1em; color: var(--text-muted);
}
.markdown-body table { border-collapse: collapse; margin: 0 0 1em; display: block; overflow-x: auto; }
.markdown-body th, .markdown-body td { border: 1px solid var(--border); padding: 7px 13px; }
.markdown-body th { background: var(--bg-secondary); font-weight: 600; }
.markdown-body tr:nth-child(even) td { background: rgba(127,127,127,.04); }
.markdown-body a { color: var(--accent-light); text-decoration: none; }
.markdown-body a:hover { text-decoration: underline; }
.markdown-body hr { border: none; border-top: 1px solid var(--border); margin: 1.8em 0; }
.markdown-body img { max-width: 100%; }

@media (max-width: 900px) {
  .sidebar { position: fixed; left: -240px; z-index: 100; transition: left .2s; }
  .sidebar.open { left: 0; }
  .topbar-search input { width: 100%; max-width: none; }
  .doc-layout { grid-template-columns: 1fr; }
  .doc-sidebar { max-height: 220px; }
  .doc-viewer { padding: 18px; }
}
CSS;
    }

    private function js(): string
    {
        return <<<'JS'
// ---- Echappement HTML : TOUTE donnée issue du code analyse (chemins,
// messages, extraits, résumé IA...) doit être échappée avant insertion via
// innerHTML, sinon un projet contenant du code piégé (ex: un commentaire
// "<img src=x onerror=...>") exécuterait ce code à l'ouverture du rapport.
// C'est une protection XSS indispensable pour un outil d'audit de sécurité.
function esc(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// ---- Moteur de rendu Markdown (sans dépendance externe) ----
// Règle de sécurité : le contenu est INTEGRALEMENT échappé avant toute
// transformation. Les balises produites ensuite sont uniquement celles que
// ce moteur génère lui-même, jamais du HTML présent dans le fichier source.
function renderMarkdown(src) {
  if (!src) return '<p style="color:var(--text-muted)">Document vide.</p>';

  const codeBlocks = [];
  // 1. On isole les blocs de code délimités par ``` pour les protéger
  let text = src.replace(/```([a-zA-Z0-9+#-]*)\n([\s\S]*?)```/g, (m, lang, code) => {
    codeBlocks.push({ lang: lang || '', code: code });
    return `\u0000CODEBLOCK${codeBlocks.length - 1}\u0000`;
  });

  // 2. Echappement complet : plus aucun HTML du fichier source ne survit
  text = esc(text);

  // 3. Tableaux (doivent être traités avant les autres blocs)
  text = text.replace(/(^\|.+\|[ \t]*\n\|[ \t:|-]+\|[ \t]*\n(?:\|.*\|[ \t]*\n?)*)/gm, (block) => {
    const rows = block.trim().split('\n');
    if (rows.length < 2) return block;
    const cells = (row) => row.replace(/^\||\|$/g, '').split('|').map(c => c.trim());
    let out = '<table><thead><tr>';
    cells(rows[0]).forEach(c => out += `<th>${c}</th>`);
    out += '</tr></thead><tbody>';
    for (let i = 2; i < rows.length; i++) {
      if (!rows[i].trim()) continue;
      out += '<tr>';
      cells(rows[i]).forEach(c => out += `<td>${c}</td>`);
      out += '</tr>';
    }
    return out + '</tbody></table>\n';
  });

  // 4. Titres
  text = text.replace(/^######\s+(.*)$/gm, '<h6>$1</h6>')
             .replace(/^#####\s+(.*)$/gm, '<h5>$1</h5>')
             .replace(/^####\s+(.*)$/gm, '<h4>$1</h4>')
             .replace(/^###\s+(.*)$/gm, '<h3>$1</h3>')
             .replace(/^##\s+(.*)$/gm, '<h2>$1</h2>')
             .replace(/^#\s+(.*)$/gm, '<h1>$1</h1>');

  // 5. Ligne horizontale
  text = text.replace(/^\s*([-*_])\s*\1\s*\1[\s\S]*?$/gm, (m) => /^[\s\-*_]+$/.test(m) ? '<hr>' : m);

  // 6. Citations
  text = text.replace(/^&gt;\s?(.*)$/gm, '<blockquote>$1</blockquote>');
  text = text.replace(/<\/blockquote>\n<blockquote>/g, '<br>');

  // 7. Listes (à puces et numérotées)
  text = text.replace(/(?:^[ \t]*[-*+][ \t]+.*(?:\n|$))+/gm, (block) => {
    const items = block.trim().split('\n').map(l => l.replace(/^[ \t]*[-*+][ \t]+/, ''));
    return '<ul>' + items.map(i => `<li>${i}</li>`).join('') + '</ul>\n';
  });
  text = text.replace(/(?:^[ \t]*\d+\.[ \t]+.*(?:\n|$))+/gm, (block) => {
    const items = block.trim().split('\n').map(l => l.replace(/^[ \t]*\d+\.[ \t]+/, ''));
    return '<ol>' + items.map(i => `<li>${i}</li>`).join('') + '</ol>\n';
  });

  // 8. Styles en ligne
  text = text.replace(/`([^`\n]+)`/g, '<code>$1</code>')
             .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
             .replace(/__([^_\n]+)__/g, '<strong>$1</strong>')
             .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
             .replace(/~~([^~\n]+)~~/g, '<del>$1</del>');

  // 9. Liens et images. L'URL est filtrée : seuls http(s), mailto et les
  //    chemins relatifs sont acceptés, ce qui neutralise javascript:...
  const safeUrl = (u) => /^(https?:\/\/|mailto:|#|\.{0,2}\/|[\w.-]+\.[a-z]{2,})/i.test(u) ? u : '#';
  text = text.replace(/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/g, (m, alt, url) => `<img src="${safeUrl(url)}" alt="${alt}">`);
  text = text.replace(/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/g, (m, label, url) => `<a href="${safeUrl(url)}" target="_blank" rel="noopener noreferrer">${label}</a>`);

  // 10. Paragraphes : toute ligne restante non structurée
  text = text.split(/\n{2,}/).map(block => {
    const t = block.trim();
    if (!t) return '';
    if (/^<(h[1-6]|ul|ol|table|blockquote|hr|pre)/.test(t)) return t;
    if (/^\u0000CODEBLOCK\d+\u0000$/.test(t)) return t;
    return `<p>${t.replace(/\n/g, '<br>')}</p>`;
  }).join('\n');

  // 11. Restitution des blocs de code, échappés eux aussi
  text = text.replace(/\u0000CODEBLOCK(\d+)\u0000/g, (m, i) => {
    const b = codeBlocks[parseInt(i, 10)];
    const langAttr = b.lang ? ` class="language-${esc(b.lang)}"` : '';
    return `<pre><code${langAttr}>${esc(b.code.replace(/\n$/, ''))}</code></pre>`;
  });

  return text;
}

// ---- Navigation ----
document.querySelectorAll('.nav-link').forEach(link => {
  link.addEventListener('click', (e) => {
    e.preventDefault();
    document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    link.classList.add('active');
    document.getElementById(link.dataset.section).classList.add('active');
  });
});

// ---- Thème (localStorage peut être bloqué en file://, notamment sous
// Chromium/Brave selon la configuration de sécurité : on protège donc
// chaque accès et on se dégrade proprement vers un stockage en mémoire) ----
function safeStorageGet(key, fallback) {
  try { return localStorage.getItem(key) || fallback; }
  catch (e) { return fallback; }
}
function safeStorageSet(key, value) {
  try { localStorage.setItem(key, value); }
  catch (e) { /* stockage indisponible (file://) : on continue sans persister */ }
}

const themeToggle = document.getElementById('thème-toggle');
const root = document.documentElement;
let currentThemeMemory = safeStorageGet('ca_theme', 'dark');
root.setAttribute('data-thème', currentThemeMemory);
themeToggle.addEventListener('click', () => {
  const current = root.getAttribute('data-thème');
  const next = current === 'dark' ? 'light' : 'dark';
  root.setAttribute('data-thème', next);
  safeStorageSet('ca_theme', next);
});

// ---- Mini charting engine (canvas natif) ----
// cssVar() : lit une variable CSS custom property, avec repli sur une
// valeur fixe si le moteur de rendu ne la résout pas (cas de wkhtmltopdf,
// dont le WebKit embarqué est trop ancien pour toujours honorer
// getComputedStyle() sur les custom properties -> chaîne vide -> canvas
// invisible sans ce filet de sécurité).
const CSS_VAR_FALLBACKS = {
  '--accent': '#6366f1',
  '--text': '#e4e6eb',
  '--text-muted': '#9aa0ac',
  '--bg-card': '#1c202c',
};
function cssVar(name) {
  let value = '';
  try {
    value = (getComputedStyle(root).getPropertyValue(name) || '').trim();
  } catch (e) { /* ignoré */ }
  return value || CSS_VAR_FALLBACKS[name] || '#888888';
}
function drawBarChart(canvasId, labels, values, colors) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const w = canvas.width, h = canvas.height;
  ctx.clearRect(0, 0, w, h);
  const max = Math.max(...values, 1);
  const barWidth = w / values.length * 0.6;
  const gap = w / values.length;
  ctx.font = '11px sans-serif';
  values.forEach((v, i) => {
    const barHeight = (v / max) * (h - 50);
    const x = i * gap + (gap - barWidth) / 2;
    const y = h - barHeight - 30;
    ctx.fillStyle = colors ? colors[i % colors.length] : cssVar('--accent');
    ctx.fillRect(x, y, barWidth, barHeight);
    ctx.fillStyle = cssVar('--text');
    ctx.textAlign = 'center';
    ctx.fillText(v, x + barWidth / 2, y - 6);
    ctx.fillStyle = cssVar('--text-muted');
    const label = String(labels[i]).slice(0, 10);
    ctx.fillText(label, x + barWidth / 2, h - 14);
  });
}

function drawDonutChart(canvasId, labels, values) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const w = canvas.width, h = canvas.height;
  ctx.clearRect(0, 0, w, h);
  const total = values.reduce((a, b) => a + b, 0) || 1;
  const cx = w / 2 - 60, cy = h / 2, radius = Math.min(w, h) / 2 - 20;
  const palette = ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#3b82f6', '#a855f7', '#14b8a6', '#f97316'];
  let startAngle = -Math.PI / 2;
  values.forEach((v, i) => {
    const sliceAngle = (v / total) * Math.PI * 2;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.arc(cx, cy, radius, startAngle, startAngle + sliceAngle);
    ctx.closePath();
    ctx.fillStyle = palette[i % palette.length];
    ctx.fill();
    startAngle += sliceAngle;
  });
  ctx.fillStyle = cssVar('--bg-card');
  ctx.beginPath();
  ctx.arc(cx, cy, radius * 0.55, 0, Math.PI * 2);
  ctx.fill();

  const legendX = w - 110;
  ctx.font = '11px sans-serif';
  labels.forEach((label, i) => {
    const y = 20 + i * 18;
    ctx.fillStyle = palette[i % palette.length];
    ctx.fillRect(legendX, y - 8, 10, 10);
    ctx.fillStyle = cssVar('--text');
    ctx.textAlign = 'left';
    ctx.fillText(String(label).slice(0, 14), legendX + 16, y);
  });
}

const severityColors = { critical: '#ef4444', important: '#f59e0b', moderate: '#eab308', info: '#3b82f6' };

function renderCharts() {
  const g = REPORT_DATA.graphs;
  drawDonutChart('chart-languages', g.languages.labels, g.languages.values);
  drawBarChart('chart-severity', g.severity.labels, g.severity.values, g.severity.labels.map(l => severityColors[l] || '#6366f1'));
  drawBarChart('chart-scores', g.scores.labels, g.scores.values);
  drawBarChart('chart-sizes', g.sizes.labels, g.sizes.values);
}
// ---- Statistiques (section) ----
function renderStats() {
  const s = REPORT_DATA.stats;
  const container = document.getElementById('stats-content');
  const rows = (obj) => Object.entries(obj).map(([k, v]) => `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`).join('');
  container.innerHTML = `
    <table class="stat-table">
      <tr><th>Fichiers</th><td>${esc(s.total_files)}</td></tr>
      <tr><th>Dossiers</th><td>${esc(s.total_folders)}</td></tr>
      <tr><th>Taille totale</th><td>${esc(s.total_size_human)}</td></tr>
      <tr><th>Taille moyenne</th><td>${esc(s.average_size_human)}</td></tr>
      <tr><th>Lignes de code</th><td>${esc(s.total_lines)}</td></tr>
    </table>
    <h2>Repartition par langage</h2>
    <table class="stat-table">${rows(s.by_language)}</table>
    <h2>Repartition par extension</h2>
    <table class="stat-table">${rows(s.by_extension)}</table>
    <h2>Plus gros fichiers</h2>
    <table class="stat-table">
      <thead><tr><th>Fichier</th><th>Taille</th><th>Lignes</th></tr></thead>
      <tbody>${s.biggest_files.slice(0, 15).map(f => `<tr><td>${esc(f.relative)}</td><td>${(f.size/1024).toFixed(1)} Ko</td><td>${esc(f.lines)}</td></tr>`).join('')}</tbody>
    </table>
  `;
}

// ---- Sections par catégorie (sécurité, performance, architecture, qualité, documentation) ----
function renderCategory(sectionKey, containerId) {
  const container = document.getElementById(containerId);
  const severityBadge = { critical: '🔴 Critique', important: '🟠 Important', moderate: '🟡 Moyen', info: '🔵 Information' };
  let html = '';
  REPORT_DATA.files.forEach(f => {
    const issues = (f.issues && f.issues[sectionKey]) || [];
    issues.forEach(issue => {
      html += `<div class="issue-line"><strong>${severityBadge[issue.severity] || esc(issue.severity)}</strong> — <span style="font-family:monospace">${esc(f.path)}:${esc(issue.line)}</span> — ${esc(issue.message)}</div>`;
    });
  });
  container.innerHTML = html || '<p>Aucun problème détecté dans cette catégorie. 🎉</p>';
}

// ---- Dépendances ----
function renderDependencies() {
  const d = REPORT_DATA.dependencies;
  const container = document.getElementById('dépendances-content');
  function osvBlock(osv) {
    if (!osv) return '';
    if (!osv.scanned) {
      return osv.skipped_reason
        ? `<p class="issue-line">🔵 Scan CVE (OSV.dev) non effectué : ${esc(osv.skipped_reason)}</p>`
        : '';
    }
    if (!osv.findings.length) {
      return `<p class="issue-line">🟢 Aucune vulnérabilité connue trouvée sur OSV.dev pour ces paquets.</p>`;
    }
    return `<h3>Vulnérabilités connues (OSV.dev)</h3>` + osv.findings.map(f => {
      const approx = f.approximate ? ' <em>(version approximative, vérifier manuellement)</em>' : '';
      const vulns = f.vulns.map(v => `<div class="issue-line">🔴 <a href="${esc(v.url)}" target="_blank" rel="noopener">${esc(v.id)}</a> — ${esc(v.summary)} (sévérité : ${esc(String(v.severity))})</div>`).join('');
      return `<p><strong>${esc(f.package)}</strong> @ ${esc(f.version)}${approx}</p>${vulns}`;
    }).join('');
  }
  function block(title, data) {
    if (!data.found) return `<h2>${esc(title)}</h2><p>Aucun fichier de dépendances trouvé.</p>`;
    const pkgRows = Object.entries(data.packages).map(([k, v]) => `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`).join('');
    const warnRows = data.warnings.map(w => `<div class="issue-line">${esc(w.severity)} — ${esc(w.message)}</div>`).join('');
    return `<h2>${esc(title)}</h2>
      <table class="stat-table"><thead><tr><th>Paquet</th><th>Version</th></tr></thead><tbody>${pkgRows}</tbody></table>
      ${warnRows}
      ${osvBlock(data.osv)}`;
  }
  container.innerHTML = block('Composer (PHP)', d.composer) + block('npm (JS)', d.npm);
}

// ---- Organigramme (carte des classes, groupees par dossier) ----
// Pense pour une lecture posee : positionnement par flux CSS (pas de
// simulation physique), overlay SVG pour les liens trace apres layout
// du DOM (comme les canvas de graphiques), surbrillance au clic pour
// isoler les connexions d'une classe.
function renderOrganigramme() {
  const graph = REPORT_DATA.graphs.relationships;
  const container = document.getElementById('organigramme-content');
  if (!graph || !graph.nodes || graph.nodes.length === 0) {
    container.innerHTML = '<p style="color:var(--text-muted)">Aucune classe PHP détectée pour construire un organigramme (projet sans code PHP, ou uniquement des fichiers procéduraux).</p>';
    return;
  }

  const riskColors = { critical: '#ef4444', high: '#f97316', moderate: '#eab308', low: '#3b82f6', clean: '#22c55e' };
  const riskLabels = { critical: 'Critique', high: 'Élevé', moderate: 'Modéré', low: 'Faible', clean: 'Propre' };
  const riskRank = { critical: 4, high: 3, moderate: 2, low: 1, clean: 0 };
  const entryPointLabels = {
    http_controller: "Contrôleur HTTP — reçoit des requêtes utilisateur",
    cli_command: "Commande CLI — reçoit des arguments en ligne de commande",
    queue_job: "Job de file d'attente — traite des payloads asynchrones",
    event_listener: "Listener d'événement",
    middleware: "Middleware HTTP",
    form_request: "Form Request — valide une entrée utilisateur",
  };
  const edgeStyles = {
    extends:    { color: '#6366f1', dash: '',      width: 2   },
    implements: { color: '#a855f7', dash: '5,4',   width: 1.5 },
    uses:       { color: '#94a3b8', dash: '2,3',   width: 1   },
  };

  let showUses = false;
  let selectedNode = null;
  let scale = 1;
  const expandedGroups = new Set();

  const byGroup = {};
  graph.nodes.forEach(n => { (byGroup[n.group] = byGroup[n.group] || []).push(n); });

  // Resume de risque par groupe, pour trier et afficher un badge meme replie.
  const groupSummaries = Object.entries(byGroup).map(([group, nodes]) => {
    const counts = { critical: 0, high: 0, moderate: 0, low: 0, clean: 0 };
    let entryPoints = 0;
    nodes.forEach(n => { counts[n.risk_level]++; if (n.entry_point_type) entryPoints++; });
    const worstRisk = ['critical', 'high', 'moderate', 'low', 'clean'].find(r => counts[r] > 0) || 'clean';
    return { group, nodes, counts, entryPoints, worstRisk };
  }).sort((a, b) => riskRank[b.worstRisk] - riskRank[a.worstRisk] || b.nodes.length - a.nodes.length);

  // Les 5 groupes les plus a risque sont depliés d'office ; le reste attend un clic.
  groupSummaries.slice(0, 5).forEach(g => { if (g.worstRisk !== 'clean') expandedGroups.add(g.group); });

  function nodeHtml(n) {
    const color = riskColors[n.risk_level] || '#94a3b8';
    const badge = n.entry_point_type ? `<span class="og-badge" title="${esc(entryPointLabels[n.entry_point_type] || '')}">⚡</span>` : '';
    const kind = n.is_interface ? 'interface' : (n.is_trait ? 'trait' : (n.is_abstract ? 'abstraite' : ''));
    const tooltip = `${n.file} — ${n.issue_count} problème(s) détecté(s)${kind ? ' — classe ' + kind : ''}`;
    return `<div class="og-node" data-id="${esc(n.id)}" data-search="${esc(n.label.toLowerCase())}" tabindex="0" style="border-left-color:${color}" title="${esc(tooltip)}">
      <span class="og-node-label">${badge}${esc(n.label)}</span>
      ${kind ? `<span class="og-node-kind">${kind}</span>` : ''}
    </div>`;
  }

  function groupBadgesHtml(g) {
    return ['critical', 'high', 'moderate'].filter(r => g.counts[r] > 0)
      .map(r => `<span class="og-group-badge" style="background:${riskColors[r]}22;color:${riskColors[r]}">${g.counts[r]} ${riskLabels[r].toLowerCase()}</span>`)
      .join('') + (g.entryPoints > 0 ? `<span class="og-group-badge og-group-badge-entry">⚡ ${g.entryPoints}</span>` : '');
  }

  const legendHtml = Object.keys(riskColors).map(k =>
    `<span class="og-legend-item"><i style="background:${riskColors[k]}"></i>${riskLabels[k]}</span>`
  ).join('') + `<span class="og-legend-item">⚡ Point d'entrée</span>`;

  container.innerHTML = `
    <div class="og-toolbar">
      <input type="text" id="og-search" class="og-search" placeholder="Rechercher une classe...">
      <label class="og-toggle"><input type="checkbox" id="og-toggle-uses"> Imports simples (« uses »)</label>
      <button type="button" id="og-expand-all" class="og-text-btn">Tout déplier</button>
      <button type="button" id="og-collapse-all" class="og-text-btn">Tout replier</button>
      <div class="og-zoom">
        <button type="button" id="og-zoom-out" aria-label="Zoom arrière">−</button>
        <span id="og-zoom-level">100%</span>
        <button type="button" id="og-zoom-in" aria-label="Zoom avant">+</button>
      </div>
    </div>
    <div class="og-legend-bar">${legendHtml}</div>
    <p class="og-stats">${graph.stats.total_classes} classe(s) détectée(s) dans ${groupSummaries.length} dossier(s), dont <strong>${graph.stats.entry_points}</strong> point(s) d'entrée (à auditer en priorité). Dossiers triés du plus au moins à risque ; les ${Math.min(5, groupSummaries.length)} premiers sont dépliés. Cliquez un dossier pour le déplier/replier, une classe pour isoler ses connexions.</p>
    <div class="og-scroll-wrapper">
      <div class="og-canvas" id="og-canvas">
        <svg id="og-edges" class="og-edges"></svg>
        <div class="og-groups" id="og-groups"></div>
      </div>
    </div>
  `;

  const groupsContainer = document.getElementById('og-groups');
  groupSummaries.forEach(g => {
    const wrap = document.createElement('div');
    wrap.className = 'og-group';
    wrap.dataset.group = g.group;
    const isOpen = expandedGroups.has(g.group);
    wrap.innerHTML = `
      <div class="og-group-title" role="button" tabindex="0">
        <span class="og-group-caret">${isOpen ? '▾' : '▸'}</span>
        <span class="og-group-name">${esc(g.group)}</span>
        <span class="og-group-count">(${g.nodes.length})</span>
        <span class="og-group-badges">${groupBadgesHtml(g)}</span>
      </div>
      <div class="og-group-nodes" style="display:${isOpen ? 'flex' : 'none'}">${g.nodes.map(nodeHtml).join('')}</div>
    `;
    groupsContainer.appendChild(wrap);
  });

  function drawEdges() {
    const svg = document.getElementById('og-edges');
    const canvas = document.getElementById('og-canvas');
    if (!svg || !canvas) return;
    const canvasRect = canvas.getBoundingClientRect();
    const w = canvas.scrollWidth, h = canvas.scrollHeight;
    svg.setAttribute('width', w);
    svg.setAttribute('height', h);
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);

    const positions = {};
    canvas.querySelectorAll('.og-node').forEach(el => {
      if (el.offsetParent === null) return; // groupe replie : pas de position, pas d'arete tracee
      const r = el.getBoundingClientRect();
      positions[el.dataset.id] = {
        x: (r.left - canvasRect.left) / scale + (r.width / 2) / scale,
        y: (r.top - canvasRect.top) / scale + (r.height / 2) / scale,
      };
    });

    let svgContent = `<defs>
      <marker id="og-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
        <path d="M0,0 L10,5 L0,10 z" fill="context-stroke"></path>
      </marker>
    </defs>`;

    graph.edges.forEach(e => {
      if (e.type === 'uses' && !showUses) return;
      const from = positions[e.from], to = positions[e.to];
      if (!from || !to) return;
      const style = edgeStyles[e.type] || edgeStyles.uses;
      const involved = selectedNode && (e.from === selectedNode || e.to === selectedNode);
      const opacity = selectedNode ? (involved ? 1 : 0.06) : 0.5;
      const width = involved ? style.width + 1.5 : style.width;
      const marker = e.type !== 'uses' ? ' marker-end="url(#og-arrow)"' : '';
      svgContent += `<line x1="${from.x}" y1="${from.y}" x2="${to.x}" y2="${to.y}" stroke="${style.color}" stroke-width="${width}" stroke-dasharray="${style.dash}" opacity="${opacity}"${marker}></line>`;
    });

    svg.innerHTML = svgContent;
  }

  function applyZoom() {
    document.getElementById('og-canvas').style.transform = `scale(${scale})`;
    document.getElementById('og-canvas').style.transformOrigin = 'top left';
    document.getElementById('og-zoom-level').textContent = Math.round(scale * 100) + '%';
    requestAnimationFrame(drawEdges);
  }

  function applySelection() {
    document.querySelectorAll('.og-node').forEach(el => {
      const id = el.dataset.id;
      if (!selectedNode) { el.classList.remove('og-dim', 'og-selected'); return; }
      if (id === selectedNode) { el.classList.add('og-selected'); el.classList.remove('og-dim'); return; }
      const connected = graph.edges.some(e => (e.from === selectedNode && e.to === id) || (e.to === selectedNode && e.from === id));
      el.classList.toggle('og-dim', !connected);
      el.classList.remove('og-selected');
    });
    drawEdges();
  }

  const edgeTypeLabels = { extends: 'hérite de', implements: 'implémente', uses: 'importe' };
  const edgeTypeLabelsReverse = { extends: 'est héritée par', implements: 'est implémentée par', uses: 'est importée par' };

  function nodeById(id) { return graph.nodes.find(n => n.id === id); }

  function showConnectionsPopup(nodeId) {
    const node = nodeById(nodeId);
    if (!node) return;
    const modal = document.getElementById('explain-modal');
    const title = document.getElementById('explain-title');
    const body = document.getElementById('explain-body');
    if (!modal || !title || !body) return;

    const outgoing = graph.edges.filter(e => e.from === nodeId);
    const incoming = graph.edges.filter(e => e.to === nodeId);

    const color = riskColors[node.risk_level] || '#94a3b8';
    const badge = node.entry_point_type ? `<p class="og-modal-entry">⚡ ${esc(entryPointLabels[node.entry_point_type] || '')}</p>` : '';

    function listEdges(edges, otherKey, labels) {
      if (edges.length === 0) return '<p style="color:var(--text-muted)">Aucune.</p>';
      return '<ul class="og-modal-list">' + edges.map(e => {
        const other = nodeById(e[otherKey]);
        if (!other) return '';
        const c = riskColors[other.risk_level] || '#94a3b8';
        const eb = other.entry_point_type ? ' ⚡' : '';
        return `<li><span class="og-modal-dot" style="background:${c}"></span><strong>${esc(other.label)}</strong>${eb} <span class="og-modal-relation">(${labels[e.type]})</span> <span class="og-modal-file">— ${esc(other.file)}</span></li>`;
      }).join('') + '</ul>';
    }

    title.textContent = 'Connexions — ' + node.label;
    body.innerHTML = `
      <p><span class="og-modal-dot" style="background:${color}"></span><strong>${esc(node.id)}</strong></p>
      <p class="og-modal-file">${esc(node.file)} — ${node.issue_count} problème(s) détecté(s)</p>
      ${badge}
      <h3>Dépend de (${outgoing.length})</h3>
      ${listEdges(outgoing, 'to', edgeTypeLabels)}
      <h3>Utilisée par (${incoming.length})</h3>
      ${listEdges(incoming, 'from', edgeTypeLabelsReverse)}
      ${(outgoing.length === 0 && incoming.length === 0) ? '<p style="color:var(--text-muted)">Aucune connexion locale détectée : cette classe ne dépend que de code externe (framework/vendor) et n\'est utilisée par aucune autre classe locale du projet.</p>' : ''}
    `;
    modal.classList.add('open');
  }

  function toggleGroup(groupEl, forceOpen) {
    const nodesEl = groupEl.querySelector('.og-group-nodes');
    const caret = groupEl.querySelector('.og-group-caret');
    const willOpen = forceOpen !== undefined ? forceOpen : (nodesEl.style.display === 'none');
    nodesEl.style.display = willOpen ? 'flex' : 'none';
    caret.textContent = willOpen ? '▾' : '▸';
    if (willOpen) {
      attachNodeListeners(nodesEl);
    }
    requestAnimationFrame(drawEdges);
  }

  function attachNodeListeners(scopeEl) {
    scopeEl.querySelectorAll('.og-node').forEach(el => {
      if (el.dataset.bound) return;
      el.dataset.bound = '1';
      el.addEventListener('click', () => {
        selectedNode = (selectedNode === el.dataset.id) ? null : el.dataset.id;
        applySelection();
        if (selectedNode) showConnectionsPopup(selectedNode);
      });
    });
  }

  document.querySelectorAll('.og-group-title').forEach(titleEl => {
    titleEl.addEventListener('click', () => toggleGroup(titleEl.parentElement));
    titleEl.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleGroup(titleEl.parentElement); } });
  });
  attachNodeListeners(document);

  document.getElementById('og-expand-all').addEventListener('click', () => {
    document.querySelectorAll('.og-group').forEach(g => toggleGroup(g, true));
  });
  document.getElementById('og-collapse-all').addEventListener('click', () => {
    document.querySelectorAll('.og-group').forEach(g => toggleGroup(g, false));
  });

  document.getElementById('og-search').addEventListener('input', (e) => {
    const term = e.target.value.toLowerCase().trim();
    document.querySelectorAll('.og-group').forEach(groupEl => {
      const matches = [...groupEl.querySelectorAll('.og-node')].filter(n => n.dataset.search.includes(term));
      if (term === '') {
        groupEl.style.display = '';
        groupEl.querySelectorAll('.og-node').forEach(n => n.classList.remove('og-search-hit', 'og-search-miss'));
        return;
      }
      groupEl.style.display = matches.length > 0 ? '' : 'none';
      if (matches.length > 0) toggleGroup(groupEl, true);
      groupEl.querySelectorAll('.og-node').forEach(n => {
        const hit = n.dataset.search.includes(term);
        n.classList.toggle('og-search-hit', hit);
        n.classList.toggle('og-search-miss', !hit);
      });
    });
    requestAnimationFrame(drawEdges);
  });

  document.getElementById('og-toggle-uses').addEventListener('change', (e) => {
    showUses = e.target.checked;
    drawEdges();
  });
  document.getElementById('og-zoom-in').addEventListener('click', () => { scale = Math.min(2, scale + 0.15); applyZoom(); });
  document.getElementById('og-zoom-out').addEventListener('click', () => { scale = Math.max(0.4, scale - 0.15); applyZoom(); });

  window.addEventListener('resize', () => safeRun('organigramme (resize)', drawEdges));
  requestAnimationFrame(drawEdges);
}

// ---- Fichiers (accordeon) ----
let currentFilter = 'all';
function renderFiles(searchTerm = '') {
  const container = document.getElementById('files-accordion');
  const term = searchTerm.toLowerCase();
  const severityBadge = { critical: '🔴', important: '🟠', moderate: '🟡', info: '🔵' };

  const filtered = REPORT_DATA.files.filter(f => {
    if (currentFilter === 'critical-only') {
      const hasCritical = Object.values(f.issues || {}).some(arr => arr.some(i => i.severity === 'critical'));
      if (!hasCritical) return false;
    } else if (currentFilter !== 'all' && f.lang !== currentFilter) {
      return false;
    }
    if (term && !f.path.toLowerCase().includes(term)) return false;
    return true;
  });

  container.innerHTML = filtered.map((f, idx) => {
    const allIssues = Object.entries(f.issues || {}).flatMap(([section, arr]) => arr.map(i => ({...i, section})));
    const badgeCounts = {};
    allIssues.forEach(i => badgeCounts[i.severity] = (badgeCounts[i.severity] || 0) + 1);
    const badgesHtml = Object.entries(badgeCounts).map(([sev, count]) => `<span class="badge sev-${sev}">${severityBadge[sev] || ''} ${count}</span>`).join('');

    const aiBlock = f.ai ? `
      <div class="ai-block">
        <p><strong>Résumé IA :</strong> ${esc(f.ai.résumé || '')}</p>
        <p><strong>Dette technique :</strong> ${esc(f.ai.dette_technique || 'n/a')} — <strong>Score global IA :</strong> ${esc(f.ai.score_global || 'n/a')}/10</p>
        ${f.ai.pistes_amelioration ? '<p><strong>Pistes damelioration :</strong></p><ul>' + f.ai.pistes_amelioration.map(p => `<li>${esc(p)}</li>`).join('') + '</ul>' : ''}
      </div>` : '<p style="color:var(--text-muted)">Analyse IA non disponible pour ce fichier.</p>';

    const issuesHtml = allIssues.length
      ? allIssues.map(i => `<div class="issue-line">${severityBadge[i.severity] || ''} <strong>${esc(i.section)}</strong> — ligne ${esc(i.line)} — ${esc(i.message)}</div>`).join('')
      : '<p style="color:var(--text-muted)">Aucun problème détecté.</p>';

    // Onglet "Aperçu" réservé aux fichiers Markdown : rendu façon GitHub.
    const hasMarkdown = f.lang === 'Markdown' && f.markdown;
    const markdownTab = hasMarkdown
      ? `<div class="file-tab active" onclick="showFileTab(this, 'md-${idx}')">📖 Aperçu</div>`
      : '';
    const markdownContent = hasMarkdown
      ? `<div id="md-${idx}" class="file-tab-content markdown-body">${renderMarkdown(f.markdown)}</div>`
      : '';

    // Si un aperçu markdown existe, il devient l'onglet actif par défaut :
    // les autres onglets ne portent alors plus la classe "active".
    const issuesTabActive = hasMarkdown ? '' : ' active';
    const issuesDisplay = hasMarkdown ? ' style="display:none"' : '';

    return `
    <div class="file-item" id="file-${idx}">
      <div class="file-header" onclick="document.getElementById('file-${idx}').classList.toggle('open')">
        <div>
          <div class="file-name">${esc(f.path)}</div>
          <div class="file-meta">${esc(f.lang)} · ${esc(f.lines)} lignes · ${(f.size/1024).toFixed(1)} Ko</div>
        </div>
        <div class="file-badges">${badgesHtml}</div>
      </div>
      <div class="file-body">
        <div class="file-tabs">
          ${markdownTab}
          <div class="file-tab${issuesTabActive}" onclick="showFileTab(this, 'issues-${idx}')">Problemes (${allIssues.length})</div>
          <div class="file-tab" onclick="showFileTab(this, 'ai-${idx}')">Analyse IA</div>
        </div>
        ${markdownContent}
        <div id="issues-${idx}" class="file-tab-content"${issuesDisplay}>${issuesHtml}</div>
        <div id="ai-${idx}" class="file-tab-content" style="display:none">${aiBlock}</div>
      </div>
    </div>`;
  }).join('') || '<p>Aucun fichier ne correspond à ce filtre.</p>';
}

function showFileTab(el, targetId) {
  const parent = el.closest('.file-tabs').parentElement;
  parent.querySelectorAll('.file-tab').forEach(t => t.classList.remove('active'));
  parent.querySelectorAll('.file-tab-content').forEach(c => c.style.display = 'none');
  el.classList.add('active');
  document.getElementById(targetId).style.display = 'block';
}

// ---- Documents Markdown (section dédiée, façon GitHub) ----
// Liste tous les .md du projet dans une barre latérale ; un clic affiche
// le document rendu dans le visualiseur. Le README est ouvert par défaut.
function renderDocuments() {
  const docs = REPORT_DATA.files.filter(f => f.lang === 'Markdown' && f.markdown);
  const list = document.getElementById('doc-list');
  const viewer = document.getElementById('doc-viewer');

  if (!docs.length) {
    list.innerHTML = '<p style="color:var(--text-muted);padding:12px;font-size:13px">Aucun fichier Markdown trouvé dans le projet.</p>';
    return;
  }

  // Le README (à la racine de préférence) passe en tête de liste.
  docs.sort((a, b) => {
    const aReadme = /readme\.md$/i.test(a.path) ? 0 : 1;
    const bReadme = /readme\.md$/i.test(b.path) ? 0 : 1;
    if (aReadme !== bReadme) return aReadme - bReadme;
    return a.path.localeCompare(b.path);
  });

  list.innerHTML = docs.map((d, i) =>
    `<div class="doc-item${i === 0 ? ' active' : ''}" data-doc="${i}">${esc(d.path)}</div>`
  ).join('');

  const showDoc = (i) => {
    viewer.innerHTML = renderMarkdown(docs[i].markdown);
    list.querySelectorAll('.doc-item').forEach(el => el.classList.toggle('active', +el.dataset.doc === i));
  };

  list.querySelectorAll('.doc-item').forEach(el => {
    el.addEventListener('click', () => safeRun('affichage document', () => showDoc(+el.dataset.doc)));
  });

  showDoc(0); // README ouvert par défaut
}

// ---- Modale d'explication des scores (clic sur une icône "i") ----
// Répond concrètement a "pourquoi cette note ?" en combinant la
// méthodologie générale et le détail chiffré réel calculé pour ce projet.
function buildExplanationHtml(key) {
  const summary = REPORT_DATA.summary;
  const methodo = (summary.methodology || {})[key];
  const détail = (summary.score_details || {})[key];
  if (!methodo) return '<p>Aucune explication disponible pour cette metrique.</p>';

  let html = '';
  html += `<h3>Comment cette note est calculée</h3>`;
  html += `<div class="formula">${esc(methodo.formula)}</div>`;
  html += `<p>${esc(methodo.détail)}</p>`;

  // Détail chiffré réel pour ce projet (le "pourquoi 5,2/10")
  if (détail) {
    html += `<h3>Le calcul pour VOTRE projet</h3>`;
    if (détail.score !== undefined) {
      html += `<p><strong>Note obtenue : ${esc(détail.score)}/10</strong> (moyenne sur ${esc(détail.files_analyzed || 0)} fichiers).</p>`;
    }
    const c = détail.issue_counts;
    if (c && (c.critical || c.important || c.moderate || c.info)) {
      html += `<table class="calc-table"><thead><tr><th>Severite</th><th>Nombre</th></tr></thead><tbody>`;
      const rows = [['🔴 Critiques','critical'],['🟠 Importants','important'],['🟡 Moyens','moderate'],['🔵 Informations','info']];
      rows.forEach(([lbl, k]) => { if (c[k]) html += `<tr><td>${lbl}</td><td class="num">${c[k]}</td></tr>`; });
      html += `</tbody></table>`;
    }
    if (détail.perfect_files !== undefined) {
      html += `<p>${esc(détail.perfect_files)} fichier(s) sans aucun problème dans cette catégorie, ${esc(détail.files_with_issues || 0)} avec au moins un.</p>`;
    }
    if (détail.note) {
      html += `<p>${esc(détail.note)}</p>`;
    }
    if (détail.worst_files && détail.worst_files.length) {
      html += `<h3>Fichiers qui pesent le plus sur cette note</h3><table class="calc-table"><thead><tr><th>Fichier</th><th>Note</th></tr></thead><tbody>`;
      détail.worst_files.forEach(w => html += `<tr><td>${esc(w.file)}</td><td class="num">${esc(w.score)}/10</td></tr>`);
      html += `</tbody></table>`;
    }
  }

  html += `<h3>Limites de cette mesure</h3>`;
  html += `<div class="limits">${esc(methodo.limits)}</div>`;
  return html;
}

(function initExplainModal() {
  const modal = document.getElementById('explain-modal');
  if (!modal) return;
  const body = document.getElementById('explain-body');
  const title = document.getElementById('explain-title');
  const titles = {
    global: 'Score global', security: 'Score sécurité', quality: 'Score qualité',
    architecture: 'Score architecture', performance: 'Score performance', documentation: 'Score documentation'
  };

  const open = (key) => {
    safeRun('explication score', () => {
      title.textContent = 'Explication — ' + (titles[key] || key);
      body.innerHTML = buildExplanationHtml(key);
      modal.classList.add('open');
    });
  };
  const close = () => modal.classList.remove('open');

  document.querySelectorAll('.kpi-info').forEach(btn => {
    btn.addEventListener('click', (e) => { e.stopPropagation(); open(btn.dataset.explain); });
  });
  modal.querySelector('.modal-close')?.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();

// ---- Exécution robuste : une erreur dans une section ne doit JAMAIS
// empecher l'affichage des autres sections du rapport. ----
function safeRun(label, fn) {
  try {
    fn();
  } catch (e) {
    console.error(`Clarté — erreur lors du rendu de "${label}" :`, e);
  }
}

safeRun('graphiques', renderCharts);
window.addEventListener('resize', () => safeRun('graphiques (resize)', renderCharts));
safeRun('statistiques', renderStats);
safeRun('catégorie architecture', () => renderCategory('architecture', 'architecture-content'));
safeRun('organigramme', renderOrganigramme);
safeRun('catégorie sécurité', () => renderCategory('security', 'sécurité-content'));
safeRun('catégorie performance', () => renderCategory('performance', 'performance-content'));
safeRun('catégorie qualité', () => renderCategory('quality', 'qualité-content'));
safeRun('catégorie documentation', () => renderCategory('documentation', 'documentation-content'));
safeRun('dépendances', renderDependencies);
safeRun('documents', renderDocuments);
safeRun('fichiers', () => renderFiles());

document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    safeRun('filtre fichiers', () => {
      document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentFilter = btn.dataset.filter;
      renderFiles(document.getElementById('global-search').value);
    });
  });
});

document.getElementById('global-search').addEventListener('input', (e) => {
  safeRun('recherche fichiers', () => renderFiles(e.target.value));
});
JS;
    }
}
