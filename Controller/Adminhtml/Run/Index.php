<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Run;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Result\PageFactory;
use Angeo\AeoBrandVisibility\Model\AuditResultRepository;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;

class Index extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::run';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly PageFactory $pageFactory,
        private readonly BrandVisibilityService $service,
        private readonly AuditResultRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        if ($this->getRequest()->isGet()) {
            $page = $this->pageFactory->create();
            $page->getConfig()->getTitle()->prepend(__('Brand Visibility — Run Audit'));
            return $page;
        }

        $result  = $this->jsonFactory->create();
        $refresh = (bool) $this->getRequest()->getParam('refresh', false);
        try {
            $report = $this->service->run(forceRefresh: $refresh, triggeredBy: 'admin');
            $stats  = $this->repository->getStatistics(30);

            $result->setData([
                'success'            => true,
                'overall_score'      => $report->getOverallScore(),
                'grade'              => $report->getGrade(),
                'from_cache'         => $report->fromCache,
                'generated_at'       => $report->generatedAt->format('Y-m-d H:i:s'),
                'signal_rates'       => [
                    'mentioned'          => $report->signalRate('mentioned'),
                    'recommended'        => $report->signalRate('recommended'),
                    'url_cited'          => $report->signalRate('url_cited'),
                    'first_result'       => $report->signalRate('first_result'),
                    'positive_sentiment' => $report->signalRate('positive_sentiment'),
                ],
                'scores_by_provider' => $report->scoreByProvider(),
                'results'            => array_map(fn($r) => [
                    'provider_id'    => $r->providerId,
                    'provider_label' => $r->providerLabel,
                    'prompt_key'     => $r->promptKey,
                    'prompt'         => $r->prompt,
                    'score'          => $r->score,
                    'signals'        => $r->signals,
                    'response'       => $r->isSuccess() ? mb_substr($r->rawResponse, 0, 600) : null,
                    'error'          => $r->errorMessage,
                    'success'        => $r->isSuccess(),
                ], $report->results),
                'statistics' => $stats,
            ]);
        } catch (\Throwable $e) {
            $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
        return $result;
    }
}
