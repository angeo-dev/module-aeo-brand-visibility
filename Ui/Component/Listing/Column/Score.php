<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the score together with its confidence margin, so a grid reader can
 * see at a glance whether a movement is meaningful.
 */
class Score extends Column
{
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
            $score = (int) ($item[$fieldName] ?? 0);
            $margin = (float) ($item['score_margin'] ?? 0.0);
            $item[$fieldName] = $margin > 0.0
                ? sprintf('%d/100 ±%.1f', $score, $margin)
                : sprintf('%d/100', $score);
        }

        return $dataSource;
    }
}
