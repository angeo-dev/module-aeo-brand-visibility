<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Analysis;

/**
 * Whole-word term matching for brand and competitor names.
 *
 * Before 4.1.0 the analyser used plain substring search, so a short brand
 * matched inside other words: "Aria" in "variable", "Nova" in "innovation".
 * A term now has to stand on its own: the characters before and after it may
 * not be letters or digits (Unicode-aware, so Cyrillic and accented text work).
 * Punctuation is a boundary, so "angeo" still matches in "angeo.dev" and
 * "(angeo)".
 *
 * This removes false matches inside words. It cannot tell two real businesses
 * with the same name apart; add the brand domain or a more specific alias for
 * that.
 *
 * All offsets are character offsets (mb_*), like the rest of the analyser.
 */
class TermMatcher
{
    /**
     * Character offset of the first whole-word occurrence, or null.
     */
    public static function position(string $text, string $term): ?int
    {
        if ($term === '' || $text === '') {
            return null;
        }
        if (preg_match(self::pattern($term), $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return mb_strlen(substr($text, 0, (int) $m[0][1]));
    }

    /**
     * Whether the term occurs as a whole word.
     */
    public static function contains(string $text, string $term): bool
    {
        return self::position($text, $term) !== null;
    }

    /**
     * Number of whole-word occurrences.
     */
    public static function count(string $text, string $term): int
    {
        if ($term === '' || $text === '') {
            return 0;
        }

        return (int) preg_match_all(self::pattern($term), $text);
    }

    private static function pattern(string $term): string
    {
        return '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
    }
}
