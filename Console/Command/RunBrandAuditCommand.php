<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Console\Command;

use Angeo\AeoBrandVisibility\Api\BrandVisibilityServiceInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Model\Result\BrandQueryResult;
use Angeo\AeoBrandVisibility\Model\Result\BrandVisibilityReport;
use Angeo\AeoBrandVisibility\Service\Provider\ProviderPool;
use Angeo\AeoBrandVisibility\Service\RecommendationEngine;
use Magento\Framework\App\State;
use Magento\Framework\Serialize\SerializerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a brand visibility audit from the command line.
 */
class RunBrandAuditCommand extends Command
{
    private const NAME = 'angeo:aeo:brand-visibility';

    private const OPT_STORE = 'store';
    private const OPT_REFRESH = 'refresh';
    private const OPT_PROVIDER = 'provider';
    private const OPT_PROMPT = 'prompt';
    private const OPT_FORMAT = 'format';
    private const OPT_FAIL_ON = 'fail-on';
    private const OPT_PLAN = 'plan';

    private const SIGNAL_LABELS = [
        'mentioned' => 'Mentioned',
        'recommended' => 'Recommended',
        'url_cited' => 'URL cited',
        'first_result' => 'First position',
        'positive_sentiment' => 'Positive tone',
        'negative_sentiment' => 'Negative tone',
    ];

    /**
     * @param Config $config Configuration accessor.
     * @param BrandVisibilityServiceInterface $service Audit runner.
     * @param ProviderPool $providerPool Registered providers.
     * @param RecommendationEngine $recommendationEngine Action plan builder.
     * @param SerializerInterface $serializer JSON output encoding.
     * @param State $appState Application area state.
     * @param string|null $name Command name override.
     */
    public function __construct(
        private readonly Config $config,
        private readonly BrandVisibilityServiceInterface $service,
        private readonly ProviderPool $providerPool,
        private readonly RecommendationEngine $recommendationEngine,
        private readonly SerializerInterface $serializer,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('[Angeo] Measure brand visibility across the configured AI providers')
            ->addOption(self::OPT_STORE, null, InputOption::VALUE_REQUIRED, 'Store view id to audit', '0')
            ->addOption(self::OPT_REFRESH, 'r', InputOption::VALUE_NONE, 'Bypass the result cache')
            ->addOption(
                self::OPT_PROVIDER,
                null,
                InputOption::VALUE_REQUIRED,
                'Query a single provider instead of running the full audit'
            )
            ->addOption(
                self::OPT_PROMPT,
                null,
                InputOption::VALUE_REQUIRED,
                'Prompt key for --provider (default: brand_direct)',
                'brand_direct'
            )
            ->addOption(self::OPT_FORMAT, null, InputOption::VALUE_REQUIRED, 'table, json or markdown', 'table')
            ->addOption(self::OPT_PLAN, null, InputOption::VALUE_NONE, 'Print the action plan after the report')
            ->addOption(
                self::OPT_FAIL_ON,
                null,
                InputOption::VALUE_REQUIRED,
                'Exit with code 1 when the score is below this value'
            );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // The area code is already set; nothing to do.
        }

        $storeId = (int) $input->getOption(self::OPT_STORE);
        $config = $this->config->withStore($storeId);

        if ($config->getBrandName() === '') {
            $output->writeln('<error>[Angeo] No brand name is configured for this store view.</error>');

            return Command::FAILURE;
        }

        $providerId = $input->getOption(self::OPT_PROVIDER);
        if (is_string($providerId) && $providerId !== '') {
            return $this->runSingle($input, $output, $providerId, $storeId);
        }

