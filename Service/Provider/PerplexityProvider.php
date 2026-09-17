<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Perplexity Sonar provider.
 *
 * Always answers from live web search, which makes it the most responsive
 * signal: changes to the store show up here long before they reach the
 * training data of a memory-only model.
 */
class PerplexityProvider extends AbstractHttpProvider
{
    private const URL = 'https://api.perplexity.ai/chat/completions';

    /**
     * @inheritDoc
     */
    public function getProviderId(): string
    {
        return 'perplexity';
    }

    /**
     * @inheritDoc
     */
    public function supportsGrounding(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isGrounded(Config $config): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function query(string $systemPrompt, string $userPrompt, Config $config): string
    {
        $data = $this->post(
            self::URL,
            [
                'model' => $this->resolveModel($config),
                'max_tokens' => $config->getProviderMaxTokens($this->getProviderId()),
                'temperature' => $config->getProviderTemperature($this->getProviderId()),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
            ['Authorization' => 'Bearer ' . $config->getProviderApiKey($this->getProviderId())],
            $config->getProviderTimeout($this->getProviderId())
        );

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            throw new LocalizedException(new Phrase('[perplexity] The API returned an empty answer.'));
        }

        $urls = [];
        foreach ($data['citations'] ?? [] as $citation) {
            if (is_string($citation)) {
                $urls[] = $citation;
            }
        }
        foreach ($data['search_results'] ?? [] as $result) {
            if (is_string($result['url'] ?? null)) {
                $urls[] = $result['url'];
            }
        }

        return $this->appendSources($content, $urls);
    }

    /**
     * @inheritDoc
     */
    protected function getDisplayName(): string
    {
        return 'Perplexity';
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultModel(): string
    {
        return 'sonar';
    }
}
