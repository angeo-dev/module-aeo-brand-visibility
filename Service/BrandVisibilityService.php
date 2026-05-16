<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Magento\Framework\App\CacheInterface as Cache;
use Magento\Framework\Serialize\Serializer\Json as Json;
use Psr\Log\LoggerInterface;
use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\Provider\ChatGptProvider;
use Angeo\AeoBrandVisibility\Service\Provider\ClaudeProvider;
use Angeo\AeoBrandVisibility\Service\Provider\PerplexityProvider;
use Angeo\AeoBrandVisibility\Service\Provider\GeminiProvider;
use Angeo\AeoBrandVisibility\Service\Provider\GroqProvider;

/**
 * Orchestrates brand visibility auditing:
 *   1. Collects configured + enabled providers
 *   2. Iterates providers × prompts (capped by queries_per_provider)
 *   3. Applies inter-query delay to avoid rate limits
 *   4. Caches serialised report (TTL from config)
 *   5. Persists to DB for history and trend charts
 *   6. Returns BrandVisibilityReport
 */
class BrandVisibilityService
{
    private const CACHE_TAG    = 'ANGEO_BRAND_VIS';
    private const CACHE_PREFIX = 'angeo_bv_';

    public function __construct(
        private readonly Config              $config,
        private readonly ChatGptProvider     $chatGpt,
        private readonly ClaudeProvider      $claude,
        private readonly PerplexityProvider  $perplexity,
        private readonly GeminiProvider      $gemini,
        private readonly GroqProvider        $groq,
        private readonly ResponseAnalyzer    $analyzer,
        private readonly Cache               $cache,
        private readonly Json                $json,
        private readonly LoggerInterface     $logger,
        private readonly \Angeo\AeoBrandVisibility\Model\AuditResultRepository $repository,
    ) {}

    // ── Public ──────────────────────────────────────────────────────────────

    public function run(bool $forceRefresh = false, string $triggeredBy = 'admin'): BrandVisibilityReport
    {
        $cacheKey = $this->cacheKey();

        if (!$forceRefresh && $this->config->getCacheTtlHours() > 0) {
            $cached = $this->fromCache($cacheKey);
            if ($cached !== null) {
                $this->logger->info('[BrandVis] Cache hit', ['key' => $cacheKey]);
                return $cached;
            }
        }

        $providers = $this->enabledProviders();
        if (empty($providers)) {
            throw new \RuntimeException(
                'No AI providers enabled. Configure at least one provider in Brand Visibility settings.'
            );
        }

        $prompts = $this->config->getActivePrompts();
        if (empty($prompts)) {
            throw new \RuntimeException('No query prompts configured.');
        }

        $this->logger->info('[BrandVis] Starting audit', [
            'brand'     => $this->config->getBrandName(),
            'providers' => array_map(fn($p) => $p->getProviderId(), $providers),
            'prompts'   => array_keys($prompts),
        ]);

        $results      = [];
        $delayMs      = $this->config->getDelayBetweenQueriesMs();
        $systemPrompt = $this->config->getSystemPrompt();

        foreach ($providers as $provider) {
            foreach ($prompts as $promptKey => $template) {
                $userPrompt = $this->config->buildPrompt($template);
                $result     = $this->executeQuery($provider, $promptKey, $userPrompt, $systemPrompt);
                $results[]  = $result;

                $this->logger->info('[BrandVis] Query done', [
                    'provider'    => $result->providerId,
                    'prompt_key'  => $promptKey,
                    'score'       => $result->score,
                    'mentioned'   => $result->signals['mentioned']  ?? false,
                    'recommended' => $result->signals['recommended'] ?? false,
                    'url_cited'   => $result->signals['url_cited']   ?? false,
                    'success'     => $result->isSuccess(),
                ]);

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        $report = new BrandVisibilityReport(
            brandName:   $this->config->getBrandName(),
            brandDomain: $this->config->getBrandDomain(),
            results:     $results,
            generatedAt: new \DateTimeImmutable(),
            fromCache:   false,
        );

        $this->toCache($cacheKey, $report);

        try {
            $saved = $this->repository->saveReport($report, $triggeredBy);
            $this->logger->info('[BrandVis] Saved to DB', ['id' => $saved->getId()]);
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Failed to save to DB', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $this->logger->info('[BrandVis] Audit complete', [
            'overall_score' => $report->getOverallScore(),
            'grade'         => $report->getGrade(),
            'total_queries' => count($results),
            'errors'        => count($report->failedResults()),
        ]);

        return $report;
    }

    /**
     * Run a single query for admin preview — never cached.
     */
    public function querySingle(string $providerId, string $promptKey): BrandQueryResult
    {
        $provider = $this->findProvider($providerId)
            ?? throw new \InvalidArgumentException("Unknown or disabled provider: {$providerId}");

        $prompts  = $this->config->getActivePrompts();
        // Use active prompt if available, otherwise fall back to default for that key
        $template = $prompts[$promptKey] ?? $this->config->buildPrompt(
            $this->config->defaultPromptTextPublic($promptKey)
        );

        $userPrompt = $this->config->buildPrompt($template);

        return $this->executeQuery($provider, $promptKey, $userPrompt, $this->config->getSystemPrompt());
    }

    public function clearCache(): void
    {
        $this->cache->clean([self::CACHE_TAG]);
        $this->logger->info('[BrandVis] Cache cleared');
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function executeQuery(
        AiProviderInterface $provider,
        string $promptKey,
        string $userPrompt,
        string $systemPrompt
    ): BrandQueryResult {
        try {
            if ($this->config->isLogEnabled()) {
                $this->logger->debug('[BrandVis] Sending query', [
                    'provider'   => $provider->getProviderId(),
                    'prompt_key' => $promptKey,
                    'prompt'     => mb_substr($userPrompt, 0, 150),
                ]);
            }

            $rawResponse = $provider->query($systemPrompt, $userPrompt);

            if ($this->config->isLogEnabled()) {
                $this->logger->debug('[BrandVis] Response received', [
                    'provider' => $provider->getProviderId(),
                    'length'   => strlen($rawResponse),
                    'preview'  => mb_substr($rawResponse, 0, 100),
                ]);
            }

            ['signals' => $signals, 'score' => $score] = $this->analyzer->analyse($rawResponse);

            return new BrandQueryResult(
                providerId:    $provider->getProviderId(),
                providerLabel: $provider->getProviderLabel(),
                promptKey:     $promptKey,
                prompt:        $userPrompt,
                rawResponse:   $rawResponse,
                signals:       $signals,
                score:         $score,
            );
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Query failed', [
                'provider' => $provider->getProviderId(),
                'error'    => $e->getMessage(),
            ]);
            return BrandQueryResult::error(
                $provider->getProviderId(),
                $provider->getProviderLabel(),
                $promptKey,
                $userPrompt,
                $e->getMessage()
            );
        }
    }

