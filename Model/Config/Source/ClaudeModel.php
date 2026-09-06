<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Anthropic Claude models offered in the admin.
 *
 * Model identifiers change often. The field accepts any value, so a model
 * released after this version can be entered by hand.
 */
class ClaudeModel implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'claude-sonnet-4-6', 'label' => __('claude-sonnet-4-6 (recommended)')],
            ['value' => 'claude-opus-4-6', 'label' => __('claude-opus-4-6')],
            ['value' => 'claude-haiku-4-5', 'label' => __('claude-haiku-4-5')],
        ];
    }
}
