<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Central config accessor for Angeo_AeoBrandVisibility.
 *
 * Fully standalone — all AI provider credentials and model settings
 * are owned by this module. No dependency on other Angeo modules.
 */
class Config
{
    // ── General ────────────────────────────────────────────────────────────
    private const XML_ENABLED          = 'angeo_brand_vis/general/enabled';
    private const XML_BRAND_NAME       = 'angeo_brand_vis/general/brand_name';
    private const XML_BRAND_DOMAIN     = 'angeo_brand_vis/general/brand_domain';
    private const XML_BRAND_KEYWORDS   = 'angeo_brand_vis/general/brand_keywords';
    private const XML_STORE_CATEGORY   = 'angeo_brand_vis/general/store_category';
    private const XML_TOP_PRODUCTS     = 'angeo_brand_vis/general/top_products';
    private const XML_LOG_ENABLED      = 'angeo_brand_vis/general/log_enabled';
    private const XML_CACHE_TTL        = 'angeo_brand_vis/general/cache_ttl_hours';

    // ── ChatGPT ────────────────────────────────────────────────────────────
    private const XML_GPT_ENABLED      = 'angeo_brand_vis/chatgpt/enabled';
    private const XML_GPT_API_KEY      = 'angeo_brand_vis/chatgpt/api_key';
    private const XML_GPT_MODEL        = 'angeo_brand_vis/chatgpt/model';
    private const XML_GPT_MAX_TOKENS   = 'angeo_brand_vis/chatgpt/max_tokens';
    private const XML_GPT_TEMPERATURE  = 'angeo_brand_vis/chatgpt/temperature';
    private const XML_GPT_TIMEOUT      = 'angeo_brand_vis/chatgpt/timeout';

    // ── Claude ─────────────────────────────────────────────────────────────
    private const XML_CLAUDE_ENABLED   = 'angeo_brand_vis/claude/enabled';
    private const XML_CLAUDE_API_KEY   = 'angeo_brand_vis/claude/api_key';
    private const XML_CLAUDE_MODEL     = 'angeo_brand_vis/claude/model';
    private const XML_CLAUDE_MAX_TOKENS= 'angeo_brand_vis/claude/max_tokens';
    private const XML_CLAUDE_TIMEOUT   = 'angeo_brand_vis/claude/timeout';

    // ── Perplexity ─────────────────────────────────────────────────────────
    private const XML_PPX_ENABLED      = 'angeo_brand_vis/perplexity/enabled';
    private const XML_PPX_API_KEY      = 'angeo_brand_vis/perplexity/api_key';
    private const XML_PPX_MODEL        = 'angeo_brand_vis/perplexity/model';
    private const XML_PPX_MAX_TOKENS   = 'angeo_brand_vis/perplexity/max_tokens';
    private const XML_PPX_TIMEOUT      = 'angeo_brand_vis/perplexity/timeout';


    // ── Gemini ─────────────────────────────────────────────────────────────
    private const XML_GEMINI_ENABLED    = 'angeo_brand_vis/gemini/enabled';
    private const XML_GEMINI_API_KEY    = 'angeo_brand_vis/gemini/api_key';
    private const XML_GEMINI_MODEL      = 'angeo_brand_vis/gemini/model';
    private const XML_GEMINI_MAX_TOKENS = 'angeo_brand_vis/gemini/max_tokens';
    private const XML_GEMINI_TIMEOUT    = 'angeo_brand_vis/gemini/timeout';
    // ── Groq ───────────────────────────────────────────────────────────────
    private const XML_GROQ_ENABLED    = 'angeo_brand_vis/groq/enabled';
    private const XML_GROQ_API_KEY    = 'angeo_brand_vis/groq/api_key';
    private const XML_GROQ_MODEL      = 'angeo_brand_vis/groq/model';
    private const XML_GROQ_MAX_TOKENS = 'angeo_brand_vis/groq/max_tokens';
    private const XML_GROQ_TIMEOUT    = 'angeo_brand_vis/groq/timeout';

