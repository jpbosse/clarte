<?php

namespace Clarte;

/**
 * Construit le prompt envoye au modele IA pour l'analyse d'un fichier,
 * en s'appuyant deja sur les problemes detectes par les analyseurs
 * statiques (pour que l'IA priorise et explique plutot que de re-decouvrir).
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
            $issuesSummary = "Problemes deja detectes par l'analyse statique :\n" . implode("\n", $lines);
        }

        return <<<PROMPT
Tu es un expert en revue de code PHP/Laravel senior. Analyse le fichier suivant
({$relativePath}, langage : {$lang}) et reponds UNIQUEMENT en JSON valide avec
cette structure exacte, sans texte avant ou apres :

{
  "resume": "resume en 1-2 phrases du role du fichier",
  "qualite": <note sur 10>,
  "lisibilite": <note sur 10>,
  "securite": <note sur 10>,
  "performance": <note sur 10>,
  "architecture": <note sur 10>,
  "dette_technique": "faible|moyenne|elevee",
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
