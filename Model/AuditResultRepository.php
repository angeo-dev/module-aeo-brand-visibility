<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult as AuditResultResource;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult\Collection;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult\CollectionFactory;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\ReportSerializer;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Persists brand visibility runs.
 *
 * Every read and every retention pass is scoped to a store view, so a
 * multi-store installation no longer mixes brands in one trend line.
 */
class AuditResultRepository implements AuditResultRepositoryInterface
{
    private const SIGNALS = [
        'mentioned',
        'recommended',
        'url_cited',
        'first_result',
        'positive_sentiment',
        'negative_sentiment',
    ];

    /**
     * @param AuditResultFactory $factory Model factory.
     * @param AuditResultResource $resource Resource model.
     * @param CollectionFactory $collectionFactory Collection factory.
     * @param ReportSerializer $reportSerializer Report to array conversion.
     * @param SerializerInterface $serializer JSON column encoding.
     * @param DateTime $dateTime Framework clock.
     */
    public function __construct(
        private readonly AuditResultFactory $factory,
        private readonly AuditResultResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ReportSerializer $reportSerializer,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getById(int $id): AuditResultInterface
    {
        $model = $this->factory->create();
        $this->resource->load($model, $id);

        if (!$model->getId()) {
            throw new NoSuchEntityException(new Phrase('Audit run with id "%1" does not exist.', [$id]));
        }

        return $model;
    }

    /**
     * @inheritDoc
     */
    public function createPendingRun(int $storeId, string $triggeredBy): AuditResultInterface
    {
        $model = $this->factory->create();
        $model->setData([
            AuditResultInterface::RUN_UUID => $this->generateUuid(),
            AuditResultInterface::STATUS => AuditResultInterface::STATUS_PENDING,
            AuditResultInterface::STORE_ID => $storeId,
            AuditResultInterface::TRIGGERED_BY => $triggeredBy,
            AuditResultInterface::CREATED_AT => $this->dateTime->gmtDate(),
        ]);

        try {
            $this->resource->save($model);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                new Phrase('Could not queue the audit run: %1', [$e->getMessage()]),
                $e
            );
        }

        return $model;
    }

    /**
     * @inheritDoc
     */
    public function saveReport(
        BrandVisibilityReport $report,
        string $triggeredBy = 'admin',
        int $storeId = 0,
        ?int $id = null
    ): AuditResultInterface {
        $model = $id !== null ? $this->loadForUpdate($id) : $this->factory->create();

        $signalRates = [];
        foreach (self::SIGNALS as $signal) {
            $signalRates[$signal] = $report->signalRate($signal);
        }

        $data = [
            AuditResultInterface::STATUS => AuditResultInterface::STATUS_COMPLETE,
            AuditResultInterface::ERROR_MESSAGE => null,
            AuditResultInterface::BRAND_NAME => $report->brandName,
            AuditResultInterface::BRAND_DOMAIN => $report->brandDomain,
            AuditResultInterface::STORE_ID => $storeId,
            AuditResultInterface::OVERALL_SCORE => $report->getOverallScore(),
            AuditResultInterface::SCORE_MARGIN => $report->getScoreMargin(),
            AuditResultInterface::GRADE => $report->getGrade(),
            AuditResultInterface::SAMPLES => $report->samples,
            AuditResultInterface::SHARE_OF_VOICE => $report->averageShareOfVoice(),
            AuditResultInterface::WIN_RATE => $report->winRate(),
            AuditResultInterface::ACCURACY_ISSUES => $report->accuracyIssueCount(),
            AuditResultInterface::PROVIDER_SCORES => $this->serializer->serialize($report->scoreByProvider()),
            AuditResultInterface::SIGNAL_RATES => $this->serializer->serialize($signalRates),
            AuditResultInterface::RESULTS_JSON => $this->serializer->serialize(
                $this->reportSerializer->toArray($report)['results']
            ),
            AuditResultInterface::TRIGGERED_BY => $triggeredBy,
            AuditResultInterface::QUERIES_COUNT => count($report->results),
            AuditResultInterface::ERRORS_COUNT => count($report->failedResults()),
            AuditResultInterface::FROM_CACHE => $report->fromCache ? 1 : 0,
        ];

        if ($id === null) {
            $data[AuditResultInterface::RUN_UUID] = $this->generateUuid();
            $data[AuditResultInterface::CREATED_AT] = $report->generatedAt->format('Y-m-d H:i:s');
        }

        $model->addData($data);

        try {
            $this->resource->save($model);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                new Phrase('Could not save the audit run: %1', [$e->getMessage()]),
                $e
            );
        }

