<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Analysis;

use Angeo\AeoBrandVisibility\Model\Config;

/**
 * Extracts brand visibility signals from one raw AI answer.
 *
 * Pure string analysis with no external NLP dependency. Version 4.0.0 adds
 * three-valued tone (positive, neutral, negative), mention-weighted share of
 * voice and a configurable accuracy check.
 */
class ResponseAnalyzer
{
    public const TONE_POSITIVE = 'positive';
    public const TONE_NEUTRAL = 'neutral';
    public const TONE_NEGATIVE = 'negative';

    public const SCORED_SIGNALS = [
        'mentioned',
        'recommended',
        'url_cited',
        'first_result',
        'positive_sentiment',
    ];

    private const FIRST_RESULT_QUANTILE = 0.25;
    private const RECOMMENDATION_WINDOW_BEFORE = 120;
    private const RECOMMENDATION_WINDOW_AFTER = 240;
    private const SENTIMENT_WINDOW_BEFORE = 150;
    private const SENTIMENT_WINDOW_AFTER = 300;

    /**
     * @param PhraseLibrary $phrases Locale-aware phrase sets.
     * @param ShareOfVoiceCalculator $shareOfVoice Mention-weighted share calculation.
     * @param AccuracyChecker $accuracyChecker Wrong-URL attribution detection.
     */
    public function __construct(
        private readonly PhraseLibrary $phrases,
        private readonly ShareOfVoiceCalculator $shareOfVoice,
        private readonly AccuracyChecker $accuracyChecker
    ) {
    }

    /**
     * Analyse one answer.
     *
     * @param string $rawResponse Answer text as returned by the provider.
     * @param Config $config Store-scoped configuration.
     * @return array{signals: array<string, bool>, score: int, meta: array<string, mixed>}
     */
    public function analyse(string $rawResponse, Config $config): array
    {
        $text = mb_strtolower($rawResponse);
        $language = $config->getAnalysisLanguage();
        $brandTerms = $this->buildBrandTerms($config);

        $signals = $this->extractSignals($text, $brandTerms, $language, $config);
        $meta = $this->extractMeta($text, $brandTerms, $signals, $config);
        $score = $this->calculateScore($signals, $meta, $config);

        return ['signals' => $signals, 'score' => $score, 'meta' => $meta];
    }

    /**
     * Brand name, aliases and domain variants, lower-cased and de-duplicated.
     *
     * @param Config $config Store-scoped configuration.
     * @return string[]
     */
    private function buildBrandTerms(Config $config): array
    {
        $terms = array_map('mb_strtolower', array_merge(
            [$config->getBrandName()],
            $config->getBrandKeywords()
        ));

        $domain = $config->getBrandDomain();
        if ($domain !== '') {
            $terms[] = $domain;
            $terms[] = (string) preg_replace('/^www\./', '', $domain);
        }

        return array_values(array_filter(array_unique($terms), static fn($t) => $t !== ''));
    }

    /**
     * Detect the boolean visibility signals.
     *
     * @param string $text Lower-cased answer text.
     * @param string[] $brandTerms Brand terms to look for.
     * @param string $language Two-letter analysis language.
     * @param Config $config Store-scoped configuration.
     * @return array<string, bool>
     */
    private function extractSignals(string $text, array $brandTerms, string $language, Config $config): array
    {
        $domain = $config->getBrandDomain();
        $bareDomain = $domain !== '' ? (string) preg_replace('/^www\./', '', $domain) : '';

        $mentioned = $this->containsAny($text, $brandTerms);
        $urlCited = ($domain !== '' && str_contains($text, $domain))
            || ($bareDomain !== '' && str_contains($text, $bareDomain));

        $signals = [
            'mentioned' => $mentioned,
            'url_cited' => $urlCited,
            'recommended' => false,
            'first_result' => false,
            'positive_sentiment' => false,
            'negative_sentiment' => false,
            'no_mention' => !$mentioned,
        ];

        if (!$mentioned) {
            return $signals;
        }

        $length = mb_strlen($text);
        $firstPosition = $this->firstPosition($text, $brandTerms);

        $signals['first_result'] = $firstPosition !== null
            && $firstPosition < (int) ($length * self::FIRST_RESULT_QUANTILE);

        $signals['recommended'] = $this->hasPhraseNearby(
            $text,
            $brandTerms,
            $this->phrases->recommendation($language),
            self::RECOMMENDATION_WINDOW_BEFORE,
            self::RECOMMENDATION_WINDOW_AFTER
        ) || $this->isListItem($text, $brandTerms);

        $signals['positive_sentiment'] = $this->hasPhraseNearby(
            $text,
            $brandTerms,
            $this->phrases->positive($language),
            self::SENTIMENT_WINDOW_BEFORE,
            self::SENTIMENT_WINDOW_AFTER
        );

        $signals['negative_sentiment'] = $this->hasPhraseNearby(
            $text,
            $brandTerms,
            $this->phrases->negative($language),
            self::SENTIMENT_WINDOW_BEFORE,
            self::SENTIMENT_WINDOW_AFTER
        );

        return $signals;
    }

