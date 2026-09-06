<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Cron;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Scheduled brand-visibility audit.
 *
 * Since 3.0.0 the cron is store-aware: it runs once per store view where the
 * module is enabled at that scope, so a multi-market install gets an
 * independent trend (and independent alerting baseline) per locale. When no
 * store enables the module at its own scope, a single default-scope run is
 * performed — preserving pre-3.0 behaviour.
 */
class RunBrandVisibilityAudit
{
    public function __construct(
        private readonly Config                 $config,
        private readonly BrandVisibilityService $service,
        private readonly StoreManagerInterface  $storeManager,
        private readonly LoggerInterface        $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isCronEnabled()) {
            return;
        }

        $ran = false;

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$this->config->isEnabled($storeId)) {
                continue;
            }

            $this->logger->info('[BrandVis Cron] Running scheduled audit', ['store_id' => $storeId]);
            try {
                $this->service->run(forceRefresh: true, triggeredBy: 'cron', storeId: $storeId);
                $ran = true;
            } catch (\Throwable $e) {
                $this->logger->error('[BrandVis Cron] Store run failed', [
                    'store_id' => $storeId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // No store enabled the module at its own scope — fall back to a single
        // default-scope run (pre-3.0 behaviour).
        if (!$ran && $this->config->isEnabled()) {
            $this->logger->info('[BrandVis Cron] Running scheduled audit (default scope)');
            try {
                $this->service->run(forceRefresh: true, triggeredBy: 'cron');
            } catch (\Throwable $e) {
                $this->logger->error('[BrandVis Cron] Default-scope run failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
