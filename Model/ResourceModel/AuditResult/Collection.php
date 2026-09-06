<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult;

use Angeo\AeoBrandVisibility\Model\AuditResult;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult as AuditResultResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of stored brand visibility runs.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * Bind the collection to its model and resource.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AuditResult::class, AuditResultResource::class);
    }
}
