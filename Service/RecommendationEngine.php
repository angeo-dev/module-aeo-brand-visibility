<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\AuditResultRepository;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;

/**
 * Analyses brand visibility signal patterns and produces a prioritised,
 * time-bounded action plan for improving AI brand recall.
 *
 * Each recommendation includes:
 *   - priority    : critical | high | medium | low
 *   - signal      : which signal it addresses
 *   - gap         : current rate vs target
 *   - title       : short label
 *   - problem     : what is wrong and why it matters
 *   - action      : concrete step the merchant can take
 *   - how         : detailed implementation guidance
 *   - tool        : Angeo module or external tool that fixes it
 *   - effort      : quick_win (< 1 day) | short (1 week) | medium (1 month) | long (3+ months)
 *   - impact      : expected score increase in points
 *   - timeline    : human-readable "when to expect results"
 */
class RecommendationEngine
{
    // Signal rate targets — above these a signal is considered healthy
    private const TARGET_MENTIONED   = 80.0;
    private const TARGET_RECOMMENDED = 60.0;
    private const TARGET_URL_CITED   = 50.0;
    private const TARGET_FIRST       = 40.0;
    private const TARGET_POSITIVE    = 50.0;

    // How many weeks each effort level takes to show in AI models
    // AI models update training data or web indexes on different schedules
    private const TIMELINE = [
        'quick_win' => '1–2 weeks',   // Technical fixes AI can crawl fast
        'short'     => '2–4 weeks',   // Content updates indexed by Perplexity-type models quickly
        'medium'    => '1–3 months',  // Backlinks, PR — needs time to build authority
        'long'      => '3–6 months',  // Training data cutoffs, reputation building
    ];

    public function __construct(
        private readonly Config $config,
        private readonly AuditResultRepository $repository,
    ) {}

    /**
     * Generate a full action plan from an audit report.
     *
     * @return array{
     *     score: int,
     *     grade: string,
     *     target_score: int,
     *     expected_timeline: string,
     *     recommendations: array,
     *     trend: array,
     *     brand_context: array,
     * }
     */
    public function buildPlan(BrandVisibilityReport $report): array
    {
        $score = $report->getOverallScore();
        $recs  = $this->generateRecommendations($report);

        // Sort: critical first, then by impact desc
        usort($recs, function ($a, $b) {
            $pOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            $pDiff  = ($pOrder[$a['priority']] ?? 9) - ($pOrder[$b['priority']] ?? 9);
            return $pDiff !== 0 ? $pDiff : ($b['impact'] <=> $a['impact']);
        });

        $targetScore = $this->estimateTargetScore($score, $recs);
        $timeline    = $this->estimateOverallTimeline($recs);

        // Trend from history
        $stats = [];
        try {
            $stats = $this->repository->getStatistics(10);
        } catch (\Throwable) {
            // History not available yet — no problem
        }

        return [
            'score'             => $score,
            'grade'             => $report->getGrade(),
            'target_score'      => $targetScore,
            'expected_timeline' => $timeline,
            'recommendations'   => $recs,
            'trend'             => $stats['trend'] ?? [],
            'brand_context'     => [
                'brand_name'    => $report->brandName,
                'brand_domain'  => $report->brandDomain,
                'signal_rates'  => [
                    'mentioned'          => $report->signalRate('mentioned'),
                    'recommended'        => $report->signalRate('recommended'),
                    'url_cited'          => $report->signalRate('url_cited'),
                    'first_result'       => $report->signalRate('first_result'),
                    'positive_sentiment' => $report->signalRate('positive_sentiment'),
                ],
                'provider_scores' => $report->scoreByProvider(),
                'total_queries'   => count($report->successfulResults()),
                'errors'          => count($report->failedResults()),
            ],
        ];
    }

    // ── Recommendation generators ─────────────────────────────────────────────

