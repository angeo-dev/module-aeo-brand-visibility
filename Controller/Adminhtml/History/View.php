<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\History;

use Angeo\AeoBrandVisibility\Api\AuditResultRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders one stored run in detail.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::view';
    public const REGISTRY_KEY = 'angeo_brand_vis_record';

    /**
     * @param Context $context Backend action context.
     * @param PageFactory $pageFactory Result page factory.
     * @param AuditResultRepositoryInterface $repository Run persistence.
     * @param Registry $registry Registry used to hand the record to the template.
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly AuditResultRepositoryInterface $repository,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('id', 0);

        try {
            $record = $this->repository->getById($id);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not load that audit run: %1', $e->getMessage()));

            return $this->resultRedirectFactory->create()->setPath('*/history/index');
        }

        $this->registry->register(self::REGISTRY_KEY, $record, true);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Angeo_AeoBrandVisibility::history');
        $page->getConfig()->getTitle()->prepend(__('Audit Run #%1', $id));

        return $page;
    }
}
