<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Laminas\Http\Request;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\LaminasClientFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared JSON-over-HTTPS transport for AI providers.
 *
 * Uses the framework HTTP client rather than the cURL extension directly, so
 * TLS verification, redirect handling and timeouts follow Magento defaults.
 */
abstract class AbstractHttpProvider implements AiProviderInterface
{
    /**
     * @param LaminasClientFactory $clientFactory Creates the HTTP client.
     * @param SerializerInterface $serializer Encodes and decodes JSON payloads.
     * @param LoggerInterface $logger Module logger.
     */
    public function __construct(
        private readonly LaminasClientFactory $clientFactory,
        private readonly SerializerInterface $serializer,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(Config $config): bool
    {
        return $config->isProviderEnabled($this->getProviderId())
            && $config->getProviderApiKey($this->getProviderId()) !== '';
    }

    /**
     * @inheritDoc
     */
    public function getProviderLabel(Config $config): string
    {
        $suffix = $this->isGrounded($config) ? ', web' : '';

        return sprintf('%s (%s%s)', $this->getDisplayName(), $this->resolveModel($config), $suffix);
    }

    /**
     * @inheritDoc
     */
    public function supportsGrounding(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isGrounded(Config $config): bool
    {
        return $this->supportsGrounding() && $config->isGroundingEnabled($this->getProviderId());
    }

    /**
     * Model configured for this provider, falling back to the provider default.
     *
     * @param Config $config Store-scoped configuration.
     * @return string
     */
    protected function resolveModel(Config $config): string
    {
        return $config->getProviderModel($this->getProviderId(), $this->getDefaultModel());
    }

    /**
     * Send a JSON POST request and return the decoded body.
     *
     * @param string $url Absolute endpoint URL.
     * @param array<string, mixed> $payload Request body.
     * @param array<string, string> $headers Request headers.
     * @param int $timeout Timeout in seconds.
     * @return array<string, mixed>
     * @throws LocalizedException On transport failure or a non-2xx status.
     */
    protected function post(string $url, array $payload, array $headers, int $timeout): array
    {
        $client = $this->clientFactory->create();
        $client->setUri($url);
        $client->setMethod(Request::METHOD_POST);
        $client->setOptions([
            'timeout' => $timeout,
            'maxredirects' => 0,
            'sslverifypeer' => true,
            'sslallowselfsigned' => false,
        ]);
        $client->setHeaders(array_merge(['Content-Type' => 'application/json'], $headers));
        $client->setRawBody($this->serializer->serialize($payload));

        try {
            $response = $client->send();
        } catch (\Throwable $e) {
            throw new LocalizedException(
                new Phrase('[%1] Transport error: %2', [$this->getProviderId(), $e->getMessage()]),
                $e instanceof \Exception ? $e : null
            );
        }

        $status = (int) $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $this->decode($body);

        if ($status < 200 || $status >= 300) {
            throw new LocalizedException(new Phrase(
                '[%1] API error %2: %3',
                [$this->getProviderId(), $status, $this->extractErrorMessage($decoded, $body)]
            ));
        }

        return $decoded;
    }

    /**
     * Decode a JSON body, returning an empty array when the payload is not JSON.
     *
     * @param string $body Raw response body.
     * @return array<string, mixed>
     */
    protected function decode(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($body);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Pull a human-readable message out of a failed response.
     *
     * @param array<string, mixed> $decoded Decoded body.
     * @param string $rawBody Raw body, used when nothing structured is present.
     * @return string
     */
    protected function extractErrorMessage(array $decoded, string $rawBody): string
    {
        $candidates = [
            $decoded['error']['message'] ?? null,
            $decoded['error']['type'] ?? null,
            $decoded['message'] ?? null,
            $decoded['detail'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return mb_substr(strip_tags($rawBody), 0, 200);
    }

    /**
     * Append cited source URLs so the analyser can detect domain citations.
     *
     * @param string $text Answer text.
     * @param string[] $urls Cited URLs.
     * @return string
     */
    protected function appendSources(string $text, array $urls): string
    {
        $urls = array_values(array_unique(array_filter($urls, static fn($u) => is_string($u) && $u !== '')));
        if ($urls === []) {
            return $text;
        }

        return $text . "\n\nSources: " . implode(', ', array_slice($urls, 0, 12));
    }

    /**
     * Provider display name without the model suffix.
     *
     * @return string
     */
    abstract protected function getDisplayName(): string;

    /**
     * Model used when the admin has not chosen one.
     *
     * @return string
     */
    abstract protected function getDefaultModel(): string;
}
