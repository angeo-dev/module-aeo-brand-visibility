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
        // Store view this report was produced for (since 3.0.0). null = default
        // scope / all-stores aggregate.
        public readonly ?int               $storeId = null,
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

    /**
     * Share of voice across successful results (since 2.0.0): for the own
     * brand and every watched competitor, the percentage of answers that
     * mention or cite them. This is the number that gives the absolute
     * score meaning — "40/100" says little; "you appear in 20% of answers,
     * competitor X in 80%" names the actual problem.
     *
     * @return array<string, float> display name => 0..100
     */
    public function shareOfVoice(): array
    {
        $ok = $this->successfulResults();
        if ($ok === []) {
            return [];
        }

        $sov = [$this->brandName => $this->signalRate('mentioned')];

        $counts = [];
        foreach ($ok as $result) {
            foreach ($result->competitorMentions as $name => $hit) {
                $counts[$name] = ($counts[$name] ?? 0) + ($hit ? 1 : 0);
            }
        }
        foreach ($counts as $name => $hits) {
            $sov[$name] = round($hits / count($ok) * 100, 1);
        }

        arsort($sov);
        return $sov;
    }

    /**
     * How many successful results were produced with live web access vs
     * training recall (since 2.0.0). The two modes measure different things;
     * the report surfaces the mix instead of hiding it.
     *
     * @return array{grounded: int, recall: int}
     */
    public function groundingBreakdown(): array
    {
        $grounded = 0;
        $recall   = 0;
        foreach ($this->successfulResults() as $result) {
            $result->grounded ? $grounded++ : $recall++;
        }
        return ['grounded' => $grounded, 'recall' => $recall];
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
