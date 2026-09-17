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
 * OpenAI chat completions provider.
 *
 * Reasoning models (o-series) reject `temperature` and expect
 * `max_completion_tokens`, so the payload is shaped per model family — the 3.x
 * code sent one payload for every model and failed on the o-series.
 *
 * Grounding requires a search-capable model such as gpt-4o-search-preview.
 */
class ChatGptProvider extends AbstractHttpProvider
{
    private const URL = 'https://api.openai.com/v1/chat/completions';
    private const REASONING_MODEL_PATTERN = '/^(o\d|gpt-5)/i';
    private const SEARCH_MODEL_PATTERN = '/search/i';

    /**
     * @inheritDoc
     */
    public function getProviderId(): string
    {
        return 'chatgpt';
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
    public function query(string $systemPrompt, string $userPrompt, Config $config): string
    {
        $model = $this->resolveModel($config);
        $maxTokens = $config->getProviderMaxTokens($this->getProviderId());

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if (preg_match(self::REASONING_MODEL_PATTERN, $model) === 1) {
            $payload['max_completion_tokens'] = $maxTokens;
        } else {
            $payload['max_tokens'] = $maxTokens;
            if (preg_match(self::SEARCH_MODEL_PATTERN, $model) !== 1) {
                $payload['temperature'] = $config->getProviderTemperature($this->getProviderId());
            }
        }

        if ($this->isGrounded($config)) {
            $payload['web_search_options'] = (object) [];
        }

        $data = $this->post(
            self::URL,
            $payload,
            ['Authorization' => 'Bearer ' . $config->getProviderApiKey($this->getProviderId())],
            $config->getProviderTimeout($this->getProviderId())
        );

        $message = $data['choices'][0]['message'] ?? [];
        $content = is_string($message['content'] ?? null) ? $message['content'] : '';

        if (trim($content) === '') {
            throw new LocalizedException(new Phrase('[chatgpt] The API returned an empty answer.'));
        }

        $urls = [];
        foreach ($message['annotations'] ?? [] as $annotation) {
            $url = $annotation['url_citation']['url'] ?? null;
            if (is_string($url)) {
                $urls[] = $url;
            }
        }

        return $this->appendSources($content, $urls);
    }

    /**
     * @inheritDoc
     */
    protected function getDisplayName(): string
    {
        return 'ChatGPT';
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultModel(): string
    {
        return 'gpt-4.1-mini';
    }
}
