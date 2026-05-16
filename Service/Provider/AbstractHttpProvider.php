<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

/**
 * Shared HTTP transport for AI provider implementations.
 * Eliminates the identical post() method that existed in all three providers.
 */
abstract class AbstractHttpProvider
{
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
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException(sprintf('[%s] cURL error: %s', $this->getProviderId(), $err));
        }

        $decoded = json_decode((string) $body, true) ?? [];

        if ($code !== 200) {
            $msg = $this->extractErrorMessage($decoded, (string) $body);
            throw new \RuntimeException(sprintf('[%s] API error [%d]: %s', $this->getProviderId(), $code, $msg));
        }

        return $decoded;
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
