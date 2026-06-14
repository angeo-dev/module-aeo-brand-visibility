<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * @method int    getId()
 * @method string getBrandName()
 * @method string getBrandDomain()
 * @method int    getOverallScore()
 * @method string getGrade()
 * @method string getProviderScores()
 * @method string getSignalRates()
 * @method string getResultsJson()
 * @method string getTriggeredBy()
 * @method int    getQueriesCount()
 * @method int    getErrorsCount()
 * @method int    getFromCache()
 * @method string getCreatedAt()
 */
class AuditResult extends AbstractModel
{
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

    protected function _construct(): void
    {
        $this->_init(\Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult::class);
    }

    /** @return array<string, mixed> */
    public function getProviderScoresDecoded(): array
    {
        return $this->decode((string) $this->getProviderScores());
    }

    /** @return array<string, mixed> */
    public function getSignalRatesDecoded(): array
    {
        return $this->decode((string) $this->getSignalRates());
    }

    /** @return array<int, mixed> */
    public function getResultsDecoded(): array
    {
        return $this->decode((string) $this->getResultsJson());
    }

    /** @return array<mixed> */
    private function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($raw);
            return is_array($decoded) ? $decoded : [];
        } catch (\InvalidArgumentException) {
            return [];
        }
    }
}
