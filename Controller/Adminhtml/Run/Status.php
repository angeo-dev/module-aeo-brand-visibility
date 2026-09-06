<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Run;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
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
        $results = [];
        foreach ($row->getResultsDecoded() as $cell) {
            $responses = (array) ($cell['responses'] ?? []);
            $preview = isset($responses[0]) && is_string($responses[0])
                ? mb_substr($responses[0], 0, self::RESPONSE_PREVIEW_CHARS)
                : null;

            $results[] = [
                'provider_id' => (string) ($cell['provider_id'] ?? ''),
                'provider_label' => (string) ($cell['provider_label'] ?? ''),
                'prompt_key' => (string) ($cell['prompt_key'] ?? ''),
                'prompt' => (string) ($cell['prompt'] ?? ''),
                'samples' => (int) ($cell['samples'] ?? 0),
                'score' => (int) ($cell['score'] ?? 0),
                'score_margin' => (float) ($cell['score_margin'] ?? 0),
                'signals' => (array) ($cell['signals'] ?? []),
                'signal_rates' => (array) ($cell['signal_rates'] ?? []),
                'meta' => (array) ($cell['meta'] ?? []),
                'response' => $preview,
                'error' => $cell['error'] ?? null,
                'success' => empty($cell['error']),
            ];
        }

        return [
            'created_at' => (string) $row->getData(AuditResultInterface::CREATED_AT),
            'overall_score' => $row->getOverallScore(),
            'score_margin' => (float) $row->getData(AuditResultInterface::SCORE_MARGIN),
            'grade' => $row->getGrade(),
            'samples' => (int) $row->getData(AuditResultInterface::SAMPLES),
            'signal_rates' => $row->getSignalRatesDecoded(),
            'scores_by_provider' => $row->getProviderScoresDecoded(),
            'competitive' => [
                'share_of_voice' => (float) $row->getData(AuditResultInterface::SHARE_OF_VOICE),
                'win_rate' => (float) $row->getData(AuditResultInterface::WIN_RATE),
                'accuracy_issues' => (int) $row->getData(AuditResultInterface::ACCURACY_ISSUES),
            ],
            'results' => $results,
        ];
    }
}
