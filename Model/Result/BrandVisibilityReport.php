<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Result;

/** Aggregated brand visibility report across all providers × prompts. */
final class BrandVisibilityReport
{
    /** @param BrandQueryResult[] $results */
    public function __construct(
        public readonly string             $brandName,
        public readonly string             $brandDomain,
        public readonly array              $results,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly bool               $fromCache = false,
    ) {}

    public function getOverallScore(): int
    {
        $ok = $this->successfulResults();
        return empty($ok) ? 0 : (int) round(array_sum(array_map(fn($r) => $r->score, $ok)) / count($ok));
    }

    public function getGrade(): string
    {
        return match (true) {
            $this->getOverallScore() >= 90 => 'A',
            $this->getOverallScore() >= 75 => 'B',
            $this->getOverallScore() >= 60 => 'C',
            $this->getOverallScore() >= 40 => 'D',
            default                        => 'F',
        };
    }

    /** Rate (0–100%) of successful results where signal was true */
    public function signalRate(string $signal): float
    {
        $ok = $this->successfulResults();
        if (empty($ok)) return 0.0;
        $positive = count(array_filter($ok, fn($r) => $r->signals[$signal] ?? false));
        return round($positive / count($ok) * 100, 1);
    }

    /** @return BrandQueryResult[] */
    public function successfulResults(): array
    {
        return array_values(array_filter($this->results, fn($r) => $r->isSuccess()));
    }

    /** @return BrandQueryResult[] */
    public function failedResults(): array
    {
        return array_values(array_filter($this->results, fn($r) => !$r->isSuccess()));
    }

    /** Results grouped by provider */
    public function resultsByProvider(): array
    {
        $grouped = [];
        foreach ($this->results as $r) {
            $grouped[$r->providerId][] = $r;
        }
        return $grouped;
    }

    /** Average score per provider */
    public function scoreByProvider(): array
    {
        $scores = [];
        foreach ($this->resultsByProvider() as $id => $results) {
            $ok = array_filter($results, fn($r) => $r->isSuccess());
            $scores[$id] = empty($ok)
                ? null
                : (int) round(array_sum(array_map(fn($r) => $r->score, $ok)) / count($ok));
        }
        return $scores;
    }
}
