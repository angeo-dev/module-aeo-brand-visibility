<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Angeo\AeoBrandVisibility\Model\AuditResult;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult as AuditResultResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(AuditResult::class, AuditResultResource::class);
    }
}
