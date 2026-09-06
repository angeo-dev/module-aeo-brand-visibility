<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Angeo\AeoBrandVisibility\Api\Data\VisibilitySummaryInterface;
use Angeo\AeoBrandVisibility\Api\Data\VisibilitySummaryInterfaceFactory;
use Angeo\AeoBrandVisibility\Api\ReportManagementInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * REST implementation (since 3.0.0). Reads the newest persisted record for the
 * requested scope and maps it onto the public summary DTO. No live audit is
 * triggered — the endpoint is a cheap read of stored results.
 */
class ReportManagement implements ReportManagementInterface
{
    public function __construct(
        private readonly AuditResultRepository            $repository,
        private readonly VisibilitySummaryInterfaceFactory $summaryFactory,
        private readonly SerializerInterface              $serializer,
    ) {
    }

    public function getLatest(?int $storeId = null): VisibilitySummaryInterface
    {
        $record = $this->repository->getLatestForStore($storeId);
        if ($record === null) {
            throw new NoSuchEntityException(
                __('No brand visibility audit has been recorded yet%1.',
                    $storeId !== null ? __(' for store %1', $storeId) : '')
            );
        }

        $sov = $record->getShareOfVoiceDecoded();

        /** @var VisibilitySummaryInterface $summary */
        $summary = $this->summaryFactory->create();
        return $summary
            ->setScore((int) $record->getOverallScore())
            ->setGrade((string) $record->getGrade())
            ->setBrandName((string) $record->getBrandName())
            ->setStoreId($record->getStoreId() !== null ? (int) $record->getStoreId() : null)
            ->setGeneratedAt((string) $record->getCreatedAt())
            ->setShareOfVoiceJson($this->serializer->serialize($sov));
    }
}
