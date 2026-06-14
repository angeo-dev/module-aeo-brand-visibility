<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service;

use Angeo\AeoBrandVisibility\Model\AuditResultRepository;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Angeo\AeoBrandVisibility\Service\Provider\ChatGptProvider;
use Angeo\AeoBrandVisibility\Service\Provider\ClaudeProvider;
use Angeo\AeoBrandVisibility\Service\Provider\GeminiProvider;
use Angeo\AeoBrandVisibility\Service\Provider\GroqProvider;
use Angeo\AeoBrandVisibility\Service\Provider\PerplexityProvider;
use Angeo\AeoBrandVisibility\Service\ResponseAnalyzer;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \Angeo\AeoBrandVisibility\Service\BrandVisibilityService
 */
class BrandVisibilityServiceTest extends TestCase
{
    private Config&MockObject $config;
    private AuditResultRepository&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private CacheInterface&MockObject $cache;
    private ResponseAnalyzer&MockObject $analyzer;
    private ChatGptProvider&MockObject $chatGpt;
    private ClaudeProvider&MockObject $claude;
    private PerplexityProvider&MockObject $perplexity;
    private GeminiProvider&MockObject $gemini;
    private GroqProvider&MockObject $groq;
    private Json $json;

    protected function setUp(): void
    {
        $this->config     = $this->createMock(Config::class);
        $this->repository = $this->createMock(AuditResultRepository::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->cache      = $this->createMock(CacheInterface::class);
        $this->analyzer   = $this->createMock(ResponseAnalyzer::class);
        $this->chatGpt    = $this->createMock(ChatGptProvider::class);
        $this->claude     = $this->createMock(ClaudeProvider::class);
        $this->perplexity = $this->createMock(PerplexityProvider::class);
        $this->gemini     = $this->createMock(GeminiProvider::class);
        $this->groq       = $this->createMock(GroqProvider::class);
        // Use the real serializer — pure class, no infra dependency.
        $this->json       = new Json();

        // Sensible defaults; individual tests override as needed.
        $this->config->method('getBrandName')->willReturn('Angeo');
        $this->config->method('getBrandDomain')->willReturn('angeo.dev');
        $this->config->method('getSystemPrompt')->willReturn('system');
        $this->config->method('isLogEnabled')->willReturn(false);
        $this->config->method('getDelayBetweenQueriesMs')->willReturn(0);

        foreach ([$this->chatGpt, $this->claude, $this->perplexity, $this->gemini] as $p) {
            $p->method('isConfigured')->willReturn(false);
        }
    }

    private function makeService(): BrandVisibilityService
    {
        return new BrandVisibilityService(
            config:     $this->config,
            chatGpt:    $this->chatGpt,
            claude:     $this->claude,
            perplexity: $this->perplexity,
            gemini:     $this->gemini,
            groq:       $this->groq,
            analyzer:   $this->analyzer,
            cache:      $this->cache,
            json:       $this->json,
            logger:     $this->logger,
            repository: $this->repository,
        );
    }

    // ── guard clauses ────────────────────────────────────────────────────────

    public function testThrowsWhenNoProvidersConfigured(): void
    {
        $this->groq->method('isConfigured')->willReturn(false);
        $this->config->method('getCacheTtlHours')->willReturn(0);
        $this->repository->expects($this->never())->method('saveReport');

        $this->expectException(\RuntimeException::class);
        $this->makeService()->run();
    }

    public function testThrowsWhenNoPromptsConfigured(): void
    {
        $this->groq->method('isConfigured')->willReturn(true);
        $this->config->method('getCacheTtlHours')->willReturn(0);
        $this->config->method('getActivePrompts')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->makeService()->run();
    }

    // ── cache behaviour ──────────────────────────────────────────────────────

    public function testReturnsCachedReportWhenAvailable(): void
    {
        $flat = [
            'brand_name'   => 'Angeo',
            'brand_domain' => 'angeo.dev',
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'results'      => [],
        ];

        $this->config->method('getCacheTtlHours')->willReturn(12);
        $this->config->method('getActivePrompts')->willReturn(['brand_direct' => 'tpl']);
        $this->cache->method('load')->willReturn($this->json->serialize($flat));

        // A cache hit must never query providers or persist.
        $this->groq->expects($this->never())->method('query');
        $this->repository->expects($this->never())->method('saveReport');

        $report = $this->makeService()->run(forceRefresh: false);

        $this->assertInstanceOf(BrandVisibilityReport::class, $report);
        $this->assertTrue($report->fromCache);
    }

    public function testForceRefreshBypassesCacheAndSaves(): void
    {
        $this->configureSingleProviderRun();
        $this->cache->method('load')->willReturn('should-not-be-used');
        $this->repository->expects($this->once())->method('saveReport');

        $report = $this->makeService()->run(forceRefresh: true, triggeredBy: 'admin');

        $this->assertFalse($report->fromCache);
        $this->assertCount(1, $report->results);
    }

    // ── save resilience ──────────────────────────────────────────────────────

    public function testSaveFailureDoesNotThrow(): void
    {
        $this->configureSingleProviderRun();
        $this->cache->method('load')->willReturn(false);

        $this->repository->method('saveReport')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('[BrandVis] Failed to save'));

        $report = $this->makeService()->run();

        $this->assertInstanceOf(BrandVisibilityReport::class, $report);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function configureSingleProviderRun(): void
    {
        $this->config->method('getCacheTtlHours')->willReturn(0);
        $this->config->method('getActivePrompts')->willReturn(['brand_direct' => 'tpl']);
        $this->config->method('buildPrompt')->willReturn('prompt text');

        $this->groq->method('isConfigured')->willReturn(true);
        $this->groq->method('getProviderId')->willReturn('groq');
        $this->groq->method('getProviderLabel')->willReturn('Groq (llama-3.3-70b-versatile)');
        $this->groq->method('query')->willReturn('Angeo is a great store at angeo.dev');

        $this->analyzer->method('analyse')->willReturn([
            'signals' => [
                'mentioned'          => true,
                'recommended'        => true,
                'url_cited'          => true,
                'first_result'       => false,
                'positive_sentiment' => true,
            ],
            'score'   => 70,
        ]);
    }
}
