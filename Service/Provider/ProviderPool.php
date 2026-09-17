<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Registry of AI providers, populated from di.xml.
 *
 * Replaces the hard-coded provider list of 3.x: a new provider is added by
 * implementing AiProviderInterface and appending it to the di.xml argument.
 */
class ProviderPool
{
    /**
     * @var AiProviderInterface[]
     */
    private array $providers;

    /**
     * @param AiProviderInterface[] $providers Provider instances keyed by identifier.
     * @throws LocalizedException When an entry does not implement the provider contract.
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $name => $provider) {
            if (!$provider instanceof AiProviderInterface) {
                throw new LocalizedException(new Phrase(
                    'Provider "%1" must implement %2.',
                    [(string) $name, AiProviderInterface::class]
                ));
            }
        }

        $this->providers = $providers;
    }

    /**
     * Every registered provider, configured or not.
     *
     * @return AiProviderInterface[]
     */
    public function getAll(): array
    {
        return $this->providers;
    }

    /**
     * Providers that are enabled and hold credentials for this scope.
     *
     * @param Config $config Store-scoped configuration.
     * @return AiProviderInterface[]
     */
    public function getEnabled(Config $config): array
    {
        return array_values(array_filter(
            $this->providers,
            static fn(AiProviderInterface $provider): bool => $provider->isConfigured($config)
        ));
    }

    /**
     * One enabled provider by identifier.
     *
     * @param string $providerId Provider identifier.
     * @param Config $config Store-scoped configuration.
     * @return AiProviderInterface|null
     */
    public function get(string $providerId, Config $config): ?AiProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getProviderId() === $providerId && $provider->isConfigured($config)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Identifier to label map for the whole pool.
     *
     * @param Config $config Store-scoped configuration.
     * @return array<string, string>
     */
    public function getLabels(Config $config): array
    {
        $labels = [];
        foreach ($this->providers as $provider) {
            $labels[$provider->getProviderId()] = $provider->getProviderLabel($config);
        }

        return $labels;
    }
}
