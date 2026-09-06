<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Angeo\AeoBrandVisibility\Service\Analysis\PhrasePack;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Language options for the multilingual response analysis (since 1.3.0).
 * Options are driven by the phrase packs actually shipped in PhrasePack,
 * so the admin can never select a language the analyzer cannot handle.
 */
class AnalysisLanguage implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (PhrasePack::LANGUAGES as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }
        return $options;
    }
}
