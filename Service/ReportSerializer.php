<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;

/**
 * Converts reports to and from plain arrays.
 *
 * One shared shape is used by the result cache, the database column and the CSV
 * export, so analyser metadata can no longer be lost on the way to storage —
 * which is what happened to the competitor data in 3.0.0.
 */
class ReportSerializer
{
    /**
     * Flatten a report into a storable array.
     *
     * @param BrandVisibilityReport $report Report to flatten.
     * @return array<string, mixed>
     */
    public function toArray(BrandVisibilityReport $report): array
    {
        return [
            'brand_name' => $report->brandName,
            'brand_domain' => $report->brandDomain,
            'generated_at' => $report->generatedAt->format(\DateTimeInterface::ATOM),
            'samples' => $report->samples,
            'results' => array_map(
                fn(BrandQueryResult $result): array => $this->resultToArray($result),
                $report->results
            ),
        ];
    }

    /**
     * Flatten one result cell.
     *
     * @param BrandQueryResult $result Cell to flatten.
     * @return array<string, mixed>
     */
    public function resultToArray(BrandQueryResult $result): array
    {
        return [
            'provider_id' => $result->providerId,
            'provider_label' => $result->providerLabel,
            'prompt_key' => $result->promptKey,
            'prompt' => $result->prompt,
            'samples' => $result->samples,
            'score' => $result->score,
            'score_margin' => $result->scoreMargin,
            'signal_rates' => $result->signalRates,
            'signals' => $result->getSignals(),
            'meta' => $result->meta,
            'responses' => $result->responses,
            'error' => $result->errorMessage,
        ];
    }

    /**
     * Rebuild a report from a stored array.
     *
     * @param array<string, mixed> $data Stored payload.
     * @param bool $fromCache Whether the payload came from the cache.
     * @return BrandVisibilityReport
     */
    public function fromArray(array $data, bool $fromCache = false): BrandVisibilityReport
    {
        $results = [];
        foreach ((array) ($data['results'] ?? []) as $row) {
            $results[] = $this->resultFromArray((array) $row);
        }

        $generatedAt = new \DateTimeImmutable((string) ($data['generated_at'] ?? 'now'));

        return new BrandVisibilityReport(
            brandName: (string) ($data['brand_name'] ?? ''),
            brandDomain: (string) ($data['brand_domain'] ?? ''),
            results: $results,
            generatedAt: $generatedAt,
            samples: max(1, (int) ($data['samples'] ?? 1)),
            fromCache: $fromCache
        );
    }

    /**
     * Rebuild one result cell from a stored array.
     *
     * @param array<string, mixed> $row Stored cell.
     * @return BrandQueryResult
     */
    private function resultFromArray(array $row): BrandQueryResult
    {
        $error = $row['error'] ?? null;
        if (is_string($error) && $error !== '') {
            return BrandQueryResult::error(
                (string) ($row['provider_id'] ?? ''),
                (string) ($row['provider_label'] ?? ''),
                (string) ($row['prompt_key'] ?? ''),
                (string) ($row['prompt'] ?? ''),
                $error
            );
        }

        return new BrandQueryResult(
            providerId: (string) ($row['provider_id'] ?? ''),
            providerLabel: (string) ($row['provider_label'] ?? ''),
            promptKey: (string) ($row['prompt_key'] ?? ''),
            prompt: (string) ($row['prompt'] ?? ''),
            samples: (int) ($row['samples'] ?? 1),
            score: (int) ($row['score'] ?? 0),
            scoreMargin: (float) ($row['score_margin'] ?? 0.0),
            signalRates: (array) ($row['signal_rates'] ?? []),
            meta: (array) ($row['meta'] ?? []),
            responses: array_values(array_filter(
                (array) ($row['responses'] ?? []),
                static fn($r) => is_string($r)
            ))
        );
    }
}
