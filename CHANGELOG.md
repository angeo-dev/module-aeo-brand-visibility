# Changelog

All notable changes to `angeo/module-aeo-brand-visibility` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project uses [Semantic Versioning](https://semver.org/).

## [4.0.0] — 2026-08-31

A rewrite for the Adobe Commerce Marketplace technical requirements, plus fixes
for the data-loss and methodology problems found in 3.0.0.

### Breaking

- `BrandVisibilityServiceInterface::run()` takes a fourth argument,
  `?int $auditResultId`, so a queued run can be written back onto its own row.
- `AuditResultRepositoryInterface::saveReport()` takes `?int $id`;
  `createPendingRun()` and `markFailed()` were added; `pruneOldRecords()` was
  replaced by `prune(int $maxPerStore, int $maxAgeDays)`.
- `BrandQueryResult` now represents a cell aggregated over N samples. Its
  `signals` array of booleans became `signalRates` (percentages), and
  `rawResponse` became `responses` with a `getRawResponse()` accessor.
- Configuration path `angeo_brand_vis/queries/queries_per_provider` was renamed
  to `.../max_prompts`; `0` now means "no cap" rather than "cap at zero".
- `angeo_brand_vis/analysis/llm_judge` was removed. It cost a second API call
  per answer and produced a number that could not be compared between runs.
- Admin route `angeo_brand_vis/query/single` became `angeo_brand_vis/query/test`
  and is POST-only; `angeo_brand_vis/history/viewdata` was removed in favour of
  the UI component grid.
- The single ACL resource was split into `::view`, `::run`, `::export` and
  `::config`. Existing admin roles must be re-granted.
- New database columns are added by declarative schema. Runs stored by 3.0.0
  keep their scores, but have no samples, margin or competitive data.

### Added

- Repeated sampling with a 95% confidence interval on every score. The reported
  `±` margin makes it possible to tell a real regression from model variance.
- Web-grounded answers per provider: Claude's server-side search tool, Gemini's
  Google Search tool, OpenAI search-preview models. Perplexity is always
  grounded; Groq cannot be.
- Three-valued tone. Negative wording around the brand is detected and reduces
  the earned score by a configurable penalty.
- Real share of voice, weighted by mention count and by how early the brand
  appears, replacing the `1/N` placeholder.
- Asynchronous runs over the message queue, with a pending row, a polling admin
  screen and an inline fallback for installations without consumers.
- A dedicated `angeo_brand_visibility` cron group, and a nightly retention job
  with a per-store-view row budget and a maximum age.
- A standard UI component grid for audit history, with filters, bookmarks and
  row actions.
- Configurable extra top-level domains, so the accuracy check recognises `nl`,
  `de`, `co.uk` and other country domains.
- Unit tests for the aggregator, the share-of-voice calculator, the report
  aggregation, the webhook guard and the configuration reader.
- `i18n/en_US.csv`, `etc/csp_whitelist.xml`, `phpcs.xml`, `phpstan.neon` and
  `phpunit.xml`.

### Changed

- Providers are injected as an array through `ProviderPool` in `di.xml` instead
  of being hard-coded in the service constructor. A new provider is now one
  interface implementation and one di.xml line.
- All HTTP traffic uses the framework HTTP client instead of raw cURL calls, and
  all JSON goes through `SerializerInterface`.
- The admin templates contain no inline `<script>`, no inline `<style>` and no
  event attributes. Behaviour moved to a RequireJS module loaded through
  `data-mage-init`, so the screens work under a restrictive content security
  policy.
- The action plan is built from the latest stored run instead of triggering a
  fresh billable audit on every request.
- Retention runs in its own cron job rather than inside every save.
- OpenAI reasoning models receive `max_completion_tokens` and no `temperature`,
  so o-series and search-preview models no longer return HTTP 400.
- Provider labels, model option lists and the analysis language list are derived
  from code rather than duplicated in static arrays.

### Fixed

- Analyser metadata — competitors, share of voice, winner, tone and accuracy —
  is now persisted. In 3.0.0 `saveReport()` dropped the `meta` key, so the
  headline feature of that release was lost on write and never reached the
  history or the CSV export.
- `getLatest()`, `getStatistics()` and retention are scoped by store view.
  Previously a multi-store installation averaged unrelated brands together, and
  alerts compared a store against that mixed average.
- The audit checker honours the `StoreInterface` it is given. Previously the
  argument was ignored and every store view was scored against the default
  scope.
- `cache_ttl_hours = 0` disables the cache, as the field comment always claimed;
  it was previously coerced back to 24. The same fix applies to a temperature of
  `0`.
- A prompt override text is ignored while its enable toggle is off.
- The result cache lifetime is read from the scoped configuration rather than
  the unscoped instance.
- The Gemini API key travels in the `x-goog-api-key` header instead of the query
  string, where it was written to proxy and access logs.
- The alert webhook is validated: HTTPS only, and hosts resolving to private or
  reserved addresses are rejected unless explicitly allowed. This closes a
  server-side request forgery path to internal services.
- The CLI used an invalid `<e>` style tag, which threw instead of printing the
  error it was trying to report.
- Dutch and German separable verbs ("raad ik … aan", "empfehle ich") are matched
  as recommendations.
- `magento/module-email` is declared in `composer.json` and sequenced in
  `module.xml`; it was used but never required.
- Documentation drift: the supported analysis languages are `en, uk, nl, de, fr,
  es` (there is no Russian phrase set), negative sentiment is real rather than
  aspirational, and the CLI description lists every provider.

### Security

- No raw cURL, no API keys in URLs, no unvalidated outbound requests.
- Every billable action is POST-only and behind its own ACL resource.
- Admin output is escaped in PHP and built with the DOM API in JavaScript, so a
  provider answer cannot inject markup into the admin panel.

## [3.0.0]

### Added

- Competitor tracking, share of voice and answer-accuracy checking.
- Gemini and Groq providers.
- Recommendation engine and CSV export.

## [1.1.1]

### Added

- Initial release: ChatGPT, Claude and Perplexity providers, five scoring
  signals, admin dashboard and the `live_signal` audit checker.
