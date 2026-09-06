<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Api\AiProviderInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Optional LLM-based sentiment classifier (since 3.0.0).
 *
 * Phrase packs are fast and deterministic but shallow — they cannot tell
 * "not the best choice" from "the best choice", and they miss paraphrase.
 * When *Sentiment Analysis* is set to "LLM judge", the analyzer delegates the
 * positive/negative decision to the cheapest configured provider, which reads
 * the whole answer in context and returns a strict JSON verdict.
 *
 * Design guarantees:
 *  - Never throws to the caller: any API/parse failure returns null, and the
 *    analyzer falls back to phrase-pack detection. A judge outage degrades
 *    accuracy, never availability.
 *  - Cheapest-first provider order (Groq → Gemini → the rest) keeps the extra
 *    call inexpensive; a free Groq key makes LLM judging effectively free.
 */
class SentimentJudge
{
    private const SYSTEM = 'You are a strict sentiment classifier. You judge how an AI shopping '
        . 'assistant portrays ONE specific brand in a passage. Reply with JSON only.';

    /**
     * @param AiProviderInterface[] $providers Injected via di.xml (same registry as the service)
     */
    public function __construct(
        private readonly Config              $config,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface     $logger,
        private readonly array               $providers = [],
    ) {
    }

    /**
     * @return bool|null true = positive, false = neutral/negative, null = undetermined
     */
    public function isPositive(string $answer, string $brand): ?bool
    {
        $provider = $this->cheapestProvider();
        if ($provider === null || $brand === '') {
            return null;
        }

        $user = sprintf(
            "Brand: \"%s\"\n\nPassage:\n%s\n\n"
            . 'Does the passage portray this brand positively (praise, recommendation, '
            . 'trust)? Respond with exactly: {"positive": true} or {"positive": false}.',
            $brand,
            mb_substr($answer, 0, 4000)
        );

        try {
            $response = $provider->query(self::SYSTEM, $user);
            $verdict  = $this->parseVerdict($response->text);
            if ($verdict === null) {
                $this->logger->debug('[BrandVis] Sentiment judge returned unparseable verdict', [
                    'provider' => $provider->getProviderId(),
                ]);
            }
            return $verdict;
        } catch (\Throwable $e) {
            $this->logger->warning('[BrandVis] Sentiment judge call failed; falling back to phrases', [
                'provider' => $provider->getProviderId(),
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extracts {"positive": bool} from a model reply that may wrap the JSON in
     * prose or code fences.
     */
    private function parseVerdict(string $text): ?bool
    {
        if (preg_match('/\{[^{}]*"positive"[^{}]*\}/s', $text, $m)) {
            try {
                $decoded = $this->serializer->unserialize($m[0]);
                if (is_array($decoded) && array_key_exists('positive', $decoded)) {
                    return (bool) $decoded['positive'];
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // Last-resort literal scan.
        if (preg_match('/"positive"\s*:\s*(true|false)/i', $text, $m)) {
            return strtolower($m[1]) === 'true';
        }

        return null;
    }

    /**
     * Cheapest configured provider by a fixed cost preference. Honours an
     * explicit "sentiment provider" order implicitly via this list.
     */
    private function cheapestProvider(): ?AiProviderInterface
    {
        $order = ['groq', 'gemini', 'chatgpt', 'claude', 'perplexity'];

        $byId = [];
        foreach ($this->providers as $provider) {
            if ($provider instanceof AiProviderInterface && $provider->isConfigured()) {
                $byId[$provider->getProviderId()] = $provider;
            }
        }

        foreach ($order as $id) {
            if (isset($byId[$id])) {
                return $byId[$id];
            }
        }

        return $byId === [] ? null : array_values($byId)[0];
    }
}
