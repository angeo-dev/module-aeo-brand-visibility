<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Google Gemini provider via Google AI Studio REST API.
 *
 * Uses the generateContent endpoint — request/response format differs
 * from the OpenAI-compatible format used by ChatGPT, Claude and Perplexity.
 *
 * Free tier (Google AI Studio):
 *   - gemini-2.0-flash: 15 req/min, 1500 req/day — sufficient for brand visibility testing
 *   - gemini-1.5-pro:   2 req/min,  50 req/day
 *
 * Get a free API key (no card required):
 *   aistudio.google.com → Get API key → Create API key
 *
 * API reference: https://ai.google.dev/api/generate-content
 */
class GeminiProvider extends AbstractHttpProvider implements AiProviderInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly Config $config,
        SerializerInterface $serializer
    ) {
        parent::__construct($serializer);
    }

    public function getProviderId(): string    { return 'gemini'; }
    public function getProviderLabel(): string { return 'Gemini (' . $this->config->getGeminiModel() . ')'; }

    public function isConfigured(): bool
    {
        return $this->config->isGeminiEnabled() && $this->config->getGeminiApiKey() !== '';
    }

    public function query(string $systemPrompt, string $userPrompt): string
    {
        $model = $this->config->getGeminiModel();
        $url   = sprintf(self::BASE_URL, $model);

        $data = $this->post(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [['text' => $userPrompt]],
                    ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $this->config->getGeminiMaxTokens(),
                    'temperature'     => 0.3,
                ],
            ],
            [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->config->getGeminiApiKey(),
            ],
            $this->config->getGeminiTimeout()
        );

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if ($text === '') {
            // Surface finish reason if available (e.g. SAFETY, RECITATION)
            $reason = $data['candidates'][0]['finishReason'] ?? 'unknown';
            throw new \RuntimeException(
                sprintf('Gemini: empty response. Finish reason: %s', $reason)
            );
        }

        return $text;
    }

    /**
     * Gemini error format: {"error": {"code": 400, "message": "...", "status": "..."}}
     */
    protected function extractErrorMessage(array $decoded, string $rawBody): string
    {
        return $decoded['error']['message']
            ?? mb_substr($rawBody, 0, 200);
    }
}
