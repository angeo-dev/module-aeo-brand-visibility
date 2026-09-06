<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * How an admin-triggered audit is executed.
 */
class RunMode implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::RUN_MODE_QUEUE,
                'label' => __('Background queue (recommended)'),
            ],
            [
                'value' => Config::RUN_MODE_SYNC,
                'label' => __('Inline (blocks the request)'),
            ],
        ];
    }
}
