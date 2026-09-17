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
 * Google Gemini provider using the generateContent endpoint.
 *
 * The API key travels in the x-goog-api-key header. Version 3.x appended it to
 * the query string, where it ended up in proxy and access logs.
 */
class GeminiProvider extends AbstractHttpProvider
{
    private const URL_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * @inheritDoc
     */
    public function getProviderId(): string
    {
        return 'gemini';
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
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => [
                'maxOutputTokens' => $config->getProviderMaxTokens($this->getProviderId()),
                'temperature' => $config->getProviderTemperature($this->getProviderId()),
            ],
        ];

        if ($this->isGrounded($config)) {
            $payload['tools'] = [['google_search' => (object) []]];
        }

        $data = $this->post(
            sprintf(self::URL_TEMPLATE, rawurlencode($this->resolveModel($config))),
            $payload,
            ['x-goog-api-key' => $config->getProviderApiKey($this->getProviderId())],
            $config->getProviderTimeout($this->getProviderId())
        );

        $candidate = $data['candidates'][0] ?? [];
        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if (trim($text) === '') {
            throw new LocalizedException(new Phrase(
                '[gemini] The API returned an empty answer. Finish reason: %1.',
                [(string) ($candidate['finishReason'] ?? 'unknown')]
            ));
        }

        $urls = [];
        foreach ($candidate['groundingMetadata']['groundingChunks'] ?? [] as $chunk) {
            if (is_string($chunk['web']['uri'] ?? null)) {
                $urls[] = $chunk['web']['uri'];
            }
            if (is_string($chunk['web']['domain'] ?? null)) {
                $urls[] = $chunk['web']['domain'];
            }
        }

        return $this->appendSources($text, $urls);
    }

    /**
     * @inheritDoc
     */
    protected function extractErrorMessage(array $decoded, string $rawBody): string
    {
        $message = $decoded['error']['message'] ?? null;

        return is_string($message) && $message !== ''
            ? $message
            : parent::extractErrorMessage($decoded, $rawBody);
    }

    /**
     * @inheritDoc
     */
    protected function getDisplayName(): string
    {
        return 'Gemini';
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultModel(): string
    {
        return 'gemini-2.0-flash';
    }
}