    // ── Queries ────────────────────────────────────────────────────────────
    private const XML_Q_QUERIES_PER_RUN    = 'angeo_brand_vis/queries/queries_per_provider';
    private const XML_Q_DELAY_MS           = 'angeo_brand_vis/queries/delay_between_ms';
    private const XML_Q_SYSTEM_PROMPT      = 'angeo_brand_vis/queries/system_prompt';
    private const XML_Q_CUSTOM_PROMPTS     = 'angeo_brand_vis/queries/custom_prompts';

    // Per-prompt-type toggles
    private const XML_Q_RECOMMENDATION    = 'angeo_brand_vis/queries/prompt_recommendation';
    private const XML_Q_CATEGORY          = 'angeo_brand_vis/queries/prompt_category';
    private const XML_Q_BRAND_DIRECT      = 'angeo_brand_vis/queries/prompt_brand_direct';
    private const XML_Q_PRODUCT_SEARCH    = 'angeo_brand_vis/queries/prompt_product_search';
    private const XML_Q_COMPARISON        = 'angeo_brand_vis/queries/prompt_comparison';
    private const XML_Q_GIFT_GUIDE        = 'angeo_brand_vis/queries/prompt_gift_guide';

    // ── Scoring ────────────────────────────────────────────────────────────
    private const XML_S_MENTIONED         = 'angeo_brand_vis/scoring/weight_mentioned';
    private const XML_S_RECOMMENDED       = 'angeo_brand_vis/scoring/weight_recommended';
    private const XML_S_URL_CITED         = 'angeo_brand_vis/scoring/weight_url_cited';
    private const XML_S_FIRST_RESULT      = 'angeo_brand_vis/scoring/weight_first_result';
    private const XML_S_POSITIVE          = 'angeo_brand_vis/scoring/weight_positive_sentiment';
    private const XML_S_PASS_THRESHOLD    = 'angeo_brand_vis/scoring/pass_threshold';
    private const XML_S_WARN_THRESHOLD    = 'angeo_brand_vis/scoring/warn_threshold';

