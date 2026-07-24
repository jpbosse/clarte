// Extension VS Code "Clarté — Diagnostics"
//
// Role volontairement simple (Option A) : cette extension ne lance JAMAIS
// elle-meme d'analyse. Elle se contente de LIRE le reports/rapport.json
// deja genere par `php clarte.php`, et d'afficher son contenu comme des
// diagnostics natifs de VS Code (soulignes dans l'editeur, listes dans le
// panneau "Problems"). L'analyse reste pilotee depuis le terminal, comme
// aujourd'hui ; cette extension evite seulement l'aller-retour manuel
// vers le rapport HTML pour retrouver la bonne ligne.
//
// Ecrite en JavaScript simple (pas de TypeScript/etape de compilation)
// pour rester facile a lire et a modifier sans outillage supplementaire.

const vscode = require('vscode');
const fs = require('fs');
const path = require('path');

const SEVERITY_MAP = {
  critical: vscode.DiagnosticSeverity.Error,
  important: vscode.DiagnosticSeverity.Warning,
  moderate: vscode.DiagnosticSeverity.Warning,
  info: vscode.DiagnosticSeverity.Information,
};

const SECTION_LABELS = {
  security: 'Sécurité',
  performance: 'Performance',
  architecture: 'Architecture',
  quality: 'Qualité',
  documentation: 'Documentation',
};

let diagnosticCollection;
let statusBarItem;
let outputChannel;

function activate(context) {
  diagnosticCollection = vscode.languages.createDiagnosticCollection('clarte');
  context.subscriptions.push(diagnosticCollection);

  statusBarItem = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 100);
  context.subscriptions.push(statusBarItem);

  outputChannel = vscode.window.createOutputChannel('Clarté');
  context.subscriptions.push(outputChannel);

  context.subscriptions.push(
    vscode.commands.registerCommand('clarte.reloadReport', () => loadReport(true))
  );
  context.subscriptions.push(
    vscode.commands.registerCommand('clarte.openHtmlReport', openHtmlReport)
  );

  // Surveille le fichier rapport.json : des qu'il est regenere (nouvelle
  // execution de `php clarte.php` dans un terminal), les diagnostics se
  // mettent a jour automatiquement, sans action de la personne.
  const reportPathSetting = getReportPathSetting();
  const watcher = vscode.workspace.createFileSystemWatcher(`**/${reportPathSetting}`);
  watcher.onDidChange(() => loadReport(false));
  watcher.onDidCreate(() => loadReport(false));
  watcher.onDidDelete(() => {
    diagnosticCollection.clear();
    statusBarItem.hide();
  });
  context.subscriptions.push(watcher);

  loadReport(false);
}

function getReportPathSetting() {
  return vscode.workspace.getConfiguration('clarte').get('reportPath', 'reports/rapport.json');
}

function getWorkspaceRoot() {
  const folders = vscode.workspace.workspaceFolders;
  return folders && folders.length > 0 ? folders[0].uri.fsPath : null;
}

function getReportFilePath() {
  const root = getWorkspaceRoot();
  if (!root) {
    return null;
  }
  return path.join(root, getReportPathSetting());
}

function openHtmlReport() {
  const root = getWorkspaceRoot();
  if (!root) {
    vscode.window.showWarningMessage('Clarté : aucun dossier de projet ouvert.');
    return;
  }
  const htmlPath = path.join(root, path.dirname(getReportPathSetting()), 'rapport.html');
  if (!fs.existsSync(htmlPath)) {
    vscode.window.showWarningMessage(`Clarté : rapport HTML introuvable (${htmlPath}). Lancez d'abord "php clarte.php" sur votre projet.`);
    return;
  }
  vscode.env.openExternal(vscode.Uri.file(htmlPath));
}

/**
 * Lit reports/rapport.json et reconstruit l'ensemble des diagnostics.
 * @param {boolean} notify Affiche un message de confirmation/erreur (utilise pour la commande manuelle, pas pour le rafraichissement automatique silencieux).
 */
function loadReport(notify) {
  const filePath = getReportFilePath();

  if (!filePath || !fs.existsSync(filePath)) {
    diagnosticCollection.clear();
    statusBarItem.hide();
    if (notify) {
      const message = filePath
        ? `Clarté : rapport introuvable (${filePath}). Lancez d'abord "php clarte.php" sur votre projet.`
        : 'Clarté : aucun dossier de projet ouvert.';
      vscode.window.showWarningMessage(message);
    }
    return;
  }

  let data;
  try {
    data = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (err) {
    outputChannel.appendLine(`Erreur de lecture de ${filePath} : ${err.message}`);
    if (notify) {
      vscode.window.showErrorMessage(`Clarté : rapport.json illisible ou corrompu (${err.message}). Voir la sortie "Clarté" pour le détail.`);
    }
    return;
  }

  diagnosticCollection.clear();
  const root = getWorkspaceRoot();
  const fileResults = data.fileResults || {};
  let totalIssues = 0;
  let filesWithIssues = 0;

  for (const [relativePath, result] of Object.entries(fileResults)) {
    const issuesBySection = (result && result.issues) || {};
    const diagnostics = [];

    for (const [section, issues] of Object.entries(issuesBySection)) {
      if (!Array.isArray(issues)) {
        continue;
      }
      for (const issue of issues) {
        const lineIndex = Math.max(0, (issue.line || 1) - 1);
        const range = new vscode.Range(lineIndex, 0, lineIndex, 300);
        const label = SECTION_LABELS[section] || section;
        const diagnostic = new vscode.Diagnostic(
          range,
          `[${label}] ${issue.message}`,
          SEVERITY_MAP[issue.severity] ?? vscode.DiagnosticSeverity.Hint
        );
        diagnostic.source = 'Clarté';
        diagnostic.code = issue.rule || section;
        diagnostics.push(diagnostic);
        totalIssues++;
      }
    }

    if (diagnostics.length > 0) {
      filesWithIssues++;
      const fileUri = vscode.Uri.file(path.join(root, relativePath));
      diagnosticCollection.set(fileUri, diagnostics);
    }
  }

  const score = data.summary && data.summary.global_score;
  if (score !== undefined) {
    statusBarItem.text = `$(shield) Clarté : ${score}/100`;
    statusBarItem.tooltip = `Score global Clarté — ${totalIssues} problème(s) sur ${filesWithIssues} fichier(s). Cliquez pour recharger le rapport.`;
    statusBarItem.command = 'clarte.reloadReport';
    statusBarItem.show();
  } else {
    statusBarItem.hide();
  }

  outputChannel.appendLine(`Rapport charge : ${totalIssues} probleme(s) sur ${filesWithIssues} fichier(s), score global ${score ?? 'inconnu'}/100.`);

  if (notify) {
    vscode.window.showInformationMessage(`Clarté : rapport rechargé — ${totalIssues} problème(s), score ${score ?? '?'}/100.`);
  }
}

function deactivate() {
  if (diagnosticCollection) {
    diagnosticCollection.dispose();
  }
}

module.exports = { activate, deactivate };
