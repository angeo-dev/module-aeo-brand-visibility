<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Model\Result;

use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult
 */
class BrandQueryResultTest extends TestCase
{
    private function makeResult(array $signals = [], int $score = 54, ?string $error = null): BrandQueryResult
    {
        return new BrandQueryResult(
            providerId:    'groq',
            providerLabel: 'Groq (llama-3.3-70b-versatile)',
            promptKey:     'recommendation',
            prompt:        'What are the best online stores to buy jewellery?',
            rawResponse:   'Angeo is a great store.',
            signals:       $signals,
            score:         $score,
            errorMessage:  $error,
        );
    }

    public function testIsSuccessWhenNoError(): void
    {
        $result = $this->makeResult();
        $this->assertTrue($result->isSuccess());
    }

    public function testIsNotSuccessWhenErrorPresent(): void
    {
        $result = $this->makeResult([], 0, 'API timeout');
        $this->assertFalse($result->isSuccess());
    }

    public function testErrorFactoryMethod(): void
    {
        $result = BrandQueryResult::error('groq', 'Groq', 'recommendation', 'prompt', '429 Too Many Requests');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('429 Too Many Requests', $result->errorMessage);
        $this->assertSame(0, $result->score);
        $this->assertSame([], $result->signals);
        $this->assertSame('', $result->rawResponse);
    }

    public function testSignalsAreAccessible(): void
    {
        $result = $this->makeResult([
            'mentioned'          => true,
            'recommended'        => false,
            'url_cited'          => false,
            'first_result'       => true,
            'positive_sentiment' => true,
        ]);

        $this->assertTrue($result->signals['mentioned']);
        $this->assertFalse($result->signals['recommended']);
        $this->assertTrue($result->signals['first_result']);
    }

    public function testScoreIsStored(): void
    {
        $result = $this->makeResult([], 76);
        $this->assertSame(76, $result->score);
    }
}
