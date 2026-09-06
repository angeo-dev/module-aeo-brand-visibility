# Changelog

All notable changes to `angeo/module-aeo-brand-visibility` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] — 2026-07-03

The measurement-validity release. Until now 4 of 5 providers measured
*training recall* — what a model memorised months ago — while the UI implied
live AI-search visibility. 2.0 closes that gap and adds the competitive
dimension. **Breaking changes** (see Upgrading below).

### Added

- **Grounded (live web search) mode per provider.** ChatGPT via the Responses
  API + `web_search` tool, Gemini via Grounding with Google Search, Claude via
  the server-side `web_search` tool — the same retrieval real users get.
  Toggle *Live Web Search* under each provider (default OFF; grounded calls
  cost more and get a longer timeout). Perplexity is always grounded; Groq
  never is. Each result records which mode produced it, and the report never
  averages the two silently — `groundingBreakdown()` surfaces the mix.
- **Share of voice.** A competitor watch-list (*Response Analysis → Competitor
  Watch-list*, `Name | domain.tld` per line) turns "your score is 40" into
  "you appear in 20% of answers, competitor X in 80%". The analyzer now
  extracts every competitor mention and every domain cited in each answer;
  `BrandVisibilityReport::shareOfVoice()` ranks brand vs competitors, persisted
  in the new `share_of_voice` column and surfaced in the audit checker.
- **Structured citations.** Providers return their source URLs as structured
  data (`ProviderResponse::$citations`) instead of Perplexity smuggling them
  into the text as a "Sources:" suffix. A citation of your own domain now
  feeds `url_cited` directly — being a cited source is the strongest
  visibility outcome, and it is scored as such.
- **Repeats per prompt (1–5).** LLM answers are stochastic even at low
  temperature. Sampling each prompt N times and keeping the MEDIAN score /
  majority-vote signals turns noisy single samples into stable trend points.
  Default 1 (cost-neutral); 3 recommended for weekly tracking. A failed
  attempt no longer poisons the rest; all-failed still yields a clean error
  result.
- **Pluggable provider registry.** `BrandVisibilityService` now receives an
  `AiProviderInterface[]` via di.xml. Third-party modules add a provider
  (Mistral, DeepSeek, a local Ollama, …) with one di.xml `<item>` — no core
  edits. Ships with the five built-ins wired in `etc/di.xml`.

### Changed

- **`AiProviderInterface` (BC break).** `query()` now returns a
  `ProviderResponse` (text + citations + grounded) instead of a bare string,
  and adds `supportsGrounding()` / `isGrounded()`. Any custom provider must be
  updated.
- **`BrandVisibilityService` constructor (BC break).** The five concrete
  provider arguments are replaced by a single `providers` array (injected via
  di.xml). Custom instantiation must pass the array.
- **Cache namespace bumped** to `angeo_bv2_` — the 2.0 payload shape must not
  hydrate from 1.x cache entries. Old entries expire naturally; no action
  needed.

### Database

- New nullable `share_of_voice` column on `angeo_brand_visibility_audit`
  (declarative schema — applied by `setup:upgrade`). No data migration.

### Upgrading from 1.3.x

1. `composer require angeo/module-aeo-brand-visibility:^2.0`
2. `bin/magento setup:upgrade && bin/magento setup:di:compile`
3. If you wrote a **custom AiProviderInterface** implementation, update it to
   return `ProviderResponse` and implement the two new methods.
4. If you **instantiate `BrandVisibilityService` yourself** (not via DI),
   pass providers as the `providers` array argument.
5. Optional: add competitors under *Response Analysis*, enable *Live Web
   Search* per provider, and set *Repeats Per Prompt* to 3 for stable trends.

## [1.3.0] — 2026-07-02

Release hygiene + the multilingual analyzer. Drop-in upgrade from 1.2.x
(`composer update`, `setup:upgrade`, `setup:di:compile`) — no DB changes;
one config field renamed with a read fallback, saved values survive.

### Added

- **Multilingual response analysis.** Recommendation and sentiment phrases
  now come from per-language packs — English, Dutch, German, French and
  Ukrainian (`Service/Analysis/PhrasePack`). AI assistants answer in the
  shopper's language; before this release a Dutch "zeker aan te raden" or a
  German "sehr empfehlenswert" scored `recommended = false`. Languages are
  selectable under *Response Analysis → Analysis Languages* (empty = all
  packs, the safe default). Adding a language is one array in PhrasePack.
- **`{{language}}` prompt placeholder** plus a *Query Language* config field
  — probe AI models in the language of your market, not just English.