    /** @return AiProviderInterface[] */
    private function enabledProviders(): array
    {
        return array_values(array_filter(
            [$this->chatGpt, $this->claude, $this->perplexity, $this->gemini, $this->groq],
            fn($p) => $p->isConfigured()
        ));
    }

    private function findProvider(string $id): ?AiProviderInterface
    {
        foreach ([$this->chatGpt, $this->claude, $this->perplexity, $this->gemini, $this->groq] as $p) {
            if ($p->getProviderId() === $id && $p->isConfigured()) {
                return $p;
            }
        }
        return null;
    }

    private function cacheKey(): string
    {
        return self::CACHE_PREFIX . md5(
            $this->config->getBrandName() .
            $this->config->getBrandDomain() .
            implode(',', array_keys($this->config->getActivePrompts()))
        );
    }

    private function fromCache(string $key): ?BrandVisibilityReport
    {
        $raw = $this->cache->load($key);
        if ($raw === false) return null;
        try {
            return $this->hydrateReport($this->json->unserialize($raw));
        } catch (\Throwable $e) {
            $this->logger->warning('[BrandVis] Cache hydrate failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function toCache(string $key, BrandVisibilityReport $report): void
    {
        if ($this->config->getCacheTtlHours() <= 0) return;
        $this->cache->save(
            $this->json->serialize($this->flattenReport($report)),
            $key,
            [self::CACHE_TAG],
            $this->config->getCacheTtlHours() * 3600
        );
    }

    private function flattenReport(BrandVisibilityReport $r): array
    {
        return [
            'brand_name'   => $r->brandName,
            'brand_domain' => $r->brandDomain,
            'generated_at' => $r->generatedAt->format(\DateTimeInterface::ATOM),
            'results'      => array_map(fn(BrandQueryResult $q) => [
                'provider_id'    => $q->providerId,
                'provider_label' => $q->providerLabel,
                'prompt_key'     => $q->promptKey,
                'prompt'         => $q->prompt,
                'raw_response'   => $q->rawResponse,
                'signals'        => $q->signals,
                'score'          => $q->score,
                'error'          => $q->errorMessage,
            ], $r->results),
        ];
    }

    private function hydrateReport(array $data): BrandVisibilityReport
    {
        $results = array_map(function (array $q): BrandQueryResult {
            if ($q['error'] !== null) {
                return BrandQueryResult::error(
                    $q['provider_id'], $q['provider_label'],
                    $q['prompt_key'], $q['prompt'], $q['error']
                );
            }
            return new BrandQueryResult(
                $q['provider_id'], $q['provider_label'],
                $q['prompt_key'], $q['prompt'],
                $q['raw_response'], $q['signals'], $q['score']
            );
        }, $data['results'] ?? []);

        return new BrandVisibilityReport(
            brandName:   $data['brand_name'],
            brandDomain: $data['brand_domain'],
            results:     $results,
            generatedAt: new \DateTimeImmutable($data['generated_at']),
            fromCache:   true,
        );
    }
}
