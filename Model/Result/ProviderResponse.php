<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Result;

/**
 * Structured answer from an AI provider (since 2.0.0).
 *
 * Before 2.0 providers returned a bare string, which forced Perplexity to
 * smuggle its citations into the text as a "Sources: …" suffix — conflating
 * "brand appears in the answer" with "brand is a cited source". These are
 * different signals with different weight: a citation is the answer engine
 * saying "this is where I got it", the strongest visibility outcome there is.
 *
 * grounded  — whether this answer was produced with live web access
 *             (Perplexity always; ChatGPT/Claude/Gemini when the admin
 *             enables grounded mode). Training-recall answers and live-search
 *             answers measure different things and must never be averaged
 *             silently — the report keeps the flag per result.
 * citations — URLs the provider returned as structured sources (not URLs
 *             merely written in prose; those are found by the analyzer).
 */
final class ProviderResponse
{
    /** @param string[] $citations */
    public function __construct(
        public readonly string $text,
        public readonly array  $citations = [],
        public readonly bool   $grounded = false,
    ) {
    }
}
