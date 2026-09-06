# Angeo AEO Brand Visibility for Magento 2

[![Latest Stable Version](https://img.shields.io/packagist/v/angeo/module-aeo-brand-visibility.svg)](https://packagist.org/packages/angeo/module-aeo-brand-visibility)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Measure whether AI assistants actually name your store when a shopper asks them
where to buy something.

The module asks ChatGPT, Claude, Perplexity, Gemini and Groq a set of shopping
questions, reads their answers, and scores how visible your brand is. It repeats
every question several times, so the score comes with a confidence interval
instead of pretending one sample is the truth.

It plugs into [`angeo/module-aeo-audit`](https://github.com/angeo-dev/module-aeo-audit)
as a `live_signal` checker, and also runs on its own from the admin panel, cron
or the command line.

---

## Why this exists

Classic SEO tools tell you where you rank on a results page. They tell you
nothing about what a language model says when someone asks it a question. This
module measures that directly: it asks, then reads the answer.

---

## What is measured

Each answer is scored on five signals:

| Signal | Question it answers | Default weight |
| --- | --- | --- |
| Mentioned | Does the answer name the brand at all? | 1.0 |
| Recommended | Is the brand suggested, not just listed? | 1.5 |
| URL cited | Is your domain quoted? | 1.5 |
| First position | Is the brand named before its competitors? | 2.0 |
| Positive tone | Is the wording around the brand favourable? | 0.5 |

A sixth signal, **negative tone**, is not weighted. It reduces the earned score
by the configured penalty, because "avoid this shop" is worse than not being
mentioned at all.

On top of the per-answer score the module reports:

- **Share of voice** — your weight against every tracked competitor in the same
  answer, based on how often you are named and how early.
- **Win rate** — the share of answers where you are named before every
  competitor.
- **Accuracy issues** — answers where a model attributes a website you do not
  own to your brand.

---

## Reading the score honestly

Two things decide whether the number means anything.

**Grounding.** A model answering from memory reflects a training set that is
months old. Nothing you change on your store will move that number this quarter.
A grounded model searches the live web before answering, so it responds to your
work within days. Perplexity is always grounded; ChatGPT, Claude and Gemini can
be, per provider, in the configuration. Groq cannot. If you want a metric that
reacts to what you do, enable grounding.

**Sampling.** Language models are not deterministic. The same prompt returns
different answers. Every provider and prompt pair is therefore asked N times
(three by default) and reported as a mean with the half-width of its 95%
confidence interval — the `±` figure next to the score. A movement smaller than
that margin is noise, and the alert system will not fire on it.

---

## Requirements

- Magento Open Source or Adobe Commerce 2.4.6 – 2.4.8
- PHP 8.2, 8.3 or 8.4
- `angeo/module-aeo-audit` 3.x or 4.x
- At least one AI provider API key
- A running message queue consumer (or inline run mode, see below)

---

## Installation

```bash
composer require angeo/module-aeo-brand-visibility
bin/magento module:enable Angeo_AeoBrandVisibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Start the consumer that executes queued audits:

```bash
bin/magento queue:consumers:start angeoBrandVisAuditRun
```

On most installations `consumers_runner` in `app/etc/env.php` starts it from
cron automatically. If your platform does not run message queue consumers at
all, set **Run Mode** to *Inline* in the configuration; be aware that a full run
then happens inside the admin request and may hit the PHP or gateway timeout.

---

## Configuration

**Stores → Configuration → Angeo AEO → Brand Visibility (AI Models)**

Almost every setting is available per website and per store view, so a
multi-store installation can measure each brand separately.

### General

| Field | Notes |
| --- | --- |
| Enable Brand Visibility | Includes the check in every AEO audit for this scope |
| Run Mode | Background queue (recommended) or inline |
| Brand Name | Defaults to the store name |
| Brand Domain | Defaults to the store base URL host |
| Brand Aliases | Alternate spellings, comma separated |
| Store Category | What you sell, in two or three words |
| Top Products | One per line; derived from the catalogue when empty |
| Cache Lifetime | Hours; `0` disables caching completely |
| Log Prompts and Answers | Writes to `var/log/angeo_aeo_brand_visibility.log` |

**Set the store category.** When it is empty and the catalogue gives nothing
useful, prompts fall back to a generic phrase, and generic phrases are won by
the large marketplaces every time. This single field moves the score more than
any other.

### Providers

Each provider has its own group with the same fields: enable, API key (stored
encrypted), model, grounding, max tokens, temperature and timeout.

| Provider | Grounded answers | Notes |
| --- | --- | --- |
| ChatGPT | Optional | Needs a search-capable model such as `gpt-4o-search-preview` |
| Claude | Optional | Uses the server-side web search tool; billed per search |
| Perplexity | Always | The most responsive signal of the five |
| Gemini | Optional | Uses Google Search as a tool |
| Groq | No | Free tier; a memory-only baseline |

Model identifiers change often. Pick the closest option, and expect to revisit
the field after a provider release.

### Queries

Six built-in prompts, each with its own on/off switch and optional override
text: best stores in category, where to buy, tell me about this brand, product
search, compare with competitors, gift guide. Add your own under **Custom
Prompts**, one per line, as `key: prompt text`.

Placeholders: `{{brand}}`, `{{domain}}`, `{{category}}`, `{{products}}`.

**Samples per Query** controls how many times each pair is asked. Cost scales
linearly with it; the confidence interval narrows with the square root of it.

### Analysis

Phrase matching supports **English, Ukrainian, Dutch, German, French and
Spanish**. English phrases are always matched in addition to the selected
language, because AI answers mix languages freely.

**Extra Top-Level Domains** matters for the accuracy check: without `nl`, `de`
or `co.uk` in that field, a wrong country domain attributed to your brand will
not be flagged.

### Alerts

An alert fires when the score drops by more than the configured number of points
against the trailing average **and** the drop exceeds the confidence margin of
the run, or when a competitor outranks you, or negative tone or a wrong URL is
detected.

The webhook must be HTTPS, and hosts resolving to private or reserved addresses
are rejected unless an operator explicitly allows them.

---

## Command line

```bash
# Full audit for the default scope
bin/magento angeo:aeo:brand-visibility

# One store view, ignoring the cache, with the action plan
bin/magento angeo:aeo:brand-visibility --store=2 --refresh --plan

# Machine-readable output
bin/magento angeo:aeo:brand-visibility --format=json
bin/magento angeo:aeo:brand-visibility --format=markdown

# One provider and prompt, printing the raw answer
bin/magento angeo:aeo:brand-visibility --provider=perplexity --prompt=brand_direct

# Fail a CI pipeline below a threshold
bin/magento angeo:aeo:brand-visibility --fail-on=50
```

| Option | Meaning |
| --- | --- |
| `--store` | Store view id (default `0`) |
| `--refresh`, `-r` | Bypass the result cache |
| `--provider` | Query one provider instead of the full audit |
| `--prompt` | Prompt key used with `--provider` |
| `--format` | `table`, `json` or `markdown` |
| `--plan` | Print the action plan |
| `--fail-on` | Exit code 1 below this score |

---

## Admin panel

**Marketing → AEO Brand Visibility**

- **Dashboard** — pick a store view, start a run, watch it complete, read each
  answer, and generate the action plan from the latest stored run.
- **Audit History** — a standard grid with filters, plus a detail page and CSV
  export per run.

Access is split into four permissions, so an agency can be given read access
without the ability to spend money on API calls:

| Resource | Grants |
| --- | --- |
| `Angeo_AeoBrandVisibility::view` | Read reports and history |
| `Angeo_AeoBrandVisibility::run` | Start audits and single test queries |
| `Angeo_AeoBrandVisibility::export` | Download CSV |
| `Angeo_AeoBrandVisibility::config` | Change the configuration |

---

## Scheduling and retention

Scheduled runs use the dedicated `angeo_brand_visibility` cron group, so slow
provider calls cannot delay Magento's default group. A second job applies the
retention policy nightly: runs older than the configured age, and runs beyond
the per-store-view budget, are deleted.

---

## Cost

Cost is `providers × prompts × samples` requests per run. With three providers,
three prompts and three samples that is 27 requests. Weekly runs on small models
are cents; grounded searches on large models are not. Start with `samples = 3`
and one grounded provider.

---

## Development

```bash
vendor/bin/phpcs --standard=vendor/angeo/module-aeo-brand-visibility/phpcs.xml
vendor/bin/phpstan analyse -c vendor/angeo/module-aeo-brand-visibility/phpstan.neon
vendor/bin/phpunit -c vendor/angeo/module-aeo-brand-visibility/phpunit.xml
```

Adding a provider takes an `AiProviderInterface` implementation and one line in
the `ProviderPool` argument of `etc/di.xml`. No existing class needs editing.

---

## Related modules

- `angeo/module-aeo-audit` — the audit framework this checker plugs into
- `angeo/module-llms-txt` — publish `llms.txt` for AI crawlers
- `angeo/module-rich-data` — stronger Organization and Product schema
- `angeo/module-robots-txt-aeo` — control which AI crawlers may read the store

---

## License

MIT. See [LICENSE](LICENSE).

Built by [Ievgenii Gryshkun](https://angeo.dev) — Magento 2 solution architect,
Netherlands.
