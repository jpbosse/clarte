# Clarté — Diagnostics (extension VS Code)

Affiche dans VS Code les problèmes déjà détectés par [Clarté](../../README.md),
directement dans l'éditeur — sans avoir à ouvrir le rapport HTML séparément
et chercher la bonne ligne à la main.

## Ce que ça fait (et ce que ça ne fait pas)

Cette extension **lit uniquement** le fichier `reports/rapport.json` déjà
généré par `php clarte.php`. Elle ne lance **jamais** elle-même
l'analyse : il faut continuer à exécuter Clarté depuis un terminal comme
d'habitude. L'extension se contente d'afficher ce résultat comme des
diagnostics natifs de VS Code :

- les lignes concernées sont soulignées dans l'éditeur (rouge pour
  critique, orange pour important/modéré, bleu pour information) ;
- le survol de la souris affiche le message d'explication ;
- tous les problèmes apparaissent aussi dans le panneau **Problems**
  (`Ctrl+Shift+M` / `Cmd+Shift+M`) ;
- une pastille dans la barre de statut affiche le score global, et se
  met à jour **automatiquement** dès que `reports/rapport.json` est
  régénéré (pas besoin de relancer VS Code).

## Installation

Aucune publication sur le Marketplace pour l'instant : installation
manuelle du fichier `.vsix`.

1. Récupérez le fichier `clarte-diagnostics-0.1.0.vsix` (dans ce dossier,
   ou reconstruit avec la commande ci-dessous).
2. Dans VS Code : `Ctrl+Shift+P` (`Cmd+Shift+P` sur Mac) → tapez
   **"Extensions: Install from VSIX..."** → sélectionnez le fichier.
3. Rechargez VS Code si demandé.

## Utilisation

1. Ouvrez votre projet PHP/Laravel dans VS Code.
2. Lancez l'analyse comme d'habitude, dans un terminal :
   ```bash
   php /chemin/vers/Clarte/clarte.php .
   ```
3. Les problèmes apparaissent automatiquement dans l'éditeur.
4. Pour forcer un rechargement manuel (rarement nécessaire, le
   rafraîchissement est automatique) : `Ctrl+Shift+P` →
   **"Clarté : Recharger le rapport"**.
5. Pour ouvrir le rapport HTML complet (graphiques, organigramme...) :
   `Ctrl+Shift+P` → **"Clarté : Ouvrir le rapport HTML complet"**.

## Configuration

Si votre `rapport.json` ne se trouve pas à l'emplacement par défaut
(`reports/rapport.json` à la racine du dossier ouvert), ajustez dans les
paramètres VS Code (`Ctrl+,`) :

```json
{
  "clarte.reportPath": "un/autre/chemin/rapport.json"
}
```

## Reconstruire le fichier .vsix

Si vous modifiez `extension.js` ou `package.json` :

```bash
cd editor-extensions/vscode
npx @vscode/vsce package
```

## Limites connues

- Ne se déclenche pas tout seul : il faut relancer `php clarte.php`
  manuellement (ou via une tâche VS Code que vous configurez vous-même)
  pour que le rapport — et donc les diagnostics — se mette à jour.
- Les numéros de ligne viennent directement du rapport JSON ; si le
  fichier a été modifié depuis la dernière analyse, les diagnostics
  peuvent pointer légèrement à côté tant qu'une nouvelle analyse n'a pas
  été relancée.
