# angeo/module-aeo-brand-visibility

[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-aeo-brand-visibility.svg)](https://packagist.org/packages/angeo/module-aeo-brand-visibility)
[![Packagist Downloads](https://img.shields.io/packagist/dt/angeo/module-aeo-brand-visibility.svg)](https://packagist.org/packages/angeo/module-aeo-brand-visibility)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-blue.svg)](https://php.net)
[![Magento](https://img.shields.io/badge/Magento-2.4.x-orange.svg)](https://magento.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

**Live AI brand visibility audit for Magento 2 — queries ChatGPT, Claude, Perplexity, Gemini and Groq with brand-probing prompts and scores real-world AI recall, citation rate and recommendation presence. Multilingual since 1.3: detects recommendations and sentiment in English, Dutch, German, French and Ukrainian answers, and probes models in your market's language via the `{{language}}` placeholder.**

`angeo/module-aeo-brand-visibility` is an open-source Magento 2 module that answers one question: *when someone asks ChatGPT "where should I buy X?", does your store appear in the answer?* It runs configurable prompts across all major AI providers, detects brand signals in responses, and scores your visibility from 0 to 100 with a letter grade.

---

## Table of contents

- [What it measures](#what-it-measures)
- [Supported AI providers](#supported-ai-providers)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Admin Panel](#admin-panel)
- [CLI usage](#cli-usage)
- [Full configuration reference](#full-configuration-reference)
    - [General](#general)
    - [AI Providers](#ai-providers)
    - [Query Prompts](#query-prompts)
    - [Scoring](#scoring)
    - [Cron](#cron)
- [Setup guides](#setup-guides)
    - [Groq API key (free)](#groq-api-key-free)
    - [OpenAI API key](#openai-api-key)
    - [Anthropic Claude API key](#anthropic-claude-api-key)
    - [Google Gemini API key](#google-gemini-api-key)
    - [Perplexity API key](#perplexity-api-key)
- [Scoring explained](#scoring-explained)
- [How to improve your score](#how-to-improve-your-score)
- [Integration with angeo/module-aeo-audit](#integration-with-angeomodule-aeo-audit)
- [Related modules](#related-modules)

---

## What it measures

Each AI query result is analysed for five signals:

| Signal | Description |
|---|---|
| **Mentioned** | Your brand name appears in the AI response |
| **Recommended** | AI actively suggests your store as a destination |
| **URL Cited** | Your domain is included in the answer |
| **1st Position** | Your store is the first recommendation |
| **Positive Sentiment** | Response tone about your brand is positive |

Each signal has a configurable weight. The overall score is a weighted average across all successful query results, converted to 0–100 and graded A–F.

### Two measurement modes (since 2.0)

AI assistants answer in two fundamentally different ways, and they measure different things:

- **Training recall** (default) — the model answers from what it memorised during training. Fast and cheap, but months out of date and blind to your latest content and backlinks.
- **Live web search** (*grounded* toggle) — the model actually searches the web before answering, exactly like a real ChatGPT / Gemini / Claude user's session. This is what "AI search visibility" really means.

Enable *Live Web Search* per provider (ChatGPT, Claude, Gemini; Perplexity is always live, Groq never is). Every result records which mode produced it, and the report reports the mix rather than averaging the two silently.

### Share of voice (since 2.0)

A visibility score in isolation is hard to act on. Add competitors to the watch-list (`Name | domain.tld` per line) and every answer to "what are the best stores for X?" — which already names the competition — is mined for who else shows up. The report ranks your brand against each competitor: *you appear in 20% of answers, competitor X in 80%* names the actual problem an abstract "40/100" hides.

---

## Supported AI providers

| Provider | Models | Cost | Notes |
|---|---|---|---|
| **Groq** | llama-3.3-70b-versatile, mixtral-8x7b | **Free** | Best starting point — 14,400 req/day, no card |
| **Perplexity** | sonar, sonar-pro, sonar-deep-research | Paid | Always live web search — most realistic signal |
| **OpenAI** | gpt-4.1, gpt-4.1-mini, gpt-4o | Paid | Optional live search via Responses API `web_search` |
| **Anthropic Claude** | claude-sonnet-4-6, claude-haiku-4-5 | Paid | Optional live search via `web_search` tool |
| **Google Gemini** | gemini-2.5-flash-preview, gemini-2.0-flash | Free tier + paid | Optional Grounding with Google Search |

> **Extending providers (since 2.0):** providers are wired as a di.xml array. Add your own (Mistral, DeepSeek, a local Ollama, …) by implementing `Angeo\AeoBrandVisibility\Api\AiProviderInterface` and appending one `<item>` to the `providers` argument of `BrandVisibilityService` — no core changes.

### Per-store, alerting, API (since 3.0)

- **Per-store-view scoping** — brand identity, competitors and languages are read at store scope, so a multi-market Magento install gets an independent score, trend and alerting baseline per locale. The scheduled cron runs once per enabled store view.
- **Email alerts** — a scheduled run that drops past a threshold, or a competitor newly overtaking you in share of voice, emails your team. Configured under *Brand Visibility → Alerting*; fires on cron/CLI only.
- **REST API** — `GET /V1/angeo/brand-visibility/latest` (and `…/latest/store/:storeId`) returns the newest summary for headless storefronts and dashboards.
- **LLM-judge sentiment** — optionally let the cheapest configured model classify sentiment in context instead of phrase packs; falls back automatically on failure.
- **Evidence tie-in with `angeo/module-aeo-audit` v4** — when visibility is weak *and* your store's own instrumentation shows AI search crawlers never arrived, the audit checker points you at the WAF instead of at your content.

Enable one or more providers. Each active provider runs all configured prompts, and results are aggregated into a single score.

---

## Requirements

- PHP 8.2, 8.3, or 8.4
- Magento 2.4.6 / 2.4.7 / 2.4.8 (Adobe Commerce / Mage-OS supported)
- `angeo/module-aeo-audit` ^4.0 (the v3 evidence tie-in uses the v4 bot-hit layer)
- `ext-curl`

> **Compatibility note**: 3.x requires `angeo/module-aeo-audit` ^4.0 for the evidence tie-in. If you are on the audit module's v3.x, pin this module to `^2.0`, which requires `^3.0 || ^4.0`.

---

## Installation

```bash
composer require angeo/module-aeo-brand-visibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## Quick start

**Step 1 — Configure your brand**

Go to **Stores → Configuration → Angeo AEO → Brand Visibility → General**:

1. **Brand Name** → your store name as AI systems know it (e.g. `Angeo`)
2. **Brand Domain** → your domain without protocol (e.g. `angeo.dev`)
3. **Store Category** → what you sell (e.g. `Magento development tools`)

**Step 2 — Enable a free provider (Groq)**

Go to **Groq Settings**:

1. Get a free API key at [console.groq.com](https://console.groq.com) — no credit card
2. **Enable Groq** → `Yes`
3. **Groq API Key** → paste your `gsk_...` key
4. **Save Config**

**Step 3 — Run your first audit**

```bash
bin/magento angeo:aeo:brand-visibility
```

Or from Admin Panel: **Marketing → Angeo AEO → Brand Visibility → Run Audit**

---

## Admin Panel

**Marketing → Angeo AEO → Brand Visibility**

### Run Audit

The main dashboard with:

- **Score ring** — overall score 0–100 with letter grade (A–F)
- **Signal breakdown** — mention rate, recommendation rate, URL citation rate, 1st position rate, positive sentiment rate
- **Results table** — per-provider, per-prompt responses with detected signals highlighted
- **Action plan** — prioritised recommendations to improve your score
- **Single Query Tester** — test one provider + one prompt without saving to history

### Audit History

Grid view of all past audit runs with:

- Date, brand, score (colour-coded), grade (badge), triggered by, query count, error count
- Signal pills showing signal rates at a glance
- Click any row action → **View** for full detail page with raw AI responses

### Configuration

**Stores → Configuration → Angeo AEO → Brand Visibility**

---

## CLI usage

```bash
# Full audit — all enabled providers, all enabled prompts
bin/magento angeo:aeo:brand-visibility

# Force fresh queries — bypass cache
bin/magento angeo:aeo:brand-visibility --refresh

# Test a single provider
bin/magento angeo:aeo:brand-visibility --provider=groq

# Test a single provider + specific prompt
bin/magento angeo:aeo:brand-visibility --provider=chatgpt --prompt=brand_direct

# Output as JSON (useful for CI pipelines)
bin/magento angeo:aeo:brand-visibility --format=json

# CI mode — exit code 1 if score below threshold
bin/magento angeo:aeo:brand-visibility --fail-on=70
```

| Option | Values | Description |
|---|---|---|
| `--refresh` / `-r` | flag | Bypass cache, force live queries |
| `--provider` | `chatgpt` `claude` `perplexity` `gemini` `groq` | Test one provider only |
| `--prompt` | `recommendation` `category` `brand_direct` `product_search` `comparison` `gift_guide` | Test one prompt type only |
| `--format` | `table` `json` `markdown` | Output format. Default: `table` |
| `--fail-on` | `0`–`100` | Exit 1 if overall score is below this value |

---

## Full configuration reference

**Path:** Stores → Configuration → Angeo AEO → Brand Visibility

---

### General

| Field | Default | Description |
|---|---|---|
| Brand Name | *(store name)* | Your brand name as AI systems know it |
| Brand Domain | — | Your domain without protocol (e.g. `angeo.dev`). Used for URL citation detection. |
| Brand Keywords | — | Comma-separated aliases the AI may use to refer to your brand |
| Store Category | — | What you sell (e.g. `Magento 2 development tools`). Used in prompts. |
| Top Products / Services | — | Newline-separated product or service names for product search prompts |
| Cache Results (hours) | 12 | How long to cache audit results. `0` = always run live. |
| Enable Cron | No | Run automatically on a schedule |

---

### AI Providers

Each provider has its own section. Enable the ones you have API keys for.

#### ChatGPT (OpenAI)

| Field | Default | Description |
|---|---|---|
| Enable ChatGPT | No | |
| API Key | — | Starts with `sk-`. Stored encrypted. |
| Model | gpt-4.1 | `gpt-4.1-mini` is fastest and cheapest. |
| Max Tokens | 800 | Maximum response length. |
| Request Timeout (s) | 30 | |

#### Claude (Anthropic)

| Field | Default | Description |
|---|---|---|
| Enable Claude | No | |
| API Key | — | Starts with `sk-ant-`. Stored encrypted. |
| Model | claude-sonnet-4-6 | `claude-haiku-4-5` is fastest and cheapest. |
| Max Tokens | 800 | |
| Request Timeout (s) | 60 | |

#### Perplexity

| Field | Default | Description |
|---|---|---|
| Enable Perplexity | No | |
| API Key | — | Stored encrypted. |
| Model | sonar | `sonar-pro` for deeper web search. `sonar-deep-research` for most thorough results. |
| Max Tokens | 800 | |
| Request Timeout (s) | 60 | Perplexity performs live web searches — may be slower. |

> **Note:** Perplexity uses live web search, making it the most realistic indicator of actual AI visibility. It reflects what customers would see today, not what was in training data months ago.

#### Gemini (Google)

| Field | Default | Description |
|---|---|---|
| Enable Gemini | No | |
| API Key | — | Stored encrypted. |
| Model | gemini-2.5-flash-preview-05-20 | `gemini-2.0-flash` has a free tier. |
| Max Tokens | 800 | |
| Request Timeout (s) | 30 | |

#### Groq (Free)

| Field | Default | Description |
|---|---|---|
| Enable Groq | No | |
| API Key | — | Starts with `gsk_`. No credit card required. |
| Model | llama-3.3-70b-versatile | Best quality on free tier. |
| Max Tokens | 800 | |
| Request Timeout (s) | 30 | |

Free tier: **30 RPM, 14,400 requests/day**.

---

### Query Prompts

Six prompt types are available. Enable or disable each individually.

| Prompt Key | Example query sent to AI |
|---|---|
| `recommendation` | "What are the best online stores to buy [category]?" |
| `category` | "Where can I buy [category] online?" |
| `brand_direct` | "Tell me about [brand] — what do they sell and what is their website?" |
| `product_search` | "I'm looking for [top products] online. Which stores do you recommend?" |
| `comparison` | "Compare [brand] with other [category] stores online." |
| `gift_guide` | "Which online stores have the best [category] for gifts?" |

Additional settings:

| Field | Default | Description |
|---|---|---|
| Queries per Provider | 3 | How many prompts to run per enabled provider per audit |
| Delay Between Queries (ms) | 500 | Rate limiting delay between individual API calls |
| System Prompt | *(default)* | Instructions sent to each AI model before the query |
| Custom Prompts | — | Additional prompts, one per line, format: `key: prompt text` |

---

### Scoring

Signal weights determine the contribution of each detected signal to the overall score:

| Signal | Default Weight |
|---|---|
| 1st Position | 2.0 |
| Recommended | 1.5 |
| URL Cited | 1.5 |
| Mentioned | 1.0 |
| Positive Sentiment | 0.5 |

Grade thresholds:

| Score | Grade |
|---|---|
| 90–100 | A |
| 75–89 | B |
| 60–74 | C |
| 40–59 | D |
| 0–39 | F |

---

### Cron

| Field | Default | Description |
|---|---|---|
| Enable Cron | No | Run audit automatically on a schedule |
| Schedule | `0 6 * * *` | Standard cron expression. Default: daily at 6:00 AM. |

Results from cron runs appear in **Audit History** with `triggered_by: cron`.

---

## Setup guides

### Groq API key (free)

1. Go to [console.groq.com](https://console.groq.com) — create an account, **no credit card required**
2. **API Keys → Create API key**
3. Copy the key (starts with `gsk_`)
4. In Magento: **Stores → Configuration → Angeo AEO → Brand Visibility → Groq Settings → API Key**

---

### OpenAI API key

1. Go to [platform.openai.com](https://platform.openai.com) → sign in or create account
2. **API keys → Create new secret key**
3. Copy the key (starts with `sk-`) — shown only once
4. In Magento: **... → ChatGPT Settings → API Key**

---

### Anthropic Claude API key

1. Go to [console.anthropic.com](https://console.anthropic.com) → create account
2. **API Keys → Create Key**
3. Copy the key (starts with `sk-ant-`)
4. In Magento: **... → Claude Settings → API Key**

---

### Google Gemini API key

1. Go to [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey)
2. **Create API key in new project**
3. Copy the key
4. In Magento: **... → Gemini Settings → API Key**

---

### Perplexity API key

1. Go to [perplexity.ai/settings/api](https://perplexity.ai/settings/api)
2. **Generate** → copy the key
3. In Magento: **... → Perplexity Settings → API Key**

---

## Scoring explained

Each AI query produces a `BrandQueryResult` with five boolean signals. Signals are weighted and averaged:

```
query_score = sum(signal_weight for each detected signal) /
              sum(all_signal_weights) * 100
```

The overall score is the average of all successful query scores. Failed queries (API errors) are excluded from the average.

**Example with default weights:**
- 1st Position detected → +2.0
- Recommended detected → +1.5
- URL Cited not detected → 0
- Mentioned detected → +1.0
- Positive Sentiment detected → +0.5

```
query_score = (2.0 + 1.5 + 1.0 + 0.5) / (2.0 + 1.5 + 1.5 + 1.0 + 0.5) * 100
            = 5.0 / 6.5 * 100 = 76.9 → Grade B
```

---

## How to improve your score

| Signal missing | Root cause | Fix |
|---|---|---|
| Not mentioned | AI has no knowledge of your brand | Publish content that AI systems crawl: Dev.to, Reddit, GitHub |
| URL not cited | Domain not in AI training data or live index | Install [`angeo/module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt) to give AI systems a structured map of your site |
| Not recommended | No authority signals in AI-accessible content | Add Product and Organization JSON-LD via [`angeo/module-rich-data`](https://packagist.org/packages/angeo/module-rich-data) |
| Not 1st position | Competitors have stronger AI presence | Increase external mentions: guest posts, Packagist downloads, GitHub stars |
| Negative sentiment | Poor reviews or negative coverage | Address public feedback; ensure AI-crawlable content is positive |

Run `bin/magento angeo:aeo:audit` for a full 15-signal technical AEO audit to identify and fix the infrastructure issues that block AI indexing.

---

## Integration with angeo/module-aeo-audit

When `angeo/module-aeo-audit` v3.0+ is installed (required dependency), this
module adds a `brand_visibility` checker to the AEO audit pipeline as the
**16th signal** alongside the 15 built-in ones.

```bash
# Full 16-signal audit including brand visibility
bin/magento angeo:aeo:audit

# Skip brand visibility (saves API calls) — runs only the 15 built-in technical checks
bin/magento angeo:aeo:audit --category=technical,feed

# Run only live signals (this checker — live_signal category is reserved for third-party live checks)
bin/magento angeo:aeo:audit --category=live_signal
```

Brand visibility is registered with:
- **Category**: `live_signal` — calls external APIs
- **Severity**: `critical` — headline AEO metric
- **Weight**: 1.0 — top-tier signal in the score

Pass/warn/fail status is driven by your configured score thresholds
(default pass = 80, warn = 60).

### Custom-checker authors

This module is the canonical example of how to extend the audit pipeline.
v3 checker contract:

```php
public function check(\Magento\Store\Api\Data\StoreInterface $store): CheckResult;
public function getCategory(): string;   // CheckerInterface::CATEGORY_*
public function getSeverity(): string;   // CheckerInterface::SEVERITY_*
```

Extending `\Angeo\AeoAudit\Model\Checker\AbstractChecker` is the easiest
path — you get `HttpCache` + `StoreUrlSampler` + JSON-LD parsing
helpers + result factory methods for free.

---

## Related modules

| Module | Purpose |
|---|---|
| [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit) | 15-signal CLI audit — robots/llms/schema/UCP/feeds/etc. |
| [`angeo/module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt) | Auto-generates llms.txt and llms.jsonl |
| [`angeo/module-rich-data`](https://packagist.org/packages/angeo/module-rich-data) | Product, Organization, FAQPage JSON-LD schema |
| [`angeo/module-openai-product-feed`](https://packagist.org/packages/angeo/module-openai-product-feed) | ChatGPT Shopping product feed |
| [`angeo/module-ucp`](https://packagist.org/packages/angeo/module-ucp) | Universal Commerce Protocol `/.well-known/ucp` |
| [`angeo/module-ai-description-updater`](https://packagist.org/packages/angeo/module-ai-description-updater) | Bulk AI product description generation |

---

## Security &amp; data handling

This module talks to external AI APIs and renders their responses in the admin
panel, so it follows defensive defaults:

- **API keys** for every provider (ChatGPT, Claude, Perplexity, Gemini, Groq)
  are stored with Magento's `Magento\Config\Model\Config\Backend\Encrypted`
  backend model and rendered as `obscure` fields. They are never written to
  logs. The Gemini key is sent in the `x-goog-api-key` request header rather
  than the URL query string, so it cannot leak into proxy or access logs.
- **Outbound HTTP** is HTTPS-only and does not follow redirects
  (`CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS` pinned to HTTPS,
  `CURLOPT_FOLLOWLOCATION` disabled), with an explicit connect timeout.
- **Admin AJAX endpoints** are protected by ACL
  (`Angeo_AeoBrandVisibility::run`) and Magento's form key; mutating actions
  are POST-only. Unexpected exceptions are logged to the module log and only a
  generic message is returned to the browser.
- **Output escaping**: server-rendered templates use `escapeHtml`/`escapeUrl`,
  and the JS that injects AI-provider text into the admin UI routes every
  untrusted value through a strict HTML/attribute escaper before insertion.
- **Serialization** uses Magento's `SerializerInterface` throughout (no native
  `json_encode`/`json_decode` and no PHP `serialize()` of untrusted data),
  avoiding object-injection surfaces.
- **Log contents**: when *Enable Logging* is on, truncated prompt and response
  previews are written to `var/log/angeo_aeo_brand_visibility.log`. Keep logging
  off in production if your prompts may contain sensitive data.

---

## License

MIT — free to use, modify, and distribute.

---

## Author

**Ievgenii Gryshkun** · [angeo.dev](https://angeo.dev) · [info@angeo.dev](mailto:info@angeo.dev)
