<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Model\Config\Source;
use Magento\Framework\Data\OptionSourceInterface;
class GptModel implements OptionSourceInterface {
    public function toOptionArray(): array {
        return [
            ['value' => 'gpt-4.1',           'label' => 'GPT-4.1 — Latest (Recommended)'],
            ['value' => 'gpt-4.1-mini',       'label' => 'GPT-4.1 Mini — Faster/Cheaper'],
            ['value' => 'gpt-4.1-nano',       'label' => 'GPT-4.1 Nano — Fastest/Cheapest'],
            ['value' => 'gpt-4o',             'label' => 'GPT-4o'],
            ['value' => 'gpt-4o-mini',        'label' => 'GPT-4o Mini'],
            ['value' => 'o4-mini',            'label' => 'o4-mini — Reasoning model'],
            ['value' => 'o3-mini',            'label' => 'o3-mini — Reasoning model'],
        ];
    }
}
