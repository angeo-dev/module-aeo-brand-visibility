<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\Analysis\PhrasePack;

/**
 * Analyses raw AI response text and extracts brand visibility signals.
 *
 * Signals:
 *   mentioned          — brand/keyword found as a whole word in the response
 *   url_cited          — brand domain found in response
 *   recommended        — recommendation phrase near a brand mention
 *   first_result       — brand appears in first 25% of text (position)
 *   positive_sentiment — positive phrase within the window of a brand mention
 *   no_mention         — brand completely absent (inverse, for reporting)
 *
 * v1.3.0 rewrites two core mechanics:
 *
 *  1. MULTILINGUAL DETECTION. Recommendation / sentiment phrases come from
 *     per-language packs (PhrasePack: en, nl, de, fr, uk) selected in admin.
 *     AI assistants answer in the shopper's language; an English-only list
 *     silently zeroed these signals for every non-English market.
 *
 *  2. WORD-BOUNDARY BRAND MATCHING. Brand terms are matched as whole words
 *     using Unicode-safe boundaries ((?<![\p{L}\p{N}]) … (?![\p{L}\p{N}]))
 *     instead of raw substrings, so the brand "Geo" no longer matches
 *     "geography" and a keyword "top" no longer fires on every list intro.
 *     Domain matching intentionally stays substring-based — domains are
 *     distinctive and often appear inside URLs.
 *
 * 2.0.0 adds the competitive dimension:
 *
 *  3. STRUCTURED CITATIONS. analyse() accepts the provider's citation URLs
 *     separately from prose. Being a cited source is the strongest
 *     visibility outcome — it now feeds url_cited directly instead of being
 *     smuggled into the text.
 *
 *  4. SHARE OF VOICE. Every answer to "what are the best stores for X?"
 *     names the competition — data that was previously discarded. The
 *     analyzer now reports which configured competitors were mentioned or
 *     cited, plus every domain observed in the answer, so the report can
 *     say not just "your score is 40" but "competitor X appears in 8/10
 *     answers where you appear in 2".
 *
 * Zero external NLP dependencies — pure string analysis.
 */
class ResponseAnalyzer
{
    public function __construct(
        private readonly Config $config,
        private readonly PhrasePack $phrasePack,
        // Optional (since 3.0.0). Only consulted when sentiment mode is 'llm';
        // null keeps the analyzer a pure, deterministic string function.
        private readonly ?SentimentJudge $sentimentJudge = null,
    ) {
    }

    /**
     * @param string[] $citations Structured source URLs from the provider
     * @return array{
     *     signals: array<string, bool>,
     *     score: int,
     *     competitor_mentions: array<string, bool>,
     *     cited_domains: string[]
     * }
     */
    public function analyse(string $rawResponse, array $citations = [], ?int $storeId = null): array
    {
        $text         = mb_strtolower($rawResponse);
        $citationText = mb_strtolower(implode("\n", $citations));
        $terms        = $this->buildSearchTerms($storeId);

        $signals = $this->extractSignals($text, $terms, $citationText, $storeId, $rawResponse);
        $score   = $this->calculateScore($signals);

        return [
            'signals'             => $signals,
            'score'               => $score,
            'competitor_mentions' => $this->detectCompetitors($text, $citationText, $storeId),
            'cited_domains'       => $this->extractDomains($text . "\n" . $citationText),
        ];
    }

    // ── Private ─────────────────────────────────────────────────────────

    /** @return string[] lowercase whole-word terms to search */
    private function buildSearchTerms(?int $storeId = null): array
    {
        return array_values(array_filter(array_unique(array_map(
            'mb_strtolower',
            array_merge(
                [$this->config->getBrandName($storeId)],
                $this->config->getBrandKeywords($storeId)
            )
        ))));
    }

    /** @return array<string, bool> */
    private function extractSignals(
        string $text,
        array $terms,
        string $citationText = '',
        ?int $storeId = null,
        string $rawResponse = ''
    ): array {
        $domain   = mb_strtolower($this->config->getBrandDomain($storeId));
        $domainNw = $domain !== '' ? (string) preg_replace('/^www\./', '', $domain) : '';

        $firstPos  = $this->firstWordPosition($text, $terms);
        $mentioned = $firstPos !== null;
        // A structured citation of the brand domain is the strongest form of
        // url_cited — the answer engine names the store as its source.
        $urlCited  = ($domain !== '' && str_contains($text, $domain))
                  || ($domainNw !== '' && str_contains($text, $domainNw))
                  || ($domainNw !== '' && $citationText !== '' && str_contains($citationText, $domainNw));

        $signals = [
            'mentioned'          => $mentioned || $urlCited,
            'url_cited'          => $urlCited,
            'recommended'        => false,
            'first_result'       => false,
            'positive_sentiment' => false,
            'no_mention'         => !($mentioned || $urlCited),
        ];

        if (!$mentioned && !$urlCited) {
            return $signals;
        }

        $languages = $this->config->getAnalysisLanguages($storeId);
        $recPhrases = $this->phrasePack->recommendationPhrases($languages);
        $posPhrases = $this->phrasePack->positivePhrases($languages);

        // Domain citation counts as a position anchor too (URL-only mentions).
        if ($firstPos === null && $urlCited) {
            $domPos = mb_strpos($text, $domainNw !== '' ? $domainNw : $domain);
            $firstPos = $domPos === false ? null : $domPos;
        }

        $len = mb_strlen($text);
        $signals['first_result'] = $firstPos !== null && $len > 0 && $firstPos < ($len * 0.25);

        $windows = $this->mentionWindows($text, $terms, $domainNw !== '' ? $domainNw : $domain);
        $signals['recommended']        = $this->windowsContainAny($windows, $recPhrases)
                                      || $this->appearsAsListItem($text, $terms);
        $signals['positive_sentiment'] = $this->detectSentiment(
            $windows,
            $posPhrases,
            $rawResponse,
            $this->config->getBrandName($storeId)
        );

        return $signals;
    }

