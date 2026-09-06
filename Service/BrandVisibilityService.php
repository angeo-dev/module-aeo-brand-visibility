<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Magento\Framework\App\CacheInterface as Cache;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;
use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Model\Result\ProviderResponse;

/**
 * Orchestrates brand visibility auditing:
 *   1. Collects configured + enabled providers (di.xml array since 2.0.0 —
 *      third-party providers plug in by implementing AiProviderInterface)
 *   2. Iterates providers × prompts (capped by max prompts per provider),
 *      sampling each prompt N times when repeats are configured and taking
 *      the median score / majority signals — one stochastic answer is not a
 *      trend point
 *   3. Applies inter-query delay to avoid rate limits
 *   4. Caches serialised report (TTL from config)
 *   5. Persists to DB for history and trend charts
 *   6. Returns BrandVisibilityReport
 */
class BrandVisibilityService
{
    private const CACHE_TAG    = 'ANGEO_BRAND_VIS';
    /** v2 prefix — the 2.0.0 payload shape must never hydrate from 1.x entries. */
    private const CACHE_PREFIX = 'angeo_bv2_';

    /**
     * @param AiProviderInterface[] $providers Injected via di.xml
     */
    public function __construct(
        private readonly Config              $config,
        private readonly ResponseAnalyzer    $analyzer,
        private readonly Cache               $cache,
        private readonly SerializerInterface $json,
        private readonly LoggerInterface     $logger,
        private readonly \Angeo\AeoBrandVisibility\Model\AuditResultRepository $repository,
        private readonly array               $providers = [],
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

    /**
     * Runs one prompt against one provider, sampling it N times when repeats
     * are configured. Aggregation: median score, majority-vote signals,
     * union of competitor mentions / citations. A single stochastic answer
     * is not a trend point; the median of three is.
     */
    private function executeQuery(
        AiProviderInterface $provider,
        string $promptKey,
        string $userPrompt,
        string $systemPrompt
    ): BrandQueryResult {
        $repeats  = $this->config->getRepeatsPerPrompt();
        $attempts = [];
        $lastError = null;

        for ($i = 0; $i < $repeats; $i++) {
            try {
                if ($this->config->isLogEnabled()) {
                    $this->logger->debug('[BrandVis] Sending query', [
                        'provider'   => $provider->getProviderId(),
                        'prompt_key' => $promptKey,
                        'attempt'    => $i + 1,
                        'prompt'     => mb_substr($userPrompt, 0, 150),
                    ]);
                }

                $response = $provider->query($systemPrompt, $userPrompt);

                if ($this->config->isLogEnabled()) {
                    $this->logger->debug('[BrandVis] Response received', [
                        'provider'  => $provider->getProviderId(),
                        'length'    => strlen($response->text),
                        'grounded'  => $response->grounded,
                        'citations' => count($response->citations),
                    ]);
                }

                $attempts[] = [
                    'response' => $response,
                    'analysis' => $this->analyzer->analyse($response->text, $response->citations),
                ];
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->logger->error('[BrandVis] Query failed', [
                    'provider' => $provider->getProviderId(),
                    'attempt'  => $i + 1,
                    'error'    => $e->getMessage(),
                ]);
            }

            if ($repeats > 1 && $i < $repeats - 1) {
                $delayMs = $this->config->getDelayBetweenQueriesMs();
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        if ($attempts === []) {
            return BrandQueryResult::error(
                $provider->getProviderId(),
                $provider->getProviderLabel(),
                $promptKey,
                $userPrompt,
                $lastError?->getMessage() ?? 'All attempts failed.'
            );
        }

        return $this->aggregateAttempts($provider, $promptKey, $userPrompt, $attempts);
    }

    /**
     * @param array<int, array{response: ProviderResponse, analysis: array}> $attempts
     */
    private function aggregateAttempts(
        AiProviderInterface $provider,
        string $promptKey,
        string $userPrompt,
        array $attempts
    ): BrandQueryResult {
        $n = count($attempts);

        // Median score
        $scores = array_map(fn($a) => (int) $a['analysis']['score'], $attempts);
        sort($scores);
        $mid   = intdiv($n, 2);
        $score = $n % 2 === 1 ? $scores[$mid] : (int) round(($scores[$mid - 1] + $scores[$mid]) / 2);

        // Majority-vote signals
        $signalKeys = array_keys($attempts[0]['analysis']['signals']);
        $signals    = [];
        foreach ($signalKeys as $key) {
            $votes = 0;
            foreach ($attempts as $a) {
                $votes += ($a['analysis']['signals'][$key] ?? false) ? 1 : 0;
            }
            $signals[$key] = $votes * 2 >= $n; // true when at least half agree
        }
        $signals['no_mention'] = !($signals['mentioned'] ?? false);

        // Union of competitor mentions (seen in ANY attempt = visible)
        $competitors = [];
        foreach ($attempts as $a) {
            foreach ($a['analysis']['competitor_mentions'] ?? [] as $name => $hit) {
                $competitors[$name] = ($competitors[$name] ?? false) || $hit;
            }
        }

        // Union of cited domains and citations
        $domains   = [];
        $citations = [];
        $grounded  = false;
        foreach ($attempts as $a) {
            $domains   = array_merge($domains, $a['analysis']['cited_domains'] ?? []);
            $citations = array_merge($citations, $a['response']->citations);
            $grounded  = $grounded || $a['response']->grounded;
        }

        return new BrandQueryResult(
            providerId:         $provider->getProviderId(),
            providerLabel:      $provider->getProviderLabel(),
            promptKey:          $promptKey,
            prompt:             $userPrompt,
            rawResponse:        $attempts[0]['response']->text,
            signals:            $signals,
            score:              $score,
            citations:          array_values(array_unique($citations)),
            grounded:           $grounded,
            competitorMentions: $competitors,
            citedDomains:       array_slice(array_values(array_unique($domains)), 0, 25),
            attempts:           $n,
        );
    }

    /** @return AiProviderInterface[] */
    private function enabledProviders(): array
    {
        return array_values(array_filter(
            $this->providers,
            fn($p) => $p instanceof AiProviderInterface && $p->isConfigured()
        ));
    }

    private function findProvider(string $id): ?AiProviderInterface
    {
        foreach ($this->enabledProviders() as $p) {
            if ($p->getProviderId() === $id) {
                return $p;
            }
        }
        return null;
    }

    private function cacheKey(): string
    {
        // Provider labels embed the configured model (e.g. "Claude (claude-sonnet-4-6)"),
        // so enabling/disabling a provider or switching its model invalidates the cache.
        $providerSignature = implode(',', array_map(
            fn($p) => $p->getProviderLabel(),
            $this->enabledProviders()
        ));

        return self::CACHE_PREFIX . md5(
            $this->config->getBrandName() .
            $this->config->getBrandDomain() .
            implode(',', array_keys($this->config->getActivePrompts())) .
            $providerSignature .
            $this->config->getSystemPrompt()
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
                'citations'      => $q->citations,
                'grounded'       => $q->grounded,
                'competitors'    => $q->competitorMentions,
                'cited_domains'  => $q->citedDomains,
                'attempts'       => $q->attempts,
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
                $q['raw_response'], $q['signals'], $q['score'],
                null,
                $q['citations'] ?? [],
                (bool) ($q['grounded'] ?? false),
                $q['competitors'] ?? [],
                $q['cited_domains'] ?? [],
                (int) ($q['attempts'] ?? 1)
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
