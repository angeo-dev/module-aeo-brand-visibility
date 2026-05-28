# Changelog

All notable changes to `angeo/module-aeo-brand-visibility` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
