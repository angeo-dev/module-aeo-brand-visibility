<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class Grade extends Column
{
    private const COLORS = [
        'A' => ['bg' => '#dcfce7', 'text' => '#166534'],
        'B' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
        'C' => ['bg' => '#fef9c3', 'text' => '#854d0e'],
        'D' => ['bg' => '#ffedd5', 'text' => '#9a3412'],
        'F' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    ];

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $grade  = strtoupper((string) ($item['grade'] ?? 'F'));
            $colors = self::COLORS[$grade] ?? self::COLORS['F'];
            $item[$this->getData('name')] = sprintf(
                '<span style="display:inline-block;padding:2px 10px;border-radius:12px;'
                . 'background:%s;color:%s;font-weight:700;font-size:13px">%s</span>',
                $colors['bg'], $colors['text'], $grade
            );
        }
        return $dataSource;
    }
}
