<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\MessageQueue;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\BrandVisibilityServiceInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes queued audit runs outside the web request.
 */
class AuditRunConsumer
{
    /**
     * @param BrandVisibilityServiceInterface $service Audit runner.
     * @param AuditResultRepositoryInterface $repository Run persistence.
     * @param SerializerInterface $serializer Message payload decoding.
     * @param LoggerInterface $logger Module logger.
     */
    public function __construct(
        private readonly BrandVisibilityServiceInterface $service,
        private readonly AuditResultRepositoryInterface $repository,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle one queued run.
     *
     * @param string $message Serialised job payload.
     * @return void
     */
    public function process(string $message): void
    {
        $auditResultId = 0;

        try {
            $payload = $this->serializer->unserialize($message);
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Malformed job payload.');
            }

            $auditResultId = (int) ($payload['audit_result_id'] ?? 0);
            $storeId = (int) ($payload['store_id'] ?? 0);

            $this->service->run(
                $storeId,
                (bool) ($payload['force_refresh'] ?? false),
                (string) ($payload['triggered_by'] ?? 'admin'),
                $auditResultId > 0 ? $auditResultId : null
            );
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Queued run failed.', [
                'audit_result_id' => $auditResultId,
                'error' => $e->getMessage(),
            ]);

            if ($auditResultId > 0) {
                $this->repository->markFailed($auditResultId, $e->getMessage());
            }
        }
    }
}
