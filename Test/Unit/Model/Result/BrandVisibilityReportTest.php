<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Model\Result;

use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use PHPUnit\Framework\TestCase;

/**
 * Covers aggregation across cells.
 */
class BrandVisibilityReportTest extends TestCase
{
    /**
     * Failed cells must not drag the average down.
     *
     * @return void
     */
    public function testFailedCellsAreExcludedFromTheAverage(): void
    {
        $report = $this->report([
            $this->cell(80),
            $this->cell(60),
            BrandQueryResult::error('groq', 'Groq', 'category', 'p', 'timeout'),
        ]);

        $this->assertSame(70, $report->getOverallScore());
        $this->assertCount(1, $report->failedResults());
    }

    /**
     * A report without a single successful cell must score zero and report no data.
     *
     * @return void
     */
    public function testEmptyReportScoresZero(): void
    {
        $report = $this->report([BrandQueryResult::error('groq', 'Groq', 'category', 'p', 'timeout')]);

        $this->assertFalse($report->hasData());
        $this->assertSame(0, $report->getOverallScore());
        $this->assertSame('F', $report->getGrade());
    }

    /**
     * Grades must follow the documented thresholds.
     *
     * @param int $score Cell score.
     * @param string $expected Expected grade.
     * @return void
     * @dataProvider gradeProvider
     */
    public function testGradeThresholds(int $score, string $expected): void
    {
        $this->assertSame($expected, $this->report([$this->cell($score)])->getGrade());
    }

    /**
     * Score to grade pairs.
     *
     * @return array<int, array{int, string}>
     */
    public static function gradeProvider(): array
    {
        return [[95, 'A'], [80, 'B'], [65, 'C'], [45, 'D'], [10, 'F']];
    }

    /**
     * Win rate must count only successful cells.
     *
     * @return void
     */
    public function testWinRateCountsSuccessfulCellsOnly(): void
    {
        $report = $this->report([
            $this->cell(80, ['brand_is_winner' => true]),
            $this->cell(40, ['brand_is_winner' => false]),
            BrandQueryResult::error('groq', 'Groq', 'category', 'p', 'timeout'),
        ]);

        $this->assertSame(50.0, $report->winRate());
    }

    /**
     * Build one successful cell.
     *
     * @param int $score Cell score.
     * @param array<string, mixed> $meta Cell metadata.
     * @return BrandQueryResult
     */
    private function cell(int $score, array $meta = []): BrandQueryResult
    {
        return new BrandQueryResult(
            'claude',
            'Claude',
            'brand_direct',
            'prompt',
            3,
            $score,
            2.5,
            ['mentioned' => 100.0],
            $meta + ['tone' => 'neutral', 'share_of_voice' => 40.0],
            ['answer']
        );
    }

    /**
     * Build a report from cells.
     *
     * @param BrandQueryResult[] $cells Report cells.
     * @return BrandVisibilityReport
     */
    private function report(array $cells): BrandVisibilityReport
    {
        return new BrandVisibilityReport('Brand', 'brand.nl', $cells, new \DateTimeImmutable(), 3);
    }
}
