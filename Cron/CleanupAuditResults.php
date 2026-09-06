<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Cron;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Applies the retention policy to stored runs.
 *
 * Version 3.x pruned inside every save, which put a delete statement on the
 * critical path of each audit and kept a single global row budget regardless of
 * how many store views existed.
 */
class CleanupAuditResults
{
    /**
     * @param AuditResultRepositoryInterface $repository Run persistence.
     * @param Config $config Configuration accessor.
     * @param LoggerInterface $logger Module logger.
     */
    public function __construct(
        private readonly AuditResultRepositoryInterface $repository,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Remove runs beyond the retention window.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $deleted = $this->repository->prune(
                $this->config->getMaxRecordsPerStore(),
                $this->config->getMaxAgeDays()
            );

            if ($deleted > 0) {
                $this->logger->info('[BrandVis] Retention pass removed old runs.', ['deleted' => $deleted]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Retention pass failed.', ['error' => $e->getMessage()]);
        }
    }
}
