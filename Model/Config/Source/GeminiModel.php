<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Model\Config\Source;
use Magento\Framework\Data\OptionSourceInterface;
class GeminiModel implements OptionSourceInterface {
    public function toOptionArray(): array {
        return [
            ['value' => 'gemini-2.5-flash-preview-05-20', 'label' => 'Gemini 2.5 Flash Preview (Recommended)'],
            ['value' => 'gemini-2.0-flash',                'label' => 'Gemini 2.0 Flash — Fast & free'],
            ['value' => 'gemini-2.0-flash-lite',           'label' => 'Gemini 2.0 Flash Lite — Fastest/cheapest'],
            ['value' => 'gemini-2.5-pro-preview-05-06',    'label' => 'Gemini 2.5 Pro Preview — Most capable'],
            ['value' => 'gemini-1.5-flash',                'label' => 'Gemini 1.5 Flash'],
        ];
    }
}