- **GitHub Actions CI** (`.github/workflows/ci.yml`): phpcs, PHPStan, PHPUnit
  on PHP 8.2/8.3/8.4, and a Mage-OS mirror installability job. The
  aeo-audit dependency resolves from GitHub until new tags reach Packagist.
- **`i18n/en_US.csv`** — base translation dictionary (46 admin strings).

### Changed

- **Unicode word-boundary brand matching.** Brand name and keywords are now
  matched as whole words (`(?<![\p{L}\p{N}]) … (?![\p{L}\p{N}])`), so the
  brand "Geo" no longer matches inside "geography" and short keywords stop
  firing on unrelated words. Domain matching intentionally remains
  substring-based (domains are distinctive and live inside URLs).
- **Deterministic provider temperatures.** Claude now sends an explicit
  `temperature: 0.2` (previously unset → provider default ≈ 1.0); Gemini and
  Groq aligned from 0.3 to 0.2. Visibility measurement needs the model's
  most probable answer — creative variance was score jitter between runs.
- **"Queries Per Provider" renamed to "Max Prompts Per Provider"** — the old
  name described the cap incorrectly (it limits prompts; total queries =
  prompts × providers). Values saved under the legacy path are still read.
- `angeo/module-aeo-audit` constraint widened to `^3.0||^4.0` — compatible
  with the aeo-audit 4.0.0 evidence-layer release.

### Fixed

- `GroqProviderTest::testIsConfiguredWithApiKey` never mocked the enabled
  flag and silently failed — it had never actually run (no CI existed).
  Fixed; full suite green (63 tests).

## [1.2.0] — 2026-06-12

Security hardening and code-quality release. No database or configuration
changes — upgrading from 1.1.x is drop-in (`composer update`, then
`bin/magento setup:upgrade && bin/magento setup:di:compile`).

### Security

- **Admin UI XSS hardening.** The JavaScript escaper used when injecting
  AI-provider responses into the Run/History/Single-Test panels now escapes
  single quotes, backticks and slashes in addition to `& < > "`, making it safe
  for both HTML-text and quoted-attribute contexts. All untrusted provider text
  (raw responses, prompts, labels) is routed through it.
- **Gemini API key moved out of the URL.** The key is now sent in the
  `x-goog-api-key` header instead of the `?key=` query parameter, so it can no
  longer appear in proxy logs, access logs or error messages.
- **Outbound HTTP locked down.** The shared provider transport is now HTTPS-only
  (`CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS`), no longer follows redirects
  (`CURLOPT_FOLLOWLOCATION` disabled — SSRF guard), and sets an explicit
  `CURLOPT_CONNECTTIMEOUT`.
- **No exception detail leaks to the browser.** Admin AJAX controllers
  (`Run`, `Plan`, `History/Data`, `History/ViewData`) now log the full
  exception to the dedicated module log and return a generic message. The
  single-query diagnostic tool still surfaces the provider message (it is a
  manual debugging aid and no longer key-bearing).

### Changed

- **All (de)serialization goes through Magento `SerializerInterface`.** Replaced
  every native `json_encode` / `json_decode` call (provider transport, audit
  result model, history grid column, CLI `--format=json`) with the injected
  serializer. The main service and repository now depend on the interface
  rather than the concrete `Json` class.
- **Cache invalidation fixed.** The audit cache key now includes the set of
  enabled providers, their configured models and the system prompt, so toggling
  a provider or switching a model no longer serves a stale report.
- **`Cache Results (hours) = 0` now truly disables caching.** Previously the
  `0` value was swallowed and silently treated as 24h.
- **Provider list corrected in the CLI.** Command description and the
  `--provider` option help now list all five providers
  (`chatgpt|claude|perplexity|gemini|groq`).

### Tests

- `BrandVisibilityServiceTest` rewritten against the real service contract
  (Magento `CacheInterface`, real method names and constructor signature, real
  serializer) covering guard clauses, cache hits, force-refresh and save-failure
  resilience.
- `GroqProviderTest` updated for the new serializer-aware constructor.

## [1.1.1] — 2026-05-28

### Changed — documentation only (no code changes)

