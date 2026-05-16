<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult as AuditResultResource;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult\Collection;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult\CollectionFactory;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;

/**
 * Persists and retrieves BrandVisibilityReport records.
 * Keeps only the last MAX_RECORDS rows — prunes on every save.
 */
class AuditResultRepository
{
    private const MAX_RECORDS = 90;

    public function __construct(
        private readonly AuditResultFactory  $factory,
        private readonly AuditResultResource $resource,
        private readonly CollectionFactory   $collectionFactory,
        private readonly Json                $json,
    ) {}

    // ── Save ──────────────────────────────────────────────────────────────

    public function saveReport(BrandVisibilityReport $report, string $triggeredBy = 'admin'): AuditResult
    {
        $model = $this->factory->create();

        $signalRates = [
            'mentioned'          => $report->signalRate('mentioned'),
            'recommended'        => $report->signalRate('recommended'),
            'url_cited'          => $report->signalRate('url_cited'),
            'first_result'       => $report->signalRate('first_result'),
            'positive_sentiment' => $report->signalRate('positive_sentiment'),
        ];

        $resultsData = array_map(fn($r) => [
            'provider_id'    => $r->providerId,
            'provider_label' => $r->providerLabel,
            'prompt_key'     => $r->promptKey,
            'prompt'         => $r->prompt,
            'raw_response'   => $r->rawResponse,
            'signals'        => $r->signals,
            'score'          => $r->score,
            'error'          => $r->errorMessage,
        ], $report->results);

        $model->setData([
            'brand_name'      => (string) $report->brandName,
            'brand_domain'    => (string) $report->brandDomain,
            'overall_score'   => $report->getOverallScore(),
            'grade'           => $report->getGrade(),
            'provider_scores' => $this->json->serialize($report->scoreByProvider()),
            'signal_rates'    => $this->json->serialize($signalRates),
            'results_json'    => $this->json->serialize($resultsData),
            'triggered_by'    => $triggeredBy,
            'queries_count'   => count($report->results),
            'errors_count'    => count($report->failedResults()),
            'from_cache'      => $report->fromCache ? 1 : 0,
            'created_at'      => $report->generatedAt->format('Y-m-d H:i:s'),
        ]);

        $this->resource->save($model);
        $this->pruneOldRecords();

        return $model;
    }

    // ── Read ──────────────────────────────────────────────────────────────

    public function getById(int $id): AuditResult
    {
        $model = $this->factory->create();
        $this->resource->load($model, $id);
        if (!$model->getId()) {
            throw new \RuntimeException("Audit result #{$id} not found.");
        }
        return $model;
    }

    /**
     * Latest N records, newest first.
     */
    public function getLatest(int $limit = 20): Collection
    {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->setOrder('created_at', 'DESC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);
        return $collection;
    }

    /**
     * Statistics over the last N records for trend charts.
     *
     * @return array{
     *     avg_score: float,
     *     max_score: int,
     *     min_score: int,
     *     trend: array<array{date: string, score: int, grade: string}>,
     *     signal_averages: array<string, float>,
     *     total_runs: int,
     * }
     */
    public function getStatistics(int $lastN = 30): array
    {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('from_cache', 0); // Only count non-cached runs
        $collection->setOrder('created_at', 'DESC');
        $collection->setPageSize($lastN);

        $scores        = [];
        $trend         = [];
        $signalTotals  = [];
        $signalCounts  = [];

        foreach ($collection as $row) {
            $scores[] = (int) $row->getOverallScore();
            $trend[]  = [
                'date'  => substr($row->getCreatedAt(), 0, 10),
                'score' => (int) $row->getOverallScore(),
                'grade' => $row->getGrade(),
            ];

            $rates = $row->getSignalRatesDecoded();
            foreach ($rates as $signal => $rate) {
                $signalTotals[$signal]  = ($signalTotals[$signal]  ?? 0) + $rate;
                $signalCounts[$signal]  = ($signalCounts[$signal]  ?? 0) + 1;
            }
        }

        $signalAverages = [];
        foreach ($signalTotals as $signal => $total) {
            $signalAverages[$signal] = round($total / $signalCounts[$signal], 1);
        }

        return [
            'avg_score'       => empty($scores) ? 0 : round(array_sum($scores) / count($scores), 1),
            'max_score'       => empty($scores) ? 0 : max($scores),
            'min_score'       => empty($scores) ? 0 : min($scores),
            'trend'           => array_reverse($trend), // chronological order
            'signal_averages' => $signalAverages,
            'total_runs'      => count($scores),
        ];
    }

    // ── Prune ─────────────────────────────────────────────────────────────

    private function pruneOldRecords(): void
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getMainTable();

        // Find the ID of the Nth record from the top
        $subQuery = $connection->select()
            ->from($table, 'id')
            ->order('id DESC')
            ->limit(1, self::MAX_RECORDS);

        $cutoffId = $connection->fetchOne($subQuery);
        if ($cutoffId) {
            $connection->delete($table, ['id < ?' => $cutoffId]);
        }
    }
}
