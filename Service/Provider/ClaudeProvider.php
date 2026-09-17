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
 * Anthropic Claude messages provider.
 *
 * When grounding is enabled the server-side web search tool is attached, so the
 * answer reflects the live web rather than training memory.
 */
class ClaudeProvider extends AbstractHttpProvider
{
    private const URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const WEB_SEARCH_TOOL = 'web_search_20250305';
    private const MAX_SEARCHES = 5;

    /**
     * @inheritDoc
     */
    public function getProviderId(): string
    {
        return 'claude';
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
        $payload = [
            'model' => $this->resolveModel($config),
            'max_tokens' => $config->getProviderMaxTokens($this->getProviderId()),
            'temperature' => $config->getProviderTemperature($this->getProviderId()),
            'system' => $systemPrompt,
            'messages' => [['role' => 'user', 'content' => $userPrompt]],
        ];

        if ($this->isGrounded($config)) {
            $payload['tools'] = [[
                'type' => self::WEB_SEARCH_TOOL,
                'name' => 'web_search',
                'max_uses' => self::MAX_SEARCHES,
            ]];
        }

        $data = $this->post(
            self::URL,
            $payload,
            [
                'x-api-key' => $config->getProviderApiKey($this->getProviderId()),
                'anthropic-version' => self::API_VERSION,
            ],
            $config->getProviderTimeout($this->getProviderId())
        );

        $text = '';
        $urls = [];

        foreach ($data['content'] ?? [] as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $text .= ($text === '' ? '' : "\n") . $block['text'];
                foreach ($block['citations'] ?? [] as $citation) {
                    if (is_string($citation['url'] ?? null)) {
                        $urls[] = $citation['url'];
                    }
                }
            }
            if ($type === 'web_search_tool_result') {
                foreach ($block['content'] ?? [] as $item) {
                    if (is_string($item['url'] ?? null)) {
                        $urls[] = $item['url'];
                    }
                }
            }
        }

        if (trim($text) === '') {
            throw new LocalizedException(new Phrase('[claude] The API returned no text block.'));
        }

        return $this->appendSources($text, $urls);
    }

    /**
     * @inheritDoc
     */
    protected function getDisplayName(): string
    {
        return 'Claude';
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultModel(): string
    {
        return 'claude-sonnet-4-6';
    }
}