    /**
     * Competitors, share of voice, ranking winner, tone and accuracy.
     *
     * @param string $text Lower-cased answer text.
     * @param string[] $brandTerms Brand terms to look for.
     * @param array<string, bool> $signals Detected signals.
     * @param Config $config Store-scoped configuration.
     * @return array<string, mixed>
     */
    private function extractMeta(string $text, array $brandTerms, array $signals, Config $config): array
    {
        $length = mb_strlen($text);
        $brandName = $config->getBrandName();

        $entities = [];
        $brandPosition = $this->firstPosition($text, $brandTerms);
        if ($brandPosition !== null) {
            $entities[] = [
                'name' => $brandName,
                'mentions' => $this->countMentions($text, $brandTerms),
                'first_position' => $brandPosition,
            ];
        }

        $competitorsFound = [];
        foreach ($config->getCompetitors() as $competitor) {
            $terms = array_values(array_filter([
                mb_strtolower($competitor['name']),
                $competitor['domain'] !== '' ? mb_strtolower($competitor['domain']) : '',
            ], static fn($t) => $t !== ''));

            $position = $this->firstPosition($text, $terms);
            if ($position === null) {
                continue;
            }

            $entities[] = [
                'name' => $competitor['name'],
                'mentions' => $this->countMentions($text, $terms),
                'first_position' => $position,
            ];
            $competitorsFound[] = $competitor['name'];
        }

        $shares = $this->shareOfVoice->calculate($entities, $length);

        $winner = null;
        $winnerPosition = PHP_INT_MAX;
        foreach ($entities as $entity) {
            if ($entity['first_position'] < $winnerPosition) {
                $winnerPosition = $entity['first_position'];
                $winner = $entity['name'];
            }
        }

        return [
            'competitors_found' => $competitorsFound,
            'competitor_count' => count($competitorsFound),
            'share_of_voice' => $shares[$brandName] ?? 0.0,
            'share_of_voice_all' => $shares,
            'winner' => $winner,
            'brand_is_winner' => $brandPosition !== null && $winner === $brandName,
            'tone' => $this->resolveTone($signals),
            'accuracy' => $this->accuracyChecker->check($text, $brandTerms, $config),
        ];
    }

    /**
     * Collapse the two sentiment flags into a single tone value.
     *
     * @param array<string, bool> $signals Detected signals.
     * @return string
     */
    private function resolveTone(array $signals): string
    {
        if (!empty($signals['negative_sentiment'])) {
            return self::TONE_NEGATIVE;
        }
        if (!empty($signals['positive_sentiment'])) {
            return self::TONE_POSITIVE;
        }

        return self::TONE_NEUTRAL;
    }

    /**
     * Weighted score for one answer, 0-100, reduced when the tone is negative.
     *
     * @param array<string, bool> $signals Detected signals.
     * @param array<string, mixed> $meta Analyser metadata.
     * @param Config $config Store-scoped configuration.
     * @return int
     */
    private function calculateScore(array $signals, array $meta, Config $config): int
    {
        $achieved = 0.0;
        $maximum = 0.0;

        foreach (self::SCORED_SIGNALS as $signal) {
            $weight = $config->getScoringWeight($signal);
            $maximum += $weight;
            if (!empty($signals[$signal])) {
                $achieved += $weight;
            }
        }

        if ($maximum <= 0.0) {
            return 0;
        }

        if (($meta['tone'] ?? self::TONE_NEUTRAL) === self::TONE_NEGATIVE) {
            $achieved *= (1.0 - $config->getNegativePenalty());
        }

        return (int) round($achieved / $maximum * 100);
    }

    /**
     * Whether any term appears in the text.
     *
     * @param string $text Lower-cased answer text.
     * @param string[] $terms Terms to look for.
     * @return bool
     */
    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Character offset of the earliest matching term.
     *
     * @param string $text Lower-cased answer text.
     * @param string[] $terms Terms to look for.
     * @return int|null
     */
    private function firstPosition(string $text, array $terms): ?int
    {
        $earliest = null;
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            $position = mb_strpos($text, $term);
            if ($position !== false && ($earliest === null || $position < $earliest)) {
                $earliest = $position;
            }
        }

        return $earliest;
    }

    /**
     * Total occurrences of the longest matching term, used as the mention count.
     *
     * @param string $text Lower-cased answer text.
     * @param string[] $terms Terms to look for.
     * @return int
     */
    private function countMentions(string $text, array $terms): int
    {
        $best = 0;
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            $best = max($best, mb_substr_count($text, $term));
        }

        return $best;
    }

    /**
     * Whether a phrase appears within a window around any brand term.
     *
     * @param string $text Lower-cased answer text.
     * @param string[] $terms Brand terms.
     * @param string[] $phrases Phrases to look for.
     * @param int $before Characters of context before the term.
     * @param int $after Characters of context after the term.
     * @return bool
     */
    private function hasPhraseNearby(string $text, array $terms, array $phrases, int $before, int $after): bool
    {
        foreach ($terms as $term) {
            $position = $term !== '' ? mb_strpos($text, $term) : false;
            if ($position === false) {
                continue;
            }

            $start = max(0, $position - $before);
            $window = mb_substr($text, $start, mb_strlen($term) + $before + $after);

            foreach ($phrases as $phrase) {
                if ($phrase !== '' && str_contains($window, $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a brand term appears as a bullet or numbered list entry.
     *
     * @param string $text Lower-cased answer text.
     * @param string[] $terms Brand terms.
     * @return bool
     */
    private function isListItem(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            $pattern = '/(?:^|\n)\s*(?:[-*\x{2022}]|\d+[.)])\s*\**\s*' . preg_quote($term, '/') . '/u';
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
