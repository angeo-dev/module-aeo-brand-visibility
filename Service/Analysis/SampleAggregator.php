<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Analysis;

use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;

/**
 * Turns N repeated samples of one provider/prompt pair into a single cell.
 *
 * Language model answers vary between identical calls, so a one-shot score is
 * not comparable over time. Each cell reports the mean and the half-width of
 * the 95% confidence interval; a movement smaller than the margin is noise.
 */
class SampleAggregator
{
    /**
     * Two-sided 95% t-distribution critical values for small samples, indexed by n.
     */
    private const T_CRITICAL = [
        2 => 12.706,
        3 => 4.303,
        4 => 3.182,
        5 => 2.776,
        6 => 2.571,
        7 => 2.447,
        8 => 2.365,
        9 => 2.306,
        10 => 2.262,
    ];

    private const T_CRITICAL_LARGE = 1.96;
    private const MAX_STORED_RESPONSES = 3;

    /**
     * Combine samples into one aggregated result cell.
     *
     * @param string $providerId Provider identifier.
     * @param string $providerLabel Human-readable provider and model.
     * @param string $promptKey Prompt identifier.
     * @param string $prompt Prompt text that was sent.
     * @param array<int, array{score: int, signals: array<string, bool>, meta: array<string, mixed>,
     *     response: string}> $samples Successful samples.
     * @return BrandQueryResult
     */
    public function aggregate(
        string $providerId,
        string $providerLabel,
        string $promptKey,
        string $prompt,
        array $samples
    ): BrandQueryResult {
        $samples = array_values($samples);
        $count = count($samples);

        if ($count === 0) {
            return BrandQueryResult::error(
                $providerId,
                $providerLabel,
                $promptKey,
                $prompt,
                'No successful samples.'
            );
        }

        $scores = array_map(static fn(array $s): int => $s['score'], $samples);
        $mean = array_sum($scores) / $count;

        return new BrandQueryResult(
            providerId: $providerId,
            providerLabel: $providerLabel,
            promptKey: $promptKey,
            prompt: $prompt,
            samples: $count,
            score: (int) round($mean),
            scoreMargin: $this->confidenceMargin($scores, $mean),
            signalRates: $this->signalRates($samples),
            meta: $this->mergeMeta($samples),
            responses: array_slice(
                array_map(static fn(array $s): string => $s['response'], $samples),
                0,
                self::MAX_STORED_RESPONSES
            )
        );
    }

    /**
     * Half-width of the 95% confidence interval around the mean score.
     *
     * @param int[] $scores Sample scores.
     * @param float $mean Mean score.
     * @return float
     */
    private function confidenceMargin(array $scores, float $mean): float
    {
        $count = count($scores);
        if ($count < 2) {
            return 0.0;
        }

        $sumSquares = 0.0;
        foreach ($scores as $score) {
            $sumSquares += ($score - $mean) ** 2;
        }

        $standardError = sqrt($sumSquares / ($count - 1)) / sqrt($count);
        $critical = self::T_CRITICAL[$count] ?? self::T_CRITICAL_LARGE;

        return round($critical * $standardError, 1);
    }

    /**
     * Share of samples in which each signal fired, 0-100.
     *
     * @param array<int, array<string, mixed>> $samples Successful samples.
     * @return array<string, float>
     */
    private function signalRates(array $samples): array
    {
        $totals = [];
        foreach ($samples as $sample) {
            foreach ((array) $sample['signals'] as $signal => $fired) {
                $totals[$signal] = ($totals[$signal] ?? 0) + ($fired ? 1 : 0);
            }
        }

        $count = count($samples);
        $rates = [];
        foreach ($totals as $signal => $hits) {
            $rates[(string) $signal] = round($hits / $count * 100, 1);
        }

        return $rates;
    }

    /**
     * Merge per-sample metadata into one view of the cell.
     *
     * @param array<int, array<string, mixed>> $samples Successful samples.
     * @return array<string, mixed>
     */
    private function mergeMeta(array $samples): array
    {
        $count = count($samples);
        $competitors = [];
        $shareSum = 0.0;
        $wins = 0;
        $accuracyIssues = [];
        $toneCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $winners = [];

        foreach ($samples as $sample) {
            $meta = (array) $sample['meta'];

            foreach ((array) ($meta['competitors_found'] ?? []) as $name) {
                $competitors[(string) $name] = ($competitors[(string) $name] ?? 0) + 1;
            }

            $shareSum += (float) ($meta['share_of_voice'] ?? 0.0);

            if (!empty($meta['brand_is_winner'])) {
                $wins++;
            }
            if (is_string($meta['winner'] ?? null)) {
                $winners[$meta['winner']] = ($winners[$meta['winner']] ?? 0) + 1;
            }

            $tone = (string) ($meta['tone'] ?? 'neutral');
            if (isset($toneCounts[$tone])) {
                $toneCounts[$tone]++;
            }

            foreach ((array) ($meta['accuracy']['issues'] ?? []) as $issue) {
                $accuracyIssues[] = (string) $issue;
            }
        }

        arsort($competitors);
        arsort($winners);
        $accuracyIssues = array_values(array_unique($accuracyIssues));

        return [
            'competitors_found' => array_keys($competitors),
            'competitor_hits' => $competitors,
            'competitor_count' => count($competitors),
            'share_of_voice' => round($shareSum / $count, 1),
            'winner' => array_key_first($winners),
            'brand_is_winner' => $wins > $count / 2,
            'win_rate' => round($wins / $count * 100, 1),
            'tone' => (string) array_search(max($toneCounts), $toneCounts, true),
            'tone_counts' => $toneCounts,
            'accuracy' => [
                'has_issue' => $accuracyIssues !== [],
                'issues' => $accuracyIssues,
            ],
        ];
    }
}
