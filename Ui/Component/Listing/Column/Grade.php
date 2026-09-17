<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the letter grade with a severity class for the grid stylesheet.
 */
class Grade extends Column
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
            $grade = strtoupper((string) ($item[$fieldName] ?? 'F'));
            $item[$fieldName] = sprintf(
                '<span class="angeo-bv-grade angeo-bv-grade-%s">%s</span>',
                strtolower($grade),
                $grade
            );
        }

        return $dataSource;
    }
}
