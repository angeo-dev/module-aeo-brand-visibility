<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\AbstractChecker;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Angeo\AeoBrandVisibility\Api\BrandVisibilityServiceInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\RecommendationEngine;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Live-signal checker that reports measured AI brand recall to angeo/module-aeo-audit.
 *
 * The audited store view is now passed through to the service, so a multi-store
 * audit scores each store against its own brand configuration.
 *
 * Since 4.1.0 every result carries details['score_group'] = 'visibility', so
 * angeo/module-aeo-audit 4.3+ reports it next to the readiness score instead of
 * blending a measurement of what assistants say into a score of what the store
 * controls. A switched-off check is details['applicable'] = false: it used to
 * return WARN at full weight, which lowered the audit score of every store that
 * had the module installed but disabled.
 */
class BrandVisibilityChecker extends AbstractChecker
{
    private const CODE = 'brand_visibility';
    private const WEIGHT = 1.0;
    private const SCORE_GROUP = 'visibility';

    /**
     * @param HttpCache $httpCache Shared HTTP cache required by the base checker.
     * @param StoreUrlSampler $urlSampler URL sampler required by the base checker.
     * @param Config $config Configuration accessor.
     * @param BrandVisibilityServiceInterface $service Audit runner.
     * @param RecommendationEngine $recommendationEngine Action plan builder.
     */
    public function __construct(
        HttpCache $httpCache,
        StoreUrlSampler $urlSampler,
        private readonly Config $config,
        private readonly BrandVisibilityServiceInterface $service,
        private readonly RecommendationEngine $recommendationEngine
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'Brand Visibility in AI Models';
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritDoc
     */
    public function getWeight(): float
    {
        return self::WEIGHT;
    }

    /**
     * @inheritDoc
     */
    public function getCategory(): string
    {
        return CheckerInterface::CATEGORY_LIVE_SIGNAL;
    }

    /**
     * @inheritDoc
     */
    public function getSeverity(): string
    {
        return CheckerInterface::SEVERITY_CRITICAL;
    }

    /**
     * @inheritDoc
     */
    public function getFixCommand(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function check(StoreInterface $store): CheckResult
    {
        $storeId = (int) $store->getId();
        $config = $this->config->withStore($storeId);

        if (!$config->isEnabled()) {
            return $this->pass(
                'Brand visibility monitoring is switched off for this store view (not scored).',
                $this->group(['enabled' => false, 'store_id' => $storeId, 'applicable' => false])
            );
        }

        if ($config->getBrandName() === '') {
            return $this->fail(
                'No brand name is configured.',
                'Set the brand name under Stores > Configuration > Angeo AEO > Brand Visibility > General.',
                $this->group(['brand_name' => null, 'store_id' => $storeId])
            );
        }

        try {
            $report = $this->service->run($storeId, false, 'audit');
        } catch (\Throwable $e) {
            return $this->fail(
                'The brand visibility check could not run: ' . $e->getMessage(),
                'Verify the provider API keys and that at least one provider is enabled.',
                $this->group(['exception_class' => $e::class, 'store_id' => $storeId])
            );
        }

        return $this->buildResult($report, $config, $storeId);
    }

    /**
     * Translate a report into an audit check result.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @param int $storeId Store view id.
     * @return CheckResult
     */
    private function buildResult(BrandVisibilityReport $report, Config $config, int $storeId): CheckResult
    {
        $details = $this->group([
            'store_id' => $storeId,
            'score' => $report->getOverallScore(),
            'score_margin' => $report->getScoreMargin(),
            'grade' => $report->getGrade(),
            'samples_per_query' => $report->samples,
            'cells_total' => count($report->results),
            'cells_ok' => count($report->successfulResults()),
            'rate_mention' => $report->signalRate('mentioned'),
            'rate_recommend' => $report->signalRate('recommended'),
            'rate_url' => $report->signalRate('url_cited'),
            'rate_first' => $report->signalRate('first_result'),
            'share_of_voice' => $report->averageShareOfVoice(),
            'win_rate' => $report->winRate(),
            'accuracy_issues' => $report->accuracyIssueCount(),
            'tone' => $report->toneCounts(),
            'from_cache' => $report->fromCache,
        ]);

        if (!$report->hasData()) {
            return $this->fail(
                'No AI provider returned a usable answer, so visibility could not be measured.',
                'Check the API keys, the configured model names and outbound network access.',
                $details
            );
        }

        $message = sprintf(
            '%s: score %d/100 (+/-%.1f), grade %s%s. %d of %d queries answered. '
            . 'Mentioned %.0f%% | Recommended %.0f%% | URL cited %.0f%% | First %.0f%% | SoV %.1f%%.',
            $report->brandName,
            $report->getOverallScore(),
            $report->getScoreMargin(),
            $report->getGrade(),
            $report->fromCache ? ' (cached)' : '',
            count($report->successfulResults()),
            count($report->results),
            $report->signalRate('mentioned'),
            $report->signalRate('recommended'),
            $report->signalRate('url_cited'),
            $report->signalRate('first_result'),
            $report->averageShareOfVoice()
        );

        $plan = $this->recommendationEngine->buildPlan($report, $config);
        $recommendation = implode(' ', array_map(
            static fn(array $item): string => $item['title'] . ': ' . $item['detail'],
            array_slice($plan, 0, 3)
        ));

        $score = $report->getOverallScore();

        return match (true) {
            $score >= $config->getPassThreshold() => $this->pass($message, $details),
            $score >= $config->getWarnThreshold() => $this->warn($message, $recommendation, $details),
            default => $this->fail($message, $recommendation, $details),
        };
    }

    /**
     * Tag details so the audit reports this result as measured visibility.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function group(array $details): array
    {
        return $details + ['score_group' => self::SCORE_GROUP];
    }
}
