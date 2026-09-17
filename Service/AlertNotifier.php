<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\Webhook\WebhookClient;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Raises an alert when visibility regresses beyond ordinary model variance.
 *
 * The drop is compared against the confidence margin of the current run, so a
 * movement that sits inside the noise band never triggers an e-mail. Trailing
 * statistics are read for the same store scope as the run, not globally.
 */
class AlertNotifier
{
    private const TRAILING_RUNS = 10;
    private const TEMPLATE_ID = 'angeo_brand_vis_alert';

    /**
     * @param AuditResultRepositoryInterface $repository Historic runs.
     * @param TransportBuilder $transportBuilder Mail transport builder.
     * @param StoreManagerInterface $storeManager Resolves the sending store.
     * @param WebhookClient $webhookClient Optional outbound webhook.
     * @param LoggerInterface $logger Module logger.
     */
    public function __construct(
        private readonly AuditResultRepositoryInterface $repository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly WebhookClient $webhookClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Evaluate the alert rules for a finished report.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @return void
     */
    public function maybeAlert(BrandVisibilityReport $report, Config $config): void
    {
        if (!$config->isAlertEnabled() || !$report->hasData()) {
            return;
        }

        $storeId = $config->getScopeStoreId() ?? 0;
        $reasons = $this->collectReasons($report, $config, $storeId);

        if ($reasons === []) {
            return;
        }

        $this->dispatch($report, $config, $reasons, $storeId);
    }

    /**
     * Build the list of reasons that justify an alert.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @param int $storeId Store view id.
     * @return string[]
     */
    private function collectReasons(BrandVisibilityReport $report, Config $config, int $storeId): array
    {
        $reasons = [];
        $current = $report->getOverallScore();

        try {
            $statistics = $this->repository->getStatistics($storeId, self::TRAILING_RUNS);
        } catch (\Throwable $e) {
            $this->logger->warning('[BrandVis] Trailing statistics unavailable.', ['error' => $e->getMessage()]);
            $statistics = [];
        }

        $average = (float) ($statistics['avg_score'] ?? 0.0);
        $drop = $average - $current;
        $threshold = $config->getAlertDropThreshold();
        $noiseBand = $report->getScoreMargin();

        if ($average > 0.0 && $drop >= $threshold && $drop > $noiseBand) {
            $reasons[] = sprintf(
                'Score fell %d points to %d (trailing average %.0f, noise band +/-%.1f).',
                (int) round($drop),
                $current,
                $average,
                $noiseBand
            );
        }

        $lost = 0;
        foreach ($report->successfulResults() as $result) {
            if (!empty($result->meta['winner']) && empty($result->meta['brand_is_winner'])) {
                $lost++;
            }
        }
        if ($lost > 0) {
            $reasons[] = sprintf('A competitor ranked ahead of the brand in %d of the tested queries.', $lost);
        }

        $negative = $report->toneCounts()['negative'] ?? 0;
        if ($negative > 0) {
            $reasons[] = sprintf('Negative tone was detected around the brand in %d answers.', $negative);
        }

        if ($report->accuracyIssueCount() > 0) {
            $reasons[] = sprintf(
                'An incorrect website was attributed to the brand in %d answers.',
                $report->accuracyIssueCount()
            );
        }

        return $reasons;
    }

    /**
     * Send the alert by e-mail and, when configured, by webhook.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @param string[] $reasons Reasons that triggered the alert.
     * @param int $storeId Store view id.
     * @return void
     */
    private function dispatch(BrandVisibilityReport $report, Config $config, array $reasons, int $storeId): void
    {
        $recipient = $config->getAlertRecipient();

        if ($recipient !== '') {
            try {
                $transport = $this->transportBuilder
                    ->setTemplateIdentifier(self::TEMPLATE_ID)
                    ->setTemplateOptions([
                        'area' => Area::AREA_ADMINHTML,
                        'store' => $storeId > 0 ? $storeId : (int) $this->storeManager
                            ->getDefaultStoreView()
                            ->getId(),
                    ])
                    ->setTemplateVars([
                        'brand' => $report->brandName,
                        'domain' => $report->brandDomain,
                        'score' => $report->getOverallScore(),
                        'margin' => $report->getScoreMargin(),
                        'grade' => $report->getGrade(),
                        'reasons' => $reasons,
                    ])
                    ->setFromByScope('general')
                    ->addTo($recipient)
                    ->getTransport();
                $transport->sendMessage();
            } catch (\Throwable $e) {
                $this->logger->error('[BrandVis] Alert e-mail failed.', ['error' => $e->getMessage()]);
            }
        }

        $webhook = $config->getAlertWebhookUrl();
        if ($webhook !== '') {
            $this->webhookClient->send(
                $webhook,
                [
                    'brand' => $report->brandName,
                    'domain' => $report->brandDomain,
                    'store_id' => $storeId,
                    'score' => $report->getOverallScore(),
                    'score_margin' => $report->getScoreMargin(),
                    'grade' => $report->getGrade(),
                    'share_of_voice' => $report->averageShareOfVoice(),
                    'win_rate' => $report->winRate(),
                    'reasons' => $reasons,
                    'generated_at' => $report->generatedAt->format(\DateTimeInterface::ATOM),
                ],
                $config->isWebhookPrivateAllowed()
            );
        }
    }
}
