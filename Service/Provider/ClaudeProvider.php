<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\Serialize\SerializerInterface;

class ClaudeProvider extends AbstractHttpProvider implements AiProviderInterface
{
    private const URL     = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    public function __construct(
        private readonly Config $config,
        SerializerInterface $serializer
    ) {
        parent::__construct($serializer);
    }

    public function getProviderId(): string    { return 'claude'; }
    public function getProviderLabel(): string { return 'Claude (' . $this->config->getClaudeModel() . ')'; }

    public function isConfigured(): bool
    {
        return $this->config->isClaudeEnabled() && $this->config->getClaudeApiKey() !== '';
    }

    public function query(string $systemPrompt, string $userPrompt): string
    {
        $data = $this->post(
            self::URL,
            [
                'model'      => $this->config->getClaudeModel(),
                'max_tokens' => $this->config->getClaudeMaxTokens(),
                'system'     => $systemPrompt,
                'messages'   => [['role' => 'user', 'content' => $userPrompt]],
            ],
            [
                'x-api-key: ' . $this->config->getClaudeApiKey(),
                'anthropic-version: ' . self::VERSION,
                'Content-Type: application/json',
            ],
            $this->config->getClaudeTimeout()
        );

        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'];
            }
        }

        throw new \RuntimeException('Claude: no text block in response.');
    }
}
