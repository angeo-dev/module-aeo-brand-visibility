<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\ProviderResponse;
use Angeo\AeoBrandVisibility\Service\SentimentJudge;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SentimentJudgeTest extends TestCase
{
    private function judgeWith(?AiProviderInterface $provider): SentimentJudge
    {
        return new SentimentJudge(
            $this->createMock(Config::class),
            new Json(),
            $this->createMock(LoggerInterface::class),
            $provider === null ? [] : [$provider],
        );
    }

    private function provider(callable $queryBehaviour): AiProviderInterface
    {
        $p = $this->createMock(AiProviderInterface::class);
        $p->method('isConfigured')->willReturn(true);
        $p->method('getProviderId')->willReturn('groq');
        $p->method('query')->willReturnCallback($queryBehaviour);
        return $p;
    }

    public function testPositiveVerdict(): void
    {
        $judge = $this->judgeWith($this->provider(
            fn() => new ProviderResponse('{"positive": true}')
        ));

        $this->assertTrue($judge->isPositive('Great store, highly recommended.', 'Angeo'));
    }

    public function testNegativeVerdict(): void
    {
        $judge = $this->judgeWith($this->provider(
            fn() => new ProviderResponse('Here is my classification: {"positive": false}')
        ));

        $this->assertFalse($judge->isPositive('Not the best option out there.', 'Angeo'));
    }

    public function testUnparseableVerdictReturnsNull(): void
    {
        $judge = $this->judgeWith($this->provider(
            fn() => new ProviderResponse('I am not sure how to classify this.')
        ));

        $this->assertNull($judge->isPositive('Ambiguous text.', 'Angeo'));
    }

    public function testProviderExceptionReturnsNull(): void
    {
        $judge = $this->judgeWith($this->provider(
            fn() => throw new \RuntimeException('rate limited')
        ));

        $this->assertNull($judge->isPositive('Some answer.', 'Angeo'));
    }

    public function testNoProviderReturnsNull(): void
    {
        $judge = $this->judgeWith(null);

        $this->assertNull($judge->isPositive('Some answer.', 'Angeo'));
    }

    public function testEmptyBrandReturnsNull(): void
    {
        $judge = $this->judgeWith($this->provider(
            fn() => new ProviderResponse('{"positive": true}')
        ));

        $this->assertNull($judge->isPositive('Some answer.', ''));
    }
}
