<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Api;

use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;

/**
 * Runs brand visibility audits against the configured AI providers.
 *
 * @api
 */
interface BrandVisibilityServiceInterface
{
    /**
     * Run a full audit for one store scope.
     *
     * @param int|null $storeId Store view to scope config, cache and persistence to.
     * @param bool $forceRefresh Bypass the result cache.
     * @param string $triggeredBy One of admin, cron, cli, audit.
     * @param int|null $auditResultId Existing queued row to write the result onto.
     * @return BrandVisibilityReport
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function run(
        ?int $storeId = null,
        bool $forceRefresh = false,
        string $triggeredBy = 'admin',
        ?int $auditResultId = null
    ): BrandVisibilityReport;

    /**
     * Run one provider/prompt pair for the admin preview. Never cached, never persisted.
     *
     * @param string $providerId Provider identifier.
     * @param string $promptKey Prompt identifier.
     * @param int|null $storeId Store view to scope configuration to.
     * @return BrandQueryResult
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function querySingle(string $providerId, string $promptKey, ?int $storeId = null): BrandQueryResult;

    /**
     * Drop every cached report.
     *
     * @return void
     */
    public function clearCache(): void;
}
