<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Webhook;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Guards the admin-supplied alert webhook against server-side request forgery.
 *
 * Version 3.0.0 posted to whatever URL was stored, which allowed an admin
 * account to reach internal services such as cloud metadata endpoints. Here the
 * URL must be HTTPS and must resolve outside private and reserved ranges,
 * unless an operator explicitly allows private targets.
 */
class WebhookUrlValidator
{
    private const ALLOWED_SCHEME = 'https';

    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata.google.internal',
        'metadata.goog',
    ];

    /**
     * Validate a webhook endpoint.
     *
     * @param string $url Configured endpoint.
     * @param bool $allowPrivate Whether private and reserved addresses are permitted.
     * @return void
     * @throws LocalizedException When the endpoint is not safe to call.
     */
    public function validate(string $url, bool $allowPrivate = false): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new LocalizedException(new Phrase('The webhook URL is not a valid absolute URL.'));
        }

        if (strtolower((string) $parts['scheme']) !== self::ALLOWED_SCHEME) {
            throw new LocalizedException(new Phrase('The webhook URL must use HTTPS.'));
        }

        $host = strtolower((string) $parts['host']);
        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new LocalizedException(new Phrase('The webhook host is not allowed.'));
        }

        if ($allowPrivate) {
            return;
        }

        foreach ($this->resolveAddresses($host) as $address) {
            if (!$this->isPublicAddress($address)) {
                throw new LocalizedException(new Phrase(
                    'The webhook host resolves to a private or reserved address (%1).',
                    [$address]
                ));
            }
        }
    }

    /**
     * Resolve every IPv4 and IPv6 address behind a host name.
     *
     * @param string $host Host name or literal address.
     * @return string[]
     * @throws LocalizedException When the host cannot be resolved.
     */
    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        foreach (['A', 'AAAA'] as $type) {
            // Magento's error handler turns the DNS warning into an exception;
            // an unresolvable host simply yields no records.
            try {
                $records = dns_get_record($host, $type === 'A' ? DNS_A : DNS_AAAA);
            } catch (\Throwable $e) {
                $records = [];
            }
            foreach (is_array($records) ? $records : [] as $record) {
                $address = $record['ip'] ?? ($record['ipv6'] ?? null);
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            throw new LocalizedException(new Phrase('The webhook host could not be resolved.'));
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether an address sits outside the private and reserved ranges.
     *
     * @param string $address IPv4 or IPv6 address.
     * @return bool
     */
    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
