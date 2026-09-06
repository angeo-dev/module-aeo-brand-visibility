<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\Export;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Angeo\AeoBrandVisibility\Api\Data\AuditResultInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Exports one stored run as CSV, including the competitive columns that 3.0.0
 * computed but never wrote to storage.
 */
class Csv extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::export';

    private const EXPORT_SUBDIR = 'export/';

    private const HEADER = [
        'Provider',
        'Prompt key',
        'Samples',
        'Score',
        'Margin',
        'Mentioned %',
        'Recommended %',
        'URL cited %',
        'First position %',
        'Positive %',
        'Negative %',
        'Tone',
        'Share of voice %',
        'Winner',
        'Competitors',
        'Accuracy issues',
        'Error',
    ];

    /**
     * @param Context $context Backend action context.
     * @param FileFactory $fileFactory Streams the file to the browser.
     * @param Filesystem $filesystem Filesystem access.
     * @param AuditResultRepositoryInterface $repository Run persistence.
     * @param DateTime $dateTime Framework clock.
     */
    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem,
        private readonly AuditResultRepositoryInterface $repository,
        private readonly DateTime $dateTime
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('id', 0);
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        try {
            $row = $id > 0 ? $this->repository->getById($id) : ($this->repository->getLatest($storeId, 1)[0] ?? null);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not load that audit run: %1', $e->getMessage()));

            return $this->resultRedirectFactory->create()->setPath('*/history/index');
        }

        if ($row === null) {
            $this->messageManager->addErrorMessage(__('There is no audit run to export yet.'));

            return $this->resultRedirectFactory->create()->setPath('*/history/index');
        }

        $fileName = sprintf(
            'brand_visibility_%d_%s.csv',
            (int) $row->getData(AuditResultInterface::ID),
            $this->dateTime->gmtDate('Ymd_His')
        );

        $stream = $this->filesystem
            ->getDirectoryWrite(DirectoryList::VAR_DIR)
            ->openFile(self::EXPORT_SUBDIR . $fileName, 'w+');
        $stream->lock();

        try {
            $stream->writeCsv(self::HEADER);
            foreach ($row->getResultsDecoded() as $cell) {
                $stream->writeCsv($this->buildRow($cell));
            }
        } finally {
            $stream->unlock();
            $stream->close();
        }

        return $this->fileFactory->create(
            $fileName,
            ['type' => 'filename', 'value' => self::EXPORT_SUBDIR . $fileName, 'rm' => true],
            DirectoryList::VAR_DIR,
            'text/csv'
        );
    }

    /**
     * Flatten one stored cell into a CSV row.
     *
     * @param array<string, mixed> $cell Stored cell.
     * @return array<int, string|int|float>
     */
    private function buildRow(array $cell): array
    {
        $rates = (array) ($cell['signal_rates'] ?? []);
        $meta = (array) ($cell['meta'] ?? []);

        return [
            (string) ($cell['provider_label'] ?? ($cell['provider_id'] ?? '')),
            (string) ($cell['prompt_key'] ?? ''),
            (int) ($cell['samples'] ?? 0),
            (int) ($cell['score'] ?? 0),
            (float) ($cell['score_margin'] ?? 0),
            (float) ($rates['mentioned'] ?? 0),
            (float) ($rates['recommended'] ?? 0),
            (float) ($rates['url_cited'] ?? 0),
            (float) ($rates['first_result'] ?? 0),
            (float) ($rates['positive_sentiment'] ?? 0),
            (float) ($rates['negative_sentiment'] ?? 0),
            (string) ($meta['tone'] ?? ''),
            (float) ($meta['share_of_voice'] ?? 0),
            (string) ($meta['winner'] ?? ''),
            implode('; ', array_map('strval', (array) ($meta['competitors_found'] ?? []))),
            implode('; ', array_map('strval', (array) ($meta['accuracy']['issues'] ?? []))),
            (string) ($cell['error'] ?? ''),
        ];
    }
}
