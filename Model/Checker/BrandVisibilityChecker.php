<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Checker;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * AEO Audit checker that measures real-world AI brand recall.
 *
 * Weight 1.5 — higher than technical checks (OG tags, canonical) because
 * this measures actual observable behaviour from AI systems.
 *
 * Results are cached per BrandVisibilityService TTL so running
 * `bin/magento angeo:aeo:audit` repeatedly doesn't spam AI APIs.
 */
class BrandVisibilityChecker implements CheckerInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly BrandVisibilityService $service,
    ) {}

    public function getName(): string   { return 'Brand Visibility in AI Models'; }
    public function getCode(): string   { return 'brand_visibility'; }
    public function getWeight(): float  { return 1.5; }

    public function check(string $baseUrl): CheckResult
    {
        if (!$this->config->isEnabled()) {
            return new CheckResult(
                CheckResult::STATUS_SKIP,
                'Brand Visibility check is disabled.',
                'Enable it under Stores → Config → Angeo AEO → Brand Visibility → General.',
            );
        }

        if (empty($this->config->getBrandName())) {
            return new CheckResult(
                CheckResult::STATUS_FAIL,
                'Brand name is not set.',
                'Configure your brand name under Angeo AEO → Brand Visibility → General.',
            );
        }

        try {
            $report = $this->service->run(forceRefresh: false);
        } catch (\RuntimeException $e) {
            return new CheckResult(
                CheckResult::STATUS_FAIL,
                'Brand visibility check could not run: ' . $e->getMessage(),
                'Check your AI provider API keys and ensure at least one provider is enabled.',
            );
        }

        $score         = $report->getOverallScore();
        $grade         = $report->getGrade();
        $successful    = count($report->successfulResults());
        $totalQueries  = count($report->results);
        $mentionRate   = $report->signalRate('mentioned');
        $recommendRate = $report->signalRate('recommended');
        $urlRate       = $report->signalRate('url_cited');
        $cacheNote     = $report->fromCache ? ' (cached)' : '';

        $message = sprintf(
            '%s: score %d/100, grade %s%s. %d/%d queries succeeded. ' .
            'Mentioned %.0f%% | Recommended %.0f%% | URL cited %.0f%%.',
            $report->brandName, $score, $grade, $cacheNote,
            $successful, $totalQueries,
            $mentionRate, $recommendRate, $urlRate,
        );

        $pass = $this->config->getPassThreshold();
        $warn = $this->config->getWarnThreshold();

        $status = match (true) {
            $score >= $pass => CheckResult::STATUS_PASS,
            $score >= $warn => CheckResult::STATUS_WARN,
            default         => CheckResult::STATUS_FAIL,
        };

        return new CheckResult($status, $message, $this->buildRecommendation($report, $score));
    }

    private function buildRecommendation(
        \Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport $report,
        int $score
    ): string {
        $tips = [];

        if ($report->signalRate('mentioned') < 50) {
            $tips[] = 'Your brand is largely unknown to AI models. Publish llms.txt (angeo/module-llms-txt) to expose your store structure, and ensure your store name appears consistently across all pages.';
        }
        if ($report->signalRate('url_cited') < 30) {
            $tips[] = 'Your domain URL is not being cited. Strengthen your backlink profile and ensure llms.txt explicitly references your canonical URL.';
        }
        if ($report->signalRate('recommended') < 40) {
            $tips[] = 'AI models are not actively recommending your store. Improve product content quality (angeo/module-openai-description-updater) and ensure review schema is implemented.';
        }
        if ($report->signalRate('first_result') < 30) {
            $tips[] = 'Your brand appears late in AI responses. Build topical authority by publishing category-focused content.';
        }

        if (empty($tips)) {
            return $score >= 80
                ? 'Excellent AI brand visibility. Run weekly to track trends.'
                : 'Good progress. Continue improving structured data and content quality.';
        }

        return implode(' ', $tips);
    }

    public function getFixCommand(): string
    {
        return '';
    }
}
