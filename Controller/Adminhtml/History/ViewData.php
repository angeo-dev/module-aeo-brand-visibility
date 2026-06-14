<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\History;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Angeo\AeoBrandVisibility\Model\AuditResultRepository;
use Psr\Log\LoggerInterface;

class ViewData extends Action implements HttpGetActionInterface
{
    const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::run';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AuditResultRepository $repository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $id     = (int) $this->getRequest()->getParam('id');
        try {
            $row = $this->repository->getById($id);
            $result->setData([
                'success'         => true,
                'id'              => $row->getId(),
                'created_at'      => $row->getCreatedAt(),
                'overall_score'   => $row->getOverallScore(),
                'grade'           => $row->getGrade(),
                'triggered_by'    => $row->getTriggeredBy(),
                'queries_count'   => $row->getQueriesCount(),
                'errors_count'    => $row->getErrorsCount(),
                'from_cache'      => (bool) $row->getFromCache(),
                'signal_rates'    => $row->getSignalRatesDecoded(),
                'provider_scores' => $row->getProviderScoresDecoded(),
                'results'         => $row->getResultsDecoded(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Audit detail load failed', ['id' => $id, 'error' => $e->getMessage()]);
            $result->setData([
                'success' => false,
                'message' => (string) __('Audit detail could not be loaded. See the Brand Visibility log for details.'),
            ]);
        }
        return $result;
    }
}
