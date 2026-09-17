<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Model;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\StoreContextResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the configuration reads that were wrong in 3.0.0.
 */
class ConfigTest extends TestCase
{
    /**
     * Zero must survive as zero for the cache lifetime, not fall back to the default.
     *
     * @return void
     */
    public function testZeroCacheLifetimeIsPreserved(): void
    {
        $config = $this->config(['angeo_brand_vis/general/cache_ttl_hours' => '0']);

        $this->assertSame(0, $config->getCacheTtlHours());
    }

    /**
     * A missing value must fall back to the documented default.
     *
     * @return void
     */
    public function testMissingCacheLifetimeUsesDefault(): void
    {
        $this->assertSame(24, $this->config([])->getCacheTtlHours());
    }

    /**
     * Zero temperature is a legal, deterministic setting and must be preserved.
     *
     * @return void
     */
    public function testZeroTemperatureIsPreserved(): void
    {
        $config = $this->config(['angeo_brand_vis/chatgpt/temperature' => '0']);

        $this->assertSame(0.0, $config->getProviderTemperature('chatgpt'));
    }

    /**
     * The domain must be stripped of scheme, path and trailing slash.
     *
     * @return void
     */
    public function testDomainIsNormalised(): void
    {
        $config = $this->config(['angeo_brand_vis/general/brand_domain' => 'HTTPS://Example.NL/shop/']);

        $this->assertSame('example.nl', $config->getBrandDomain());
    }

    /**
     * Override text must be ignored while its prompt toggle is off.
     *
     * @return void
     */
    public function testDisabledPromptIgnoresOverrideText(): void
    {
        $config = $this->config([
            'angeo_brand_vis/queries/enable_category' => '0',
            'angeo_brand_vis/queries/prompt_category' => 'Custom text',
        ]);

        $this->assertArrayNotHasKey('category', $config->getActivePrompts());
    }

    /**
     * Override text must replace the built-in template while the toggle is on.
     *
     * @return void
     */
    public function testEnabledPromptUsesOverrideText(): void
    {
        $config = $this->config([
            'angeo_brand_vis/queries/enable_category' => '1',
            'angeo_brand_vis/queries/prompt_category' => 'Custom text',
        ]);

        $this->assertSame('Custom text', $config->getActivePrompts()['category']);
    }

    /**
     * Country domains must be accepted by the extra top-level domain field.
     *
     * @return void
     */
    public function testExtraTopLevelDomainsAreParsed(): void
    {
        $config = $this->config(['angeo_brand_vis/analysis/extra_tlds' => ' .nl, DE , co.uk, !bad ']);

        $this->assertSame(['nl', 'de', 'co.uk'], $config->getExtraTlds());
    }

    /**
     * Competitors must be parsed from either separator.
     *
     * @return void
     */
    public function testCompetitorsAreParsed(): void
    {
        $config = $this->config([
            'angeo_brand_vis/competitors/list' => "Marktplaats | marktplaats.nl\nEtsy,etsy.com\n\n",
        ]);

        $this->assertSame(
            [
                ['name' => 'Marktplaats', 'domain' => 'marktplaats.nl'],
                ['name' => 'Etsy', 'domain' => 'etsy.com'],
            ],
            $config->getCompetitors()
        );
    }

    /**
     * The prompt cap must apply, and zero must mean no cap.
     *
     * @return void
     */
    public function testPromptCapApplies(): void
    {
        $enabled = [
            'angeo_brand_vis/queries/enable_recommendation' => '1',
            'angeo_brand_vis/queries/enable_category' => '1',
            'angeo_brand_vis/queries/enable_brand_direct' => '1',
        ];

        $this->assertCount(2, $this->config($enabled + ['angeo_brand_vis/queries/max_prompts' => '2'])
            ->getActivePrompts());
        $this->assertCount(3, $this->config($enabled + ['angeo_brand_vis/queries/max_prompts' => '0'])
            ->getActivePrompts());
    }

    /**
     * Build a Config backed by a fixed array of settings.
     *
     * @param array<string, string> $settings Configuration values by path.
     * @return Config
     */
    private function config(array $settings): Config
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn(string $path) => $settings[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn(string $path) => (bool) ($settings[$path] ?? false));

        return new Config(
            $scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StoreContextResolver::class)
        );
    }
}
