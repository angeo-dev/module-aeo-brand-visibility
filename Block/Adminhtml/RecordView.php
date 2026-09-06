<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Block\Adminhtml;

use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Controller\Adminhtml\History\View;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

/**
 * Supplies the run detail template with the record registered by the controller.
 */
class RecordView extends Template
{
    /**
     * @param Context $context Block context.
     * @param Registry $registry Registry holding the loaded record.
     * @param array<string, mixed> $data Block data.
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The loaded run, or null when the controller could not load one.
     *
     * @return AuditResultInterface|null
     */
    public function getRecord(): ?AuditResultInterface
    {
        $record = $this->registry->registry(View::REGISTRY_KEY);

        return $record instanceof AuditResultInterface ? $record : null;
    }

    /**
     * Stored cells of the run.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCells(): array
    {
        $record = $this->getRecord();

        return $record === null ? [] : $record->getResultsDecoded();
    }

    /**
     * URL of the CSV export for this run.
     *
     * @return string
     */
    public function getExportUrl(): string
    {
        $record = $this->getRecord();
        $id = $record === null ? 0 : (int) $record->getData(AuditResultInterface::ID);

        return $this->getUrl('angeo_brand_vis/export/csv', ['id' => $id]);
    }

    /**
     * URL back to the history grid.
     *
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('angeo_brand_vis/history/index');
    }
}
