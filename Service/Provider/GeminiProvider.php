<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Provider;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\ProviderResponse;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Google Gemini provider via the Google AI Studio REST API.
 *
 * Two modes since 2.0.0: plain training recall (default) and Grounding with
 * Google Search — the same retrieval Gemini consumer surfaces use. Grounded
 * answers return groundingMetadata with the exact web sources; collected
 * here as structured citations.
 *
 * Free tier (Google AI Studio):
 *   - gemini-2.0-flash: 15 req/min, 1500 req/day
 * Get a free API key (no card required): aistudio.google.com
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

    public function supportsGrounding(): bool { return true; }
    public function isGrounded(): bool        { return $this->config->isGeminiGrounded(); }

    public function query(string $systemPrompt, string $userPrompt): ProviderResponse
    {
        $grounded = $this->isGrounded();
        $url      = sprintf(self::BASE_URL, $this->config->getGeminiModel());

        $payload = [
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
                'temperature'     => 0.2,
            ],
        ];

        if ($grounded) {
            // Empty object required by the API: {"google_search": {}}
            $payload['tools'] = [['google_search' => (object) []]];
        }

        $data = $this->post(
            $url,
            $payload,
            [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->config->getGeminiApiKey(),
            ],
            $grounded
                ? max($this->config->getGeminiTimeout(), 60)
                : $this->config->getGeminiTimeout()
        );

        $candidate = $data['candidates'][0] ?? [];
        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= ($text !== '' ? "\n" : '') . $part['text'];
            }
        }

        if ($text === '') {
            // Surface finish reason if available (e.g. SAFETY, RECITATION)
            $reason = $candidate['finishReason'] ?? 'unknown';
            throw new \RuntimeException(
                sprintf('Gemini: empty response. Finish reason: %s', $reason)
            );
        }

        $citations = [];
        foreach ($candidate['groundingMetadata']['groundingChunks'] ?? [] as $chunk) {
            if (!empty($chunk['web']['uri'])) {
                $citations[] = (string) $chunk['web']['uri'];
            }
        }

        return new ProviderResponse($text, array_values(array_unique($citations)), $grounded);
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
