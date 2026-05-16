<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Magento\Framework\Model\AbstractModel;

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
    protected function _construct(): void
    {
        $this->_init(\Angeo\AeoBrandVisibility\Model\ResourceModel\AuditResult::class);
    }

    public function getProviderScoresDecoded(): array
    {
        return json_decode($this->getProviderScores() ?: '{}', true) ?? [];
    }

    public function getSignalRatesDecoded(): array
    {
        return json_decode($this->getSignalRates() ?: '{}', true) ?? [];
    }

    public function getResultsDecoded(): array
    {
        return json_decode($this->getResultsJson() ?: '[]', true) ?? [];
    }
}
