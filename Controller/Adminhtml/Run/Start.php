<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Run;

use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\MessageQueue\AuditRunPublisher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Starts an audit run.
 *
 * POST only: the run spends money at the provider, so it must never be
 * reachable through a link, a prefetch or a browser cache revalidation.
 */
class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::run';

    /**
     * @param Context $context Backend action context.
     * @param JsonFactory $jsonFactory JSON result factory.
     * @param AuditRunPublisher $publisher Queues or executes the run.
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AuditRunPublisher $publisher
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
            $row = $this->publisher->start(
                (int) $this->getRequest()->getParam('store', 0),
                (bool) $this->getRequest()->getParam('refresh', false),
                'admin'
            );

            return $result->setData([
                'success' => true,
                'id' => (int) $row->getData(AuditResultInterface::ID),
                'status' => $row->getStatus(),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
