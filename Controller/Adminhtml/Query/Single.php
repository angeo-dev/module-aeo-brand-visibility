<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Query;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
use Psr\Log\LoggerInterface;
class Single extends Action implements HttpGetActionInterface
{
    const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::run';
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly BrandVisibilityService $service,
        private readonly LoggerInterface $logger
    ) { parent::__construct($context); }
    public function execute()
    {
        $result    = $this->jsonFactory->create();
        $provider  = (string) $this->getRequest()->getParam('provider', 'chatgpt');
        $promptKey = (string) $this->getRequest()->getParam('prompt_key', 'brand_direct');
        try {
            $r = $this->service->querySingle($provider, $promptKey);
            $result->setData([
                'success'        => $r->isSuccess(),
                'provider_label' => $r->providerLabel,
                'prompt'         => $r->prompt,
                'raw_response'   => $r->rawResponse,
                'signals'        => $r->signals,
                'score'          => $r->score,
                'error'          => $r->errorMessage,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Single query failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            $result->setData([
                'success' => false,
                'message' => (string) __('Query failed: %1', $e->getMessage()),
            ]);
        }
        return $result;
    }
}
