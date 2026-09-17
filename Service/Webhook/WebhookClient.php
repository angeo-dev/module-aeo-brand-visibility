<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Webhook;

use Laminas\Http\Request;
use Magento\Framework\HTTP\LaminasClientFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Delivers alert payloads to a validated external endpoint.
 */
class WebhookClient
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * @param LaminasClientFactory $clientFactory Creates the HTTP client.
     * @param WebhookUrlValidator $urlValidator Rejects unsafe endpoints.
     * @param SerializerInterface $serializer Encodes the payload.
     * @param LoggerInterface $logger Module logger.
     */
    public function __construct(
        private readonly LaminasClientFactory $clientFactory,
        private readonly WebhookUrlValidator $urlValidator,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Post a JSON payload, logging rather than throwing on failure.
     *
     * @param string $url Configured endpoint.
     * @param array<string, mixed> $payload Alert payload.
     * @param bool $allowPrivate Whether private addresses are permitted.
     * @return bool Whether delivery succeeded.
     */
    public function send(string $url, array $payload, bool $allowPrivate = false): bool
    {
        try {
            $this->urlValidator->validate($url, $allowPrivate);

            $client = $this->clientFactory->create();
            $client->setUri($url);
            $client->setMethod(Request::METHOD_POST);
            $client->setOptions([
                'timeout' => self::TIMEOUT_SECONDS,
                'maxredirects' => 0,
                'sslverifypeer' => true,
            ]);
            $client->setHeaders(['Content-Type' => 'application/json']);
            $client->setRawBody($this->serializer->serialize($payload));

            $status = (int) $client->send()->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('[BrandVis] Webhook returned a non-success status.', [
                    'status' => $status,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[BrandVis] Webhook delivery failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
