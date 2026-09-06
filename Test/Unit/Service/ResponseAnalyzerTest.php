<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\Analysis\PhrasePack;
use Angeo\AeoBrandVisibility\Service\ResponseAnalyzer;
use Angeo\AeoBrandVisibility\Service\SentimentJudge;
use PHPUnit\Framework\TestCase;

class ResponseAnalyzerTest extends TestCase
{
    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private Config $config;

    private ResponseAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getBrandName')->willReturn('Angeo Ceramics');
        $this->config->method('getBrandDomain')->willReturn('angeo-ceramics.nl');
        $this->config->method('getBrandKeywords')->willReturn(['angeo']);
        $this->config->method('getAnalysisLanguages')->willReturn([]); // all packs
        $this->config->method('getScoringWeight')->willReturnCallback(
            static fn(string $s) => ['mentioned' => 1.0, 'recommended' => 1.5,
                'url_cited' => 1.5, 'first_result' => 2.0, 'positive_sentiment' => 0.5][$s] ?? 1.0
        );

        $this->analyzer = new ResponseAnalyzer($this->config, new PhrasePack());
    }

    // ── Multilingual detection (the 1.3.0 headline) ─────────────────────

    public function testDetectsDutchRecommendationAndSentiment(): void
    {
        $response = 'Voor handgemaakt keramiek is Angeo Ceramics zeker aan te raden — '
            . 'een betrouwbaar bedrijf met uitstekende recensies.';

        ['signals' => $signals] = $this->analyzer->analyse($response);

        $this->assertTrue($signals['mentioned']);
        $this->assertTrue($signals['recommended'], 'Dutch "aan te raden" must register');
        $this->assertTrue($signals['positive_sentiment'], 'Dutch "uitstekende"/"betrouwbaar" must register');
    }

    public function testDetectsGermanRecommendation(): void
    {
        $response = 'Für Keramik empfehle ich Angeo Ceramics — ein renommierter Shop.';

        ['signals' => $signals] = $this->analyzer->analyse($response);

        $this->assertTrue($signals['recommended'], 'German "empfehle" (stem "empfehl") must register');
        $this->assertTrue($signals['positive_sentiment'], 'German "renommierter" must register');
    }

    public function testDetectsUkrainianRecommendation(): void
    {
        $response = 'Для кераміки ручної роботи рекомендую Angeo Ceramics — надійний магазин з чудовим вибором.';

        ['signals' => $signals] = $this->analyzer->analyse($response);

        $this->assertTrue($signals['recommended'], 'Ukrainian "рекомендую" must register');
        $this->assertTrue($signals['positive_sentiment'], 'Ukrainian "надійний" must register');
    }

    public function testDetectsFrenchRecommendation(): void
    {
        $response = 'Je recommande Angeo Ceramics, une boutique fiable pour la céramique.';

        ['signals' => $signals] = $this->analyzer->analyse($response);

        $this->assertTrue($signals['recommended']);
        $this->assertTrue($signals['positive_sentiment']);
    }

    public function testLanguageSelectionRestrictsPacks(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getBrandName')->willReturn('Angeo Ceramics');
        $config->method('getBrandDomain')->willReturn('');
        $config->method('getBrandKeywords')->willReturn([]);
        $config->method('getAnalysisLanguages')->willReturn([PhrasePack::LANG_EN]);
        $config->method('getScoringWeight')->willReturn(1.0);

        $analyzer = new ResponseAnalyzer($config, new PhrasePack());

        // Dutch phrase, but only EN pack enabled → recommended must stay false.
        ['signals' => $signals] = $analyzer->analyse(
            'Angeo Ceramics is zeker aan te raden voor keramiek.'
        );

        $this->assertTrue($signals['mentioned']);
        $this->assertFalse($signals['recommended']);
    }

    // ── Word-boundary matching ───────────────────────────────────────────

    public function testBrandTermDoesNotMatchInsideLongerWord(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getBrandName')->willReturn('Geo');
        $config->method('getBrandDomain')->willReturn('');
        $config->method('getBrandKeywords')->willReturn([]);
        $config->method('getAnalysisLanguages')->willReturn([]);
        $config->method('getScoringWeight')->willReturn(1.0);

        $analyzer = new ResponseAnalyzer($config, new PhrasePack());

        ['signals' => $signals] = $analyzer->analyse(
            'Geography and geometry stores are plentiful, but none stand out.'
        );

        $this->assertFalse($signals['mentioned'], '"Geo" must not match inside "Geography"');
        $this->assertTrue($signals['no_mention']);
    }

    public function testWholeWordBrandStillMatches(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getBrandName')->willReturn('Geo');
        $config->method('getBrandDomain')->willReturn('');
        $config->method('getBrandKeywords')->willReturn([]);
        $config->method('getAnalysisLanguages')->willReturn([]);
        $config->method('getScoringWeight')->willReturn(1.0);

        $analyzer = new ResponseAnalyzer($config, new PhrasePack());

        ['signals' => $signals] = $analyzer->analyse('You could try Geo, a well-known shop.');

        $this->assertTrue($signals['mentioned']);
    }

    // ── Core signals regression ──────────────────────────────────────────

    public function testDomainCitationDetected(): void
    {
        ['signals' => $signals] = $this->analyzer->analyse(
            'A good place to look is https://www.angeo-ceramics.nl/ for handmade pottery.'
        );

        $this->assertTrue($signals['url_cited']);
        $this->assertTrue($signals['mentioned'], 'URL-only citation still counts as mentioned');
    }

    public function testListItemCountsAsRecommendation(): void
    {
        $response = "Here are some options:\n1. Angeo Ceramics\n2. Some Other Store\n";

        ['signals' => $signals] = $this->analyzer->analyse($response);

        $this->assertTrue($signals['recommended'], 'Numbered-list membership is an implicit recommendation');
    }

    public function testFirstResultPositionSignal(): void
    {
        $early = 'Angeo Ceramics leads the list. ' . str_repeat('Other stores exist too. ', 30);
        $late  = str_repeat('Many stores sell ceramics online today. ', 30) . 'Finally there is Angeo Ceramics.';

        ['signals' => $earlySignals] = $this->analyzer->analyse($early);
        ['signals' => $lateSignals]  = $this->analyzer->analyse($late);

        $this->assertTrue($earlySignals['first_result']);
        $this->assertFalse($lateSignals['first_result']);
    }

    public function testNoMentionYieldsZeroScore(): void
    {
        ['signals' => $signals, 'score' => $score] = $this->analyzer->analyse(
            'There are many pottery shops online, for example ClayWorld and PotteryHub.'
        );

        $this->assertTrue($signals['no_mention']);
        $this->assertSame(0, $score);
    }

    public function testScoreReflectsWeightedSignals(): void
    {
        ['score' => $score] = $this->analyzer->analyse(
            'I recommend Angeo Ceramics (angeo-ceramics.nl) — an excellent, trusted shop.'
        );

        // mentioned + recommended + url_cited + positive (+ first_result) —
        // with the default weights this must land in the upper half at minimum.
        $this->assertGreaterThanOrEqual(70, $score);
    }

    // ── LLM sentiment routing (3.0.0) ────────────────────────────────────

    public function testLlmJudgeOverridesPhraseSentimentWhenModeIsLlm(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getBrandName')->willReturn('Angeo');
        $config->method('getBrandDomain')->willReturn('');
        $config->method('getBrandKeywords')->willReturn([]);
        $config->method('getAnalysisLanguages')->willReturn([]);
        $config->method('getCompetitors')->willReturn([]);
        $config->method('getScoringWeight')->willReturn(1.0);
        $config->method('getSentimentMode')->willReturn('llm');

        // Text has NO positive phrase, so phrase packs would score false —
        // the judge flips it to true, proving the judge is consulted.
        $judge = $this->createMock(SentimentJudge::class);
        $judge->method('isPositive')->willReturn(true);

        $analyzer = new ResponseAnalyzer($config, new PhrasePack(), $judge);

        ['signals' => $signals] = $analyzer->analyse('Angeo sells ceramics online.');

        $this->assertTrue($signals['positive_sentiment']);
    }

    public function testLlmJudgeNullVerdictFallsBackToPhrases(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getBrandName')->willReturn('Angeo');
        $config->method('getBrandDomain')->willReturn('');
        $config->method('getBrandKeywords')->willReturn([]);
        $config->method('getAnalysisLanguages')->willReturn([]);
        $config->method('getCompetitors')->willReturn([]);
        $config->method('getScoringWeight')->willReturn(1.0);
        $config->method('getSentimentMode')->willReturn('llm');

        $judge = $this->createMock(SentimentJudge::class);
        $judge->method('isPositive')->willReturn(null); // undetermined

        $analyzer = new ResponseAnalyzer($config, new PhrasePack(), $judge);

        // Phrase "excellent" present → fallback detection must catch it.
        ['signals' => $signals] = $analyzer->analyse('Angeo is an excellent ceramics store.');

        $this->assertTrue($signals['positive_sentiment']);
    }
}
