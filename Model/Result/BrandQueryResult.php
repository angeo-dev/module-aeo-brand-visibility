<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Result;

/** Immutable result for one AI model × one prompt query. */
final class BrandQueryResult
{
    /** @param array<string, bool> $signals */
    public function __construct(
        public readonly string  $providerId,
        public readonly string  $providerLabel,
        public readonly string  $promptKey,
        public readonly string  $prompt,
        public readonly string  $rawResponse,
        public readonly array   $signals,
        public readonly int     $score,
        public readonly ?string $errorMessage = null,
    ) {}

    public function isSuccess(): bool { return $this->errorMessage === null; }

    public static function error(string $id, string $label, string $key, string $prompt, string $msg): self
    {
        return new self($id, $label, $key, $prompt, '', [], 0, $msg);
    }
}
