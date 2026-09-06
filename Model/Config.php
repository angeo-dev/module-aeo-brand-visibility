<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Angeo\AeoBrandVisibility\Service\StoreContextResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store-scoped configuration accessor for Angeo_AeoBrandVisibility.
 *
 * Provider settings are read generically from angeo_brand_vis/<providerId>/<key>,
 * so a new provider only needs a system.xml group and a di.xml pool entry.
 */
class Config
{
    public const SECTION = 'angeo_brand_vis';

    public const RUN_MODE_QUEUE = 'queue';
    public const RUN_MODE_SYNC = 'sync';

    /**
     * Built-in prompt identifiers in the order they are offered.
     */
    public const BUILT_IN_PROMPTS = [
        'recommendation',
        'category',
        'brand_direct',
        'product_search',
        'comparison',
        'gift_guide',
    ];

    private const XML_ENABLED = 'angeo_brand_vis/general/enabled';
    private const XML_RUN_MODE = 'angeo_brand_vis/general/run_mode';
    private const XML_BRAND_NAME = 'angeo_brand_vis/general/brand_name';
    private const XML_BRAND_DOMAIN = 'angeo_brand_vis/general/brand_domain';
    private const XML_BRAND_KEYWORDS = 'angeo_brand_vis/general/brand_keywords';
    private const XML_STORE_CATEGORY = 'angeo_brand_vis/general/store_category';
    private const XML_TOP_PRODUCTS = 'angeo_brand_vis/general/top_products';
    private const XML_LOG_ENABLED = 'angeo_brand_vis/general/log_enabled';
    private const XML_CACHE_TTL = 'angeo_brand_vis/general/cache_ttl_hours';

    private const XML_MAX_PROMPTS = 'angeo_brand_vis/queries/max_prompts';
    private const XML_SAMPLES = 'angeo_brand_vis/queries/samples';
    private const XML_DELAY_MS = 'angeo_brand_vis/queries/delay_between_ms';
    private const XML_MAX_RETRIES = 'angeo_brand_vis/queries/max_retries';
    private const XML_SYSTEM_PROMPT = 'angeo_brand_vis/queries/system_prompt';
    private const XML_CUSTOM_PROMPTS = 'angeo_brand_vis/queries/custom_prompts';

    private const XML_PASS_THRESHOLD = 'angeo_brand_vis/scoring/pass_threshold';
    private const XML_WARN_THRESHOLD = 'angeo_brand_vis/scoring/warn_threshold';
    private const XML_NEGATIVE_PENALTY = 'angeo_brand_vis/scoring/negative_penalty';

    private const XML_COMPETITORS = 'angeo_brand_vis/competitors/list';
    private const XML_ANALYSIS_LANG = 'angeo_brand_vis/analysis/language';
    private const XML_EXTRA_TLDS = 'angeo_brand_vis/analysis/extra_tlds';

    private const XML_ALERT_ENABLED = 'angeo_brand_vis/alerts/enabled';
    private const XML_ALERT_RECIPIENT = 'angeo_brand_vis/alerts/recipient';
    private const XML_ALERT_DROP = 'angeo_brand_vis/alerts/drop_threshold';
    private const XML_ALERT_WEBHOOK = 'angeo_brand_vis/alerts/webhook_url';
    private const XML_ALERT_WEBHOOK_PRIVATE = 'angeo_brand_vis/alerts/webhook_allow_private';

    private const XML_CRON_ENABLED = 'angeo_brand_vis/cron/enabled';

    private const XML_RETENTION_MAX = 'angeo_brand_vis/retention/max_records_per_store';
    private const XML_RETENTION_DAYS = 'angeo_brand_vis/retention/max_age_days';

    private const DEFAULT_SCORING_WEIGHTS = [
        'mentioned' => 1.0,
        'recommended' => 1.5,
        'url_cited' => 1.5,
        'first_result' => 2.0,
        'positive_sentiment' => 0.5,
    ];

    /**
     * Store view the reads below resolve against. Null means default scope.
     *
     * @var int|null
     */
    private ?int $scopeStoreId = null;

