<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;

class ChatGptProvider extends AbstractHttpProvider implements AiProviderInterface
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(private readonly Config $config) {}

    public function getProviderId(): string    { return 'chatgpt'; }
    public function getProviderLabel(): string { return 'ChatGPT (' . $this->config->getGptModel() . ')'; }

    public function isConfigured(): bool
    {
        return $this->config->isGptEnabled() && $this->config->getGptApiKey() !== '';
    }

    public function query(string $systemPrompt, string $userPrompt): string
    {
        $data = $this->post(
            self::URL,
            [
                'model'       => $this->config->getGptModel(),
                'max_tokens'  => $this->config->getGptMaxTokens(),
                'temperature' => $this->config->getGptTemperature(),
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
            ],
            [
                'Authorization: Bearer ' . $this->config->getGptApiKey(),
                'Content-Type: application/json',
            ],
            $this->config->getGptTimeout()
        );

        return $data['choices'][0]['message']['content']
            ?? throw new \RuntimeException('ChatGPT: empty response content.');
    }
}
