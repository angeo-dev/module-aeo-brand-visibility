<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class CacheFlag extends Column
{
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $cached = (bool) ($item['from_cache'] ?? false);
            $item[$this->getData('name')] = $cached
                ? '<span style="color:#6b7280;font-size:12px">cached</span>'
                : '<span style="color:#16a34a;font-size:12px;font-weight:600">live</span>';
        }
        return $dataSource;
    }
}
