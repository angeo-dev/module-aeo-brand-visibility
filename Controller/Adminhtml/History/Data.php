<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\History;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Supplies the history table and trend statistics for one store scope.
 */
class Data extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::view';

    private const HISTORY_LIMIT = 50;
    private const STATISTICS_LIMIT = 30;

    /**
     * @param Context $context Backend action context.
     * @param JsonFactory $jsonFactory JSON result factory.
     * @param AuditResultRepositoryInterface $repository Run persistence.
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AuditResultRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        try {
            $rows = [];
            foreach ($this->repository->getLatest($storeId, self::HISTORY_LIMIT) as $row) {
                $rows[] = [
                    'id' => (int) $row->getData(AuditResultInterface::ID),
                    'created_at' => (string) $row->getData(AuditResultInterface::CREATED_AT),
                    'overall_score' => $row->getOverallScore(),
                    'score_margin' => (float) $row->getData(AuditResultInterface::SCORE_MARGIN),
                    'grade' => $row->getGrade(),
                    'samples' => (int) $row->getData(AuditResultInterface::SAMPLES),
                    'triggered_by' => (string) $row->getData(AuditResultInterface::TRIGGERED_BY),
                    'queries_count' => (int) $row->getData(AuditResultInterface::QUERIES_COUNT),
                    'errors_count' => (int) $row->getData(AuditResultInterface::ERRORS_COUNT),
                    'share_of_voice' => (float) $row->getData(AuditResultInterface::SHARE_OF_VOICE),
                    'win_rate' => (float) $row->getData(AuditResultInterface::WIN_RATE),
                    'signal_rates' => $row->getSignalRatesDecoded(),
                ];
            }

            return $result->setData([
                'success' => true,
                'history' => $rows,
                'statistics' => $this->repository->getStatistics($storeId, self::STATISTICS_LIMIT),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
