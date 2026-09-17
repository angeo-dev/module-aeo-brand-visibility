<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\BrandVisibilityServiceInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\Analysis\ResponseAnalyzer;
use Angeo\AeoBrandVisibility\Service\Analysis\SampleAggregator;
use Angeo\AeoBrandVisibility\Service\Provider\ProviderPool;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a brand visibility run.
 *
 * For each enabled provider and active prompt the service takes N samples,
 * aggregates them into one cell, then caches, persists and evaluates the
 * resulting report. Every configuration read, the cache key and the stored row
 * are scoped to the requested store view.
 */
class BrandVisibilityService implements BrandVisibilityServiceInterface
{
    private const CACHE_TAG = 'ANGEO_BRAND_VIS';
    private const CACHE_PREFIX = 'angeo_bv_';
    private const RETRY_BACKOFF_MS = 1500;

    /**
     * @param Config $config Configuration accessor.
     * @param ProviderPool $providerPool Registered AI providers.
     * @param ResponseAnalyzer $analyzer Signal extraction.
     * @param SampleAggregator $aggregator Sample averaging and confidence margin.
     * @param ReportSerializer $reportSerializer Report to array conversion.
     * @param AuditResultRepositoryInterface $repository Run persistence.
     * @param AlertNotifier $alertNotifier Regression alerting.
     * @param CacheInterface $cache Result cache.
     * @param SerializerInterface $serializer Cache payload serialisation.
     * @param Sleeper $sleeper Rate-limit pacing.
     * @param LoggerInterface $logger Module logger.
     */
    public function __construct(
        private readonly Config $config,
        private readonly ProviderPool $providerPool,
        private readonly ResponseAnalyzer $analyzer,
        private readonly SampleAggregator $aggregator,
        private readonly ReportSerializer $reportSerializer,
        private readonly AuditResultRepositoryInterface $repository,
        private readonly AlertNotifier $alertNotifier,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly Sleeper $sleeper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(
        ?int $storeId = null,
        bool $forceRefresh = false,
        string $triggeredBy = 'admin',
        ?int $auditResultId = null
    ): BrandVisibilityReport {
        $config = $this->config->withStore($storeId);
        $cacheKey = $this->buildCacheKey($config);

        if (!$forceRefresh && $config->getCacheTtlHours() > 0) {
            $cached = $this->loadFromCache($cacheKey);
            if ($cached !== null) {
                $this->logger->info('[BrandVis] Cache hit.', ['store_id' => $storeId]);

                return $cached;
            }
        }

        $providers = $this->providerPool->getEnabled($config);
        if ($providers === []) {
            throw new LocalizedException(new Phrase(
                'No AI provider is enabled. Configure at least one provider under '
                . 'Stores > Configuration > Angeo AEO > Brand Visibility.'
            ));
        }

        $prompts = $config->getActivePrompts();
        if ($prompts === []) {
            throw new LocalizedException(new Phrase('No query prompts are active.'));
        }

        $report = $this->execute($providers, $prompts, $config);

        $this->storeInCache($cacheKey, $report, $config);
        $this->persist($report, $triggeredBy, $config, $auditResultId);
        $this->notify($report, $config);

        return $report;
    }

    /**
     * @inheritDoc
     */
    public function querySingle(string $providerId, string $promptKey, ?int $storeId = null): BrandQueryResult
    {
        $config = $this->config->withStore($storeId);
        $provider = $this->providerPool->get($providerId, $config);

        if ($provider === null) {
            throw new LocalizedException(new Phrase(
                'Provider "%1" is unknown or not configured.',
                [$providerId]
            ));
        }

        $prompts = $config->getActivePrompts();
        $template = $prompts[$promptKey] ?? $config->getDefaultPromptTemplate($promptKey);

        return $this->runCell($provider, $promptKey, $config->buildPrompt($template), $config, 1);
    }

    /**
     * @inheritDoc
     */
    public function clearCache(): void
    {
        $this->cache->clean([self::CACHE_TAG]);
        $this->logger->info('[BrandVis] Result cache cleared.');
    }

    /**
     * Query every provider and prompt combination.
     *
     * @param AiProviderInterface[] $providers Enabled providers.
     * @param array<string, string> $prompts Active prompt templates.
     * @param Config $config Store-scoped configuration.
     * @return BrandVisibilityReport
     */
    private function execute(array $providers, array $prompts, Config $config): BrandVisibilityReport
    {
        $samples = $config->getSamples();
        $results = [];

        $this->logger->info('[BrandVis] Run started.', [
            'store_id' => $config->getScopeStoreId(),
            'brand' => $config->getBrandName(),
            'providers' => array_map(
                static fn(AiProviderInterface $p): string => $p->getProviderId(),
                $providers
            ),
            'prompts' => array_keys($prompts),
            'samples' => $samples,
        ]);

        foreach ($providers as $provider) {
            foreach ($prompts as $promptKey => $template) {
                $results[] = $this->runCell(
                    $provider,
                    (string) $promptKey,
                    $config->buildPrompt($template),
                    $config,
                    $samples
                );
            }
        }

        $report = new BrandVisibilityReport(
            brandName: $config->getBrandName(),
            brandDomain: $config->getBrandDomain(),
            results: $results,
            generatedAt: new \DateTimeImmutable(),
            samples: $samples,
            fromCache: false
        );

        $this->logger->info('[BrandVis] Run finished.', [
            'score' => $report->getOverallScore(),
            'margin' => $report->getScoreMargin(),
            'grade' => $report->getGrade(),
            'cells' => count($results),
            'failed_cells' => count($report->failedResults()),
        ]);

        return $report;
    }

    /**
     * Take N samples of one provider/prompt pair and aggregate them.
     *
     * @param AiProviderInterface $provider Provider to query.
     * @param string $promptKey Prompt identifier.
     * @param string $userPrompt Rendered prompt text.
     * @param Config $config Store-scoped configuration.
     * @param int $samples Number of repeats.
     * @return BrandQueryResult
     */
    private function runCell(
        AiProviderInterface $provider,
        string $promptKey,
        string $userPrompt,
        Config $config,
        int $samples
    ): BrandQueryResult {
        $systemPrompt = $config->getSystemPrompt();
        $collected = [];
        $lastError = null;

        for ($i = 0; $i < $samples; $i++) {
            try {
                $response = $this->callWithRetry($provider, $systemPrompt, $userPrompt, $config);
                $analysis = $this->analyzer->analyse($response, $config);
                $collected[] = [
                    'score' => $analysis['score'],
                    'signals' => $analysis['signals'],
                    'meta' => $analysis['meta'],
                    'response' => $response,
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger->error('[BrandVis] Sample failed.', [
                    'provider' => $provider->getProviderId(),
                    'prompt_key' => $promptKey,
                    'error' => $lastError,
                ]);
            }

            $this->sleeper->sleep($config->getDelayBetweenQueriesMs());
        }

        if ($collected === []) {
            return BrandQueryResult::error(
                $provider->getProviderId(),
                $provider->getProviderLabel($config),
                $promptKey,
                $userPrompt,
                $lastError ?? 'Every sample failed.'
            );
        }

        return $this->aggregator->aggregate(
            $provider->getProviderId(),
            $provider->getProviderLabel($config),
            $promptKey,
            $userPrompt,
            $collected
        );
    }

    /**
     * Call a provider, retrying transient failures with a linear backoff.
     *
     * @param AiProviderInterface $provider Provider to query.
     * @param string $systemPrompt System instruction.
     * @param string $userPrompt Rendered prompt text.
     * @param Config $config Store-scoped configuration.
     * @return string
     * @throws LocalizedException When every attempt fails.
     */
    private function callWithRetry(
        AiProviderInterface $provider,
        string $systemPrompt,
        string $userPrompt,
        Config $config
    ): string {
        $attempts = $config->getMaxRetries() + 1;
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                if ($config->isLogEnabled()) {
                    $this->logger->debug('[BrandVis] Request.', [
                        'provider' => $provider->getProviderId(),
                        'attempt' => $attempt,
                        'prompt' => mb_substr($userPrompt, 0, 200),
                    ]);
                }

                return $provider->query($systemPrompt, $userPrompt, $config);
            } catch (LocalizedException $e) {
                $lastException = $e;
                if ($attempt < $attempts) {
                    $this->sleeper->sleep(self::RETRY_BACKOFF_MS * $attempt);
                }
            }
        }

        throw $lastException ?? new LocalizedException(new Phrase('The provider call failed.'));
    }

