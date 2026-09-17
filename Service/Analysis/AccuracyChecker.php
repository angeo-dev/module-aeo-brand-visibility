<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Analysis;

use Angeo\AeoBrandVisibility\Model\Config;

/**
 * Flags answers where an AI attributes the wrong website to the brand.
 *
 * The recognised top-level domain list is extendable from configuration, so
 * European ccTLDs such as nl, de or co.uk are covered — 3.x hard-coded a short
 * list of generic TLDs and silently ignored everything else.
 */
class AccuracyChecker
{
    private const BASE_TLDS = [
        'com', 'net', 'org', 'io', 'dev', 'store', 'shop', 'co', 'app', 'eu', 'online',
    ];

    private const WINDOW_CHARS = 120;

    /**
     * Inspect one answer for wrong-URL attribution around the brand mention.
     *
     * @param string $text Lower-cased answer text.
     * @param string[] $brandTerms Brand name, aliases and domain variants.
     * @param Config $config Store-scoped configuration.
     * @return array{has_issue: bool, issues: string[]}
     */
    public function check(string $text, array $brandTerms, Config $config): array
    {
        $domain = $config->getBrandDomain();
        if ($domain === '' || $text === '') {
            return ['has_issue' => false, 'issues' => []];
        }

        $bare = (string) preg_replace('/^www\./', '', $domain);
        if (str_contains($text, $domain) || ($bare !== '' && str_contains($text, $bare))) {
            return ['has_issue' => false, 'issues' => []];
        }

        $pattern = $this->buildDomainPattern($config);
        $issues = [];

        foreach ($brandTerms as $term) {
            if ($term === '') {
                continue;
            }
            $position = mb_strpos($text, $term);
            if ($position === false) {
                continue;
            }

            $window = mb_substr($text, $position, mb_strlen($term) + self::WINDOW_CHARS);
            if (preg_match($pattern, $window, $matches) === 1 && $matches[1] !== $bare) {
                $issues[] = 'possible_wrong_url:' . $matches[1];
                break;
            }
        }

        return ['has_issue' => $issues !== [], 'issues' => $issues];
    }

    /**
     * Build the domain-detection regular expression for the configured TLD set.
     *
     * @param Config $config Store-scoped configuration.
     * @return string
     */
    private function buildDomainPattern(Config $config): string
    {
        $tlds = array_unique(array_merge(self::BASE_TLDS, $config->getExtraTlds()));
        usort($tlds, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $quoted = array_map(static fn(string $tld): string => preg_quote($tld, '/'), $tlds);

        return '/\b([a-z0-9][a-z0-9-]*\.(?:' . implode('|', $quoted) . '))\b/u';
    }
}
