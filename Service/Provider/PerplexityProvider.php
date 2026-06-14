<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Perplexity Sonar provider — uses live web search for real-world brand recall.
 * Most realistic signal for "what does the internet actually say about my store right now".
 *
 * API reference: https://docs.perplexity.ai/api-reference/chat-completions
 */
class PerplexityProvider extends AbstractHttpProvider implements AiProviderInterface
{
    private const URL = 'https://api.perplexity.ai/chat/completions';

    public function __construct(
        private readonly Config $config,
        SerializerInterface $serializer
    ) {
        parent::__construct($serializer);
    }

    public function getProviderId(): string    { return 'perplexity'; }
    public function getProviderLabel(): string { return 'Perplexity (' . $this->config->getPerplexityModel() . ')'; }

    public function isConfigured(): bool
    {
        return $this->config->isPerplexityEnabled() && $this->config->getPerplexityApiKey() !== '';
    }

    public function query(string $systemPrompt, string $userPrompt): string
    {
        $data = $this->post(
            self::URL,
            [
                'model'            => $this->config->getPerplexityModel(),
                'max_tokens'       => $this->config->getPerplexityMaxTokens(),
                'temperature'      => 0.2, // Low temperature for factual recall
                'messages'         => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                'return_citations' => true,
            ],
            [
                'Authorization: Bearer ' . $this->config->getPerplexityApiKey(),
                'Content-Type: application/json',
            ],
            $this->config->getPerplexityTimeout()
        );

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            throw new \RuntimeException('Perplexity: empty response content.');
        }

        // Append cited URLs to the text for better domain detection in ResponseAnalyzer
        $citations = $data['citations'] ?? [];
        if (!empty($citations)) {
            $content .= "\n\nSources: " . implode(', ', array_slice($citations, 0, 10));
        }

        return $content;
    }
}
