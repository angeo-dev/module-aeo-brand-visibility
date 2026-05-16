<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Plan;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Result\PageFactory;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Angeo\AeoBrandVisibility\Service\RecommendationEngine;

class Index extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::run';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly PageFactory $pageFactory,
        private readonly BrandVisibilityService $service,
        private readonly RecommendationEngine $engine
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        if ($this->getRequest()->isGet()) {
            $page = $this->pageFactory->create();
            $page->getConfig()->getTitle()->prepend(__('Brand Visibility — Action Plan'));
            return $page;
        }

        $result  = $this->jsonFactory->create();
        $refresh = (bool) $this->getRequest()->getParam('refresh', false);
        try {
            $report = $this->service->run(forceRefresh: $refresh);
            $plan   = $this->engine->buildPlan($report);
            $result->setData(['success' => true, 'plan' => $plan]);
        } catch (\Throwable $e) {
            $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
        return $result;
    }
}
