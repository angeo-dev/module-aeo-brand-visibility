<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Result;

/**
 * Aggregated report across every provider and prompt of one run.
 */
final class BrandVisibilityReport
{
    private const GRADE_THRESHOLDS = ['A' => 90, 'B' => 75, 'C' => 60, 'D' => 40];

    /**
     * @param string $brandName Brand the run was about.
     * @param string $brandDomain Brand domain the run was about.
     * @param BrandQueryResult[] $results One cell per provider and prompt.
     * @param \DateTimeImmutable $generatedAt Completion timestamp.
     * @param int $samples Repeats requested per cell.
     * @param bool $fromCache Whether the report came from the cache.
     */
    public function __construct(
        public readonly string $brandName,
        public readonly string $brandDomain,
        public readonly array $results,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly int $samples = 1,
        public readonly bool $fromCache = false
    ) {
    }

    /**
     * Whether any cell produced usable data.
     *
     * @return bool
     */
    public function hasData(): bool
    {
        return $this->successfulResults() !== [];
    }

    /**
     * Mean score across successful cells, 0-100.
     *
     * @return int
     */
    public function getOverallScore(): int
    {
        $successful = $this->successfulResults();
        if ($successful === []) {
            return 0;
        }

        return (int) round(
            array_sum(array_map(static fn(BrandQueryResult $r): int => $r->score, $successful))
            / count($successful)
        );
    }

    /**
     * Half-width of the confidence interval around the overall score.
     *
     * @return float
     */
    public function getScoreMargin(): float
    {
        $successful = $this->successfulResults();
        if ($successful === []) {
            return 0.0;
        }

        $margins = array_map(static fn(BrandQueryResult $r): float => $r->scoreMargin, $successful);

        return round(array_sum($margins) / count($successful), 1);
    }

    /**
     * Letter grade for the overall score.
     *
     * @return string
     */
    public function getGrade(): string
    {
        $score = $this->getOverallScore();
        foreach (self::GRADE_THRESHOLDS as $grade => $threshold) {
            if ($score >= $threshold) {
                return $grade;
            }
        }

        return 'F';
    }

    /**
     * Share of successful cells where a signal fired, 0-100.
     *
     * @param string $signal Signal identifier.
     * @return float
     */
    public function signalRate(string $signal): float
    {
        $successful = $this->successfulResults();
        if ($successful === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($successful as $result) {
            $sum += $result->signalRates[$signal] ?? 0.0;
        }

        return round($sum / count($successful), 1);
    }

    /**
     * Cells with at least one successful sample.
     *
     * @return BrandQueryResult[]
     */
    public function successfulResults(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn(BrandQueryResult $r): bool => $r->isSuccess()
        ));
    }

    /**
     * Cells where every sample failed.
     *
     * @return BrandQueryResult[]
     */
    public function failedResults(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn(BrandQueryResult $r): bool => !$r->isSuccess()
        ));
    }

    /**
     * Cells grouped by provider identifier.
     *
     * @return array<string, BrandQueryResult[]>
     */
    public function resultsByProvider(): array
    {
        $grouped = [];
        foreach ($this->results as $result) {
            $grouped[$result->providerId][] = $result;
        }

        return $grouped;
    }

    /**
     * Mean score per provider, null when the provider produced no data.
     *
     * @return array<string, int|null>
     */
    public function scoreByProvider(): array
    {
        $scores = [];
        foreach ($this->resultsByProvider() as $providerId => $results) {
            $successful = array_filter($results, static fn(BrandQueryResult $r): bool => $r->isSuccess());
            $scores[$providerId] = $successful === []
                ? null
                : (int) round(
                    array_sum(array_map(static fn(BrandQueryResult $r): int => $r->score, $successful))
                    / count($successful)
                );
        }

        return $scores;
    }

    /**
     * Mean share of voice across successful cells, 0-100.
     *
     * @return float
     */
    public function averageShareOfVoice(): float
    {
        $successful = $this->successfulResults();
        if ($successful === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($successful as $result) {
            $sum += (float) ($result->meta['share_of_voice'] ?? 0.0);
        }

        return round($sum / count($successful), 1);
    }

    /**
     * Share of successful cells where the brand was named before every competitor.
     *
     * @return float
     */
    public function winRate(): float
    {
        $successful = $this->successfulResults();
        if ($successful === []) {
            return 0.0;
        }

        $wins = count(array_filter(
            $successful,
            static fn(BrandQueryResult $r): bool => !empty($r->meta['brand_is_winner'])
        ));

        return round($wins / count($successful) * 100, 1);
    }

    /**
     * Competitor name to number of cells where they appeared, most frequent first.
     *
     * @return array<string, int>
     */
    public function competitorMentionCounts(): array
    {
        $counts = [];
        foreach ($this->successfulResults() as $result) {
            foreach ((array) ($result->meta['competitors_found'] ?? []) as $name) {
                $key = (string) $name;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * Number of successful cells with a flagged accuracy issue.
     *
     * @return int
     */
    public function accuracyIssueCount(): int
    {
        $count = 0;
        foreach ($this->successfulResults() as $result) {
            if (!empty($result->meta['accuracy']['has_issue'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count of successful cells per tone value.
     *
     * @return array<string, int>
     */
    public function toneCounts(): array
    {
        $counts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        foreach ($this->successfulResults() as $result) {
            $tone = $result->getTone();
            if (isset($counts[$tone])) {
                $counts[$tone]++;
            }
        }

        return $counts;
    }
}
