<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\ProviderResponse;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * OpenAI provider with two measurement modes (since 2.0.0):
 *
 *  - TRAINING RECALL (default): plain Chat Completions. Measures what the
 *    model remembers about the brand from training data.
 *  - LIVE SEARCH (grounded toggle): the Responses API with the web_search
 *    tool — the same machinery real ChatGPT users get. Measures whether the
 *    brand appears when the model actually searches the web, and returns
 *    the URLs it cited as structured citations.
 *
 * The two modes answer different questions and are labelled per result so
 * they are never silently averaged.
 */
class ChatGptProvider extends AbstractHttpProvider implements AiProviderInterface
{
    private const URL_CHAT      = 'https://api.openai.com/v1/chat/completions';
    private const URL_RESPONSES = 'https://api.openai.com/v1/responses';

    public function __construct(
        private readonly Config $config,
        SerializerInterface $serializer
    ) {
        parent::__construct($serializer);
    }

    public function getProviderId(): string    { return 'chatgpt'; }
    public function getProviderLabel(): string { return 'ChatGPT (' . $this->config->getGptModel() . ')'; }

    public function isConfigured(): bool
    {
        return $this->config->isGptEnabled() && $this->config->getGptApiKey() !== '';
    }

    public function supportsGrounding(): bool { return true; }
    public function isGrounded(): bool        { return $this->config->isGptGrounded(); }

    public function query(string $systemPrompt, string $userPrompt): ProviderResponse
    {
        return $this->isGrounded()
            ? $this->queryGrounded($systemPrompt, $userPrompt)
            : $this->queryRecall($systemPrompt, $userPrompt);
    }

    private function queryRecall(string $systemPrompt, string $userPrompt): ProviderResponse
    {
        $data = $this->post(
            self::URL_CHAT,
            [
                'model'       => $this->config->getGptModel(),
                'max_tokens'  => $this->config->getGptMaxTokens(),
                'temperature' => $this->config->getGptTemperature(),
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
            ],
            $this->headers(),
            $this->config->getGptTimeout()
        );

        $text = $data['choices'][0]['message']['content']
            ?? throw new \RuntimeException('ChatGPT: empty response content.');

        return new ProviderResponse($text, [], false);
    }

    /**
     * Responses API + web_search tool. Text lives in output[] message items;
     * cited URLs arrive as url_citation annotations on the text parts.
     */
    private function queryGrounded(string $systemPrompt, string $userPrompt): ProviderResponse
    {
        $data = $this->post(
            self::URL_RESPONSES,
            [
                'model'             => $this->config->getGptModel(),
                'max_output_tokens' => $this->config->getGptMaxTokens(),
                'instructions'      => $systemPrompt,
                'input'             => $userPrompt,
                'tools'             => [['type' => 'web_search']],
            ],
            $this->headers(),
            // Live search needs headroom over the plain-recall timeout.
            max($this->config->getGptTimeout(), 60)
        );

        $text      = '';
        $citations = [];

        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') !== 'output_text') {
                    continue;
                }
                $text .= ($text !== '' ? "\n" : '') . ($part['text'] ?? '');
                foreach ($part['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation' && !empty($annotation['url'])) {
                        $citations[] = (string) $annotation['url'];
                    }
                }
            }
        }

        if ($text === '') {
            throw new \RuntimeException('ChatGPT (web search): empty response output.');
        }

        return new ProviderResponse($text, array_values(array_unique($citations)), true);
    }

    /** @return string[] */
    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->config->getGptApiKey(),
            'Content-Type: application/json',
        ];
    }
}
