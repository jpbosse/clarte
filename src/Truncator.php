<?php

namespace Clarte;

/**
 * Troncature intelligente : conserve le debut (imports, declarations,
 * en-tetes de classes) et la fin (souvent la logique de sortie / export)
 * d'un fichier trop volumineux pour etre envoye integralement a l'IA
 * ou affiche dans le rapport.
 */
class Truncator
{
    private int $headLines;
    private int $tailLines;

    public function __construct(int $headLines = 300, int $tailLines = 100)
    {
        $this->headLines = $headLines;
        $this->tailLines = $tailLines;
    }

    public function truncate(string $content): array
    {
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($totalLines <= $this->headLines + $this->tailLines) {
            return [
                'content'    => $content,
                'truncated'  => false,
                'total_lines' => $totalLines,
            ];
        }

        $head = array_slice($lines, 0, $this->headLines);
        $tail = array_slice($lines, -$this->tailLines);
        $omitted = $totalLines - $this->headLines - $this->tailLines;

        $truncatedContent = implode("\n", $head)
            . "\n\n/* [... {$omitted} lignes omises par troncature intelligente ...] */\n\n"
            . implode("\n", $tail);

        return [
            'content'     => $truncatedContent,
            'truncated'   => true,
            'total_lines' => $totalLines,
            'omitted'     => $omitted,
        ];
    }
}
