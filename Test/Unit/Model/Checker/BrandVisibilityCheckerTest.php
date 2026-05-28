<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Angeo\AeoBrandVisibility\Model\Checker\BrandVisibilityChecker;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the v3-contract BrandVisibilityChecker:
 *   - signature compatibility with AbstractChecker / CheckerInterface
 *   - disabled / unconfigured short-circuits
 *   - threshold-driven pass / warn / fail
 *   - recommendation building
 *   - service exceptions translated to FAIL
 */
class BrandVisibilityCheckerTest extends TestCase
{
    /** @var HttpCache&MockObject */
    private HttpCache $httpCache;

    /** @var StoreUrlSampler&MockObject */
    private StoreUrlSampler $urlSampler;

    /** @var Config&MockObject */
    private Config $config;

    /** @var BrandVisibilityService&MockObject */
    private BrandVisibilityService $service;

    /** @var StoreInterface&MockObject */
    private StoreInterface $store;

    private BrandVisibilityChecker $checker;

    protected function setUp(): void
    {
        $this->httpCache  = $this->createMock(HttpCache::class);
        $this->urlSampler = $this->createMock(StoreUrlSampler::class);
        $this->config     = $this->createMock(Config::class);
        $this->service    = $this->createMock(BrandVisibilityService::class);
        $this->store      = $this->createMock(StoreInterface::class);

        $this->store->method('getId')->willReturn(1);
        $this->store->method('getCode')->willReturn('default');

        $this->checker = new BrandVisibilityChecker(
            $this->httpCache,
            $this->urlSampler,
            $this->config,
            $this->service,
        );
    }

    // ── Contract / metadata ──────────────────────────────────────────────

    public function testImplementsV3Interface(): void
    {
        $this->assertInstanceOf(CheckerInterface::class, $this->checker);
    }

    public function testCheckMethodMatchesV3Signature(): void
    {
        // Reflection: prove the v3 signature is matched exactly. If a future
        // commit reintroduces `check(string $baseUrl)` this test fails
        // before the fatal hits production.
        $rm = new \ReflectionMethod($this->checker, 'check');
        $params = $rm->getParameters();
        $this->assertCount(1, $params);
        $type = $params[0]->getType();
        $this->assertNotNull($type);
        $this->assertSame(StoreInterface::class, (string) $type);
    }

    public function testMetadata(): void
    {
        $this->assertSame('brand_visibility', $this->checker->getCode());
        $this->assertSame('Brand Visibility in AI Models', $this->checker->getName());
        $this->assertSame(1.0, $this->checker->getWeight());
        $this->assertSame(CheckerInterface::CATEGORY_LIVE_SIGNAL, $this->checker->getCategory());
        $this->assertSame(CheckerInterface::SEVERITY_CRITICAL, $this->checker->getSeverity());
        $this->assertSame('', $this->checker->getFixCommand());
    }

    // ── Short-circuits ───────────────────────────────────────────────────

    public function testDisabledModuleReturnsWarn(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('disabled', $result->getMessage());
        $this->assertFalse($result->getDetails()['enabled']);
    }

    public function testMissingBrandNameReturnsFail(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getBrandName')->willReturn('');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('Brand name', $result->getMessage());
    }

    public function testServiceExceptionReturnsFail(): void
    {
        $this->stubEnabled();
        $this->service->method('run')->willThrowException(new \RuntimeException('rate limited'));

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('rate limited', $result->getMessage());
        $this->assertSame('RuntimeException', $result->getDetails()['exception_class']);
    }

    public function testThrowableOtherThanRuntimeStillCaught(): void
    {
        $this->stubEnabled();
        // Previously the v1.0 checker caught only RuntimeException — Throwable
        // is broader and catches \Error / \LogicException too.
        $this->service->method('run')->willThrowException(new \LogicException('boom'));

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('boom', $result->getMessage());
    }

    // ── Threshold-driven outcomes ────────────────────────────────────────

    public function testPassWhenScoreAboveThreshold(): void
    {
        $this->stubEnabled();
        $this->config->method('getPassThreshold')->willReturn(80);
        $this->config->method('getWarnThreshold')->willReturn(60);
        // Build a report whose successful results' avg score is 90 → grade A
        $report = $this->buildReport([
            $this->ok('gpt', 90, ['mentioned' => true, 'recommended' => true, 'url_cited' => true, 'first_result' => true]),
            $this->ok('claude', 90, ['mentioned' => true, 'recommended' => true, 'url_cited' => true, 'first_result' => true]),
        ]);
        $this->service->method('run')->willReturn($report);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
        $this->assertSame(90, $result->getDetails()['score']);
        $this->assertSame('A', $result->getDetails()['grade']);
    }

