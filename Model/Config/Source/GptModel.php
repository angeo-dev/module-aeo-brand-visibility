<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * OpenAI chat models offered in the admin.
 *
 * Model identifiers change often. The field accepts any value, so a model
 * released after this version can be entered by hand.
 */
class GptModel implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'gpt-4.1', 'label' => __('gpt-4.1')],
            ['value' => 'gpt-4.1-mini', 'label' => __('gpt-4.1-mini (recommended)')],
            ['value' => 'gpt-4.1-nano', 'label' => __('gpt-4.1-nano')],
            ['value' => 'gpt-4o', 'label' => __('gpt-4o')],
            ['value' => 'gpt-4o-mini', 'label' => __('gpt-4o-mini')],
            ['value' => 'gpt-4o-search-preview', 'label' => __('gpt-4o-search-preview (web grounded)')],
            ['value' => 'gpt-4o-mini-search-preview', 'label' => __('gpt-4o-mini-search-preview (web grounded)')],
        ];
    }
}
