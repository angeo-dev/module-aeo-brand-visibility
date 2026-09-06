<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Result;

/**
 * Immutable result for one AI model × one prompt query.
 *
 * 2.0.0 additions:
 *   citations          — structured source URLs the provider returned
 *   grounded           — whether the answer used live web access
 *   competitorMentions — competitor name => was it mentioned/cited
 *   citedDomains       — every domain observed in the answer + citations
 *   attempts           — how many samples this result aggregates (repeats)
 */
final class BrandQueryResult
{
    /**
     * @param array<string, bool> $signals
     * @param string[]            $citations
     * @param array<string, bool> $competitorMentions
     * @param string[]            $citedDomains
     */
    public function __construct(
        public readonly string  $providerId,
        public readonly string  $providerLabel,
        public readonly string  $promptKey,
        public readonly string  $prompt,
        public readonly string  $rawResponse,
        public readonly array   $signals,
        public readonly int     $score,
        public readonly ?string $errorMessage = null,
        public readonly array   $citations = [],
        public readonly bool    $grounded = false,
        public readonly array   $competitorMentions = [],
        public readonly array   $citedDomains = [],
        public readonly int     $attempts = 1,
    ) {}

    public function isSuccess(): bool { return $this->errorMessage === null; }

    public static function error(string $id, string $label, string $key, string $prompt, string $msg): self
    {
        return new self($id, $label, $key, $prompt, '', [], 0, $msg);
    }
}
