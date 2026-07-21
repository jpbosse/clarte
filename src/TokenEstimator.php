<?php

namespace Clarte;

/**
 * Estimation approximative du nombre de tokens d'un texte, sans dépendance
 * à une bibliothèque de tokenisation exacte (tiktoken, etc.).
 * Règle empirique : ~1 token pour 3.5 à 4 caractères de code source.
 */
class TokenEstimator
{
    private const CHARS_PER_TOKEN = 3.8;

    public function estimate(string $text): int
    {
        $length = mb_strlen($text);
        return (int) ceil($length / self::CHARS_PER_TOKEN);
    }

    public function estimateCost(int $tokens, float $pricePerMillionInput = 0.15): float
    {
        return ($tokens / 1_000_000) * $pricePerMillionInput;
    }
}
