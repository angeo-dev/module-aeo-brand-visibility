<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service\Analysis;

use Angeo\AeoBrandVisibility\Service\Analysis\ShareOfVoiceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mention- and position-weighted share of voice.
 */
class ShareOfVoiceCalculatorTest extends TestCase
{
    private ShareOfVoiceCalculator $calculator;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->calculator = new ShareOfVoiceCalculator();
    }

    /**
     * More mentions must earn a larger share.
     *
     * @return void
     */
    public function testMoreMentionsEarnLargerShare(): void
    {
        $shares = $this->calculator->calculate([
            ['name' => 'Us', 'mentions' => 4, 'first_position' => 10],
            ['name' => 'Them', 'mentions' => 1, 'first_position' => 10],
        ], 1000);

        $this->assertGreaterThan($shares['Them'], $shares['Us']);
    }

    /**
     * An earlier first mention must beat a later one at equal mention counts.
     *
     * @return void
     */
    public function testEarlierMentionWinsAtEqualCounts(): void
    {
        $shares = $this->calculator->calculate([
            ['name' => 'Us', 'mentions' => 2, 'first_position' => 0],
            ['name' => 'Them', 'mentions' => 2, 'first_position' => 900],
        ], 1000);

        $this->assertGreaterThan($shares['Them'], $shares['Us']);
    }

    /**
     * Shares must add up to one hundred per cent.
     *
     * @return void
     */
    public function testSharesSumToOneHundred(): void
    {
        $shares = $this->calculator->calculate([
            ['name' => 'A', 'mentions' => 3, 'first_position' => 5],
            ['name' => 'B', 'mentions' => 2, 'first_position' => 300],
            ['name' => 'C', 'mentions' => 1, 'first_position' => 800],
        ], 1000);

        $this->assertEqualsWithDelta(100.0, array_sum($shares), 0.3);
    }

    /**
     * A brand with no mentions must not appear at all.
     *
     * @return void
     */
    public function testUnmentionedBrandIsExcluded(): void
    {
        $shares = $this->calculator->calculate([
            ['name' => 'Us', 'mentions' => 0, 'first_position' => 0],
            ['name' => 'Them', 'mentions' => 2, 'first_position' => 10],
        ], 500);

        $this->assertArrayNotHasKey('Us', $shares);
        $this->assertSame(100.0, $shares['Them']);
    }
}
