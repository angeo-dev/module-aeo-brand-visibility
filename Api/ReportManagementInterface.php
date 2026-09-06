<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Api;

use Angeo\AeoBrandVisibility\Api\Data\VisibilitySummaryInterface;

/**
 * Public REST surface for brand-visibility reporting (since 3.0.0).
 *
 * @api
 */
interface ReportManagementInterface
{
    /**
     * Latest persisted brand-visibility summary, optionally scoped to a store.
     *
     * @param int|null $storeId Store view id; null = default scope.
     * @return \Angeo\AeoBrandVisibility\Api\Data\VisibilitySummaryInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException When no audit has run yet.
     */
    public function getLatest(?int $storeId = null): VisibilitySummaryInterface;
}
