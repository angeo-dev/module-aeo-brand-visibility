<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Perplexity Sonar models offered in the admin.
 *
 * Model identifiers change often. The field accepts any value, so a model
 * released after this version can be entered by hand.
 */
class PerplexityModel implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'sonar', 'label' => __('sonar (recommended)')],
            ['value' => 'sonar-pro', 'label' => __('sonar-pro')],
            ['value' => 'sonar-reasoning', 'label' => __('sonar-reasoning')],
            ['value' => 'sonar-reasoning-pro', 'label' => __('sonar-reasoning-pro')],
        ];
    }
}