    /**
     * First character position of any term matched as a whole word,
     * or null when no term matches.
     */
    private function firstWordPosition(string $text, array $terms): ?int
    {
        $earliest = null;
        foreach ($terms as $term) {
            $pos = $this->wordPosition($text, $term);
            if ($pos !== null && ($earliest === null || $pos < $earliest)) {
                $earliest = $pos;
            }
        }
        return $earliest;
    }

    /**
     * Unicode-safe whole-word search. Returns the CHARACTER offset of the
     * first match, or null. preg offsets are bytes, so the offset is
     * converted via substr + mb_strlen.
     */
    private function wordPosition(string $text, string $term): ?int
    {
        if ($term === '') {
            return null;
        }
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
        if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return mb_strlen(substr($text, 0, $m[0][1]));
    }

    /**
     * Collects ±150-char windows around every matched term (and the domain),
     * for phrase proximity checks.
     *
     * @param string[] $terms
     * @return string[]
     */
    private function mentionWindows(string $text, array $terms, string $domain): array
    {
        $windows = [];

        foreach ($terms as $term) {
            $pos = $this->wordPosition($text, $term);
            if ($pos === null) {
                continue;
            }
            $start     = max(0, $pos - 150);
            $windows[] = mb_substr($text, $start, mb_strlen($term) + 300);
        }

        if ($domain !== '') {
            $pos = mb_strpos($text, $domain);
            if ($pos !== false) {
                $start     = max(0, $pos - 150);
                $windows[] = mb_substr($text, $start, mb_strlen($domain) + 300);
            }
        }

        return $windows;
    }

    /**
     * @param string[] $windows
     * @param string[] $phrases
     */
    private function windowsContainAny(array $windows, array $phrases): bool
    {
        foreach ($windows as $window) {
            foreach ($phrases as $phrase) {
                if ($phrase !== '' && str_contains($window, $phrase)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Brand as a list item (*, -, •, "1." …) is an implicit recommendation
     * in any language — assistants enumerate the stores they endorse.
     *
     * @param string[] $terms
     */
    private function appearsAsListItem(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            $pattern = '/(^|\n)\s*(?:[\*\-\•]|\d+[\.\)])\s+[^\n]{0,80}'
                . '(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Which configured competitors appear in the answer (by whole-word name
     * or by domain in prose/citations)?
     *
     * @return array<string, bool> competitor display name => mentioned
     */
    private function detectCompetitors(string $text, string $citationText, ?int $storeId = null): array
    {
        $mentions = [];
        foreach ($this->config->getCompetitors($storeId) as $competitor) {
            $name   = mb_strtolower($competitor['name']);
            $domain = $competitor['domain'];
            $key    = $competitor['name'] !== '' ? $competitor['name'] : $competitor['domain'];

            $hit = false;
            if ($name !== '') {
                $hit = $this->wordPosition($text, $name) !== null;
            }
            if (!$hit && $domain !== '') {
                $hit = str_contains($text, $domain)
                    || ($citationText !== '' && str_contains($citationText, $domain));
            }

            $mentions[$key] = $hit;
        }
        return $mentions;
    }

    /**
     * Every registrable-looking domain observed in the answer and its
     * citations — the raw material for "who ELSE do AI engines send buyers
     * to". Capped to keep stored payloads bounded.
     *
     * @return string[]
     */
    private function extractDomains(string $haystack): array
    {
        if (!preg_match_all(
            '#(?:https?://)?(?:www\.)?([a-z0-9][a-z0-9\-]{0,62}(?:\.[a-z0-9][a-z0-9\-]{0,62})*\.[a-z]{2,12})(?=[/\s\)\],.!?"\'>]|$)#iu',
            $haystack,
            $m
        )) {
            return [];
        }

        $domains = array_values(array_unique(array_map('strtolower', $m[1])));

        return array_slice($domains, 0, 25);
    }

    /**
     * Positive-sentiment decision. In 'llm' mode the configured judge reads
     * the whole answer and returns a verdict; a null verdict (judge disabled,
     * unavailable, or unparseable) falls back to phrase-window detection so
     * behaviour degrades gracefully.
     *
     * @param string[] $windows
     * @param string[] $posPhrases
     */
    private function detectSentiment(array $windows, array $posPhrases, string $rawResponse, string $brand): bool
    {
        if ($this->sentimentJudge !== null
            && $this->config->getSentimentMode() === 'llm'
            && $rawResponse !== ''
        ) {
            $verdict = $this->sentimentJudge->isPositive($rawResponse, $brand);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        return $this->windowsContainAny($windows, $posPhrases);
    }

    /** @param array<string, bool> $signals */
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
