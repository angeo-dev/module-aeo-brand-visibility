<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Service\Provider;
use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Groq AI provider — OpenAI-compatible API.
 * Free tier: 30 RPM, 14,400 RPD. No credit card required.
 * Get API key: console.groq.com
 */
class GroqProvider extends AbstractHttpProvider implements AiProviderInterface
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(
        private readonly Config $config,
        SerializerInterface $serializer
    ) {
        parent::__construct($serializer);
    }

    public function getProviderId(): string    { return 'groq'; }
    public function getProviderLabel(): string { return 'Groq (' . $this->config->getGroqModel() . ')'; }

    public function isConfigured(): bool
    {
        return $this->config->isGroqEnabled() && $this->config->getGroqApiKey() !== '';
    }

    public function query(string $systemPrompt, string $userPrompt): string
    {
        $data = $this->post(
            self::URL,
            [
                'model'       => $this->config->getGroqModel(),
                'max_tokens'  => $this->config->getGroqMaxTokens(),
                'temperature' => 0.3,
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
            ],
            [
                'Authorization: Bearer ' . $this->config->getGroqApiKey(),
                'Content-Type: application/json',
            ],
            $this->config->getGroqTimeout()
        );

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            throw new \RuntimeException('Groq: empty response content.');
        }
        return $content;
    }
}