        return $model;
    }

    /**
     * @inheritDoc
     */
    public function markFailed(int $id, string $message): void
    {
        try {
            $model = $this->loadForUpdate($id);
            $model->addData([
                AuditResultInterface::STATUS => AuditResultInterface::STATUS_ERROR,
                AuditResultInterface::ERROR_MESSAGE => mb_substr($message, 0, 2000),
            ]);
            $this->resource->save($model);
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * @inheritDoc
     */
    public function getLatest(int $storeId = 0, int $limit = 20): array
    {
        $collection = $this->buildScopedCollection($storeId);
        $collection->setOrder('created_at', Collection::SORT_ORDER_DESC);
        $collection->setPageSize(max(1, $limit));
        $collection->setCurPage(1);

        return array_values($collection->getItems());
    }

    /**
     * @inheritDoc
     */
    public function getStatistics(int $storeId = 0, int $lastN = 30): array
    {
        $collection = $this->buildScopedCollection($storeId);
        $collection->setOrder('created_at', Collection::SORT_ORDER_DESC);
        $collection->setPageSize(max(1, $lastN));
        $collection->setCurPage(1);

        $scores = [];
        $margins = [];
        $trend = [];
        $signalTotals = [];

        /** @var AuditResult $row */
        foreach ($collection as $row) {
            $score = (int) $row->getOverallScore();
            $scores[] = $score;
            $margins[] = (float) $row->getData(AuditResultInterface::SCORE_MARGIN);

            $trend[] = [
                'date' => substr((string) $row->getData(AuditResultInterface::CREATED_AT), 0, 16),
                'score' => $score,
                'margin' => (float) $row->getData(AuditResultInterface::SCORE_MARGIN),
                'grade' => $row->getGrade(),
            ];

            foreach ($row->getSignalRatesDecoded() as $signal => $rate) {
                $signalTotals[(string) $signal][] = (float) $rate;
            }
        }

        $signalAverages = [];
        foreach ($signalTotals as $signal => $values) {
            $signalAverages[$signal] = round(array_sum($values) / count($values), 1);
        }

        $count = count($scores);

        return [
            'avg_score' => $count === 0 ? 0.0 : round(array_sum($scores) / $count, 1),
            'avg_margin' => $count === 0 ? 0.0 : round(array_sum($margins) / $count, 1),
            'max_score' => $count === 0 ? 0 : max($scores),
            'min_score' => $count === 0 ? 0 : min($scores),
            'trend' => array_reverse($trend),
            'signal_averages' => $signalAverages,
            'total_runs' => $count,
        ];
    }

    /**
     * @inheritDoc
     */
    public function prune(int $maxPerStore, int $maxAgeDays): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        $deleted = 0;

        $cutoff = $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - $maxAgeDays * 86400);
        $deleted += (int) $connection->delete($table, ['created_at < ?' => $cutoff]);

        $storeIds = $connection->fetchCol(
            $connection->select()->from($table, 'store_id')->distinct(true)
        );

        foreach ($storeIds as $storeId) {
            $keepIds = $connection->fetchCol(
                $connection->select()
                    ->from($table, 'id')
                    ->where('store_id = ?', (int) $storeId)
                    ->order('id DESC')
                    ->limit($maxPerStore)
            );

            if ($keepIds === []) {
                continue;
            }

            $deleted += (int) $connection->delete($table, [
                'store_id = ?' => (int) $storeId,
                'id NOT IN (?)' => $keepIds,
            ]);
        }

        return $deleted;
    }

    /**
     * Load a row for update, throwing when it no longer exists.
     *
     * @param int $id Entity id.
     * @return AuditResult
     * @throws CouldNotSaveException
     */
    private function loadForUpdate(int $id): AuditResult
    {
        $model = $this->factory->create();
        $this->resource->load($model, $id);

        if (!$model->getId()) {
            throw new CouldNotSaveException(new Phrase('Audit run with id "%1" does not exist.', [$id]));
        }

        return $model;
    }

    /**
     * Collection limited to completed runs of one store scope.
     *
     * @param int $storeId Store view id.
     * @return Collection
     */
    private function buildScopedCollection(int $storeId): Collection
    {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(AuditResultInterface::STORE_ID, $storeId);
        $collection->addFieldToFilter(
            AuditResultInterface::STATUS,
            AuditResultInterface::STATUS_COMPLETE
        );

        return $collection;
    }

    /**
     * Generate a version 4 UUID for cross-referencing a run in logs.
     *
     * @return string
     */
    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }
}
