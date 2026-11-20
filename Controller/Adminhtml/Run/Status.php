<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Run;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Service\RunPayloadBuilder;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Polling endpoint for a queued or finished run.
 */
class Status extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::view';

    private const RESPONSE_PREVIEW_CHARS = 700;

    /**
     * @param Context $context Backend action context.
     * @param JsonFactory $jsonFactory JSON result factory.
     * @param AuditResultRepositoryInterface $repository Run persistence.
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AuditResultRepositoryInterface $repository,
        private readonly RunPayloadBuilder $payloadBuilder
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $id = (int) $this->getRequest()->getParam('id', 0);

        try {
            $row = $this->repository->getById($id);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }

        $status = $row->getStatus();
        $payload = [
            'success' => true,
            'id' => (int) $row->getData(AuditResultInterface::ID),
            'status' => $status,
            'error' => $row->getData(AuditResultInterface::ERROR_MESSAGE),
        ];

        if ($status === AuditResultInterface::STATUS_COMPLETE) {
            $payload += $this->buildCompletePayload($row);
            $payload['statistics'] = $this->repository->getStatistics($row->getStoreId(), 30);
        }

        return $result->setData($payload);
    }

    /**
     * Shape a finished run for the dashboard.
     *
     * @param AuditResultInterface $row Finished run.
     * @return array<string, mixed>
     */
    private function buildCompletePayload(AuditResultInterface $row): array
    {
        $payload = $this->payloadBuilder->build($row, self::RESPONSE_PREVIEW_CHARS);
        unset($payload['id'], $payload['brand_name'], $payload['brand_domain']);

        return $payload;
    }
}
