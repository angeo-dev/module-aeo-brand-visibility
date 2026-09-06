<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Api;

use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;

/**
 * Persists and retrieves brand visibility audit runs.
 *
 * @api
 */
interface AuditResultRepositoryInterface
{
    /**
     * Load one run by entity id.
     *
     * @param int $id Entity id.
     * @return AuditResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $id): AuditResultInterface;

    /**
     * Create a placeholder row for an audit that has been queued but not yet executed.
     *
     * @param int $storeId Store view id.
     * @param string $triggeredBy Origin of the run.
     * @return AuditResultInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function createPendingRun(int $storeId, string $triggeredBy): AuditResultInterface;

    /**
     * Write a finished report onto an existing row, or create a new one when $id is null.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @param string $triggeredBy Origin of the run.
     * @param int $storeId Store view id.
     * @param int|null $id Existing row to update.
     * @return AuditResultInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function saveReport(
        BrandVisibilityReport $report,
        string $triggeredBy = 'admin',
        int $storeId = 0,
        ?int $id = null
    ): AuditResultInterface;

    /**
     * Mark a queued run as failed.
     *
     * @param int $id Entity id.
     * @param string $message Failure reason.
     * @return void
     */
    public function markFailed(int $id, string $message): void;

    /**
     * Latest completed runs for one store scope, newest first.
     *
     * @param int $storeId Store view id.
     * @param int $limit Maximum rows.
     * @return AuditResultInterface[]
     */
    public function getLatest(int $storeId = 0, int $limit = 20): array;

    /**
     * Trend and aggregate statistics for one store scope.
     *
     * @param int $storeId Store view id.
     * @param int $lastN Number of recent runs to aggregate.
     * @return array<string, mixed>
     */
    public function getStatistics(int $storeId = 0, int $lastN = 30): array;

    /**
     * Delete runs beyond the configured retention window.
     *
     * @param int $maxPerStore Rows to keep per store scope.
     * @param int $maxAgeDays Maximum age in days.
     * @return int Number of deleted rows.
     */
    public function prune(int $maxPerStore, int $maxAgeDays): int;
}