    private function generateRecommendations(BrandVisibilityReport $report): array
    {
        $recs = [];

        $mentioned   = $report->signalRate('mentioned');
        $recommended = $report->signalRate('recommended');
        $urlCited    = $report->signalRate('url_cited');
        $firstResult = $report->signalRate('first_result');
        $positive    = $report->signalRate('positive_sentiment');
        $score       = $report->getOverallScore();

        // ── CRITICAL: Brand unknown ──────────────────────────────────────────
        if ($mentioned < 30) {
            $recs[] = [
                'id'       => 'llms_txt',
                'priority' => 'critical',
                'signal'   => 'mentioned',
                'gap'      => self::TARGET_MENTIONED - $mentioned,
                'title'    => 'Publish llms.txt — Make your store visible to AI agents',
                'problem'  => sprintf(
                    'AI models mention your brand in only %.0f%% of queries. Your store is effectively invisible to AI search. '
                    . 'Without a machine-readable store description, AI models have no structured data to pull from when recommending stores in your category.',
                    $mentioned
                ),
                'action'   => 'Install angeo/module-llms-txt and generate your llms.txt and llms.ljson files immediately.',
                'how'      => "1. composer require angeo/module-llms-txt\n"
                    . "2. bin/magento setup:upgrade && bin/magento angeo:llms:generate\n"
                    . "3. Verify: https://" . ($report->brandDomain ?: 'yourstore.com') . "/llms.txt exists and includes your store name, category, and product list.\n"
                    . "4. Submit your URL to Perplexity via their web index submission form.\n"
                    . "5. Add your store to Google My Business if not already done — this feeds into AI knowledge graphs.",
                'tool'     => 'angeo/module-llms-txt',
                'effort'   => 'quick_win',
                'impact'   => 25,
                'timeline' => self::TIMELINE['quick_win'],
            ];
        }

        // ── CRITICAL: Domain never cited ────────────────────────────────────
        if ($urlCited < 20) {
            $recs[] = [
                'id'       => 'url_visibility',
                'priority' => 'critical',
                'signal'   => 'url_cited',
                'gap'      => self::TARGET_URL_CITED - $urlCited,
                'title'    => 'Your domain URL is never cited by AI models',
                'problem'  => sprintf(
                    'AI models include your website URL in only %.0f%% of responses. '
                    . 'Without URL citations, shoppers cannot click through even when your brand is mentioned. '
                    . 'This is the clearest sign that AI models do not have your domain in their active knowledge base.',
                    $urlCited
                ),
                'action'   => 'Ensure your domain appears prominently in structured data, llms.txt, and high-authority backlinks.',
                'how'      => "1. Add Organization schema to your homepage with your exact domain URL:\n"
                    . '   {"@type":"Organization","name":"' . $report->brandName . '","url":"https://' . ($report->brandDomain ?: 'yourstore.com') . '"}'
                    . "\n2. Publish llms.txt with explicit 'Homepage: https://" . ($report->brandDomain ?: 'yourstore.com') . "' entry.\n"
                    . "3. Get your store listed on Trustpilot, Google Shopping, and industry directories — these are primary sources for AI knowledge.\n"
                    . "4. Build 3–5 high-authority editorial backlinks that mention your domain by name.\n"
                    . "5. Run bin/magento angeo:aeo:audit to check your schema.org implementation.",
                'tool'     => 'angeo/module-aeo-audit',
                'effort'   => 'short',
                'impact'   => 20,
                'timeline' => self::TIMELINE['short'],
            ];
        }

        // ── HIGH: Mentioned but not recommended ─────────────────────────────
        if ($mentioned >= 30 && $recommended < 40) {
            $recs[] = [
                'id'       => 'boost_recommendations',
                'priority' => 'high',
                'signal'   => 'recommended',
                'gap'      => self::TARGET_RECOMMENDED - $recommended,
                'title'    => 'AI models know you exist but do not recommend you',
                'problem'  => sprintf(
                    'Your brand is mentioned in %.0f%% of queries but only recommended in %.0f%%. '
                    . 'AI models likely know your brand but associate it with insufficient positive signals — no reviews, '
                    . 'low domain authority, or missing comparison content.',
                    $mentioned, $recommended
                ),
                'action'   => 'Generate high-quality product descriptions and accumulate third-party reviews.',
                'how'      => "1. Run angeo/module-openai-description-updater to regenerate all product descriptions with SEO-optimised content — this is the single fastest way to improve content quality signals.\n"
                    . "2. Enable AggregateRating schema on product pages. AI models use review counts and scores as recommendation signals.\n"
                    . "3. Get your store reviewed on Trustpilot and Google — aim for 50+ reviews with avg ≥ 4.3 stars.\n"
                    . "4. Publish 3–5 category-level buying guides targeting keywords like 'best [category] stores'. These get cited by AI when answering recommendation queries.\n"
                    . "5. Optimise your About Us page to clearly explain what makes your store better than competitors.",
                'tool'     => 'angeo/module-openai-description-updater',
                'effort'   => 'short',
                'impact'   => 18,
                'timeline' => self::TIMELINE['short'],
            ];
        }

        // ── HIGH: Not in first position ─────────────────────────────────────
        if ($mentioned >= 50 && $firstResult < 30) {
            $recs[] = [
                'id'       => 'topical_authority',
                'priority' => 'high',
                'signal'   => 'first_result',
                'gap'      => self::TARGET_FIRST - $firstResult,
                'title'    => 'Your brand appears late in AI responses — not a top-of-mind store',
                'problem'  => sprintf(
                    'Your brand is mentioned in %.0f%% of queries but appears as a top result in only %.0f%%. '
                    . 'AI models rank stores by topical authority, citation frequency, and recency of web mentions. '
                    . 'You are being included as a secondary option after stronger competitors.',
                    $mentioned, $firstResult
                ),
                'action'   => 'Build topical authority with category-focused content and increase mention frequency in AI-indexed sources.',
                'how'      => "1. Generate category descriptions for all main categories using angeo/module-ai-category-content. AI models read category pages as topical authority signals.\n"
                    . "2. Publish a weekly blog post targeting 'best [product type]' and '[category] buying guide' keywords — these are exactly the queries AI models reference.\n"
                    . "3. Get mentioned in at least 2–3 industry roundups or 'best stores' articles per month.\n"
                    . "4. Run an active Google Shopping campaign — ad presence increases AI data freshness.\n"
                    . "5. Submit your sitemap to Bing (feeds Perplexity) and ensure all main category pages are crawlable by OAI-SearchBot and ClaudeBot in robots.txt.",
                'tool'     => 'angeo/module-ai-category-content',
                'effort'   => 'medium',
                'impact'   => 15,
                'timeline' => self::TIMELINE['medium'],
            ];
        }

        // ── MEDIUM: Positive sentiment low ──────────────────────────────────
        if ($positive < self::TARGET_POSITIVE && $mentioned >= 40) {
            $recs[] = [
                'id'       => 'sentiment_improvement',
                'priority' => 'medium',
                'signal'   => 'positive_sentiment',
                'gap'      => self::TARGET_POSITIVE - $positive,
                'title'    => 'AI describes your brand in neutral or negative terms',
                'problem'  => sprintf(
                    'Positive language appears near your brand in only %.0f%% of AI responses. '
                    . 'AI models form sentiment from review aggregation sites, media coverage, and comparison articles. '
                    . 'Neutral sentiment means less confident recommendation language.',
                    $positive
                ),
                'action'   => 'Improve review profiles and publish comparison content that highlights your strengths.',
                'how'      => "1. Actively respond to all Trustpilot and Google reviews — AI models read response quality as a trust signal.\n"
                    . "2. Publish comparison pages: 'MyStore vs Competitor' — these rank well and often get cited by AI.\n"
                    . "3. Add FAQPage schema to product and category pages with questions like 'Why buy from [brand]?'.\n"
                    . "4. Get a journalist or blogger to write a positive feature about your store in a DA 40+ publication.\n"
                    . "5. Run bin/magento angeo:aeo:brand-visibility --provider=perplexity --prompt=comparison to see exactly what AI says about you vs competitors.",
                'tool'     => 'angeo/module-aeo-audit',
                'effort'   => 'medium',
                'impact'   => 10,
                'timeline' => self::TIMELINE['medium'],
            ];
        }

        // ── MEDIUM: robots.txt blocking AI crawlers ──────────────────────────
        // Check via aeo-audit signal presence heuristic
        if ($score < 50 && $urlCited < 30) {
            $recs[] = [
                'id'       => 'robots_txt_check',
                'priority' => 'medium',
                'signal'   => 'url_cited',
                'gap'      => 0,
                'title'    => 'Verify AI crawlers are not blocked in robots.txt',
                'problem'  => 'Low URL citation combined with low overall score is often caused by robots.txt blocking AI crawlers (OAI-SearchBot, ClaudeBot, PerplexityBot). '
                    . 'If blocked, AI models cannot crawl your store and will not have current content in their knowledge base.',
                'action'   => 'Run the AEO audit to check robots.txt and unblock AI crawlers.',
                'how'      => "1. Run: bin/magento angeo:aeo:audit — it checks robots.txt automatically.\n"
                    . "2. Open https://" . ($report->brandDomain ?: 'yourstore.com') . "/robots.txt and look for Disallow rules affecting OAI-SearchBot, ClaudeBot, or PerplexityBot.\n"
                    . "3. If blocked, add explicit Allow rules:\n"
                    . "   User-agent: OAI-SearchBot\n   Allow: /\n\n   User-agent: ClaudeBot\n   Allow: /\n\n   User-agent: PerplexityBot\n   Allow: /\n"
                    . "4. Verify sitemap.xml is declared in robots.txt and is valid XML.",
                'tool'     => 'angeo/module-aeo-audit',
                'effort'   => 'quick_win',
                'impact'   => 12,
                'timeline' => self::TIMELINE['quick_win'],
            ];
        }

        // ── LOW: Perplexity not enabled but would help ──────────────────────
        if (!$this->config->isPerplexityEnabled() && $score < 70) {
            $recs[] = [
                'id'       => 'enable_perplexity',
                'priority' => 'low',
                'signal'   => 'url_cited',
                'gap'      => 0,
                'title'    => 'Enable Perplexity for live web-search recall testing',
                'problem'  => 'Currently you are only testing AI knowledge-base recall (ChatGPT/Claude). '
                    . 'Perplexity uses live web search and is the most accurate real-world signal for how AI search engines find your store today.',
                'action'   => 'Add a Perplexity API key and enable it in Brand Visibility → Perplexity settings.',
                'how'      => "1. Go to perplexity.ai/settings/api and generate an API key.\n"
                    . "2. Enable Perplexity in Stores → Config → Angeo AEO → Brand Visibility → Perplexity.\n"
                    . "3. Re-run the audit. Perplexity results are the most actionable because they reflect current web indexing.",
                'tool'     => null,
                'effort'   => 'quick_win',
                'impact'   => 5,
                'timeline' => 'Immediate',
            ];
        }

        // ── LOW: Image alt texts missing (AEO signal) ───────────────────────
        if ($score < 60) {
            $recs[] = [
                'id'       => 'image_alt_texts',
                'priority' => 'low',
                'signal'   => 'mentioned',
                'gap'      => 0,
                'title'    => 'Generate AI alt text for product images',
                'problem'  => 'Missing or generic image alt texts reduce your product pages\' semantic richness. '
                    . 'AI models that process product feeds use alt text as a product description signal.',
                'action'   => 'Install angeo/module-ai-image-alt to auto-generate descriptive alt texts for all product images.',
                'how'      => "1. composer require angeo/module-ai-image-alt\n"
                    . "2. Configure with your OpenAI or Claude key.\n"
                    . "3. Run: bin/magento angeo:ai-image-alt:run --dry-run to preview.\n"
                    . "4. Remove --dry-run to apply to all products.",
                'tool'     => 'angeo/module-ai-image-alt',
                'effort'   => 'quick_win',
                'impact'   => 5,
                'timeline' => self::TIMELINE['short'],
            ];
        }

        return $recs;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function estimateTargetScore(int $currentScore, array $recs): int
    {
        $totalImpact = array_sum(array_column($recs, 'impact'));
        // Diminishing returns — can't exceed 95
        return min(95, $currentScore + (int) round($totalImpact * 0.75));
    }

    private function estimateOverallTimeline(array $recs): string
    {
        if (empty($recs)) return 'Already performing well';

        $effortMap = ['quick_win' => 1, 'short' => 2, 'medium' => 3, 'long' => 4];
        $highest   = 0;
        $hasCritical = false;

        foreach ($recs as $r) {
            $e = $effortMap[$r['effort']] ?? 1;
            if ($e > $highest) $highest = $e;
            if ($r['priority'] === 'critical') $hasCritical = true;
        }

        return match ($highest) {
            1 => $hasCritical ? '2–4 weeks for first meaningful improvement' : '1–2 weeks',
            2 => '4–8 weeks for significant improvement',
            3 => '2–4 months for major improvement',
            4 => '3–6 months for full recovery',
            default => '4–8 weeks',
        };
    }
}
