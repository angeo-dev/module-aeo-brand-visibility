<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\AbstractChecker;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Magento\Store\Api\Data\StoreInterface;

/**
 * AEO Audit checker that measures real-world AI brand recall.
 *
 * Queries the configured AI providers (ChatGPT, Claude, Perplexity, Gemini,
 * Groq) with brand-probing prompts and scores actual observable behaviour:
 * was the brand mentioned, was the domain cited, was it recommended, was it
 * the first result.
 *
 * Compatible with angeo/module-aeo-audit v3.x. The checker is registered as
 * the 17th built-in signal via di.xml argument extension.
 *
 * Category: live_signal — calls external APIs, may incur paid usage.
 *
 * Results are cached by BrandVisibilityService per its configured TTL so
 * repeated `bin/magento angeo:aeo:audit` runs don't spam AI APIs.
 *
 * @since 1.1.0 — rewritten for module-aeo-audit v3.0.0 contract
 */
class BrandVisibilityChecker extends AbstractChecker
{
    public function __construct(
        HttpCache $httpCache,
        StoreUrlSampler $urlSampler,
        private readonly Config $config,
        private readonly BrandVisibilityService $service,
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'Brand Visibility in AI Models';
    }

    public function getCode(): string
    {
        return 'brand_visibility';
    }

    /**
     * Weight 1.0 (the v3 cap). The v3 score model is normalised: a 1.0-weight
     * critical signal contributes the same as any other 1.0-weight signal, so
     * brand visibility lands among robots.txt, llms.txt and product schema as
     * a top-tier signal — which is the intended editorial position.
     */
    public function getWeight(): float
    {
        return 1.0;
    }

    /**
     * Live signal — calls external paid APIs, cannot run on every cron tick.
     * The audit runner can filter this out with `--category=technical`.
     */
    public function getCategory(): string
    {
        return CheckerInterface::CATEGORY_LIVE_SIGNAL;
    }

    /**
     * Critical — brand recall is the headline AEO metric.
     */
    public function getSeverity(): string
    {
        return CheckerInterface::SEVERITY_CRITICAL;
    }

    public function getFixCommand(): string
    {
        // No "install module X" fix — improving brand recall is a content /
        // backlink effort, not a software install.
        return '';
    }

    public function check(StoreInterface $store): CheckResult
    {
        // 1. Module disabled → WARN, not FAIL. Score is unaffected because the
        //    v3 audit treats WARN as half-weight, and a disabled live-signal
        //    check should not be a deal-breaker.
        if (!$this->config->isEnabled()) {
            return $this->warn(
                'Brand Visibility check is disabled.',
                'Enable it under Stores → Configuration → Angeo AEO → Brand Visibility → General.',
                ['enabled' => false]
            );
        }

        // 2. Brand name not configured → FAIL. The check cannot run at all
        //    without it, and silently passing would mislead operators.
        $brandName = $this->config->getBrandName();
        if ($brandName === '') {
            return $this->fail(
                'Brand name is not set.',
                'Configure your brand name under Stores → Configuration → Angeo AEO → Brand Visibility → General.',
                ['brand_name' => null]
            );
        }

        // 3. Run the live audit (cached per configured TTL inside the service).
        try {
            $report = $this->service->run(forceRefresh: false);
        } catch (\Throwable $e) {
            return $this->fail(
                'Brand visibility check could not run: ' . $e->getMessage(),
                'Verify AI provider API keys and ensure at least one provider is enabled '
                    . 'under Stores → Configuration → Angeo AEO → Brand Visibility.',
                ['exception_class' => $e::class, 'brand_name' => $brandName]
            );
        }

        return $this->buildResult($report);
    }

    private function buildResult(BrandVisibilityReport $report): CheckResult
    {
        $score          = $report->getOverallScore();
        $grade          = $report->getGrade();
        $totalQueries   = count($report->results);
        $successful     = count($report->successfulResults());
        $mentionRate    = $report->signalRate('mentioned');
        $recommendRate  = $report->signalRate('recommended');
        $urlRate        = $report->signalRate('url_cited');
        $firstRate      = $report->signalRate('first_result');
        $cacheNote      = $report->fromCache ? ' (cached)' : '';

        $message = sprintf(
            '%s: score %d/100, grade %s%s. %d/%d queries succeeded. '
                . 'Mentioned %.0f%% | Recommended %.0f%% | URL cited %.0f%% | First %.0f%%.',
            $report->brandName,
            $score,
            $grade,
            $cacheNote,
            $successful,
            $totalQueries,
            $mentionRate,
            $recommendRate,
            $urlRate,
            $firstRate,
        );

        $passThreshold = $this->config->getPassThreshold();
        $warnThreshold = $this->config->getWarnThreshold();

        $details = [
            'score'         => $score,
            'grade'         => $grade,
            'queries_run'   => $totalQueries,
            'queries_ok'    => $successful,
            'rate_mention'  => round($mentionRate, 1),
            'rate_recommend'=> round($recommendRate, 1),
            'rate_url'      => round($urlRate, 1),
            'rate_first'    => round($firstRate, 1),
            'from_cache'    => $report->fromCache,
            'pass_at'       => $passThreshold,
            'warn_at'       => $warnThreshold,
        ];

        $recommendation = $this->buildRecommendation($report, $score);

        return match (true) {
            $score >= $passThreshold => $this->pass($message, $details),
            $score >= $warnThreshold => $this->warn($message, $recommendation, $details),
            default                  => $this->fail($message, $recommendation, $details),
        };
    }

    private function buildRecommendation(BrandVisibilityReport $report, int $score): string
    {
        $tips = [];

        if ($report->signalRate('mentioned') < 50) {
            $tips[] = 'Your brand is largely unknown to AI models. Publish llms.txt '
                . '(angeo/module-llms-txt) to expose your store structure, and ensure '
                . 'your store name appears consistently across all pages.';
        }
        if ($report->signalRate('url_cited') < 30) {
            $tips[] = 'Your domain URL is not being cited. Strengthen your backlink '
                . 'profile and ensure llms.txt explicitly references your canonical URL.';
        }
        if ($report->signalRate('recommended') < 40) {
            $tips[] = 'AI models are not actively recommending your store. Improve '
                . 'product content quality (angeo/module-openai-description-updater) '
                . 'and ensure review schema is implemented.';
        }
        if ($report->signalRate('first_result') < 30) {
            $tips[] = 'Your brand appears late in AI responses. Build topical authority '
                . 'by publishing category-focused content.';
        }

        if ($tips === []) {
            return $score >= 80
                ? 'Excellent AI brand visibility. Run weekly to track trends.'
                : 'Good progress. Continue improving structured data and content quality.';
        }

        return implode(' ', $tips);
    }
}