    /**
     * @param ScopeConfigInterface $scopeConfig Scoped configuration reader.
     * @param EncryptorInterface $encryptor Decrypts stored API keys.
     * @param StoreManagerInterface $storeManager Resolves brand fallbacks from the store.
     * @param StoreContextResolver $contextResolver Derives category and products from the catalogue.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreContextResolver $contextResolver
    ) {
    }

    /**
     * Return a clone whose reads resolve against the given store view.
     *
     * @param int|null $storeId Store view id, or null for default scope.
     * @return self
     */
    public function withStore(?int $storeId): self
    {
        $clone = clone $this;
        $clone->scopeStoreId = $storeId;

        return $clone;
    }

    /**
     * Store view this instance is scoped to.
     *
     * @return int|null
     */
    public function getScopeStoreId(): ?int
    {
        return $this->scopeStoreId;
    }

    /**
     * Whether the brand visibility check is switched on.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->flag(self::XML_ENABLED);
    }

    /**
     * Execution mode: queue (asynchronous consumer) or sync (inline).
     *
     * @return string
     */
    public function getRunMode(): string
    {
        return $this->stringValue(self::XML_RUN_MODE, self::RUN_MODE_QUEUE) === self::RUN_MODE_SYNC
            ? self::RUN_MODE_SYNC
            : self::RUN_MODE_QUEUE;
    }

