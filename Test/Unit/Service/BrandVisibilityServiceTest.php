<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\AuditResultRepository;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Angeo\AeoBrandVisibility\Service\Provider\GroqProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @covers \Angeo\AeoBrandVisibility\Service\BrandVisibilityService
 */
class BrandVisibilityServiceTest extends TestCase
{
    private Config&MockObject $config;
    private AuditResultRepository&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private CacheInterface&MockObject $cache;
    private GroqProvider&MockObject $groqProvider;

    protected function setUp(): void
    {
        $this->config       = $this->createMock(Config::class);
        $this->repository   = $this->createMock(AuditResultRepository::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cache        = $this->createMock(CacheInterface::class);
        $this->groqProvider = $this->createMock(GroqProvider::class);
    }

    private function makeService(): BrandVisibilityService
    {
        return new BrandVisibilityService(
            config:     $this->config,
            repository: $this->repository,
            logger:     $this->logger,
            cache:      $this->cache,
        );
    }

    // ── enabled providers ────────────────────────────────────────────────────

    public function testReturnsEmptyEnabledProvidersWhenNoneConfigured(): void
    {
        $this->groqProvider->method('isConfigured')->willReturn(false);

        $service = new BrandVisibilityService(
            config:     $this->config,
            repository: $this->repository,
            logger:     $this->logger,
            cache:      $this->cache,
            groq:       $this->groqProvider,
        );

        // run() should not call repository->saveReport if no providers
        $this->repository->expects($this->never())->method('saveReport');

        $this->config->method('getBrandName')->willReturn('Angeo');
        $this->config->method('getBrandDomain')->willReturn('angeo.dev');
        $this->config->method('getEnabledPrompts')->willReturn([]);
        $this->cache->method('get')->willReturn(null);

        $report = $service->run();

        $this->assertSame(0, $report->getOverallScore());
        $this->assertCount(0, $report->results);
    }

    // ── cache behaviour ──────────────────────────────────────────────────────

    public function testReturnsCachedReportWhenAvailable(): void
    {
        $cachedReport = new BrandVisibilityReport(
            brandName:   'Angeo',
            brandDomain: 'angeo.dev',
            results:     [],
            generatedAt: new \DateTimeImmutable(),
            fromCache:   true,
        );

        $this->cache->method('get')->willReturn(serialize($cachedReport));
        $this->config->method('getCacheTtl')->willReturn(12);

        // If cached, repository should never be called
        $this->repository->expects($this->never())->method('saveReport');

        $service = $this->makeService();
        $report  = $service->run(forceRefresh: false);

        $this->assertTrue($report->fromCache);
    }

    public function testForceRefreshBypassesCache(): void
    {
        $cachedReport = new BrandVisibilityReport(
            brandName:   'Angeo',
            brandDomain: 'angeo.dev',
            results:     [],
            generatedAt: new \DateTimeImmutable(),
            fromCache:   true,
        );

        // Cache has data but forceRefresh = true
        $this->cache->method('get')->willReturn(serialize($cachedReport));
        $this->config->method('getBrandName')->willReturn('Angeo');
        $this->config->method('getBrandDomain')->willReturn('angeo.dev');
        $this->config->method('getEnabledPrompts')->willReturn([]);
        $this->config->method('getCacheTtl')->willReturn(12);

        $this->groqProvider->method('isConfigured')->willReturn(false);

        $service = new BrandVisibilityService(
            config:     $this->config,
            repository: $this->repository,
            logger:     $this->logger,
            cache:      $this->cache,
            groq:       $this->groqProvider,
        );

        $report = $service->run(forceRefresh: true);

        // Should NOT be from cache
        $this->assertFalse($report->fromCache);
    }

    // ── save behaviour ───────────────────────────────────────────────────────

    public function testSavesReportAfterSuccessfulRun(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->config->method('getBrandName')->willReturn('Angeo');
        $this->config->method('getBrandDomain')->willReturn('angeo.dev');
        $this->config->method('getEnabledPrompts')->willReturn([]);
        $this->config->method('getCacheTtl')->willReturn(12);

        $this->groqProvider->method('isConfigured')->willReturn(false);

        $this->repository->expects($this->once())->method('saveReport');

        $service = new BrandVisibilityService(
            config:     $this->config,
            repository: $this->repository,
            logger:     $this->logger,
            cache:      $this->cache,
            groq:       $this->groqProvider,
        );

        $service->run(triggeredBy: 'admin');
    }

    public function testSaveFailureDoesNotThrow(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->config->method('getBrandName')->willReturn('Angeo');
        $this->config->method('getBrandDomain')->willReturn('angeo.dev');
        $this->config->method('getEnabledPrompts')->willReturn([]);
        $this->config->method('getCacheTtl')->willReturn(0);

        $this->repository->method('saveReport')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('[BrandVis] Failed to save'));

        $service = $this->makeService();

        // Should not re-throw
        $this->assertInstanceOf(BrandVisibilityReport::class, $service->run());
    }
}
