<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Cron;

use Angeo\AeoBrandVisibility\Api\BrandVisibilityServiceInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Scheduled audit, one run per store view that has the check enabled.
 *
 * Runs in the dedicated angeo_brand_visibility cron group so slow provider
 * calls cannot delay the default group.
 */
class RunBrandVisibilityAudit
{
    /**
     * @param Config $config Configuration accessor.
     * @param BrandVisibilityServiceInterface $service Audit runner.
     * @param StoreManagerInterface $storeManager Store list.
     * @param LoggerInterface $logger Module logger.
     */
    public function __construct(
        private readonly Config $config,
        private readonly BrandVisibilityServiceInterface $service,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute the scheduled audit.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $scoped = $this->config->withStore($storeId);

            if (!$scoped->isEnabled() || !$scoped->isCronEnabled()) {
                continue;
            }

            try {
                $report = $this->service->run($storeId, true, 'cron');
                $this->logger->info('[BrandVis] Scheduled run complete.', [
                    'store_id' => $storeId,
                    'score' => $report->getOverallScore(),
                    'grade' => $report->getGrade(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('[BrandVis] Scheduled run failed.', [
                    'store_id' => $storeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
