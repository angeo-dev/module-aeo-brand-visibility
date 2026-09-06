<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Plan;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\RecommendationEngine;
use Angeo\AeoBrandVisibility\Service\ReportSerializer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Builds the action plan from the most recent stored run.
 *
 * Version 3.x executed a fresh, billable audit on every request to this
 * endpoint. The plan is now derived from data that already exists.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::view';

    /**
     * @param Context $context Backend action context.
     * @param JsonFactory $jsonFactory JSON result factory.
     * @param AuditResultRepositoryInterface $repository Run persistence.
     * @param ReportSerializer $reportSerializer Rebuilds a report from storage.
     * @param RecommendationEngine $engine Action plan builder.
     * @param Config $config Configuration accessor.
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AuditResultRepositoryInterface $repository,
        private readonly ReportSerializer $reportSerializer,
        private readonly RecommendationEngine $engine,
        private readonly Config $config
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
            $latest = $this->repository->getLatest($storeId, 1);
            if ($latest === []) {
                return $result->setData([
                    'success' => true,
                    'plan' => [],
                    'message' => (string) __('Run an audit first to generate an action plan.'),
                ]);
            }

            $row = $latest[0];
            $report = $this->reportSerializer->fromArray([
                'brand_name' => (string) $row->getData(AuditResultInterface::BRAND_NAME),
                'brand_domain' => (string) $row->getData(AuditResultInterface::BRAND_DOMAIN),
                'generated_at' => (string) $row->getData(AuditResultInterface::CREATED_AT),
                'samples' => (int) $row->getData(AuditResultInterface::SAMPLES),
                'results' => $row->getResultsDecoded(),
            ]);

            return $result->setData([
                'success' => true,
                'generated_at' => (string) $row->getData(AuditResultInterface::CREATED_AT),
                'plan' => $this->engine->buildPlan($report, $this->config->withStore($storeId)),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
