<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Score extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
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
            $score = (int) ($item['overall_score'] ?? 0);
            $color = match(true) {
                $score >= 80 => '#16a34a',
                $score >= 65 => '#2563eb',
                $score >= 50 => '#d97706',
                $score >= 35 => '#ea580c',
                default      => '#dc2626',
            };
            $item[$this->getData('name')] = sprintf(
                '<span style="font-weight:700;color:%s">%d/100</span>',
                $color, $score
            );
        }
        return $dataSource;
    }
}
