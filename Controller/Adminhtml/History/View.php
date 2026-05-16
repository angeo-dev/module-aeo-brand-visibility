<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Controller\Adminhtml\History;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Angeo\AeoBrandVisibility\Model\AuditResultRepository;

class View extends Action implements HttpGetActionInterface
{
    const ADMIN_RESOURCE = 'Angeo_AeoBrandVisibility::run';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly AuditResultRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');

        try {
            $record = $this->repository->getById($id);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Audit result #%1 not found.', $id));
            return $this->resultRedirectFactory->create()
                ->setPath('angeo_brand_vis/history/index');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(
            __('Audit #%1 — %2 — Score %3/100 (%4)',
                $record->getId(),
                $record->getCreatedAt(),
                $record->getOverallScore(),
                $record->getGrade()
            )
        );

        // Pass record to block via registry pattern
        $this->getRequest()->setParam('audit_record', $record);

        return $page;
    }
}
