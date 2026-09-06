<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Model;

use Angeo\AeoBrandVisibility\Api\Data\VisibilitySummaryInterface;
use Magento\Framework\DataObject;

/**
 * DataObject-backed implementation of the REST visibility summary (3.0.0).
 */
class VisibilitySummary extends DataObject implements VisibilitySummaryInterface
{
    public function getScore(): int
    {
        return (int) $this->getData(self::SCORE);
    }

    public function setScore(int $score): VisibilitySummaryInterface
    {
        return $this->setData(self::SCORE, $score);
    }

    public function getGrade(): string
    {
        return (string) $this->getData(self::GRADE);
    }

    public function setGrade(string $grade): VisibilitySummaryInterface
    {
        return $this->setData(self::GRADE, $grade);
    }

    public function getBrandName(): string
    {
        return (string) $this->getData(self::BRAND_NAME);
    }

    public function setBrandName(string $brandName): VisibilitySummaryInterface
    {
        return $this->setData(self::BRAND_NAME, $brandName);
    }

    public function getStoreId(): ?int
    {
        $value = $this->getData(self::STORE_ID);
        return $value === null ? null : (int) $value;
    }

    public function setStoreId(?int $storeId): VisibilitySummaryInterface
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getGeneratedAt(): string
    {
        return (string) $this->getData(self::GENERATED_AT);
    }

    public function setGeneratedAt(string $generatedAt): VisibilitySummaryInterface
    {
        return $this->setData(self::GENERATED_AT, $generatedAt);
    }

    public function getShareOfVoiceJson(): string
    {
        return (string) ($this->getData(self::SHARE_OF_VOICE) ?: '{}');
    }

    public function setShareOfVoiceJson(string $json): VisibilitySummaryInterface
    {
        return $this->setData(self::SHARE_OF_VOICE, $json);
    }
}