`angeo/module-aeo-audit` v3.0.0 final release ships with **15 built-in
signals**, not the 16 referenced in this module's v1.1.0 README. The
`ai_bot_traffic` checker was removed from aeo-audit during pre-release
security review (it encouraged broad read access on `/var/log/nginx/`,
didn't work on Cloud/containerised hosting, and was dominated by false
positives behind edge caches — see aeo-audit CHANGELOG "Considered and
rejected" for the full rationale).

This patch release synchronises documentation with the published aeo-audit
v3.0.0 counts:

- README: "17th signal alongside the 16 built-in ones" →
  "16th signal alongside the 15 built-in ones"
- README CLI examples: "Full 17-signal audit" → "Full 16-signal audit";
  "16 built-in technical checks" → "15 built-in technical checks"
- README CLI examples: "Run only live signals (this checker + AI bot
  traffic)" → "Run only live signals (this checker — `live_signal`
  category is reserved for third-party live checks)"
- README Related modules: "16-signal CLI audit" → "15-signal CLI audit"
- README How-to-improve: "8-signal technical AEO audit" (stale since v2) →
  "15-signal technical AEO audit"

**No behaviour change.** `BrandVisibilityChecker` continues to register
under `CheckerInterface::CATEGORY_LIVE_SIGNAL` (still a valid constant
in aeo-audit v3.0.0), and aeo-audit's documentation explicitly reserves
that category for this module. Upgrading from 1.1.0 → 1.1.1 is risk-free.

## [1.1.0] — 2026-05-22

### Compatibility — required for `angeo/module-aeo-audit` v3.0+

This release adapts `BrandVisibilityChecker` to the v3 CheckerInterface
introduced in `angeo/module-aeo-audit` 3.0.0. Without this update, attempting
to use brand-visibility v1.0.x with aeo-audit v3.x produces a fatal at boot:

```
Fatal error: Declaration of
Angeo\AeoBrandVisibility\Model\Checker\BrandVisibilityChecker::check(string $baseUrl)
must be compatible with
Angeo\AeoAudit\Api\CheckerInterface::check(Magento\Store\Api\Data\StoreInterface $store)
```

### Changed

- `BrandVisibilityChecker::check()` signature updated to accept
  `\Magento\Store\Api\Data\StoreInterface $store` (was `string $baseUrl`).
- `BrandVisibilityChecker` now extends `\Angeo\AeoAudit\Model\Checker\AbstractChecker`
  instead of implementing `CheckerInterface` directly — gets shared `HttpCache`,
  `StoreUrlSampler`, and result factory helpers automatically.
- Replaced direct `new CheckResult(...)` calls with the v3 named factories
  (`$this->pass()` / `$this->warn()` / `$this->fail()`) — these correctly
  propagate `checkCode`, `weight`, `category` and `severity` into the result.
- The disabled-module case now returns **WARN** instead of the removed
  `STATUS_SKIP` (the v3 status vocabulary is pass / warn / fail only).
- Weight clamped from 1.5 → 1.0 to fit the v3 normalised-weight contract
  (0.0–1.0). Brand visibility remains a top-tier signal because all
  technical checks at 1.0 share the same weight.
- The service-exception catch broadened from `\RuntimeException` to
  `\Throwable` — covers `\Error` and `\LogicException` from upstream
  provider client libraries.

### Added

- `getCategory(): string` → returns `CATEGORY_LIVE_SIGNAL` (external API).
  Allows `bin/magento angeo:aeo:audit --category=technical` to skip the
  brand check for fast cron runs.
- `getSeverity(): string` → returns `SEVERITY_CRITICAL`. Plays with
  `--fail-on-severity=critical` for CI gates.
- `details` array now surfaces full breakdown: score, grade, queries
  run/ok, all four signal rates, cache flag, configured thresholds.
- New unit test suite `Test/Unit/Model/Checker/BrandVisibilityCheckerTest`
  with 13 test cases covering signature compatibility, short-circuits,
  threshold-driven outcomes, recommendation building.

### Migration from 1.0.x

For typical users — `composer update angeo/module-aeo-brand-visibility`.
The DI wiring in `etc/di.xml` is unchanged (the `AuditRunner` argument
extension is identical between v2 and v3 of aeo-audit).

If you pinned `angeo/module-aeo-audit` to v2.x:
- Keep `angeo/module-aeo-brand-visibility` pinned to `^1.0`, OR
- Update both to v3.0+ together.

## [1.0.0] — 2026-04-15

### Added

- Initial release.
- Live AI brand audit across ChatGPT, Claude, Perplexity, Gemini and Groq.
- Five-signal scoring (Mentioned / Recommended / URL Cited / First Result / Sentiment).
- Configurable prompts, models, max_tokens, temperature per provider.
- Admin UI: History grid, Detail view, Run-Audit form, Plan preview.
- CLI: `bin/magento angeo:aeo:brand-visibility`.
- Cron support with cache TTL.
- Auto-registers as the 9th checker in `angeo/module-aeo-audit` via DI.