        return $this->runFull($input, $output, $config, $storeId);
    }

    /**
     * Execute a full audit and render it.
     *
     * @param InputInterface $input Console input.
     * @param OutputInterface $output Console output.
     * @param Config $config Store-scoped configuration.
     * @param int $storeId Store view id.
     * @return int
     */
    private function runFull(InputInterface $input, OutputInterface $output, Config $config, int $storeId): int
    {
        $output->writeln(sprintf(
            '<info>[Angeo] Brand visibility for "%s" (%s), store view %d, %d samples per query.</info>',
            $config->getBrandName(),
            $config->getBrandDomain() ?: 'domain not set',
            $storeId,
            $config->getSamples()
        ));

        try {
            $report = $this->service->run(
                $storeId,
                (bool) $input->getOption(self::OPT_REFRESH),
                'cli'
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        match ((string) $input->getOption(self::OPT_FORMAT)) {
            'json' => $output->writeln($this->renderJson($report)),
            'markdown' => $output->writeln($this->renderMarkdown($report)),
            default => $this->renderTable($output, $report),
        };

        $this->renderSummary($output, $report, $config);

        if ($input->getOption(self::OPT_PLAN)) {
            $this->renderPlan($output, $report, $config);
        }

        $failOn = $input->getOption(self::OPT_FAIL_ON);
        if (is_string($failOn) && $failOn !== '' && $report->getOverallScore() < (int) $failOn) {
            $output->writeln(sprintf(
                '<error>Score %d is below the threshold %d.</error>',
                $report->getOverallScore(),
                (int) $failOn
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Execute one provider and prompt pair.
     *
     * @param InputInterface $input Console input.
     * @param OutputInterface $output Console output.
     * @param string $providerId Provider identifier.
     * @param int $storeId Store view id.
     * @return int
     */
    private function runSingle(
        InputInterface $input,
        OutputInterface $output,
        string $providerId,
        int $storeId
    ): int {
        $promptKey = (string) $input->getOption(self::OPT_PROMPT);

        try {
            $cell = $this->service->querySingle($providerId, $promptKey, $storeId);
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $output->writeln('<comment>Configured providers: '
                . implode(', ', array_keys($this->providerPool->getLabels($this->config->withStore($storeId))))
                . '</comment>');

            return Command::FAILURE;
        }

        if (!$cell->isSuccess()) {
            $output->writeln('<error>' . (string) $cell->errorMessage . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>%s — %s</info>', $cell->providerLabel, $cell->promptKey));
        $output->writeln('<comment>Prompt:</comment> ' . $cell->prompt);
        $output->writeln('');
        $output->writeln($cell->getRawResponse());
        $output->writeln('');
        $output->writeln(sprintf('<info>Score: %d/100, tone %s</info>', $cell->score, $cell->getTone()));

        return Command::SUCCESS;
    }

    /**
     * Render the per-cell table.
     *
     * @param OutputInterface $output Console output.
     * @param BrandVisibilityReport $report Finished report.
     * @return void
     */
    private function renderTable(OutputInterface $output, BrandVisibilityReport $report): void
    {
        $table = new Table($output);
        $table->setHeaders(['Provider', 'Prompt', 'N', 'Score', 'Mention', 'Recomm.', 'URL', 'First', 'Tone', 'SoV']);

        foreach ($report->results as $cell) {
            if (!$cell->isSuccess()) {
                $table->addRow([
                    $cell->providerLabel,
                    $cell->promptKey,
                    0,
                    'ERROR',
                    mb_substr((string) $cell->errorMessage, 0, 40),
                    '',
                    '',
                    '',
                    '',
                    '',
                ]);
                continue;
            }

            $table->addRow([
                $cell->providerLabel,
                $cell->promptKey,
                $cell->samples,
                sprintf('%d ±%.1f', $cell->score, $cell->scoreMargin),
                $this->percent($cell, 'mentioned'),
                $this->percent($cell, 'recommended'),
                $this->percent($cell, 'url_cited'),
                $this->percent($cell, 'first_result'),
                $cell->getTone(),
                sprintf('%.1f%%', (float) ($cell->meta['share_of_voice'] ?? 0)),
            ]);
        }

        $table->render();
    }

    /**
     * Render the closing summary lines.
     *
     * @param OutputInterface $output Console output.
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @return void
     */
    private function renderSummary(OutputInterface $output, BrandVisibilityReport $report, Config $config): void
    {
        $score = $report->getOverallScore();
        $style = match (true) {
            $score >= $config->getPassThreshold() => 'info',
            $score >= $config->getWarnThreshold() => 'comment',
            default => 'error',
        };

        $output->writeln('');
        $output->writeln(sprintf(
            '<%1$s>Overall score: %2$d/100 ±%3$.1f — grade %4$s%5$s</%1$s>',
            $style,
            $score,
            $report->getScoreMargin(),
            $report->getGrade(),
            $report->fromCache ? ' (from cache)' : ''
        ));
        $output->writeln(sprintf(
            'Share of voice %.1f%% | Win rate %.0f%% | Accuracy issues %d',
            $report->averageShareOfVoice(),
            $report->winRate(),
            $report->accuracyIssueCount()
        ));

        $failed = count($report->failedResults());
        if ($failed > 0) {
            $output->writeln(sprintf(
                '<comment>%d of %d queries failed — see var/log/angeo_aeo_brand_visibility.log.</comment>',
                $failed,
                count($report->results)
            ));
        }
    }

    /**
     * Render the action plan.
     *
     * @param OutputInterface $output Console output.
     * @param BrandVisibilityReport $report Finished report.
     * @param Config $config Store-scoped configuration.
     * @return void
     */
    private function renderPlan(OutputInterface $output, BrandVisibilityReport $report, Config $config): void
    {
        $output->writeln('');
        $output->writeln('<info>Action plan</info>');

        foreach ($this->recommendationEngine->buildPlan($report, $config) as $item) {
            $output->writeln(sprintf(' [%s] %s', strtoupper($item['priority']), $item['title']));
            $output->writeln('   ' . $item['detail']);
        }
    }

    /**
     * Render the report as JSON.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @return string
     */
    private function renderJson(BrandVisibilityReport $report): string
    {
        return (string) $this->serializer->serialize([
            'brand' => $report->brandName,
            'domain' => $report->brandDomain,
            'generated_at' => $report->generatedAt->format(\DateTimeInterface::ATOM),
            'samples' => $report->samples,
            'score' => $report->getOverallScore(),
            'score_margin' => $report->getScoreMargin(),
            'grade' => $report->getGrade(),
            'share_of_voice' => $report->averageShareOfVoice(),
            'win_rate' => $report->winRate(),
            'accuracy_issues' => $report->accuracyIssueCount(),
            'tone' => $report->toneCounts(),
            'signal_rates' => array_map(
                fn(string $signal): float => $report->signalRate($signal),
                array_combine(array_keys(self::SIGNAL_LABELS), array_keys(self::SIGNAL_LABELS))
            ),
            'scores_by_provider' => $report->scoreByProvider(),
            'competitors' => $report->competitorMentionCounts(),
        ]);
    }

    /**
     * Render the report as Markdown.
     *
     * @param BrandVisibilityReport $report Finished report.
     * @return string
     */
    private function renderMarkdown(BrandVisibilityReport $report): string
    {
        $lines = [
            sprintf('# Brand visibility — %s', $report->brandName),
            '',
            sprintf(
                '**Score:** %d/100 ±%.1f (grade %s), %d samples per query',
                $report->getOverallScore(),
                $report->getScoreMargin(),
                $report->getGrade(),
                $report->samples
            ),
            '',
            '| Signal | Rate |',
            '| --- | --- |',
        ];

        foreach (self::SIGNAL_LABELS as $signal => $label) {
            $lines[] = sprintf('| %s | %.0f%% |', $label, $report->signalRate($signal));
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Share of voice %.1f%%, win rate %.0f%%, accuracy issues %d.',
            $report->averageShareOfVoice(),
            $report->winRate(),
            $report->accuracyIssueCount()
        );

        return implode("\n", $lines);
    }

    /**
     * Format one signal rate of a cell.
     *
     * @param BrandQueryResult $cell Aggregated cell.
     * @param string $signal Signal identifier.
     * @return string
     */
    private function percent(BrandQueryResult $cell, string $signal): string
    {
        return sprintf('%.0f%%', $cell->signalRates[$signal] ?? 0.0);
    }
}
