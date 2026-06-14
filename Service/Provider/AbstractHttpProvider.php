<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Magento\Framework\Serialize\SerializerInterface;

/**
 * Shared HTTPS transport for AI provider implementations.
 * Eliminates the identical post() method that existed in every provider.
 *
 * Hardening:
 *   - HTTPS-only (CURLOPT_PROTOCOLS / CURLOPT_REDIR_PROTOCOLS)
 *   - redirects disabled (no blind Location following → SSRF guard)
 *   - explicit connect timeout
 *   - request/response (de)serialisation via Magento SerializerInterface
 */
abstract class AbstractHttpProvider
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /**
     * @param  array<string, mixed> $payload
     * @param  string[]             $headers
     * @return array<string, mixed>
     * @throws \RuntimeException on cURL error or non-200 HTTP status
     */
    protected function post(string $url, array $payload, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $this->serializer->serialize($payload),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 15),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException(sprintf('[%s] cURL error: %s', $this->getProviderId(), $err));
        }

        $decoded = $this->decode((string) $body);

        if ($code !== 200) {
            $msg = $this->extractErrorMessage($decoded, (string) $body);
            throw new \RuntimeException(sprintf('[%s] API error [%d]: %s', $this->getProviderId(), $code, $msg));
        }

        return $decoded;
    }

    /**
     * Safe JSON decode via Magento serializer. Returns [] on empty or malformed body.
     *
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($body);
            return is_array($decoded) ? $decoded : [];
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Extract a human-readable error message from a failed API response.
     * Override in subclasses if the provider uses a non-standard error format.
     *
     * @param array<string, mixed> $decoded
     */
    protected function extractErrorMessage(array $decoded, string $rawBody): string
    {
        return $decoded['error']['message']
            ?? $decoded['error']['type']
            ?? $decoded['detail']
            ?? mb_substr($rawBody, 0, 200);
    }

    abstract public function getProviderId(): string;
}
