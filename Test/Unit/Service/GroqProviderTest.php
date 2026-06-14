<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\Provider\GroqProvider;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\AeoBrandVisibility\Service\Provider\GroqProvider
 */
class GroqProviderTest extends TestCase
{
    private Config&MockObject $config;
    private GroqProvider $provider;

    protected function setUp(): void
    {
        $this->config    = $this->createMock(Config::class);
        $serializer      = $this->createMock(SerializerInterface::class);
        $this->provider  = new GroqProvider($this->config, $serializer);
    }

    public function testGetProviderId(): void
    {
        $this->assertSame('groq', $this->provider->getProviderId());
    }

    public function testGetProviderLabelContainsModel(): void
    {
        $this->config->method('getGroqModel')->willReturn('llama-3.3-70b-versatile');
        $this->assertStringContainsString('llama-3.3-70b-versatile', $this->provider->getProviderLabel());
    }

    public function testIsConfiguredWithApiKey(): void
    {
        $this->config->method('getGroqApiKey')->willReturn('gsk_abc123');
        $this->assertTrue($this->provider->isConfigured());
    }

    public function testIsNotConfiguredWithEmptyKey(): void
    {
        $this->config->method('getGroqApiKey')->willReturn('');
        $this->assertFalse($this->provider->isConfigured());
    }
}
