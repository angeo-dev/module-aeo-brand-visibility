<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service\Analysis;

/**
 * Mention- and position-weighted share of voice.
 *
 * Version 3.x used 1/N, which returned the same number regardless of how often
 * or how prominently a brand appeared. Here each brand's weight is its mention
 * count multiplied by a prominence factor between 1.0 and 1.5, where an early
 * first mention earns the higher factor.
 */
class ShareOfVoiceCalculator
{
    private const MAX_PROMINENCE_BONUS = 0.5;

    /**
     * Compute the share of voice for every brand present in one answer.
     *
     * @param array<int, array{name: string, mentions: int, first_position: int}> $entities Detected brands.
     * @param int $textLength Length of the analysed answer in characters.
     * @return array<string, float> Brand name to share of voice, 0-100.
     */
    public function calculate(array $entities, int $textLength): array
    {
        $weights = [];
        foreach ($entities as $entity) {
            $mentions = max(0, $entity['mentions']);
            if ($mentions === 0) {
                continue;
            }
            $weights[$entity['name']] = $mentions * $this->prominence($entity['first_position'], $textLength);
        }

        $total = array_sum($weights);
        if ($total <= 0.0) {
            return [];
        }

        $shares = [];
        foreach ($weights as $name => $weight) {
            $shares[$name] = round($weight / $total * 100, 1);
        }

        return $shares;
    }

    /**
     * Prominence multiplier: 1.5 at the very start of the answer, 1.0 at the end.
     *
     * @param int $firstPosition Character offset of the first mention.
     * @param int $textLength Length of the analysed answer.
     * @return float
     */
    private function prominence(int $firstPosition, int $textLength): float
    {
        if ($textLength <= 0) {
            return 1.0;
        }

        $relative = min(1.0, max(0.0, $firstPosition / $textLength));

        return 1.0 + self::MAX_PROMINENCE_BONUS * (1.0 - $relative);
    }
}
