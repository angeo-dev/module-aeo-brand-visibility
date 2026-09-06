<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\MessageQueue;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\BrandVisibilityServiceInterface;
use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Starts an audit run.
 *
 * A full run is many sequential API calls and used to be executed inside the
 * admin request, where it regularly hit the PHP or gateway timeout. The run is
 * now queued: a pending row is created immediately, the consumer fills it in,
 * and the admin screen polls for the result. Operators without a running
 * consumer can switch the module to inline mode.
 */
class AuditRunPublisher
{
    public const TOPIC = 'angeo.brandvis.audit.run';

    /**
     * @param PublisherInterface $publisher Message queue publisher.
     * @param AuditResultRepositoryInterface $repository Run persistence.
     * @param BrandVisibilityServiceInterface $service Audit runner, used in inline mode.
     * @param Config $config Configuration accessor.
     * @param SerializerInterface $serializer Message payload encoding.
     */
    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly AuditResultRepositoryInterface $repository,
        private readonly BrandVisibilityServiceInterface $service,
        private readonly Config $config,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Queue or execute a run and return the row that will hold the result.
     *
     * @param int $storeId Store view id.
     * @param bool $forceRefresh Bypass the result cache.
     * @param string $triggeredBy Origin of the run.
     * @return AuditResultInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function start(int $storeId, bool $forceRefresh, string $triggeredBy): AuditResultInterface
    {
        $row = $this->repository->createPendingRun($storeId, $triggeredBy);
        $id = (int) $row->getData(AuditResultInterface::ID);

        if ($this->config->withStore($storeId)->getRunMode() === Config::RUN_MODE_SYNC) {
            $this->service->run($storeId, $forceRefresh, $triggeredBy, $id);

            return $this->repository->getById($id);
        }

        $this->publisher->publish(self::TOPIC, $this->serializer->serialize([
            'audit_result_id' => $id,
            'store_id' => $storeId,
            'force_refresh' => $forceRefresh,
            'triggered_by' => $triggeredBy,
        ]));

        return $row;
    }
}
