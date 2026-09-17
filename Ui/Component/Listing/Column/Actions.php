<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row actions for the audit history grid.
 */
class Actions extends Column
{
    private const VIEW_PATH = 'angeo_brand_vis/history/view';
    private const EXPORT_PATH = 'angeo_brand_vis/export/csv';

    /**
     * @param ContextInterface $context UI component context.
     * @param UiComponentFactory $uiComponentFactory UI component factory.
     * @param UrlInterface $urlBuilder Admin URL builder.
     * @param array<string, mixed> $components Child components.
     * @param array<string, mixed> $data Component data.
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['id'])) {
                continue;
            }

            $id = (int) $item['id'];
            $item[$fieldName] = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl(self::VIEW_PATH, ['id' => $id]),
                    'label' => __('View'),
                ],
                'export' => [
                    'href' => $this->urlBuilder->getUrl(self::EXPORT_PATH, ['id' => $id]),
                    'label' => __('Export CSV'),
                ],
            ];
        }

        return $dataSource;
    }
}
