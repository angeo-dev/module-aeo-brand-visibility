<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Model\AuditResultRepository;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends an email when a scheduled brand-visibility run drops significantly
 * versus the previous run for the same store scope (since 3.0.0).
 *
 * "Significantly" = the configured drop threshold (default 10 points). The
 * dispatcher also flags a NEW competitor overtaking the brand in share of
 * voice, which is often the actionable half of the story: the score can hold
 * steady while a rival starts eating the answers.
 *
 * Failures are swallowed by the caller — an alerting outage must never break
 * an audit run.
 */
class AlertDispatcher
{
    private const EMAIL_TEMPLATE = 'angeo_brand_vis_alert';

    public function __construct(
        private readonly Config                $config,
        private readonly AuditResultRepository $repository,
        private readonly TransportBuilder      $transportBuilder,
        private readonly StateInterface        $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
    ) {
    }

    public function maybeAlert(BrandVisibilityReport $report, int $currentId): void
    {
        if (!$this->config->isAlertingEnabled()) {
            return;
        }

        $recipients = $this->config->getAlertRecipients();
        if ($recipients === []) {
            $this->logger->info('[BrandVis] Alerting enabled but no valid recipients configured.');
            return;
        }

        $previous = $this->repository->getPreviousResult($currentId, $report->storeId);
        if ($previous === null) {
            // First run for this scope — nothing to compare against.
            return;
        }

        $currentScore  = $report->getOverallScore();
        $previousScore = (int) $previous->getOverallScore();
        $drop          = $previousScore - $currentScore;
        $threshold     = $this->config->getAlertDropThreshold();

        $newLeaders = $this->newCompetitorLeaders($report, $previous);

        if ($drop < $threshold && $newLeaders === []) {
            return; // nothing worth an email
        }

        $this->send($recipients, $report, $previousScore, $currentScore, $drop, $newLeaders);
    }

    /**
     * Competitors that now outrank the brand in share of voice but did NOT in
     * the previous run — the "someone just overtook you" signal.
     *
     * @return array<int, array{name: string, rate: float}>
     */
    private function newCompetitorLeaders(BrandVisibilityReport $report, $previous): array
    {
        $currentSov = $report->shareOfVoice();
        $ownRate    = $currentSov[$report->brandName] ?? 0.0;

        $previousSov  = $previous->getShareOfVoiceDecoded();
        $prevOwnRate  = $previousSov[$report->brandName] ?? 0.0;

        $leaders = [];
        foreach ($currentSov as $name => $rate) {
            if ($name === $report->brandName) {
                continue;
            }
            $wasBehind = ($previousSov[$name] ?? 0.0) <= $prevOwnRate;
            if ($rate > $ownRate && $wasBehind) {
                $leaders[] = ['name' => (string) $name, 'rate' => (float) $rate];
            }
        }

        return $leaders;
    }

    /**
     * @param string[] $recipients
     * @param array<int, array{name: string, rate: float}> $newLeaders
     */
    private function send(
        array $recipients,
        BrandVisibilityReport $report,
        int $previousScore,
        int $currentScore,
        int $drop,
        array $newLeaders
    ): void {
        $this->inlineTranslation->suspend();
        try {
            $storeId = $report->storeId ?? (int) $this->storeManager->getStore()->getId();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier(self::EMAIL_TEMPLATE)
                ->setTemplateOptions([
                    'area'  => \Magento\Framework\App\Area::AREA_ADMINHTML,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'brand_name'     => $report->brandName,
                    'previous_score' => $previousScore,
                    'current_score'  => $currentScore,
                    'drop'           => $drop,
                    'grade'          => $report->getGrade(),
                    'new_leaders'    => $newLeaders,
                    'store_id'       => $storeId,
                ])
                ->setFromByScope($this->config->getAlertSenderIdentity(), $storeId)
                ->addTo($recipients)
                ->getTransport();

            $transport->sendMessage();

            $this->logger->info('[BrandVis] Alert sent', [
                'recipients' => count($recipients),
                'drop'       => $drop,
                'new_leaders'=> count($newLeaders),
            ]);
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
