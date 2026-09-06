<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\ProviderResponse;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Anthropic Claude provider. Two modes since 2.0.0: plain training recall
 * (default) and live web search via the server-side web_search tool.
 * In grounded mode Claude returns per-block citation metadata — collected
 * here as structured citations.
 */
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

    public function supportsGrounding(): bool { return true; }
    public function isGrounded(): bool        { return $this->config->isClaudeGrounded(); }

    public function query(string $systemPrompt, string $userPrompt): ProviderResponse
    {
        $grounded = $this->isGrounded();

        $payload = [
            'model'       => $this->config->getClaudeModel(),
            'max_tokens'  => $this->config->getClaudeMaxTokens(),
            // Low fixed temperature: visibility measurement needs the model's
            // most probable answer, not creative variance (score stability).
            'temperature' => 0.2,
            'system'      => $systemPrompt,
            'messages'    => [['role' => 'user', 'content' => $userPrompt]],
        ];

        if ($grounded) {
            $payload['tools'] = [[
                'type'     => 'web_search_20250305',
                'name'     => 'web_search',
                'max_uses' => 3,
            ]];
        }

        $data = $this->post(
            self::URL,
            $payload,
            [
                'x-api-key: ' . $this->config->getClaudeApiKey(),
                'anthropic-version: ' . self::VERSION,
                'Content-Type: application/json',
            ],
            $grounded
                ? max($this->config->getClaudeTimeout(), 60)
                : $this->config->getClaudeTimeout()
        );

        $text      = '';
        $citations = [];

        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') !== 'text') {
                continue;
            }
            $text .= ($text !== '' ? "\n" : '') . ($block['text'] ?? '');
            foreach ($block['citations'] ?? [] as $citation) {
                if (!empty($citation['url'])) {
                    $citations[] = (string) $citation['url'];
                }
            }
        }

        if ($text === '') {
            throw new \RuntimeException('Claude: no text block in response.');
        }

        return new ProviderResponse($text, array_values(array_unique($citations)), $grounded);
    }
}