    /**
     * Persist a finished report, without letting a storage failure abort the run.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @param string $triggeredBy Origin of the run.
     * @param Config $config Store-scoped configuration.
     * @param int|null $auditResultId Existing queued row to update.
     * @return void
     */
    private function persist(
        BrandVisibilityReport $report,
        string $triggeredBy,
        Config $config,
        ?int $auditResultId
    ): void {
        try {
            $this->repository->saveReport(
                $report,
                $triggeredBy,
                $config->getScopeStoreId() ?? 0,
                $auditResultId
            );
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Could not persist the run.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Evaluate alert rules, without letting a delivery failure abort the run.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @return void
     */
    private function notify(BrandVisibilityReport $report, Config $config): void
    {
        try {
            $this->alertNotifier->maybeAlert($report, $config);
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Alert dispatch failed.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Cache key covering the store scope and everything that changes the questions asked.
     *
     * @param Config $config Store-scoped configuration.
     * @return string
     */
    private function buildCacheKey(Config $config): string
    {
        $parts = [
            (string) ($config->getScopeStoreId() ?? 0),
            $config->getBrandName(),
            $config->getBrandDomain(),
            implode(',', array_keys($config->getActivePrompts())),
            (string) $config->getSamples(),
        ];

        return self::CACHE_PREFIX . sha1(implode('|', $parts));
    }

    /**
     * Read a cached report.
     *
     * @param string $cacheKey Cache key.
     * @return BrandVisibilityReport|null
     */
    private function loadFromCache(string $cacheKey): ?BrandVisibilityReport
    {
        $raw = $this->cache->load($cacheKey);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $data = $this->serializer->unserialize($raw);

            return is_array($data) ? $this->reportSerializer->fromArray($data, true) : null;
        } catch (\Throwable $e) {
            $this->logger->warning('[BrandVis] Cached report could not be read.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Write a report to the cache using the scoped lifetime.
     *
     * @param string $cacheKey Cache key.
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @return void
     */
    private function storeInCache(string $cacheKey, BrandVisibilityReport $report, Config $config): void
    {
        $ttlHours = $config->getCacheTtlHours();
        if ($ttlHours <= 0) {
            return;
        }

        $this->cache->save(
            $this->serializer->serialize($this->reportSerializer->toArray($report)),
            $cacheKey,
            [self::CACHE_TAG],
            $ttlHours * 3600
        );
    }
}
