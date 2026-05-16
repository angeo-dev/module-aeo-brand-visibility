<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;

/**
 * Analyses raw AI response text and extracts brand visibility signals.
 *
 * Signals:
 *   mentioned          — brand/keyword found anywhere in response
 *   url_cited          — brand domain found in response
 *   recommended        — explicit recommendation verb near brand mention
 *   first_result       — brand appears in first 25% of text (position)
 *   positive_sentiment — positive adjective within 150 chars of brand mention
 *   no_mention         — brand completely absent (inverse, for reporting)
 *
 * Zero external NLP dependencies — pure string analysis.
 */
class ResponseAnalyzer
{
    private const RECOMMENDATION_PHRASES = [
        'recommend', 'suggest', 'check out', 'visit', 'try', 'great choice',
        'good option', 'top pick', 'worth visiting', 'you should', 'consider',
        'look at', 'head to', 'go to', 'perfect for', 'ideal for',
    ];

    private const POSITIVE_PHRASES = [
        'excellent', 'great', 'best', 'top', 'leading', 'popular',
        'trusted', 'reliable', 'reputable', 'well-known', 'well known',
        'high quality', 'highly rated', 'highly recommended', 'favourite',
        'favorite', 'preferred', 'outstanding', 'impressive', 'amazing',
        'fantastic', 'superb', 'exceptional', 'renowned', 'established',
    ];

    public function __construct(private readonly Config $config) {}

    /**
     * @return array{signals: array<string, bool>, score: int}
     */
    public function analyse(string $rawResponse): array
    {
        $text     = mb_strtolower($rawResponse);
        $terms    = $this->buildSearchTerms();
        $signals  = $this->extractSignals($text, $terms);
        $score    = $this->calculateScore($signals);

        return ['signals' => $signals, 'score' => $score];
    }

    // ── Private ──────────────────────────────────────────────────────────────

    /** @return string[] lowercase terms to search */
    private function buildSearchTerms(): array
    {
        $terms = array_filter(array_unique(array_map(
            'mb_strtolower',
            array_merge(
                [$this->config->getBrandName()],
                $this->config->getBrandKeywords()
            )
        )));

        $domain = mb_strtolower($this->config->getBrandDomain());
        if ($domain !== '') {
            $terms[] = $domain;
            // Also add without www
            $terms[] = preg_replace('/^www\./', '', $domain);
        }

        return array_values(array_filter(array_unique($terms)));
    }

    /** @return array<string, bool> */
    private function extractSignals(string $text, array $terms): array
    {
        $domain   = mb_strtolower($this->config->getBrandDomain());
        $domainNw = $domain !== '' ? preg_replace('/^www\./', '', $domain) : '';

        $mentioned  = $this->containsAny($text, $terms);
        $urlCited   = ($domain !== '' && str_contains($text, $domain))
                   || ($domainNw !== '' && str_contains($text, $domainNw));

        $signals = [
            'mentioned'          => $mentioned,
            'url_cited'          => $urlCited,
            'recommended'        => false,
            'first_result'       => false,
            'positive_sentiment' => false,
            'no_mention'         => !$mentioned,
        ];

        if (!$mentioned) {
            return $signals;
        }

        $len = mb_strlen($text);
        $firstPos = $this->firstPosition($text, $terms);

        $signals['first_result']       = $firstPos !== false && $firstPos < ($len * 0.25);
        $signals['recommended']        = $this->detectRecommendation($text, $terms);
        $signals['positive_sentiment'] = $this->detectPositiveSentiment($text, $terms);

        return $signals;
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($text, $term)) {
                return true;
            }
        }
        return false;
    }

    private function firstPosition(string $text, array $terms): int|false
    {
        $earliest = false;
        foreach ($terms as $term) {
            if ($term === '') continue;
            $pos = mb_strpos($text, $term);
            if ($pos !== false && ($earliest === false || $pos < $earliest)) {
                $earliest = $pos;
            }
        }
        return $earliest;
    }

    private function detectRecommendation(string $text, array $terms): bool
    {
        // Window-based: recommendation phrase within 200 chars of brand mention
        foreach ($terms as $term) {
            $pos = mb_strpos($text, $term);
            if ($pos === false) continue;
            $start  = max(0, $pos - 120);
            $window = mb_substr($text, $start, mb_strlen($term) + 240);
            foreach (self::RECOMMENDATION_PHRASES as $phrase) {
                if (str_contains($window, $phrase)) return true;
            }
        }
        // Also: brand appears in a list item (implicit recommendation)
        foreach ($terms as $term) {
            if (preg_match('/[\*\-\•\d\.]\s+' . preg_quote($term, '/') . '/u', $text)) {
                return true;
            }
        }
        return false;
    }

    private function detectPositiveSentiment(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $pos = mb_strpos($text, $term);
            if ($pos === false) continue;
            $start  = max(0, $pos - 150);
            $window = mb_substr($text, $start, mb_strlen($term) + 300);
            foreach (self::POSITIVE_PHRASES as $phrase) {
                if (str_contains($window, $phrase)) return true;
            }
        }
        return false;
    }

    private function calculateScore(array $signals): int
    {
        $scoreable = ['mentioned', 'recommended', 'url_cited', 'positive_sentiment', 'first_result'];
        $achieved  = 0.0;
        $maxPoss   = 0.0;

        foreach ($scoreable as $s) {
            $w        = $this->config->getScoringWeight($s);
            $maxPoss += $w;
            if ($signals[$s] ?? false) {
                $achieved += $w;
            }
        }

        return $maxPoss > 0 ? (int) round(($achieved / $maxPoss) * 100) : 0;
    }
}
