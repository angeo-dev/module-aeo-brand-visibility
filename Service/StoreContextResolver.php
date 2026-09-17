<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Derives a meaningful category phrase and product list from the live catalogue.
 *
 * Without it, prompts fall back to the literal word "products", and every store
 * loses that query to the large marketplaces — which was the main reason scores
 * sat near zero before 3.0.0.
 */
class StoreContextResolver
{
    private const MAX_CATEGORIES = 3;
    private const MAX_PRODUCTS = 5;
    private const MIN_CATEGORY_LEVEL = 2;

    /**
     * @param CategoryCollectionFactory $categoryCollectionFactory Category collection factory.
     * @param ProductCollectionFactory $productCollectionFactory Product collection factory.
     * @param StoreManagerInterface $storeManager Resolves the current store view.
     */
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Best-effort phrase describing what the store sells.
     *
     * @param int|null $storeId Store view id, or null for the current store.
     * @return string
     */
    public function resolveCategoryPhrase(?int $storeId = null): string
    {
        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStore($this->resolveStoreId($storeId))
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('is_active', ['eq' => 1])
                ->addAttributeToFilter('level', ['gteq' => self::MIN_CATEGORY_LEVEL])
                ->setOrder('level', 'ASC')
                ->setPageSize(self::MAX_CATEGORIES + 1);

            $names = [];
            foreach ($collection as $category) {
                $name = trim((string) $category->getName());
                if ($name !== '' && strtolower($name) !== 'default category') {
                    $names[] = $name;
                }
            }

            return implode(', ', array_slice($names, 0, self::MAX_CATEGORIES));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Visible product names used in product-search prompts.
     *
     * @param int|null $storeId Store view id, or null for the current store.
     * @param int $limit Maximum number of names.
     * @return string[]
     */
    public function resolveTopProducts(?int $storeId = null, int $limit = self::MAX_PRODUCTS): array
    {
        try {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($this->resolveStoreId($storeId))
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('status', ['eq' => 1])
                ->setVisibility([Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH])
                ->setPageSize($limit);

            $names = [];
            foreach ($collection as $product) {
                $name = trim((string) $product->getName());
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            return $names;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Fall back to the current store when no id is given.
     *
     * @param int|null $storeId Store view id.
     * @return int
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function resolveStoreId(?int $storeId): int
    {
        return $storeId ?? (int) $this->storeManager->getStore()->getId();
    }
}