    /**
     * Brand name, falling back to the store name.
     *
     * @return string
     */
    public function getBrandName(): string
    {
        $name = $this->stringValue(self::XML_BRAND_NAME, '');
        if ($name !== '') {
            return $name;
        }

        try {
            return (string) $this->resolveStore()->getName();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Brand domain without scheme or trailing slash, falling back to the store base URL host.
     *
     * @return string
     */
    public function getBrandDomain(): string
    {
        $domain = $this->stringValue(self::XML_BRAND_DOMAIN, '');
        if ($domain !== '') {
            return $this->normaliseDomain($domain);
        }

        try {
            $host = parse_url((string) $this->resolveStore()->getBaseUrl(), PHP_URL_HOST);

            return is_string($host) ? $this->normaliseDomain($host) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Alternate brand names and misspellings.
     *
     * @return string[]
     */
    public function getBrandKeywords(): array
    {
        return $this->splitList($this->stringValue(self::XML_BRAND_KEYWORDS, ''), ',');
    }

    /**
     * What the store sells, derived from the catalogue when not set explicitly.
     *
     * @return string
     */
    public function getStoreCategory(): string
    {
        $explicit = $this->stringValue(self::XML_STORE_CATEGORY, '');
        if ($explicit !== '') {
            return $explicit;
        }

        return $this->contextResolver->resolveCategoryPhrase($this->scopeStoreId);
    }

    /**
     * Whether a usable category phrase is available for prompt building.
     *
     * @return bool
     */
    public function hasUsableCategory(): bool
    {
        return $this->getStoreCategory() !== '';
    }

    /**
     * Product names used in product-search prompts.
     *
     * @return string[]
     */
    public function getTopProducts(): array
    {
        $list = $this->splitList($this->stringValue(self::XML_TOP_PRODUCTS, ''), "\n");
        if ($list !== []) {
            return $list;
        }

        return $this->contextResolver->resolveTopProducts($this->scopeStoreId);
    }

    /**
     * Whether prompts and answers are written to the module log.
     *
     * @return bool
     */
    public function isLogEnabled(): bool
    {
        return $this->flag(self::XML_LOG_ENABLED);
    }

    /**
     * Result cache lifetime in hours. Zero disables caching.
     *
     * @return int
     */
    public function getCacheTtlHours(): int
    {
        return max(0, $this->intValue(self::XML_CACHE_TTL, 24));
    }

    /**
     * Whether the named provider is enabled and holds an API key.
     *
     * @param string $providerId Provider identifier.
     * @return bool
     */
    public function isProviderEnabled(string $providerId): bool
    {
        return $this->flag($this->providerPath($providerId, 'enabled'));
    }

    /**
     * Decrypted API key for a provider.
     *
     * @param string $providerId Provider identifier.
     * @return string
     */
    public function getProviderApiKey(string $providerId): string
    {
        $stored = $this->stringValue($this->providerPath($providerId, 'api_key'), '');
        if ($stored === '') {
            return '';
        }

        return (string) $this->encryptor->decrypt($stored);
    }

    /**
     * Model identifier for a provider.
     *
     * @param string $providerId Provider identifier.
     * @param string $default Fallback when unset.
     * @return string
     */
    public function getProviderModel(string $providerId, string $default = ''): string
    {
        return $this->stringValue($this->providerPath($providerId, 'model'), $default);
    }

    /**
     * Maximum answer length in tokens.
     *
     * @param string $providerId Provider identifier.
     * @return int
     */
    public function getProviderMaxTokens(string $providerId): int
    {
        return max(64, $this->intValue($this->providerPath($providerId, 'max_tokens'), 800));
    }

    /**
     * Sampling temperature. Zero is a legal value and is preserved.
     *
     * @param string $providerId Provider identifier.
     * @return float
     */
    public function getProviderTemperature(string $providerId): float
    {
        return $this->floatValue($this->providerPath($providerId, 'temperature'), 0.3);
    }

    /**
     * Request timeout in seconds.
     *
     * @param string $providerId Provider identifier.
     * @return int
     */
    public function getProviderTimeout(string $providerId): int
    {
        return max(5, $this->intValue($this->providerPath($providerId, 'timeout'), 60));
    }

    /**
     * Whether the provider should answer from live web search.
     *
     * @param string $providerId Provider identifier.
     * @return bool
     */
    public function isGroundingEnabled(string $providerId): bool
    {
        return $this->flag($this->providerPath($providerId, 'grounding'));
    }

    /**
     * Maximum number of prompts per provider. Zero means no cap.
     *
     * @return int
     */
    public function getMaxPrompts(): int
    {
        return max(0, $this->intValue(self::XML_MAX_PROMPTS, 3));
    }

    /**
     * How many times each provider/prompt pair is repeated to average out model variance.
     *
     * @return int
     */
    public function getSamples(): int
    {
        return min(10, max(1, $this->intValue(self::XML_SAMPLES, 3)));
    }

    /**
     * Pause between consecutive API calls, in milliseconds.
     *
     * @return int
     */
    public function getDelayBetweenQueriesMs(): int
    {
        return max(0, $this->intValue(self::XML_DELAY_MS, 600));
    }

    /**
     * Retry attempts for a transient provider failure.
     *
     * @return int
     */
    public function getMaxRetries(): int
    {
        return min(5, max(0, $this->intValue(self::XML_MAX_RETRIES, 2)));
    }

    /**
     * System instruction sent with every prompt.
     *
     * @return string
     */
    public function getSystemPrompt(): string
    {
        return $this->stringValue(
            self::XML_SYSTEM_PROMPT,
            'You are a knowledgeable shopping assistant. Answer questions about online stores and '
            . 'products accurately. When you know a specific store, mention it by name and include '
            . 'its website URL. Do not invent stores or URLs.'
        );
    }

    /**
     * Active prompt templates keyed by prompt identifier.
     *
     * @return array<string, string>
     */
    public function getActivePrompts(): array
    {
        $prompts = [];

        foreach (self::BUILT_IN_PROMPTS as $key) {
            if (!$this->isPromptEnabled($key)) {
                continue;
            }
            $override = $this->stringValue('angeo_brand_vis/queries/prompt_' . $key, '');
            $prompts[$key] = $override !== '' ? $override : $this->getDefaultPromptTemplate($key);
        }

        foreach ($this->splitList($this->stringValue(self::XML_CUSTOM_PROMPTS, ''), "\n") as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$rawKey, $template] = explode(':', $line, 2);
            $key = trim((string) preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($rawKey))), '_');
            $template = trim($template);
            if ($key !== '' && $template !== '') {
                $prompts[$key] = $template;
            }
        }

        $max = $this->getMaxPrompts();
        if ($max > 0 && count($prompts) > $max) {
            $prompts = array_slice($prompts, 0, $max, true);
        }

        return $prompts;
    }

    /**
     * Replace placeholders in a prompt template with live store data.
     *
     * @param string $template Raw template text.
     * @return string
     */
    public function buildPrompt(string $template): string
    {
        return str_replace(
            ['{{brand}}', '{{domain}}', '{{category}}', '{{products}}'],
            [
                $this->getBrandName(),
                $this->getBrandDomain(),
                $this->getStoreCategory(),
                implode(', ', array_slice($this->getTopProducts(), 0, 3)),
            ],
            $template
        );
    }

    /**
     * Placeholder template for a built-in prompt.
     *
     * @param string $key Prompt identifier.
     * @return string
     */
    public function getDefaultPromptTemplate(string $key): string
    {
        return match ($key) {
            'recommendation' => 'What are the best online stores to buy {{category}}? '
                . 'List specific store names and their websites.',
            'category' => 'Where can I buy {{category}} online? '
                . 'Give specific recommendations with store names and URLs.',
            'brand_direct' => 'Tell me about the {{brand}} online store. What do they sell, '
                . 'what is their website, and would you recommend them?',
            'product_search' => 'I am looking for {{products}} online. '
                . 'Which stores do you recommend and why?',
            'comparison' => 'Compare {{brand}} with other online stores selling {{category}}. '
                . 'What are the pros and cons of each?',
            'gift_guide' => 'I need gift ideas for someone who likes {{category}}. '
                . 'Which online stores have the best selection?',
            default => 'Tell me about online stores that sell {{category}}.',
        };
    }

    /**
     * Weight of one scoring signal.
     *
     * @param string $signal Signal identifier.
     * @return float
     */
    public function getScoringWeight(string $signal): float
    {
        if (!isset(self::DEFAULT_SCORING_WEIGHTS[$signal])) {
            return 0.0;
        }

        return max(0.0, $this->floatValue(
            'angeo_brand_vis/scoring/weight_' . $signal,
            self::DEFAULT_SCORING_WEIGHTS[$signal]
        ));
    }

    /**
     * Share of the earned score removed when the tone around the brand is negative.
     *
     * @return float
     */
    public function getNegativePenalty(): float
    {
        return min(1.0, max(0.0, $this->floatValue(self::XML_NEGATIVE_PENALTY, 0.5)));
    }

    /**
     * Score at or above which the audit signal passes.
     *
     * @return int
     */
    public function getPassThreshold(): int
    {
        return $this->intValue(self::XML_PASS_THRESHOLD, 60);
    }

    /**
     * Score at or above which the audit signal warns instead of failing.
     *
     * @return int
     */
    public function getWarnThreshold(): int
    {
        return $this->intValue(self::XML_WARN_THRESHOLD, 30);
    }

    /**
     * Tracked competitors as name and optional domain pairs.
     *
     * @return array<int, array{name: string, domain: string}>
     */
    public function getCompetitors(): array
    {
        $out = [];
        foreach ($this->splitList($this->stringValue(self::XML_COMPETITORS, ''), "\n") as $line) {
            $parts = preg_split('/[|,]/', $line, 2) ?: [];
            $name = trim((string) ($parts[0] ?? ''));
            $domain = isset($parts[1]) ? $this->normaliseDomain(trim($parts[1])) : '';
            if ($name !== '') {
                $out[] = ['name' => $name, 'domain' => $domain];
            }
        }

        return $out;
    }

    /**
     * Two-letter language used for phrase matching. Auto resolves from the store locale.
     *
     * @return string
     */
    public function getAnalysisLanguage(): string
    {
        $lang = $this->stringValue(self::XML_ANALYSIS_LANG, 'auto');
        if ($lang !== '' && $lang !== 'auto') {
            return strtolower(substr($lang, 0, 2));
        }

        $locale = $this->stringValue('general/locale/code', '');

        return $locale !== '' ? strtolower(substr($locale, 0, 2)) : 'en';
    }

    /**
     * Extra top-level domains recognised when checking URL attribution, e.g. nl, de, co.uk.
     *
     * @return string[]
     */
    public function getExtraTlds(): array
    {
        $out = [];
        foreach ($this->splitList($this->stringValue(self::XML_EXTRA_TLDS, ''), ',') as $tld) {
            $clean = strtolower(ltrim($tld, '.'));
            if (preg_match('/^[a-z]{2,}(\.[a-z]{2,})?$/', $clean) === 1) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * Whether regression alerts are switched on.
     *
     * @return bool
     */
    public function isAlertEnabled(): bool
    {
        return $this->flag(self::XML_ALERT_ENABLED);
    }

    /**
     * Alert recipient e-mail address.
     *
     * @return string
     */
    public function getAlertRecipient(): string
    {
        return $this->stringValue(self::XML_ALERT_RECIPIENT, '');
    }

    /**
     * Point drop against the trailing average that triggers an alert.
     *
     * @return int
     */
    public function getAlertDropThreshold(): int
    {
        return max(1, $this->intValue(self::XML_ALERT_DROP, 10));
    }

    /**
     * Optional webhook endpoint notified alongside the alert e-mail.
     *
     * @return string
     */
    public function getAlertWebhookUrl(): string
    {
        return $this->stringValue(self::XML_ALERT_WEBHOOK, '');
    }

    /**
     * Whether the webhook may point at a private or reserved network address.
     *
     * @return bool
     */
    public function isWebhookPrivateAllowed(): bool
    {
        return $this->flag(self::XML_ALERT_WEBHOOK_PRIVATE);
    }

    /**
     * Whether the scheduled audit is switched on.
     *
     * @return bool
     */
    public function isCronEnabled(): bool
    {
        return $this->flag(self::XML_CRON_ENABLED);
    }

    /**
     * Rows kept per store scope before pruning.
     *
     * @return int
     */
    public function getMaxRecordsPerStore(): int
    {
        return max(10, $this->intValue(self::XML_RETENTION_MAX, 90));
    }

    /**
     * Maximum age of a stored run, in days.
     *
     * @return int
     */
    public function getMaxAgeDays(): int
    {
        return max(7, $this->intValue(self::XML_RETENTION_DAYS, 365));
    }

    /**
     * Whether a built-in prompt is switched on.
     *
     * @param string $key Prompt identifier.
     * @return bool
     */
    private function isPromptEnabled(string $key): bool
    {
        return $this->flag('angeo_brand_vis/queries/enable_' . $key);
    }

    /**
     * Build a provider configuration path.
     *
     * @param string $providerId Provider identifier.
     * @param string $key Field name.
     * @return string
     */
    private function providerPath(string $providerId, string $key): string
    {
        return self::SECTION . '/' . $providerId . '/' . $key;
    }

    /**
     * Raw scope-aware read.
     *
     * @param string $path Configuration path.
     * @return mixed
     */
    private function rawValue(string $path): mixed
    {
        if ($this->scopeStoreId !== null) {
            return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $this->scopeStoreId);
        }

        return $this->scopeConfig->getValue($path);
    }

    /**
     * Scope-aware boolean read.
     *
     * @param string $path Configuration path.
     * @return bool
     */
    private function flag(string $path): bool
    {
        if ($this->scopeStoreId !== null) {
            return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $this->scopeStoreId);
        }

        return $this->scopeConfig->isSetFlag($path);
    }

    /**
     * Scope-aware string read with a default applied only when the value is absent.
     *
     * @param string $path Configuration path.
     * @param string $default Fallback value.
     * @return string
     */
    private function stringValue(string $path, string $default): string
    {
        $value = $this->rawValue($path);
        if ($value === null || $value === '') {
            return $default;
        }

        return trim((string) $value);
    }

    /**
     * Scope-aware integer read that preserves an explicit zero.
     *
     * @param string $path Configuration path.
     * @param int $default Fallback value.
     * @return int
     */
    private function intValue(string $path, int $default): int
    {
        $value = $this->rawValue($path);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Scope-aware float read that preserves an explicit zero.
     *
     * @param string $path Configuration path.
     * @param float $default Fallback value.
     * @return float
     */
    private function floatValue(string $path, float $default): float
    {
        $value = $this->rawValue($path);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }

    /**
     * Split a delimited admin field into trimmed, non-empty values.
     *
     * @param string $raw Raw field value.
     * @param string $delimiter Delimiter character.
     * @return string[]
     */
    private function splitList(string $raw, string $delimiter): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode($delimiter, $raw)), static fn($v) => $v !== ''));
    }

    /**
     * Strip scheme, www prefix, path and trailing slash from a domain.
     *
     * @param string $domain Raw domain or URL.
     * @return string
     */
    private function normaliseDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = (string) preg_replace('#[/?].*$#', '', $domain);

        return trim($domain, '.');
    }

    /**
     * Resolve the store this instance is scoped to.
     *
     * @return \Magento\Store\Api\Data\StoreInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function resolveStore(): \Magento\Store\Api\Data\StoreInterface
    {
        return $this->storeManager->getStore($this->scopeStoreId ?? null);
    }
}
