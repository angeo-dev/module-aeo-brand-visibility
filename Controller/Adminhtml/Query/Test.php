<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Query;

use Angeo\AeoBrandVisibility\Api\BrandVisibilityServiceInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Runs a single provider and prompt for the admin preview.
 *
 * POST only: this call is billable, so it must not be triggered by navigation.
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::run';

    /**
     * @param Context $context Backend action context.
     * @param JsonFactory $jsonFactory JSON result factory.
     * @param BrandVisibilityServiceInterface $service Audit runner.
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly BrandVisibilityServiceInterface $service
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $cell = $this->service->querySingle(
                (string) $this->getRequest()->getParam('provider', ''),
                (string) $this->getRequest()->getParam('prompt_key', 'brand_direct'),
                (int) $this->getRequest()->getParam('store', 0)
            );

            return $result->setData([
                'success' => $cell->isSuccess(),
                'provider_label' => $cell->providerLabel,
                'prompt' => $cell->prompt,
                'raw_response' => $cell->getRawResponse(),
                'signals' => $cell->getSignals(),
                'score' => $cell->score,
                'meta' => $cell->meta,
                'error' => $cell->errorMessage,
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
