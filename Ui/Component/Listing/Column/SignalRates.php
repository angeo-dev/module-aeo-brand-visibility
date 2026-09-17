<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Summarises the stored signal rates as a compact, escaped string.
 */
class SignalRates extends Column
{
    private const LABELS = [
        'mentioned' => 'Mention',
        'recommended' => 'Rec',
        'url_cited' => 'URL',
        'first_result' => '1st',
    ];

    /**
     * @param ContextInterface $context UI component context.
     * @param UiComponentFactory $uiComponentFactory UI component factory.
     * @param SerializerInterface $serializer Decodes the stored JSON column.
     * @param Escaper $escaper Escapes the rendered output.
     * @param array<string, mixed> $components Child components.
     * @param array<string, mixed> $data Component data.
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly SerializerInterface $serializer,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $item[$fieldName] = $this->format($item[$fieldName] ?? null);
        }

        return $dataSource;
    }

    /**
     * Turn the stored JSON into a short label list.
     *
     * @param mixed $raw Stored column value.
     * @return string
     */
    private function format(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        try {
            $rates = $this->serializer->unserialize($raw);
        } catch (\Throwable) {
            return '';
        }

        if (!is_array($rates)) {
            return '';
        }

        $parts = [];
        foreach (self::LABELS as $signal => $label) {
            if (isset($rates[$signal])) {
                $parts[] = sprintf('%s %d%%', $label, (int) round((float) $rates[$signal]));
            }
        }

        return $this->escaper->escapeHtml(implode(' · ', $parts));
    }
}
