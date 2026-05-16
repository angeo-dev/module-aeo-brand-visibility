<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Actions extends Column
{
    public function __construct(
        ContextInterface   $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) $item['id'];
            $item[$this->getData('name')] = [
                'view' => [
                    'href'  => $this->urlBuilder->getUrl('angeo_brand_vis/history/view', ['id' => $id]),
                    'label' => __('View'),
                ],
            ];
        }
        return $dataSource;
    }
}
