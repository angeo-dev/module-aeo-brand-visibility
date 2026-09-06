<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Block\Adminhtml;

use Angeo\AeoBrandVisibility\Model\Config;
use Angeo\AeoBrandVisibility\Service\Provider\ProviderPool;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Supplies the dashboard template with its endpoints and options.
 *
 * All state reaches the browser as one JSON blob consumed by a RequireJS
 * module, so the template needs no inline script and stays compatible with a
 * restrictive content security policy.
 */
class Dashboard extends Template
{
    /**
     * RequireJS module bound to the dashboard container.
     */
    private const JS_COMPONENT = 'Angeo_AeoBrandVisibility/js/dashboard';

    /**
     * @param Context $context Block context.
     * @param Config $config Configuration accessor.
     * @param ProviderPool $providerPool Registered providers.
     * @param StoreManagerInterface $storeManager Store list.
     * @param SerializerInterface $serializer JSON encoding.
     * @param array<string, mixed> $data Block data.
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ProviderPool $providerPool,
        private readonly StoreManagerInterface $storeManager,
        private readonly SerializerInterface $serializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Store view currently selected in the dashboard.
     *
     * @return int
     */
    public function getSelectedStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }

    /**
     * Store views the audit can be scoped to.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function getStoreOptions(): array
    {
        $options = [['value' => 0, 'label' => (string) __('Default scope')]];

        foreach ($this->storeManager->getStores() as $store) {
            $options[] = [
                'value' => (int) $store->getId(),
                'label' => sprintf('%s (%s)', $store->getName(), $store->getCode()),
            ];
        }

        return $options;
    }

    /**
     * Everything the RequireJS module needs, shaped for data-mage-init.
     *
     * @return string
     */
    public function getJsConfigJson(): string
    {
        $storeId = $this->getSelectedStoreId();
        $scoped = $this->config->withStore($storeId);

        return (string) $this->serializer->serialize([
            self::JS_COMPONENT => [
                'urls' => [
                    'start' => $this->getUrl('angeo_brand_vis/run/start'),
                    'status' => $this->getUrl('angeo_brand_vis/run/status'),
                    'plan' => $this->getUrl('angeo_brand_vis/plan/index'),
                    'history' => $this->getUrl('angeo_brand_vis/history/data'),
                    'test' => $this->getUrl('angeo_brand_vis/query/test'),
                    'export' => $this->getUrl('angeo_brand_vis/export/csv'),
                    'view' => $this->getUrl('angeo_brand_vis/history/view'),
                ],
                'formKey' => $this->getFormKey(),
                'storeId' => $storeId,
                'brandName' => $scoped->getBrandName(),
                'brandDomain' => $scoped->getBrandDomain(),
                'samples' => $scoped->getSamples(),
                'providers' => $this->providerPool->getLabels($scoped),
                'prompts' => array_keys($scoped->getActivePrompts()),
                'pollIntervalMs' => 3000,
                'pollTimeoutMs' => 900000,
            ],
        ]);
    }
}
