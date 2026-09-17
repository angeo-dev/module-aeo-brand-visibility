<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Analysis;

/**
 * Locale-aware phrase lists used by the response analyser.
 *
 * Supported languages: English, Ukrainian, Dutch, German, French, Spanish.
 * English is always merged in, because AI answers frequently mix languages.
 */
class PhraseLibrary
{
    private const RECOMMENDATION = [
        'en' => [
            'recommend', 'suggest', 'check out', 'visit', 'try', 'great choice', 'good option',
            'top pick', 'worth visiting', 'you should', 'consider', 'look at', 'head to',
            'go to', 'perfect for', 'ideal for', 'best place',
        ],
        'uk' => [
            'рекоменду', 'раджу', 'радимо', 'спробуйте', 'варто', 'зверніть увагу', 'чудовий вибір',
            'найкращий вибір', 'вам слід', 'розгляньте', 'ідеально', 'найкраще місце', 'завітайте',
        ],
        // Dutch and German split their separable verbs ("raad ik ... aan",
        // "empfehle ich"), so the split forms are listed as well as the infinitive.
        'nl' => [
            'aanbevel', 'aanrad', 'raad aan', 'raad ik', 'raden wij', 'raden we',
            'aan te raden', 'probeer', 'bezoek', 'bekijk', 'goede keuze',
            'beste keuze', 'je zou', 'u zou', 'overweeg', 'ideaal voor', 'de moeite waard',
            'ga naar', 'beste plek', 'kijk eens bij',
        ],
        'de' => [
            'empfehl', 'empfehle ich', 'zu empfehlen', 'vorschlag', 'schau', 'besuche',
            'probier', 'gute wahl', 'top-tipp', 'sie sollten', 'ideal für', 'am besten',
            'lohnt sich', 'schau mal bei',
        ],
        'fr' => [
            'recommand', 'suggér', 'essayez', 'consultez', 'visitez', 'bon choix', 'idéal pour',
            'vous devriez', 'meilleur endroit', 'vaut le détour',
        ],
        'es' => [
            'recomien', 'sugier', 'prueba', 'visita', 'buena opción', 'mejor opción', 'deberías',
            'ideal para', 'mejor lugar', 'merece la pena',
        ],
    ];

    private const POSITIVE = [
        'en' => [
            'excellent', 'great', 'best', 'top', 'leading', 'popular', 'trusted', 'reliable',
            'reputable', 'well-known', 'well known', 'high quality', 'highly rated',
            'highly recommended', 'favourite', 'favorite', 'preferred', 'outstanding',
            'impressive', 'fantastic', 'superb', 'exceptional', 'renowned', 'established',
        ],
        'uk' => [
            'чудов', 'найкращ', 'провідн', 'популярн', 'надійн', 'якісн', 'відом',
            'рекомендован', 'улюблен', 'визнан', 'вражаюч', 'неперевершен', 'авторитетн',
        ],
        'nl' => [
            'uitstekend', 'geweldig', 'beste', 'toonaangevend', 'populair', 'betrouwbaar',
            'gerenommeerd', 'bekend', 'hoogwaardig', 'aanbevolen', 'hoog gewaardeerd',
            'favoriet', 'voortreffelijk', 'indrukwekkend',
        ],
        'de' => [
            'ausgezeichnet', 'großartig', 'beste', 'führend', 'beliebt', 'vertrauens',
            'zuverlässig', 'hochwertig', 'bekannt', 'empfohlen', 'hervorragend',
        ],
        'fr' => [
            'excellent', 'meilleur', 'principal', 'populaire', 'fiable', 'réputé',
            'haute qualité', 'reconnu', 'recommandé', 'remarquable',
        ],
        'es' => [
            'excelente', 'mejor', 'líder', 'popular', 'fiable', 'confiable', 'reconocido',
            'recomendado', 'alta calidad', 'destacado',
        ],
    ];

    private const NEGATIVE = [
        'en' => [
            'scam', 'fraud', 'avoid', 'complaint', 'poor quality', 'bad reviews',
            'negative reviews', 'unreliable', 'not recommended', 'do not recommend',
            'disappointing', 'overpriced', 'slow shipping', 'never received', 'refund issues',
            'be careful', 'be cautious', 'no longer operating', 'out of business',
        ],
        'uk' => [
            'шахрай', 'обман', 'уникайте', 'скарг', 'погана якість', 'негативні відгуки',
            'ненадійн', 'не рекоменду', 'розчарув', 'завищена ціна', 'повільна доставка',
            'будьте обережні', 'не працює',
        ],
        'nl' => [
            'oplichting', 'fraude', 'vermijd', 'klacht', 'slechte kwaliteit', 'negatieve recensies',
            'onbetrouwbaar', 'niet aanbevolen', 'teleurstellend', 'te duur', 'trage levering',
            'wees voorzichtig',
        ],
        'de' => [
            'betrug', 'abzocke', 'vermeiden', 'beschwerde', 'schlechte qualität',
            'negative bewertungen', 'unzuverlässig', 'nicht empfohlen', 'enttäuschend',
            'überteuert', 'langsamer versand', 'vorsicht',
        ],
        'fr' => [
            'arnaque', 'fraude', 'évitez', 'plainte', 'mauvaise qualité', 'avis négatifs',
            'peu fiable', 'non recommandé', 'décevant', 'trop cher', 'livraison lente',
            'méfiez-vous',
        ],
        'es' => [
            'estafa', 'fraude', 'evita', 'queja', 'mala calidad', 'reseñas negativas',
            'poco fiable', 'no recomendado', 'decepcionante', 'demasiado caro',
            'envío lento', 'ten cuidado',
        ],
    ];

    /**
     * Phrases that mark a recommendation.
     *
     * @param string $lang Two-letter language code.
     * @return string[]
     */
    public function recommendation(string $lang): array
    {
        return $this->merge(self::RECOMMENDATION, $lang);
    }

    /**
     * Phrases that mark positive tone.
     *
     * @param string $lang Two-letter language code.
     * @return string[]
     */
    public function positive(string $lang): array
    {
        return $this->merge(self::POSITIVE, $lang);
    }

    /**
     * Phrases that mark negative tone.
     *
     * @param string $lang Two-letter language code.
     * @return string[]
     */
    public function negative(string $lang): array
    {
        return $this->merge(self::NEGATIVE, $lang);
    }

    /**
     * Languages this library covers, excluding the always-merged English base.
     *
     * @return string[]
     */
    public function getSupportedLanguages(): array
    {
        return array_keys(self::RECOMMENDATION);
    }

    /**
     * Merge the English base set with the requested language set.
     *
     * @param array<string, string[]> $set Phrase set.
     * @param string $lang Two-letter language code.
     * @return string[]
     */
    private function merge(array $set, string $lang): array
    {
        $lang = strtolower(substr($lang, 0, 2));
        $base = $set['en'];

        if ($lang !== 'en' && isset($set[$lang])) {
            return array_values(array_unique(array_merge($base, $set[$lang])));
        }

        return $base;
    }
}