    public function testWarnWhenScoreBetweenThresholds(): void
    {
        $this->stubEnabled();
        $this->config->method('getPassThreshold')->willReturn(80);
        $this->config->method('getWarnThreshold')->willReturn(60);
        $report = $this->buildReport([
            $this->ok('gpt', 70, ['mentioned' => true, 'recommended' => true, 'url_cited' => true, 'first_result' => false]),
            $this->ok('claude', 70, ['mentioned' => true, 'recommended' => false, 'url_cited' => true, 'first_result' => false]),
        ]);
        $this->service->method('run')->willReturn($report);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
    }

    public function testFailWhenScoreBelowWarnThreshold(): void
    {
        $this->stubEnabled();
        $this->config->method('getPassThreshold')->willReturn(80);
        $this->config->method('getWarnThreshold')->willReturn(60);
        $report = $this->buildReport([
            $this->ok('gpt', 20, ['mentioned' => false, 'recommended' => false, 'url_cited' => false, 'first_result' => false]),
            $this->ok('claude', 20, ['mentioned' => false, 'recommended' => false, 'url_cited' => false, 'first_result' => false]),
        ]);
        $this->service->method('run')->willReturn($report);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        // All four mention rates are 0%, so every recommendation tip should trigger
        $this->assertStringContainsString('llms.txt', $result->getRecommendation());
    }

    public function testRecommendationStaysGenericWhenSignalsHealthy(): void
    {
        $this->stubEnabled();
        $this->config->method('getPassThreshold')->willReturn(80);
        $this->config->method('getWarnThreshold')->willReturn(60);
        // Score 72 → WARN, but every signal rate is healthy (no tips fire)
        $report = $this->buildReport([
            $this->ok('gpt',    72, ['mentioned' => true, 'recommended' => true, 'url_cited' => true, 'first_result' => true]),
            $this->ok('claude', 72, ['mentioned' => true, 'recommended' => true, 'url_cited' => true, 'first_result' => true]),
        ]);
        $this->service->method('run')->willReturn($report);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        // No critical tips triggered → only generic "Good progress…"
        $this->assertStringContainsString('Good progress', $result->getRecommendation());
    }

    public function testCachedReportFlagSurfaces(): void
    {
        $this->stubEnabled();
        $this->config->method('getPassThreshold')->willReturn(80);
        $this->config->method('getWarnThreshold')->willReturn(60);
        $report = $this->buildReport([
            $this->ok('gpt', 90, ['mentioned' => true, 'recommended' => true, 'url_cited' => true, 'first_result' => true]),
        ], fromCache: true);
        $this->service->method('run')->willReturn($report);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed());
        $this->assertTrue($result->getDetails()['from_cache']);
        $this->assertStringContainsString('cached', $result->getMessage());
    }

    public function testNoSuccessfulResultsScoresZero(): void
    {
        $this->stubEnabled();
        $this->config->method('getPassThreshold')->willReturn(80);
        $this->config->method('getWarnThreshold')->willReturn(60);
        // All queries errored → overall score 0 → FAIL
        $report = $this->buildReport([
            BrandQueryResult::error('gpt', 'ChatGPT', 'q1', 'p', 'api error'),
            BrandQueryResult::error('claude', 'Claude', 'q1', 'p', 'api error'),
        ]);
        $this->service->method('run')->willReturn($report);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertSame(0, $result->getDetails()['score']);
        $this->assertSame(0, $result->getDetails()['queries_ok']);
        $this->assertSame(2, $result->getDetails()['queries_run']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function stubEnabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getBrandName')->willReturn('Example Store');
    }

    /**
     * @param array<string, bool> $signals
     */
    private function ok(string $providerId, int $score, array $signals): BrandQueryResult
    {
        return new BrandQueryResult(
            providerId:    $providerId,
            providerLabel: ucfirst($providerId),
            promptKey:     'k',
            prompt:        'Probe prompt',
            rawResponse:   'Yes, Example Store is great.',
            signals:       $signals,
            score:         $score,
        );
    }

    /**
     * @param BrandQueryResult[] $results
     */
    private function buildReport(array $results, bool $fromCache = false): BrandVisibilityReport
    {
        return new BrandVisibilityReport(
            brandName:   'Example Store',
            brandDomain: 'example.com',
            results:     $results,
            generatedAt: new \DateTimeImmutable(),
            fromCache:   $fromCache,
        );
    }
}
