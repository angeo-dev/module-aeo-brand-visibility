<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Model\Config\Source;
use Magento\Framework\Data\OptionSourceInterface;
class ClaudeModel implements OptionSourceInterface {
    public function toOptionArray(): array {
        return [
            ['value' => 'claude-sonnet-4-6',          'label' => 'Claude Sonnet 4.6 (Recommended)'],
            ['value' => 'claude-opus-4-6',             'label' => 'Claude Opus 4.6 — Most capable'],
            ['value' => 'claude-haiku-4-5-20251001',   'label' => 'Claude Haiku 4.5 — Fastest/Cheapest'],
            ['value' => 'claude-sonnet-4-5-20251001',  'label' => 'Claude Sonnet 4.5'],
        ];
    }
}
