<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model\Config\Source;

use Angeo\AeoBrandVisibility\Service\Analysis\PhraseLibrary;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Languages the phrase analyser can score, derived from the phrase library itself
 * so the option list can never drift from the code.
 */
class AnalysisLanguage implements OptionSourceInterface
{
    private const LABELS = [
        'en' => 'English',
        'uk' => 'Ukrainian',
        'nl' => 'Dutch',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
    ];

    /**
     * @param PhraseLibrary $phraseLibrary Source of the supported language codes.
     */
    public function __construct(private readonly PhraseLibrary $phraseLibrary)
    {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [['value' => 'auto', 'label' => __('Auto (store locale)')]];

        foreach ($this->phraseLibrary->getSupportedLanguages() as $code) {
            $options[] = [
                'value' => $code,
                'label' => __(self::LABELS[$code] ?? strtoupper($code)),
            ];
        }

        return $options;
    }
}
