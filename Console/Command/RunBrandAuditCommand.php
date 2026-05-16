<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;

class RunBrandAuditCommand extends Command
{
    private const SIGNALS = [
        'mentioned'          => 'Mentioned',
        'recommended'        => 'Recommended',
        'url_cited'          => 'URL Cited',
        'first_result'       => '1st Position',
        'positive_sentiment' => 'Positive Tone',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly BrandVisibilityService $service
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:aeo:brand-visibility')
            ->setDescription('[Angeo] Check real AI brand visibility across ChatGPT, Claude and Perplexity')
            ->addOption('refresh',  'r', InputOption::VALUE_NONE,     'Force fresh queries, bypass cache')
            ->addOption('provider', null, InputOption::VALUE_OPTIONAL, 'Test one provider: chatgpt|claude|perplexity')
            ->addOption('prompt',   null, InputOption::VALUE_OPTIONAL, 'Prompt key: recommendation|category|brand_direct|product_search|comparison|gift_guide')
            ->addOption('format',   null, InputOption::VALUE_OPTIONAL, 'Output format: table|json|markdown', 'table')
            ->addOption('fail-on',  null, InputOption::VALUE_OPTIONAL, 'Exit 1 if overall score below N (0–100)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (empty($this->config->getBrandName())) {
            $output->writeln('<error>[Angeo] Brand name is not configured. Set it in Stores → Config → Angeo AEO → Brand Visibility.</error>');
            return Command::FAILURE;
        }

        // Single-provider test mode
        if ($provider = $input->getOption('provider')) {
            return $this->runSingleTest($input, $output, $provider);
        }

        $output->writeln(sprintf(
            '<info>[Angeo] Brand Visibility Audit — "%s" (%s)</info>',
            $this->config->getBrandName(),
            $this->config->getBrandDomain() ?: 'domain not set'
        ));

        if ($input->getOption('refresh')) {
            $output->writeln('<comment>Cache bypassed — querying AI models live...</comment>');
        }

        try {
            $report = $this->service->run(forceRefresh: (bool) $input->getOption('refresh'), triggeredBy: 'cli');
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $format = $input->getOption('format');

        match ($format) {
            'json'     => $output->writeln($this->toJson($report)),
            'markdown' => $output->writeln($this->toMarkdown($report)),
            default    => $this->renderTable($output, $report),
        };

        $score  = $report->getOverallScore();
        $grade  = $report->getGrade();
        $colour = match (true) {
            $score >= $this->config->getPassThreshold() => 'info',
            $score >= $this->config->getWarnThreshold() => 'comment',
            default                                     => 'e',
        };
        $cache  = $report->fromCache ? ' (from cache)' : ' (fresh)';

        $output->writeln('');
        $output->writeln(sprintf('<%1$s>Overall Score: %2$d/100 — Grade %3$s%4$s</%1$s>',
            $colour, $score, $grade, $cache));

        if (count($report->failedResults()) > 0) {
            $output->writeln(sprintf('<comment>%d query/queries failed — check log for details.</comment>',
                count($report->failedResults())));
        }

        $failOn = $input->getOption('fail-on');
        if ($failOn !== null && $score < (int) $failOn) {
            $output->writeln(sprintf('<error>Score %d below threshold %d — failing.</error>', $score, (int) $failOn));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    // ── Single test ─────────────────────────────────────────────────────────

    private function runSingleTest(InputInterface $input, OutputInterface $output, string $provider): int
    {
        $promptKey = $input->getOption('prompt') ?? 'brand_direct';
        $output->writeln(sprintf('<info>Single test — provider: %s | prompt: %s</info>', $provider, $promptKey));

        try {
            $result = $this->service->querySingle($provider, $promptKey);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (!$result->isSuccess()) {
            $output->writeln('<error>Error: ' . $result->errorMessage . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<comment>Prompt sent:</comment>');
        $output->writeln(wordwrap($result->prompt, 100));
        $output->writeln('');
        $output->writeln(sprintf('<comment>Response from %s:</comment>', $result->providerLabel));
        $output->writeln(wordwrap($result->rawResponse, 100));
        $output->writeln('');
        $output->writeln('<comment>Signals:</comment>');
        foreach (self::SIGNALS as $key => $label) {
            $val = $result->signals[$key] ?? false;
            $output->writeln(sprintf('  <%s>%s %-22s</%s>', $val ? 'info' : 'e', $val ? '✓' : '✗', $label, $val ? 'info' : 'e'));
        }
        $output->writeln(sprintf('<info>Score: %d/100</info>', $result->score));

        return Command::SUCCESS;
    }

    // ── Render ───────────────────────────────────────────────────────────────

    private function renderTable(OutputInterface $output, $report): void
    {
        // Per-query results table
        $output->writeln('');
        $table = new Table($output);
        $table->setHeaders(['Provider', 'Prompt', 'Score', 'Mentioned', 'Recommended', 'URL Cited', '1st Pos', 'Positive']);
        foreach ($report->results as $r) {
            $s   = $r->signals;
            $y   = fn($v) => $v ? '<info>✓</info>' : '<error>✗</error>';
            $table->addRow([
                $r->providerLabel,
                mb_substr($r->promptKey, 0, 18),
                $r->isSuccess() ? $r->score . '/100' : '<error>ERR</error>',
                $y($s['mentioned']          ?? false),
                $y($s['recommended']        ?? false),
                $y($s['url_cited']          ?? false),
                $y($s['first_result']       ?? false),
                $y($s['positive_sentiment'] ?? false),
            ]);
        }
        $table->render();

        // Signal rate summary
        $output->writeln('');
        $output->writeln('<comment>Signal rates (% of successful queries where signal was detected):</comment>');
        foreach (self::SIGNALS as $key => $label) {
            $rate = $report->signalRate($key);
            $bar  = str_repeat('█', (int) ($rate / 10)) . str_repeat('░', 10 - (int) ($rate / 10));
            $col  = $rate >= 60 ? 'info' : ($rate >= 30 ? 'comment' : 'e');
            $output->writeln(sprintf('  %-22s <%s>%s %s%%</%s>', $label, $col, $bar, $rate, $col));
        }

        // Per-provider scores
        $byProvider = $report->scoreByProvider();
        if (count($byProvider) > 1) {
            $output->writeln('');
            $output->writeln('<comment>Average score per provider:</comment>');
            foreach ($byProvider as $id => $score) {
                $output->writeln(sprintf('  %-15s %s', $id, $score !== null ? $score . '/100' : 'n/a'));
            }
        }
    }

    private function toJson($report): string
    {
        return json_encode([
            'brand'              => $report->brandName,
            'domain'             => $report->brandDomain,
            'overall_score'      => $report->getOverallScore(),
            'grade'              => $report->getGrade(),
            'from_cache'         => $report->fromCache,
            'generated_at'       => $report->generatedAt->format(\DateTimeInterface::ATOM),
            'scores_by_provider' => $report->scoreByProvider(),
            'signal_rates' => [
                'mentioned'          => $report->signalRate('mentioned'),
                'recommended'        => $report->signalRate('recommended'),
                'url_cited'          => $report->signalRate('url_cited'),
                'first_result'       => $report->signalRate('first_result'),
                'positive_sentiment' => $report->signalRate('positive_sentiment'),
            ],
            'queries' => array_map(fn($r) => [
                'provider'   => $r->providerLabel,
                'prompt_key' => $r->promptKey,
                'score'      => $r->score,
                'signals'    => $r->signals,
                'error'      => $r->errorMessage,
            ], $report->results),
        ], JSON_PRETTY_PRINT);
    }

    private function toMarkdown($report): string
    {
        $lines = [
            '# Brand Visibility Report: ' . $report->brandName,
            '',
            sprintf('**Score:** %d/100 — Grade **%s**', $report->getOverallScore(), $report->getGrade()),
            sprintf('**Generated:** %s%s', $report->generatedAt->format('Y-m-d H:i:s'), $report->fromCache ? ' (cached)' : ''),
            '',
            '## Signal Rates',
            '',
        ];
        foreach (self::SIGNALS as $key => $label) {
            $lines[] = sprintf('- **%s:** %.1f%%', $label, $report->signalRate($key));
        }
        $lines[] = '';
        $lines[] = '## Query Results';
        $lines[] = '';
        $lines[] = '| Provider | Prompt | Score | Mentioned | Recommended | URL Cited |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach ($report->results as $r) {
            $s = $r->signals;
            $lines[] = sprintf('| %s | %s | %s | %s | %s | %s |',
                $r->providerLabel, $r->promptKey,
                $r->isSuccess() ? $r->score . '/100' : 'ERROR',
                ($s['mentioned']   ?? false) ? '✓' : '✗',
                ($s['recommended'] ?? false) ? '✓' : '✗',
                ($s['url_cited']   ?? false) ? '✓' : '✗'
            );
        }
        return implode("\n", $lines);
    }
}
