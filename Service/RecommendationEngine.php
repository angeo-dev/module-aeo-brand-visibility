<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;

/**
 * Turns a finished report into a prioritised action plan.
 *
 * Each item names the signal it addresses, why it is weak and what to change,
 * so the plan can be handed to a merchant without further interpretation.
 */
class RecommendationEngine
{
    private const PRIORITY_HIGH = 'high';
    private const PRIORITY_MEDIUM = 'medium';
    private const PRIORITY_LOW = 'low';

    /**
     * Build the action plan.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @return array<int, array{priority: string, signal: string, title: string, detail: string}>
     */
    public function buildPlan(BrandVisibilityReport $report, Config $config): array
    {
        $plan = [];

        if (!$report->hasData()) {
            return [[
                'priority' => self::PRIORITY_HIGH,
                'signal' => 'setup',
                'title' => 'No usable answers were collected',
                'detail' => 'Every provider call failed. Check the API keys, the model names and the '
                    . 'outbound network access of the store before reading any score.',
            ]];
        }

        if (!$config->hasUsableCategory()) {
            $plan[] = [
                'priority' => self::PRIORITY_HIGH,
                'signal' => 'setup',
                'title' => 'Set the store category',
                'detail' => 'Prompts fall back to a generic phrase, which the large marketplaces always '
                    . 'win. Describe what you sell in two or three words under General settings.',
            ];
        }

        if ($report->signalRate('mentioned') < 50.0) {
            $plan[] = [
                'priority' => self::PRIORITY_HIGH,
                'signal' => 'mentioned',
                'title' => 'The brand is largely unknown to AI models',
                'detail' => 'Publish llms.txt, keep the store name identical across every page and '
                    . 'earn citations on sites that AI crawlers already read.',
            ];
        }

        if ($report->signalRate('url_cited') < 30.0) {
            $plan[] = [
                'priority' => self::PRIORITY_HIGH,
                'signal' => 'url_cited',
                'title' => 'The domain is not cited',
                'detail' => 'Make the canonical URL explicit in Organization schema and in llms.txt, '
                    . 'and build links from sources the models quote.',
            ];
        }

        if ($report->signalRate('recommended') < 40.0) {
            $plan[] = [
                'priority' => self::PRIORITY_MEDIUM,
                'signal' => 'recommended',
                'title' => 'Answers mention the brand without recommending it',
                'detail' => 'Strengthen product descriptions, delivery and returns information and '
                    . 'review markup, so the model has reasons to prefer the store.',
            ];
        }

        if ($report->signalRate('first_result') < 30.0) {
            $plan[] = [
                'priority' => self::PRIORITY_MEDIUM,
                'signal' => 'first_result',
                'title' => 'The brand appears late in the answer',
                'detail' => 'Build topical authority around one category rather than the whole '
                    . 'catalogue; models name the most specific match first.',
            ];
        }

        $tones = $report->toneCounts();
        if (($tones['negative'] ?? 0) > 0) {
            $plan[] = [
                'priority' => self::PRIORITY_HIGH,
                'signal' => 'negative_sentiment',
                'title' => 'Negative tone detected',
                'detail' => sprintf(
                    'Negative wording appeared next to the brand in %d answers. Trace the source '
                    . 'reviews or articles and address them directly.',
                    (int) $tones['negative']
                ),
            ];
        }

        if ($report->accuracyIssueCount() > 0) {
            $plan[] = [
                'priority' => self::PRIORITY_HIGH,
                'signal' => 'accuracy',
                'title' => 'The wrong website is attributed to the brand',
                'detail' => 'Models are citing a domain you do not own. Consolidate redirects, fix '
                    . 'Organization sameAs entries and claim the brand on major directories.',
            ];
        }

        $competitors = $report->competitorMentionCounts();
        if ($competitors !== [] && $report->winRate() < 50.0) {
            $leader = (string) array_key_first($competitors);
            $plan[] = [
                'priority' => self::PRIORITY_MEDIUM,
                'signal' => 'share_of_voice',
                'title' => sprintf('%s outranks you in AI answers', $leader),
                'detail' => sprintf(
                    'Your share of voice is %.1f%% and you rank first in %.0f%% of answers. '
                    . 'Compare their category pages and citation sources against yours.',
                    $report->averageShareOfVoice(),
                    $report->winRate()
                ),
            ];
        }

        if ($plan === []) {
            $plan[] = [
                'priority' => self::PRIORITY_LOW,
                'signal' => 'monitoring',
                'title' => 'Visibility is healthy',
                'detail' => sprintf(
                    'Score %d/100 (+/-%.1f). Keep the weekly schedule running so a regression is '
                    . 'caught before it becomes a trend.',
                    $report->getOverallScore(),
                    $report->getScoreMargin()
                ),
            ];
        }

        return $plan;
    }
}
