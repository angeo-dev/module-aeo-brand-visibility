<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Groq models offered in the admin.
 *
 * Model identifiers change often. A model released after this version can be
 * entered in the "Custom model ID" field.
 */
class GroqModel implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'llama-3.3-70b-versatile', 'label' => __('llama-3.3-70b-versatile (recommended)')],
            ['value' => 'llama-3.1-8b-instant', 'label' => __('llama-3.1-8b-instant')],
            ['value' => 'gemma2-9b-it', 'label' => __('gemma2-9b-it')],
        ];
    }
}
