<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AuditResult extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('angeo_brand_visibility_audit', 'id');
    }
}
