<?php

namespace Clarte;

/**
 * Construit le prompt envoyé au modèle IA pour l'analyse d'un fichier,
 * en s'appuyant déjà sur les problèmes détectés par les analyseurs
 * statiques (pour que l'IA priorise et explique plutôt que de re-découvrir).
 */
class PromptBuilder
{
    public function build(string $relativePath, string $lang, string $content, array $staticIssues): string
    {
        $issuesSummary = '';
        if (!empty($staticIssues)) {
            $lines = array_map(
                fn($i) => "- [{$i['severity']}] ligne {$i['line']} : {$i['message']}",
                array_slice($staticIssues, 0, 15)
            );
            $issuesSummary = "Problèmes déjà détectés par l'analyse statique :\n" . implode("\n", $lines);
        }

        return <<<PROMPT
Tu es un expert en revue de code PHP/Laravel senior. Analyse le fichier suivant
({$relativePath}, langage : {$lang}) et reponds UNIQUEMENT en JSON valide avec
cette structure exacte, sans texte avant ou après :

{
  "résumé": "résumé en 1-2 phrases du role du fichier",
  "qualité": <note sur 10>,
  "lisibilité": <note sur 10>,
  "sécurité": <note sur 10>,
  "performance": <note sur 10>,
  "architecture": <note sur 10>,
  "dette_technique": "faible|moyenne|élevée",
  "points_forts": ["...", "..."],
  "pistes_amelioration": ["...", "..."],
  "score_global": <note sur 10>
}

{$issuesSummary}

Contenu du fichier :
```
{$content}
```
PROMPT;
    }
}
