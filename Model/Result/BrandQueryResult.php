<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Result;

/**
 * One provider/prompt cell, aggregated over N repeated samples.
 *
 * A single call to a language model is not reproducible, so 4.0.0 repeats each
 * prompt and stores the mean score together with the half-width of its
 * confidence interval. A change smaller than the margin is noise.
 */
final class BrandQueryResult
{
    /**
     * @param string $providerId Provider identifier.
     * @param string $providerLabel Human-readable provider and model.
     * @param string $promptKey Prompt identifier.
     * @param string $prompt Prompt text that was sent.
     * @param int $samples Number of successful samples behind these numbers.
     * @param int $score Mean score across samples, 0-100.
     * @param float $scoreMargin Half-width of the 95% confidence interval.
     * @param array<string, float> $signalRates Share of samples where each signal fired, 0-100.
     * @param array<string, mixed> $meta Aggregated analyser metadata.
     * @param string[] $responses Raw answers, newest first.
     * @param string|null $errorMessage Failure reason when every sample failed.
     */
    public function __construct(
        public readonly string $providerId,
        public readonly string $providerLabel,
        public readonly string $promptKey,
        public readonly string $prompt,
        public readonly int $samples = 0,
        public readonly int $score = 0,
        public readonly float $scoreMargin = 0.0,
        public readonly array $signalRates = [],
        public readonly array $meta = [],
        public readonly array $responses = [],
        public readonly ?string $errorMessage = null
    ) {
    }

    /**
     * Build a failed cell.
     *
     * @param string $providerId Provider identifier.
     * @param string $providerLabel Human-readable provider and model.
     * @param string $promptKey Prompt identifier.
     * @param string $prompt Prompt text that was sent.
     * @param string $message Failure reason.
     * @return self
     */
    public static function error(
        string $providerId,
        string $providerLabel,
        string $promptKey,
        string $prompt,
        string $message
    ): self {
        return new self($providerId, $providerLabel, $promptKey, $prompt, 0, 0, 0.0, [], [], [], $message);
    }

    /**
     * Whether at least one sample succeeded.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->errorMessage === null && $this->samples > 0;
    }

    /**
     * Boolean view of a signal: true when it fired in at least half the samples.
     *
     * @param string $signal Signal identifier.
     * @return bool
     */
    public function hasSignal(string $signal): bool
    {
        return ($this->signalRates[$signal] ?? 0.0) >= 50.0;
    }

    /**
     * All signals as booleans, for grids and exports.
     *
     * @return array<string, bool>
     */
    public function getSignals(): array
    {
        $signals = [];
        foreach ($this->signalRates as $signal => $rate) {
            $signals[$signal] = $rate >= 50.0;
        }

        return $signals;
    }

    /**
     * Representative answer text.
     *
     * @return string
     */
    public function getRawResponse(): string
    {
        return $this->responses[0] ?? '';
    }

    /**
     * Tone recorded for this cell.
     *
     * @return string
     */
    public function getTone(): string
    {
        $tone = $this->meta['tone'] ?? 'neutral';

        return is_string($tone) ? $tone : 'neutral';
    }
}
