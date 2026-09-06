<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult as AuditResultResource;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * One persisted brand visibility run.
 */
class AuditResult extends AbstractModel implements AuditResultInterface
{
    /**
     * @param Context $context Model context.
     * @param Registry $registry Registry.
     * @param SerializerInterface $serializer Decodes the stored JSON columns.
     * @param AbstractResource|null $resource Resource model.
     * @param AbstractDb|null $resourceCollection Resource collection.
     * @param array<string, mixed> $data Initial data.
     */
    public function __construct(
        Context $context,
        Registry $registry,
        private readonly SerializerInterface $serializer,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * Bind the model to its resource.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AuditResultResource::class);
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): string
    {
        $status = (string) $this->getData(self::STATUS);

        return $status !== '' ? $status : self::STATUS_COMPLETE;
    }

    /**
     * @inheritDoc
     */
    public function getOverallScore(): int
    {
        return (int) $this->getData(self::OVERALL_SCORE);
    }

    /**
     * @inheritDoc
     */
    public function getGrade(): string
    {
        return (string) $this->getData(self::GRADE);
    }

    /**
     * @inheritDoc
     */
    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    /**
     * @inheritDoc
     */
    public function getSignalRatesDecoded(): array
    {
        /** @var array<string, float> $decoded */
        $decoded = $this->decodeColumn(self::SIGNAL_RATES);

        return $decoded;
    }

    /**
     * @inheritDoc
     */
    public function getProviderScoresDecoded(): array
    {
        /** @var array<string, int|null> $decoded */
        $decoded = $this->decodeColumn(self::PROVIDER_SCORES);

        return $decoded;
    }

    /**
     * @inheritDoc
     */
    public function getResultsDecoded(): array
    {
        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = $this->decodeColumn(self::RESULTS_JSON);

        return $decoded;
    }

    /**
     * Decode one JSON column, tolerating legacy or truncated payloads.
     *
     * @param string $column Column name.
     * @return array<mixed>
     */
    private function decodeColumn(string $column): array
    {
        $raw = $this->getData($column);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
