<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for stored brand visibility runs.
 */
class AuditResult extends AbstractDb
{
    /**
     * Bind the model to its table.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('angeo_brand_visibility_audit', 'id');
    }
}
