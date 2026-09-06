<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Sentiment analysis strategy options (since 3.0.0).
 */
class SentimentMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'phrase', 'label' => __('Phrase packs (fast, free)')],
            ['value' => 'llm',    'label' => __('LLM judge (accurate, +1 call/answer)')],
        ];
    }
}
