<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class SignalRates extends Column
{
    private const LABELS = [
        'mentioned'          => 'M',
        'recommended'        => 'R',
        'url_cited'          => 'U',
        'first_result'       => '1st',
        'positive_sentiment' => '+',
    ];

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $rates = json_decode((string) ($item['signal_rates'] ?? '{}'), true) ?? [];
            $pills = '';
            foreach (self::LABELS as $key => $label) {
                $rate = (float) ($rates[$key] ?? 0);
                [$bg, $color] = match(true) {
                    $rate >= 60 => ['#dcfce7', '#166534'],
                    $rate >= 30 => ['#fef9c3', '#854d0e'],
                    default     => ['#fee2e2', '#991b1b'],
                };
                $pills .= sprintf(
                    '<span title="%s: %s%%" style="display:inline-block;margin:1px;padding:1px 5px;'
                    . 'border-radius:4px;background:%s;color:%s;font-size:11px;font-weight:600">%s</span>',
                    htmlspecialchars($key), number_format($rate, 0), $bg, $color, $label
                );
            }
            $item[$this->getData('name')] = $pills ?: '—';
        }
        return $dataSource;
    }
}
