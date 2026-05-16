<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\History;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Angeo\AeoBrandVisibility\Model\AuditResultRepository;

class Data extends Action implements HttpGetActionInterface
{
    const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::run';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AuditResultRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $latest = $this->repository->getLatest(50);
            $stats  = $this->repository->getStatistics(30);
            $rows   = [];
            foreach ($latest as $row) {
                $rows[] = [
                    'id'             => $row->getId(),
                    'created_at'     => $row->getCreatedAt(),
                    'overall_score'  => $row->getOverallScore(),
                    'grade'          => $row->getGrade(),
                    'triggered_by'   => $row->getTriggeredBy(),
                    'queries_count'  => $row->getQueriesCount(),
                    'errors_count'   => $row->getErrorsCount(),
                    'from_cache'     => (bool) $row->getFromCache(),
                    'signal_rates'   => $row->getSignalRatesDecoded(),
                    'provider_scores'=> $row->getProviderScoresDecoded(),
                ];
            }
            $result->setData(['success' => true, 'history' => $rows, 'statistics' => $stats]);
        } catch (\Throwable $e) {
            $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
        return $result;
    }
}
