<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service;

use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Service\RunPayloadBuilder;
use PHPUnit\Framework\TestCase;

class RunPayloadBuilderTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function cells(): array
    {
        return [
            [
                'provider_id' => 'claude', 'provider_label' => 'Claude', 'prompt_key' => 'category_best',
                'samples' => 4, 'score' => 60, 'score_margin' => 8.5,
                'signal_rates' => ['mentioned' => 75.0, 'url_cited' => 25.0],
                'meta' => ['competitor_hits' => ['Acme' => 3, 'Bolt' => 1]],
                'responses' => [str_repeat('x', 1000)],
            ],
            [
                'provider_id' => 'chatgpt', 'provider_label' => 'ChatGPT', 'prompt_key' => 'category_best',
                'samples' => 4, 'score' => 20, 'score_margin' => 4.0,
                'signal_rates' => ['mentioned' => 25.0],
                'meta' => ['competitor_hits' => ['Acme' => 4]],
                'responses' => ['short'],
            ],
            ['provider_id' => 'gemini', 'provider_label' => 'Gemini', 'prompt_key' => 'category_best', 'error' => 'HTTP 429'],
        ];
    }

    private function row(): AuditResultInterface
    {
        $cells = $this->cells();

        return new class ($cells) implements AuditResultInterface {
            /** @param array<int,array<string,mixed>> $cells */
            public function __construct(private readonly array $cells)
            {
            }

            public function getData(string $key = '')
            {
                return [
                    'id' => '17', 'created_at' => '2026-11-08 10:00:00', 'brand_name' => 'Demo', 'brand_domain' => 'demo.example',
                    'score_margin' => '5.5', 'samples' => '4', 'share_of_voice' => '31.2', 'win_rate' => '12.5', 'accuracy_issues' => '1',
                ][$key] ?? null;
            }

            public function getStatus(): string
            {
                return self::STATUS_COMPLETE;
            }

            public function getOverallScore(): int
            {
                return 40;
            }

            public function getGrade(): string
            {
                return 'D';
            }

            public function getStoreId(): int
            {
                return 1;
            }

            public function getSignalRatesDecoded(): array
            {
                return ['mentioned' => 50.0];
            }

            public function getProviderScoresDecoded(): array
            {
                return ['claude' => 60, 'chatgpt' => 20, 'gemini' => null];
            }

            public function getResultsDecoded(): array
            {
                return $this->cells;
            }
        };
    }

    public function testBuildKeepsTheStatusShapeAndCutsPreviews(): void
    {
        $run = (new RunPayloadBuilder())->build($this->row(), 300);

        self::assertSame(17, $run['id']);
        self::assertSame('Demo', $run['brand_name']);
        self::assertSame(40, $run['overall_score']);
        self::assertSame(5.5, $run['score_margin']);
        self::assertSame(31.2, $run['competitive']['share_of_voice']);
        self::assertCount(3, $run['results']);
        self::assertSame(300, mb_strlen((string) $run['results'][0]['response']));
        self::assertFalse($run['results'][2]['success']);
        self::assertNull($run['results'][2]['response']);
    }

    public function testNamedCountsUseAnswersAsTheDenominator(): void
    {
        $builder = new RunPayloadBuilder();
        $counts  = $builder->namedCounts($builder->build($this->row())['results']);

        // 4 + 4 answers came back; the failed Gemini cell has none.
        self::assertSame(8, $counts['answers']);
        // 75% of 4 + 25% of 4 = 4 answers name the brand.
        self::assertSame(4, $counts['brand']);
        self::assertSame(['Acme' => 7, 'Bolt' => 1], $counts['competitors']);
    }
}