    // ── Cron ───────────────────────────────────────────────────────────────
    private const XML_CRON_ENABLED        = 'angeo_brand_vis/cron/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface   $encryptor,
        private readonly StoreManagerInterface $storeManager
    ) {}

    // ── General ────────────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED);
    }

    public function getBrandName(): string
    {
        $name = (string) $this->scopeConfig->getValue(self::XML_BRAND_NAME);
        if ($name !== '') {
            return $name;
        }
        try {
            return $this->storeManager->getStore()->getName();
        } catch (\Throwable) {
            return '';
        }
    }

    public function getBrandDomain(): string
    {
        $domain = (string) $this->scopeConfig->getValue(self::XML_BRAND_DOMAIN);
        if ($domain !== '') {
            return rtrim($domain, '/');
        }
        try {
            $url = $this->storeManager->getStore()->getBaseUrl();
            return parse_url($url, PHP_URL_HOST) ?: '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return string[] */
    public function getBrandKeywords(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_BRAND_KEYWORDS);
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    public function getStoreCategory(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_STORE_CATEGORY);
    }

    /** @return string[] */
    public function getTopProducts(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_TOP_PRODUCTS);
        return array_filter(array_map('trim', explode("\n", $raw)));
    }

    public function isLogEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_LOG_ENABLED);
    }

    public function getCacheTtlHours(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_CACHE_TTL) ?: 24);
    }

    // ── ChatGPT ────────────────────────────────────────────────────────────

    public function isGptEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_GPT_ENABLED);
    }

    public function getGptApiKey(): string
    {
        return $this->encryptor->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_GPT_API_KEY)
        );
    }

    public function getGptModel(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_GPT_MODEL) ?: 'gpt-4o';
    }

    public function getGptMaxTokens(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_GPT_MAX_TOKENS) ?: 800;
    }

    public function getGptTemperature(): float
    {
        return (float) $this->scopeConfig->getValue(self::XML_GPT_TEMPERATURE) ?: 0.3;
    }

    public function getGptTimeout(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_GPT_TIMEOUT) ?: 45;
    }

    // ── Claude ─────────────────────────────────────────────────────────────

    public function isClaudeEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_CLAUDE_ENABLED);
    }

    public function getClaudeApiKey(): string
    {
        return $this->encryptor->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_CLAUDE_API_KEY)
        );
    }

    public function getClaudeModel(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_CLAUDE_MODEL) ?: 'claude-sonnet-4-6';
    }

    public function getClaudeMaxTokens(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_CLAUDE_MAX_TOKENS) ?: 800;
    }

    public function getClaudeTimeout(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_CLAUDE_TIMEOUT) ?: 60;
    }

    // ── Perplexity ─────────────────────────────────────────────────────────

    public function isPerplexityEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PPX_ENABLED);
    }

    public function getPerplexityApiKey(): string
    {
        return $this->encryptor->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_PPX_API_KEY)
        );
    }

    public function getPerplexityModel(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PPX_MODEL) ?: 'sonar';
    }

    public function getPerplexityMaxTokens(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PPX_MAX_TOKENS) ?: 800;
    }

    public function getPerplexityTimeout(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PPX_TIMEOUT) ?: 45;
    }


    // ── Gemini ─────────────────────────────────────────────────────────────

    public function isGeminiEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_GEMINI_ENABLED);
    }

    public function getGeminiApiKey(): string
    {
        return $this->encryptor->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_GEMINI_API_KEY)
        );
    }

    public function getGeminiModel(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_GEMINI_MODEL) ?: 'gemini-2.0-flash';
    }

    public function getGeminiMaxTokens(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_GEMINI_MAX_TOKENS) ?: 800;
    }

    public function getGeminiTimeout(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_GEMINI_TIMEOUT) ?: 45;
    }

    // ── Query settings ─────────────────────────────────────────────────────

    public function getQueriesPerProvider(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_Q_QUERIES_PER_RUN) ?: 3);
    }

    public function getDelayBetweenQueriesMs(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_Q_DELAY_MS) ?: 500);
    }

    public function getSystemPrompt(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_Q_SYSTEM_PROMPT)
            ?: 'You are a knowledgeable shopping assistant. Answer questions about online stores and products accurately. When you know a specific store, mention it by name and include its website URL.';
    }

    /**
     * Returns all active prompt templates keyed by prompt_key.
     * Merges: built-in enabled prompts + any custom prompts from config.
     *
     * @return array<string, string>  [key => template]
     */
    public function getActivePrompts(): array
    {
        $prompts = [];

        $builtIn = $this->getBuiltInPrompts();
        foreach ($builtIn as $key => $xmlPath) {
            $override = (string) $this->scopeConfig->getValue($xmlPath);
            if ($override !== '') {
                $prompts[$key] = $override;
            } elseif ($this->isPromptEnabled($key)) {
                $prompts[$key] = $this->defaultPromptTextPublic($key);
            }
        }

        // Parse custom prompts block: one per line, format "key: template text"
        $custom = (string) $this->scopeConfig->getValue(self::XML_Q_CUSTOM_PROMPTS);
        foreach (array_filter(array_map('trim', explode("\n", $custom))) as $line) {
            if (str_contains($line, ':')) {
                [$k, $t] = explode(':', $line, 2);
                $k = trim(preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($k))));
                $t = trim($t);
                if ($k !== '' && $t !== '') {
                    $prompts[$k] = $t;
                }
            }
        }

        // Respect queries_per_provider cap
        $max = $this->getQueriesPerProvider();
        return array_slice($prompts, 0, $max, true);
    }

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

    // ── Scoring ────────────────────────────────────────────────────────────

    public function getScoringWeight(string $signal): float
    {
        $map = [
            'mentioned'          => self::XML_S_MENTIONED,
            'recommended'        => self::XML_S_RECOMMENDED,
            'url_cited'          => self::XML_S_URL_CITED,
            'first_result'       => self::XML_S_FIRST_RESULT,
            'positive_sentiment' => self::XML_S_POSITIVE,
        ];
        $val = isset($map[$signal]) ? $this->scopeConfig->getValue($map[$signal]) : null;
        return $val !== null ? (float) $val : $this->defaultWeight($signal);
    }

    public function getPassThreshold(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_S_PASS_THRESHOLD) ?: 60;
    }

    public function getWarnThreshold(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_S_WARN_THRESHOLD) ?: 30;
    }

    // ── Cron ───────────────────────────────────────────────────────────────

    public function isCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_CRON_ENABLED);
    }

    // ── Private ────────────────────────────────────────────────────────────

    private function getBuiltInPrompts(): array
    {
        return [
            'recommendation' => self::XML_Q_RECOMMENDATION,
            'category'       => self::XML_Q_CATEGORY,
            'brand_direct'   => self::XML_Q_BRAND_DIRECT,
            'product_search' => self::XML_Q_PRODUCT_SEARCH,
            'comparison'     => self::XML_Q_COMPARISON,
            'gift_guide'     => self::XML_Q_GIFT_GUIDE,
        ];
    }

    private function isPromptEnabled(string $key): bool
    {
        // All built-in prompts enabled by default unless explicitly empty in config
        $field = match ($key) {
            'recommendation' => 'angeo_brand_vis/queries/enable_recommendation',
            'category'       => 'angeo_brand_vis/queries/enable_category',
            'brand_direct'   => 'angeo_brand_vis/queries/enable_brand_direct',
            'product_search' => 'angeo_brand_vis/queries/enable_product_search',
            'comparison'     => 'angeo_brand_vis/queries/enable_comparison',
            'gift_guide'     => 'angeo_brand_vis/queries/enable_gift_guide',
            default          => null,
        };
        if ($field === null) {
            return false;
        }
        $val = $this->scopeConfig->getValue($field);
        // Default on if config not yet set
        return $val === null || (bool) $val;
    }

    // ── Groq ───────────────────────────────────────────────────────────────

    public function isGroqEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_GROQ_ENABLED);
    }

    public function getGroqApiKey(): string
    {
        return $this->encryptor->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_GROQ_API_KEY)
        );
    }

    public function getGroqModel(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_GROQ_MODEL) ?: 'llama-3.3-70b-versatile';
    }

    public function getGroqMaxTokens(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_GROQ_MAX_TOKENS) ?: 800;
    }

    public function getGroqTimeout(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_GROQ_TIMEOUT) ?: 30;
    }

    public function defaultPromptTextPublic(string $key): string
    {
        $brand    = $this->getBrandName();
        $category = $this->getStoreCategory() ?: 'products';
        $products = implode(', ', array_slice($this->getTopProducts(), 0, 2));

        return match ($key) {
            'recommendation' => "What are the best online stores to buy {$category}? List specific store names and websites.",
            'category'       => "Where can I buy {$category} online? Give me specific recommendations with store names and URLs.",
            'brand_direct'   => "Tell me about {$brand} online store. What do they sell, what is their website, and would you recommend them?",
            'product_search' => $products
                ? "I'm looking for {$products} online. Which stores do you recommend and why?"
                : "What online stores sell the best {$category}? Include website URLs.",
            'comparison'     => "Compare {$brand} with other similar online stores for buying {$category}. What are the pros and cons of each?",
            'gift_guide'     => "I'm looking for gift ideas for someone who likes {$category}. Which online stores have the best selection?",
            default          => "Tell me about online stores that sell {$category}.",
        };
    }

    private function defaultWeight(string $signal): float
    {
        return match ($signal) {
            'mentioned'          => 1.0,
            'recommended'        => 1.5,
            'url_cited'          => 1.5,
            'first_result'       => 2.0,
            'positive_sentiment' => 0.5,
            default              => 1.0,
        };
    }
}
