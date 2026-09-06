<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\AuditResultRepository;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\ProviderResponse;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Angeo\AeoBrandVisibility\Service\Analysis\PhrasePack;
use Angeo\AeoBrandVisibility\Service\ResponseAnalyzer;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2.0.0 feature tests: repeats aggregation (median score, majority signals),
 * share of voice, structured citations feeding url_cited.
 * Uses the REAL analyzer — signal math is the subject under test.
 */
class BrandVisibilityV2Test extends TestCase
{
    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private Config $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getBrandName')->willReturn('Angeo');
        $this->config->method('getBrandDomain')->willReturn('angeo.dev');
        $this->config->method('getBrandKeywords')->willReturn([]);
        $this->config->method('getAnalysisLanguages')->willReturn([]);
        $this->config->method('getSystemPrompt')->willReturn('system');
        $this->config->method('isLogEnabled')->willReturn(false);
        $this->config->method('getDelayBetweenQueriesMs')->willReturn(0);
        $this->config->method('getCacheTtlHours')->willReturn(0);
        $this->config->method('getActivePrompts')->willReturn(['brand_direct' => 'tpl']);
        $this->config->method('buildPrompt')->willReturn('prompt text');
        $this->config->method('getCompetitors')->willReturn([
            ['name' => 'RivalShop', 'domain' => 'rivalshop.com'],
        ]);
        $this->config->method('getScoringWeight')->willReturn(1.0);
        $this->config->method('getPassThreshold')->willReturn(60);
        $this->config->method('getWarnThreshold')->willReturn(30);
    }

    private function makeService(AiProviderInterface $provider, int $repeats): BrandVisibilityService
    {
        $this->config->method('getRepeatsPerPrompt')->willReturn($repeats);

        return new BrandVisibilityService(
            config:     $this->config,
            analyzer:   new ResponseAnalyzer($this->config, new PhrasePack()),
            cache:      $this->createMock(CacheInterface::class),
            json:       new Json(),
            logger:     $this->createMock(LoggerInterface::class),
            repository: $this->createMock(AuditResultRepository::class),
            providers:  [$provider],
        );
    }

    /**
     * @param ProviderResponse[] $responses consecutive answers
     */
    private function stubProvider(array $responses): AiProviderInterface
    {
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('getProviderId')->willReturn('stub');
        $provider->method('getProviderLabel')->willReturn('Stub (test-model)');
        $provider->method('supportsGrounding')->willReturn(false);
        $provider->method('isGrounded')->willReturn(false);
        $provider->method('query')->willReturnOnConsecutiveCalls(...$responses);
        return $provider;
    }

    // ── Repeats aggregation ──────────────────────────────────────────────

    public function testMedianScoreAcrossRepeats(): void
    {
        // 3 attempts: brand mentioned in 2, absent in 1 → majority keeps signals,
        // median score comes from the middle attempt.
        $provider = $this->stubProvider([
            new ProviderResponse('I recommend Angeo — an excellent store at angeo.dev.'),
            new ProviderResponse('There are many stores. ClayWorld is decent.'),
            new ProviderResponse('Angeo (angeo.dev) is a trusted option.'),
        ]);

        $report = $this->makeService($provider, 3)->run(forceRefresh: true);
        $result = $report->results[0];

        $this->assertSame(3, $result->attempts);
        $this->assertTrue(
            $result->signals['mentioned'],
            'Mentioned in 2 of 3 attempts → majority vote must keep it true'
        );
        $this->assertGreaterThan(0, $result->score);
    }

    public function testAllAttemptsFailingYieldsErrorResult(): void
    {
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('getProviderId')->willReturn('stub');
        $provider->method('getProviderLabel')->willReturn('Stub');
        $provider->method('query')->willThrowException(new \RuntimeException('rate limited'));

        $report = $this->makeService($provider, 2)->run(forceRefresh: true);

        $this->assertFalse($report->results[0]->isSuccess());
        $this->assertStringContainsString('rate limited', (string) $report->results[0]->errorMessage);
    }

    public function testOneFailedAttemptDoesNotPoisonTheRest(): void
    {
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('getProviderId')->willReturn('stub');
        $provider->method('getProviderLabel')->willReturn('Stub');
        $provider->method('query')->willReturnCallback(function (): ProviderResponse {
            static $call = 0;
            $call++;
            if ($call === 1) {
                throw new \RuntimeException('transient timeout');
            }
            return new ProviderResponse('Angeo is worth a look — angeo.dev.');
        });

        $report = $this->makeService($provider, 3)->run(forceRefresh: true);
        $result = $report->results[0];

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->attempts, 'Only successful attempts are aggregated');
    }

    // ── Share of voice ───────────────────────────────────────────────────

    public function testCompetitorMentionsFeedShareOfVoice(): void
    {
        $provider = $this->stubProvider([
            new ProviderResponse(
                'Top options: RivalShop (rivalshop.com) is the market leader; Angeo is also solid.'
            ),
        ]);

        $report = $this->makeService($provider, 1)->run(forceRefresh: true);
        $sov    = $report->shareOfVoice();

        $this->assertSame(100.0, $sov['RivalShop']);
        $this->assertSame(100.0, $sov['Angeo']);
        $this->assertTrue($report->results[0]->competitorMentions['RivalShop']);
        $this->assertContains('rivalshop.com', $report->results[0]->citedDomains);
    }

    public function testCompetitorAbsentInAnswers(): void
    {
        $provider = $this->stubProvider([
            new ProviderResponse('Angeo (angeo.dev) is the store I would suggest.'),
        ]);

        $report = $this->makeService($provider, 1)->run(forceRefresh: true);

        $this->assertFalse($report->results[0]->competitorMentions['RivalShop']);
        $this->assertSame(0.0, $report->shareOfVoice()['RivalShop']);
    }

    // ── Structured citations ─────────────────────────────────────────────

    public function testStructuredCitationOfOwnDomainCountsAsUrlCited(): void
    {
        // Domain appears ONLY in structured citations, not in prose.
        $provider = $this->stubProvider([
            new ProviderResponse(
                'Angeo is a well-known ceramics store.',
                ['https://angeo.dev/collections/ceramics'],
                true
            ),
        ]);

        $report = $this->makeService($provider, 1)->run(forceRefresh: true);
        $result = $report->results[0];

        $this->assertTrue($result->signals['url_cited'], 'Citation URL must feed url_cited');
        $this->assertTrue($result->grounded);
        $this->assertSame(['grounded' => 1, 'recall' => 0], $report->groundingBreakdown());
    }
}
