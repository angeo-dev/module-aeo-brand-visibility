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
 * Groq provider, OpenAI-compatible chat completions.
 *
 * Answers from model memory only — useful as a free baseline, not as evidence
 * of live web visibility.
 */
class GroqProvider extends AbstractHttpProvider
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    /**
     * @inheritDoc
     */
    public function getProviderId(): string
    {
        return 'groq';
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
            throw new LocalizedException(new Phrase('[groq] The API returned an empty answer.'));
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    protected function getDisplayName(): string
    {
        return 'Groq';
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultModel(): string
    {
        return 'llama-3.3-70b-versatile';
    }
}
