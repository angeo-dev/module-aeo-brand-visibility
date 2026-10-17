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
 * Model identifiers change often. A model released after this version can be
 * entered in the "Custom model ID" field.
 */
class ClaudeModel implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'claude-sonnet-5-5', 'label' => __('claude-sonnet-5-5 (recommended)')],
            ['value' => 'claude-opus-5-5', 'label' => __('claude-opus-5-5')],
            ['value' => 'claude-haiku-5-5', 'label' => __('claude-haiku-5-5')],
            ['value' => 'claude-sonnet-4-6', 'label' => __('claude-sonnet-4-6 (previous)')],
        ];
    }
}
