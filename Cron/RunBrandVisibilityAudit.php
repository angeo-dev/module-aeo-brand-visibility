<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Cron;
use Psr\Log\LoggerInterface;
use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\BrandVisibilityService;
class RunBrandVisibilityAudit
{
    public function __construct(
        private readonly Config $config,
        private readonly BrandVisibilityService $service,
        private readonly LoggerInterface $logger
    ) {}
    public function execute(): void
    {
        if (!$this->config->isCronEnabled() || !$this->config->isEnabled()) { return; }
        $this->logger->info('[BrandVis Cron] Starting scheduled brand visibility audit');
        $this->service->run(forceRefresh: true, triggeredBy: 'cron');
    }
}
