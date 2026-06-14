# Changelog

All notable changes to `angeo/module-aeo-brand-visibility` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
