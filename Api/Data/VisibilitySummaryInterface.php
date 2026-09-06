<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Api\Data;

/**
 * Read-only brand-visibility summary exposed over REST (since 3.0.0).
 *
 * Backs GET /V1/angeo/brand-visibility/latest for headless storefronts,
 * external dashboards and monitoring. Share of voice is returned as a JSON
 * string to keep the map<string,float> intact across Magento's serializer.
 *
 * @api
 */
interface VisibilitySummaryInterface
{
    public const SCORE           = 'score';
    public const GRADE           = 'grade';
    public const BRAND_NAME      = 'brand_name';
    public const STORE_ID        = 'store_id';
    public const GENERATED_AT    = 'generated_at';
    public const SHARE_OF_VOICE  = 'share_of_voice_json';

    public function getScore(): int;

    public function setScore(int $score): self;

    public function getGrade(): string;

    public function setGrade(string $grade): self;

    public function getBrandName(): string;

    public function setBrandName(string $brandName): self;

    public function getStoreId(): ?int;

    public function setStoreId(?int $storeId): self;

    public function getGeneratedAt(): string;

    public function setGeneratedAt(string $generatedAt): self;

    /**
     * Share-of-voice map (brand + competitors → 0..100) as a JSON object string.
     */
    public function getShareOfVoiceJson(): string;

    public function setShareOfVoiceJson(string $json): self;
}
