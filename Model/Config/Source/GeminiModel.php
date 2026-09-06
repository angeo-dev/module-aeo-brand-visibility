<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Google Gemini models offered in the admin.
 *
 * Model identifiers change often. The field accepts any value, so a model
 * released after this version can be entered by hand.
 */
class GeminiModel implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'gemini-2.0-flash', 'label' => __('gemini-2.0-flash (recommended)')],
            ['value' => 'gemini-2.0-flash-lite', 'label' => __('gemini-2.0-flash-lite')],
            ['value' => 'gemini-2.5-flash', 'label' => __('gemini-2.5-flash')],
            ['value' => 'gemini-2.5-pro', 'label' => __('gemini-2.5-pro')],
        ];
    }
}
