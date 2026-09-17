<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service\Analysis;

use Angeo\AeoBrandVisibility\Service\Analysis\SampleAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Covers averaging, the confidence margin and metadata merging.
 */
class SampleAggregatorTest extends TestCase
{
    private SampleAggregator $aggregator;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->aggregator = new SampleAggregator();
    }

    /**
     * Identical samples must produce a zero margin.
     *
     * @return void
     */
    public function testIdenticalSamplesHaveNoMargin(): void
    {
        $result = $this->aggregator->aggregate('claude', 'Claude', 'brand_direct', 'prompt', [
            $this->sample(80),
            $this->sample(80),
            $this->sample(80),
        ]);

        $this->assertSame(80, $result->score);
        $this->assertSame(0.0, $result->scoreMargin);
        $this->assertSame(3, $result->samples);
    }

    /**
     * Spread between samples must widen the margin.
     *
     * @return void
     */
    public function testSpreadWidensMargin(): void
    {
        $result = $this->aggregator->aggregate('claude', 'Claude', 'brand_direct', 'prompt', [
            $this->sample(40),
            $this->sample(70),
            $this->sample(100),
        ]);

        $this->assertSame(70, $result->score);
        $this->assertGreaterThan(10.0, $result->scoreMargin);
    }

    /**
     * A signal that fires in one of two samples must be reported as 50 per cent.
     *
     * @return void
     */
    public function testSignalRatesArePercentages(): void
    {
        $result = $this->aggregator->aggregate('groq', 'Groq', 'category', 'prompt', [
            $this->sample(60, ['mentioned' => true, 'url_cited' => true]),
            $this->sample(40, ['mentioned' => true, 'url_cited' => false]),
        ]);

        $this->assertSame(100.0, $result->signalRates['mentioned']);
        $this->assertSame(50.0, $result->signalRates['url_cited']);
        $this->assertTrue($result->hasSignal('url_cited'));
    }

    /**
     * No samples must produce a failed cell rather than a zero score.
     *
     * @return void
     */
    public function testEmptySampleSetProducesError(): void
    {
        $result = $this->aggregator->aggregate('gemini', 'Gemini', 'category', 'prompt', []);

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->errorMessage);
    }

    /**
     * Win rate must be derived from the share of winning samples.
     *
     * @return void
     */
    public function testWinRateReflectsMajority(): void
    {
        $result = $this->aggregator->aggregate('claude', 'Claude', 'category', 'prompt', [
            $this->sample(70, [], ['brand_is_winner' => true, 'winner' => 'Us']),
            $this->sample(70, [], ['brand_is_winner' => true, 'winner' => 'Us']),
            $this->sample(30, [], ['brand_is_winner' => false, 'winner' => 'Them']),
        ]);

        $this->assertEqualsWithDelta(66.7, $result->meta['win_rate'], 0.1);
        $this->assertTrue($result->meta['brand_is_winner']);
    }

    /**
     * Build one sample payload.
     *
     * @param int $score Sample score.
     * @param array<string, bool> $signals Signal flags.
     * @param array<string, mixed> $meta Extra metadata.
     * @return array<string, mixed>
     */
    private function sample(int $score, array $signals = [], array $meta = []): array
    {
        return [
            'score' => $score,
            'signals' => $signals + ['mentioned' => true],
            'meta' => $meta + ['tone' => 'neutral', 'share_of_voice' => 50.0],
            'response' => 'answer',
        ];
    }
}
