<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;

/**
 * One finished run as the dashboard, the run page and the AI assistant see
 * it. Extracted from Run\Status in 4.2.0 so the three cannot drift apart.
 *
 * Pure PHP apart from the interface, unit tested.
 */
class RunPayloadBuilder
{
    public const RESPONSE_PREVIEW_CHARS = 700;

    /**
     * @return array<string,mixed>
     */
    public function build(AuditResultInterface $row, int $previewChars = self::RESPONSE_PREVIEW_CHARS): array
    {
        $results = [];
        foreach ($row->getResultsDecoded() as $cell) {
            $responses = (array) ($cell['responses'] ?? []);
            $preview = isset($responses[0]) && is_string($responses[0])
                ? mb_substr($responses[0], 0, $previewChars)
                : null;

            $results[] = [
                'provider_id' => (string) ($cell['provider_id'] ?? ''),
                'provider_label' => (string) ($cell['provider_label'] ?? ''),
                'prompt_key' => (string) ($cell['prompt_key'] ?? ''),
                'prompt' => (string) ($cell['prompt'] ?? ''),
                'samples' => (int) ($cell['samples'] ?? 0),
                'score' => (int) ($cell['score'] ?? 0),
                'score_margin' => (float) ($cell['score_margin'] ?? 0),
                'signals' => (array) ($cell['signals'] ?? []),
                'signal_rates' => (array) ($cell['signal_rates'] ?? []),
                'meta' => (array) ($cell['meta'] ?? []),
                'response' => $preview,
                'error' => $cell['error'] ?? null,
                'success' => empty($cell['error']),
            ];
        }

        return [
            'id' => (int) $row->getData(AuditResultInterface::ID),
            'created_at' => (string) $row->getData(AuditResultInterface::CREATED_AT),
            'brand_name' => (string) $row->getData(AuditResultInterface::BRAND_NAME),
            'brand_domain' => (string) $row->getData(AuditResultInterface::BRAND_DOMAIN),
            'overall_score' => $row->getOverallScore(),
            'score_margin' => (float) $row->getData(AuditResultInterface::SCORE_MARGIN),
            'grade' => $row->getGrade(),
            'samples' => (int) $row->getData(AuditResultInterface::SAMPLES),
            'signal_rates' => $row->getSignalRatesDecoded(),
            'scores_by_provider' => $row->getProviderScoresDecoded(),
            'competitive' => [
                'share_of_voice' => (float) $row->getData(AuditResultInterface::SHARE_OF_VOICE),
                'win_rate' => (float) $row->getData(AuditResultInterface::WIN_RATE),
                'accuracy_issues' => (int) $row->getData(AuditResultInterface::ACCURACY_ISSUES),
            ],
            'results' => $results,
        ];
    }

    /**
     * How many answers named the brand and each competitor, across all cells.
     * The denominator is the number of answers that came back.
     *
     * @param array<int,array<string,mixed>> $results build()['results']
     * @return array{answers:int,brand:int,competitors:array<string,int>}
     */
    public function namedCounts(array $results): array
    {
        $answers = 0;
        $brand = 0.0;
        $competitors = [];
        foreach ($results as $cell) {
            if (empty($cell['success'])) {
                continue;
            }
            $samples = max(0, (int) ($cell['samples'] ?? 0));
            $answers += $samples;
            $brand += $samples * (float) ($cell['signal_rates']['mentioned'] ?? 0) / 100;
            foreach ((array) ($cell['meta']['competitor_hits'] ?? []) as $name => $hits) {
                $competitors[(string) $name] = ($competitors[(string) $name] ?? 0) + (int) $hits;
            }
        }
        arsort($competitors);

        return ['answers' => $answers, 'brand' => (int) round($brand), 'competitors' => $competitors];
    }
}
