<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Model\Result;

use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport
 */
class BrandVisibilityReportTest extends TestCase
{
    private function makeResult(
        string $providerId,
        int $score,
        array $signals = [],
        ?string $error = null
    ): BrandQueryResult {
        return new BrandQueryResult(
            providerId:    $providerId,
            providerLabel: ucfirst($providerId),
            promptKey:     'recommendation',
            prompt:        'test prompt',
            rawResponse:   $error ? '' : 'response text',
            signals:       $signals,
            score:         $score,
            errorMessage:  $error,
        );
    }

    private function makeReport(array $results, string $brand = 'Angeo'): BrandVisibilityReport
    {
        return new BrandVisibilityReport(
            brandName:   $brand,
            brandDomain: 'angeo.dev',
            results:     $results,
            generatedAt: new \DateTimeImmutable('2026-05-16 12:00:00'),
            fromCache:   false,
        );
    }

    // ── getOverallScore ──────────────────────────────────────────────────────

    public function testOverallScoreIsAverageOfSuccessfulResults(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 60),
            $this->makeResult('groq', 80),
        ]);

        $this->assertSame(70, $report->getOverallScore());
    }

    public function testOverallScoreIsZeroWhenNoSuccessfulResults(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 0, [], 'API error'),
        ]);

        $this->assertSame(0, $report->getOverallScore());
    }

    public function testFailedResultsExcludedFromScore(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 80),
            $this->makeResult('groq', 0, [], 'timeout'),
        ]);

        // Only the successful result (80) counts
        $this->assertSame(80, $report->getOverallScore());
    }

    public function testEmptyResultsGiveScoreZero(): void
    {
        $report = $this->makeReport([]);
        $this->assertSame(0, $report->getOverallScore());
    }

    // ── getGrade ────────────────────────────────────────────────────────────

    /** @dataProvider gradeProvider */
    public function testGradeThresholds(int $score, string $expectedGrade): void
    {
        // Create results that produce the target score
        $result = $this->makeResult('groq', $score);
        $report = $this->makeReport([$result]);
        $this->assertSame($expectedGrade, $report->getGrade());
    }

    public static function gradeProvider(): array
    {
        return [
            [95,  'A'],
            [90,  'A'],
            [89,  'B'],
            [75,  'B'],
            [74,  'C'],
            [60,  'C'],
            [59,  'D'],
            [40,  'D'],
            [39,  'F'],
            [18,  'F'],
            [0,   'F'],
        ];
    }

    // ── signalRate ───────────────────────────────────────────────────────────

    public function testSignalRateCalculation(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 54, ['mentioned' => true,  'recommended' => false]),
            $this->makeResult('groq', 54, ['mentioned' => true,  'recommended' => false]),
            $this->makeResult('groq', 54, ['mentioned' => false, 'recommended' => true]),
        ]);

        $this->assertSame(66.7, $report->signalRate('mentioned'));
        $this->assertSame(33.3, $report->signalRate('recommended'));
    }

    public function testSignalRateIsZeroForUnknownSignal(): void
    {
        $report = $this->makeReport([$this->makeResult('groq', 50)]);
        $this->assertSame(0.0, $report->signalRate('nonexistent_signal'));
    }

    public function testSignalRateExcludesFailedResults(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 54, ['mentioned' => true]),
            $this->makeResult('groq', 0, [], 'error'),  // failed — excluded
        ]);

        $this->assertSame(100.0, $report->signalRate('mentioned'));
    }

    // ── successfulResults / failedResults ────────────────────────────────────

    public function testSuccessfulResultsFilter(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 54),
            $this->makeResult('claude', 0, [], 'error'),
            $this->makeResult('groq', 60),
        ]);

        $successful = $report->successfulResults();
        $this->assertCount(2, $successful);
        foreach ($successful as $r) {
            $this->assertTrue($r->isSuccess());
        }
    }

    public function testFailedResultsFilter(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 54),
            $this->makeResult('claude', 0, [], 'timeout'),
        ]);

        $failed = $report->failedResults();
        $this->assertCount(1, $failed);
        $this->assertSame('timeout', $failed[0]->errorMessage);
    }

    // ── scoreByProvider ──────────────────────────────────────────────────────

    public function testScoreByProvider(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq',   60),
            $this->makeResult('groq',   80),
            $this->makeResult('claude', 90),
        ]);

        $scores = $report->scoreByProvider();

        $this->assertSame(70, $scores['groq']);
        $this->assertSame(90, $scores['claude']);
    }

    public function testScoreByProviderIsNullWhenAllFailed(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 0, [], 'error'),
        ]);

        $scores = $report->scoreByProvider();
        $this->assertNull($scores['groq']);
    }

    // ── resultsByProvider ────────────────────────────────────────────────────

    public function testResultsGroupedByProvider(): void
    {
        $report = $this->makeReport([
            $this->makeResult('groq', 54),
            $this->makeResult('claude', 72),
            $this->makeResult('groq', 60),
        ]);

        $grouped = $report->resultsByProvider();

        $this->assertArrayHasKey('groq', $grouped);
        $this->assertArrayHasKey('claude', $grouped);
        $this->assertCount(2, $grouped['groq']);
        $this->assertCount(1, $grouped['claude']);
    }

    // ── fromCache flag ───────────────────────────────────────────────────────

    public function testFromCacheFlag(): void
    {
        $report = new BrandVisibilityReport(
            brandName:   'Angeo',
            brandDomain: 'angeo.dev',
            results:     [],
            generatedAt: new \DateTimeImmutable(),
            fromCache:   true,
        );

        $this->assertTrue($report->fromCache);
    }
}
