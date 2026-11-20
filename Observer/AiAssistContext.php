<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Observer;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Angeo\AeoBrandVisibility\Service\RunPayloadBuilder;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Facts of one run for the optional AI assistant (angeo/module-ai-assist).
 * No dependency on it: without it the event is never dispatched.
 *
 * Source "brand_visibility", ref = run id. Checks
 * Angeo_AeoBrandVisibility::view first. The answers other AI models gave are
 * sent as short previews; the assistant module treats them as data, never as
 * instructions.
 */
class AiAssistContext implements ObserverInterface
{
    private const ACL = 'Angeo_AeoBrandVisibility::view';

    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly AuditResultRepositoryInterface $repository,
        private readonly RunPayloadBuilder $payloadBuilder
    ) {
    }

    public function execute(Observer $observer): void
    {
        $context = $observer->getData('context');
        if ((string) $observer->getData('source') !== 'brand_visibility' || !$context instanceof DataObject) {
            return;
        }
        if (!$this->authorization->isAllowed(self::ACL)) {
            $context->setData('denied', true);
            return;
        }
        $ref = (string) $observer->getData('ref');
        if (!ctype_digit($ref)) {
            return;
        }

        try {
            $row = $this->repository->getById((int) $ref);
        } catch (\Throwable) {
            return;
        }
        if ($row->getStatus() !== AuditResultInterface::STATUS_COMPLETE) {
            return;
        }

        $run    = $this->payloadBuilder->build($row, 400);
        $counts = $this->payloadBuilder->namedCounts($run['results']);

        // getLatest() returns completed runs, newest first.
        $previous = null;
        foreach ($this->repository->getLatest($row->getStoreId(), 10) as $candidate) {
            $id = (int) $candidate->getData(AuditResultInterface::ID);
            if ($id > 0 && $id < $run['id']) {
                $previous = $candidate;
                break;
            }
        }

        $cells = [];
        foreach ($run['results'] as $cell) {
            $meta = $cell['meta'];
            $cells[] = [
                'assistant'      => $cell['provider_label'],
                'prompt'         => $cell['prompt'],
                'answers'        => $cell['samples'],
                'score'          => $cell['score'],
                'margin'         => $cell['score_margin'],
                'rates_percent'  => $cell['signal_rates'],
                'tone'           => $meta['tone'] ?? null,
                'winner'         => $meta['winner'] ?? null,
                'competitors_named' => array_slice((array) ($meta['competitor_hits'] ?? []), 0, 8, true),
                'accuracy_issues' => array_slice((array) ($meta['accuracy']['issues'] ?? []), 0, 5),
                'error'          => $cell['error'],
                'first_answer_preview' => $cell['response'],
            ];
        }

        $context->setData([
            'title'    => sprintf('Brand visibility run #%d for %s, %s', $run['id'], $run['brand_name'], $run['created_at']),
            'summary'  => 'Each AI assistant was asked buying questions in the brand\'s category several times. '
                . 'Each answer is scored on: mentioned, recommended, own site cited, named first, tone. '
                . 'Score 0–100 with a 95% margin (±). These are API answers, not what a shopper sees in an app.',
            'facts'    => [
                'brand'            => $run['brand_name'],
                'brand_domain'     => $run['brand_domain'],
                'run_at'           => $run['created_at'],
                'score'            => $run['overall_score'],
                'margin'           => $run['score_margin'],
                'grade'            => $run['grade'],
                'answers_per_prompt' => $run['samples'],
                'answers_total'    => $counts['answers'],
                'signal_rates_percent' => $run['signal_rates'],
                'score_by_assistant'   => $run['scores_by_provider'],
                'share_of_voice_percent' => $run['competitive']['share_of_voice'],
                'win_rate_percent'       => $run['competitive']['win_rate'],
                'accuracy_issue_answers' => $run['competitive']['accuracy_issues'],
                'answers_naming' => [
                    'brand'       => $counts['brand'],
                    'competitors' => array_slice($counts['competitors'], 0, 10, true),
                ],
                'previous_run' => $previous === null ? null : [
                    'score'  => $previous->getOverallScore(),
                    'margin' => (float) $previous->getData(AuditResultInterface::SCORE_MARGIN),
                    'run_at' => (string) $previous->getData(AuditResultInterface::CREATED_AT),
                ],
                'cells' => $cells,
            ],
            'glossary' => [
                'margin (±)'      => 'Half-width of the 95% confidence interval. A change smaller than the margin is noise.',
                'share of voice'  => 'The brand\'s weight against tracked competitors in the same answers, by how often and how early each is named.',
                'win rate'        => 'Share of answers where the brand is named before every competitor.',
                'mentioned vs cited' => 'Named but its site not cited = known to the assistant but not used as a source.',
                'grounded'        => 'The assistant searched the web before answering; it reacts to site changes within days.',
            ],
        ]);
    }
}
