<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Api\Data;

/**
 * One persisted brand visibility audit run.
 *
 * @api
 */
interface AuditResultInterface
{
    public const ID = 'id';
    public const RUN_UUID = 'run_uuid';
    public const STATUS = 'status';
    public const ERROR_MESSAGE = 'error_message';
    public const BRAND_NAME = 'brand_name';
    public const BRAND_DOMAIN = 'brand_domain';
    public const STORE_ID = 'store_id';
    public const OVERALL_SCORE = 'overall_score';
    public const SCORE_MARGIN = 'score_margin';
    public const GRADE = 'grade';
    public const SAMPLES = 'samples';
    public const SHARE_OF_VOICE = 'share_of_voice';
    public const WIN_RATE = 'win_rate';
    public const ACCURACY_ISSUES = 'accuracy_issues';
    public const PROVIDER_SCORES = 'provider_scores';
    public const SIGNAL_RATES = 'signal_rates';
    public const RESULTS_JSON = 'results_json';
    public const TRIGGERED_BY = 'triggered_by';
    public const QUERIES_COUNT = 'queries_count';
    public const ERRORS_COUNT = 'errors_count';
    public const FROM_CACHE = 'from_cache';
    public const CREATED_AT = 'created_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_ERROR = 'error';

    /**
     * Get the run status.
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * Get the overall score, 0-100.
     *
     * @return int
     */
    public function getOverallScore(): int;

    /**
     * Get the letter grade A-F.
     *
     * @return string
     */
    public function getGrade(): string;

    /**
     * Get the store view id this run was scoped to.
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Decoded per-signal rates.
     *
     * @return array<string, float>
     */
    public function getSignalRatesDecoded(): array;

    /**
     * Decoded per-provider average scores.
     *
     * @return array<string, int|null>
     */
    public function getProviderScoresDecoded(): array;

    /**
     * Decoded per-query results including analyser meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getResultsDecoded(): array;
}
