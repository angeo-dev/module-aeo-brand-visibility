<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Analysis;

/**
 * Per-language phrase packs for brand-signal detection.
 *
 * AI assistants answer in the user's language, so an English-only phrase
 * list silently zeroes the `recommended` and `positive_sentiment` signals
 * for every non-English market — a Dutch "zeker aan te raden" or a German
 * "sehr empfehlenswert" scored nothing before 1.3.0.
 *
 * Design notes:
 *  - Phrases are matched as lowercase substrings inside a ±150-char window
 *    around a brand mention, so inflected forms are covered by stems where
 *    the language allows it (e.g. "empfehl" covers empfehle / empfohlen /
 *    empfehlenswert).
 *  - Packs are additive: the analyzer matches against the UNION of enabled
 *    packs. Phrases are language-specific enough that cross-language false
 *    positives are not a practical concern.
 *  - Adding a language = adding one array here + one option in the
 *    AnalysisLanguage source model. No analyzer changes needed.
 *
 * @api
 * @since 1.3.0
 */
class PhrasePack
{
    public const LANG_EN = 'en';
    public const LANG_NL = 'nl';
    public const LANG_DE = 'de';
    public const LANG_FR = 'fr';
    public const LANG_UK = 'uk';

    /** @var array<string, string> code => label (for admin source model) */
    public const LANGUAGES = [
        self::LANG_EN => 'English',
        self::LANG_NL => 'Nederlands (Dutch)',
        self::LANG_DE => 'Deutsch (German)',
        self::LANG_FR => 'Français (French)',
        self::LANG_UK => 'Українська (Ukrainian)',
    ];

    /** @var array<string, string[]> */
    private const RECOMMENDATION = [
        self::LANG_EN => [
            'recommend', 'suggest', 'check out', 'visit', 'try', 'great choice',
            'good option', 'top pick', 'worth visiting', 'you should', 'consider',
            'look at', 'head to', 'go to', 'perfect for', 'ideal for', 'worth a look',
        ],
        self::LANG_NL => [
            'aanraden', 'aan te raden', 'aanbevolen', 'raad ik', 'raden wij',
            'goede keuze', 'goede optie', 'de moeite waard', 'zeker bekijken',
            'probeer', 'bekijk', 'kijk eens bij', 'een aanrader', 'ideaal voor',
            'perfect voor', 'overwegen',
        ],
        self::LANG_DE => [
            'empfehl', // empfehle / empfohlen / empfehlenswert / Empfehlung
            'gute wahl', 'gute option', 'lohnt sich', 'einen blick wert',
            'schauen sie', 'probieren sie', 'besuchen sie', 'ideal für',
            'perfekt für', 'in betracht ziehen', 'top-tipp',
        ],
        self::LANG_FR => [
            'recommand', // recommande / recommandé / recommandation
            'conseill',  // conseille / conseillé
            'bon choix', 'bonne option', 'vaut le détour', 'vaut la peine',
            'essayez', 'jetez un œil', 'visitez', 'idéal pour', 'parfait pour',
            'à considérer', 'à découvrir',
        ],
        self::LANG_UK => [
            'рекоменд',  // рекомендую / рекомендований / рекомендація
            'раджу', 'радимо', 'варто спробувати', 'варто відвідати',
            'гарний вибір', 'хороший вибір', 'чудовий вибір', 'гарний варіант',
            'зверніть увагу', 'зазирніть', 'спробуйте', 'ідеально підходить',
            'підійде для',
        ],
    ];

    /** @var array<string, string[]> */
    private const POSITIVE = [
        self::LANG_EN => [
            'excellent', 'great', 'best', 'top', 'leading', 'popular',
            'trusted', 'reliable', 'reputable', 'well-known', 'well known',
            'high quality', 'highly rated', 'highly recommended', 'favourite',
            'favorite', 'preferred', 'outstanding', 'impressive', 'amazing',
            'fantastic', 'superb', 'exceptional', 'renowned', 'established',
        ],
        self::LANG_NL => [
            'uitstekend', 'geweldig', 'beste', 'toonaangevend', 'populair',
            'betrouwbaar', 'gerenommeerd', 'bekend', 'hoogwaardig',
            'hoog gewaardeerd', 'favoriet', 'indrukwekkend', 'fantastisch',
            'uitzonderlijk', 'gevestigd', 'goede reputatie', 'kwalitatief',
        ],
        self::LANG_DE => [
            'ausgezeichnet', 'hervorragend', 'beste', 'führend', 'beliebt',
            'vertrauenswürdig', 'zuverlässig', 'renommiert', 'bekannt',
            'hochwertig', 'top-bewertet', 'bestbewertet', 'beeindruckend',
            'fantastisch', 'erstklassig', 'etabliert', 'seriös',
        ],
        self::LANG_FR => [
            'excellent', 'meilleur', 'réputé', 'fiable', 'populaire',
            'reconnu', 'de confiance', 'haut de gamme', 'très apprécié',
            'remarquable', 'impressionnant', 'fantastique', 'exceptionnel',
            'renommé', 'établi', 'de qualité', 'incontournable',
        ],
        self::LANG_UK => [
            'відмінн', 'чудов', 'найкращ', 'провідн', 'популярн',
            'надійн', 'перевірен', 'відом', 'якісн', 'високо оцінен',
            'улюблен', 'вражаюч', 'фантастичн', 'винятков', 'авторитетн',
            'з гарною репутацією', 'topova',
        ],
    ];

    /**
     * Union of recommendation phrases for the given language codes.
     * Unknown codes are ignored; empty input falls back to all packs.
     *
     * @param string[] $languages
     * @return string[]
     */
    public function recommendationPhrases(array $languages): array
    {
        return $this->union(self::RECOMMENDATION, $languages);
    }

    /**
     * Union of positive-sentiment phrases for the given language codes.
     *
     * @param string[] $languages
     * @return string[]
     */
    public function positivePhrases(array $languages): array
    {
        return $this->union(self::POSITIVE, $languages);
    }

    /**
     * @param array<string, string[]> $packs
     * @param string[] $languages
     * @return string[]
     */
    private function union(array $packs, array $languages): array
    {
        $languages = array_intersect($languages, array_keys(self::LANGUAGES));
        if ($languages === []) {
            $languages = array_keys(self::LANGUAGES);
        }

        $merged = [];
        foreach ($languages as $lang) {
            $merged[] = $packs[$lang] ?? [];
        }

        return array_values(array_unique(array_merge(...$merged)));
    }
}
