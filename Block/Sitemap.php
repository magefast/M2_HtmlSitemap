<?php

namespace Magefast\HtmlSitemap\Block;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;


class Sitemap extends Template
{

    private $request;

    private $storeManager;

    private $productCollectionFactory;

    private $productVisibility;

    private $stockHelper;


    private $categoryCollectionFactory;
    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;
    /**
     * @var Status
     */
    private $productStatus;

    private $url;

    /**
     * @param Context $context
     */
    public function __construct(
        Context                     $context,
        StoreManagerInterface       $storeManager,
        CategoryRepositoryInterface $categoryRepository,
        CollectionFactory           $productCollectionFactory,
        CategoryCollectionFactory   $categoryCollectionFactory,
        Visibility                  $productVisibility,
        Status                      $productStatus,
        Stock                       $stockHelper,
        UrlInterface                $url
    )
    {
        $this->request = $context->getRequest();
        $this->storeManager = $storeManager;
        $this->categoryRepository = $categoryRepository;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productVisibility = $productVisibility;
        $this->productStatus = $productStatus;
        $this->stockHelper = $stockHelper;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->url = $url;
        parent::__construct($context);
    }

    /**
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getUrls()
    {
        $arrayData = [];
        $paramCategory = $this->request->getParam('cat', '');

        /**
         * PRODUCTS
         */
        if ($paramCategory != '') {
            $paramCategory = explode('-', $paramCategory);
            $paramCategory = end($paramCategory);

            $category = $this->categoryRepository->get(intval($paramCategory), $this->storeManager->getStore()->getId());

            if ($category->getData('is_active')) {
                $categoryUrl = $category->getUrl();
                $arrayData[] = [
                    'title' => $this->escapeHtml($category->getName()),
                    'id' => 'category' . $category->getId(),
                    'url' => $categoryUrl
                ];
            }

            $products = $this->productCollectionFactory->create()
                ->addCategoryFilter($category)
                ->addAttributeToSelect('*')
                ->addAttributeToSort('name', 'ASC');

            $products->addAttributeToFilter('status', ['in' => $this->productStatus->getVisibleStatusIds()]);
            $products->setVisibility($this->productVisibility->getVisibleInSiteIds());

            if (count($products) > 0 && $category->getData('children_count') == 0) {
                $this->stockHelper->addInStockFilterToCollection($products);

                foreach ($products as $p) {
                    if ($p->isInStock()) {
                        $arrayData[] = [
                            'title' => $this->escapeHtml($p->getData('name')),
                            'id' => $p->getIs(),
                            'url' => $p->getProductUrl()
                        ];
                    }
                }

            } else {

                if ($category->getData('children_count') > 0) {
                    $catChildren = $category->getChildren();

                    if ($catChildren != '') {
                        $catChildren = explode(',', $catChildren);

                        foreach ($catChildren as $cc) {
                            $cc = $this->categoryRepository->get($cc, $this->_storeManager->getStore()->getId());
                            $urlData = $this->_getCategoryItemUrl($cc);
                            $arrayData[] = [
                                'title' => $this->escapeHtml($cc->getName()),
                                'id' => $cc->getId(),
                                'type' => $urlData['type'],
                                'url' => $urlData['url']
                            ];
                        }
                    }
                }

                $data = ['type' => 'categories', 'list' => $arrayData];

                return $data;
            }

            $data = ['type' => 'products', 'category' => $paramCategory, 'list' => $arrayData];
        } else {

            /**
             * CATEGORIES
             */
            $categories = $this->categoryCollectionFactory->create()
                ->addAttributeToSelect('id')
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('path')
                ->addAttributeToSelect('level')
                ->addAttributeToSelect('is_active')
                ->addAttributeToFilter('is_active', 1)
                ->addAttributeToFilter('level', [2, 3])//->addAttributeToFilter('path', array("like" => "1/" . "%"))
            ;

            foreach ($categories as $c) {
                if ($c->getLevel() == 2) {
                    $childLevel = $this->getChildLevel($categories, $c->getId(), $c->getData('path'));

                    $urlData = $this->_getCategoryItemUrl($c);
                    $arrayData[$c->getData('entity_id')] = [
                        'title' => $this->escapeHtml($c->getName()),
                        'id' => $c->getData('entity_id'),
                        'type' => $urlData['type'],
                        'level' => $c->getLevel(),
                        'url' => $urlData['url'],
                        'childLevel' => $childLevel
                    ];
                }
            }

            $data = ['type' => 'categories', 'list' => $arrayData];
        }

        return $data;
    }

    /**
     * Url for Back link
     *
     * @return string
     * @throws NoSuchEntityException
     */
    public function getBackUrl()
    {
        $paramCategory = $this->request->getParam('cat', '');

        if ($paramCategory != '') {
            $paramCategory = explode('-', $paramCategory);
            array_pop($paramCategory);
            $paramCategory = implode('-', $paramCategory);
            return $this->getDirectUrl('map', ['cat' => $paramCategory]);
        } else {
            return $this->storeManager->getStore()->getBaseUrl();
        }
    }

    /**
     * Label for Back link
     *
     * @return string
     */
    public function getBackLabel(): string
    {
        $paramCategory = $this->request->getParam('cat', '');
        if ($paramCategory != '') {
            return __('Back');
        } else {
            return __('To website');
        }
    }

    /**
     * @param $categories
     * @param $cId
     * @param $path
     * @return array
     */
    private function getChildLevel($categories, $cId, $path): array
    {
        $array = [];
        $path = $path . '/';

        foreach ($categories as $c) {
            if ($c->getData('is_active') && $c->getLevel() == 3) {
                if (strpos($c->getPath(), $path) !== false) {
                    $urlData = $this->_getCategoryItemUrl($c);
                    $array[$c->getData('entity_id')] = array(
                        'title' => $this->escapeHtml($c->getData('name')),
                        'id' => $c->getData('entity_id'),
                        'type' => $urlData['type'],
                        'level' => $c->getLevel(),
                        'url' => $urlData['url']
                    );
                }
            }
        }

        return $array;
    }

    /**
     * Get category item URL
     *
     * @param $category
     * @return array
     */
    protected function _getCategoryItemUrl($category)
    {
        $paramCategory = '';
        $paramCategory = $this->request->getParam('cat', '');

        if ($paramCategory != '') {
            $paramCategory = $paramCategory . '-';
        }

        $children_count = 0;

        $products = $this->productCollectionFactory->create()->addCategoryFilter($category);

        $products->addAttributeToFilter('status', ['in' => $this->productStatus->getVisibleStatusIds()]);
        $products->setVisibility($this->productVisibility->getVisibleInSiteIds());

        $this->stockHelper->addInStockFilterToCollection($products);


        $product_count = count($products);
        unset($products);

        if ($category->getChildrenCount() && $category->getChildrenCount() != 0) {
            $children_count = $category->getChildrenCount();
        }

        /**
         *
         */
        if ($category->getData('display_mode') == 'PAGE') {
            return ['type' => 'current', 'url' => $category->getUrl()];
        } elseif ($product_count == 0 && $children_count == 0) {
            return ['type' => 'current', 'url' => $category->getUrl()];
        } elseif ($product_count >= 0 && $children_count > 0) {
            return ['type' => 'map', 'url' => $this->getDirectUrl('map', array('cat' => $paramCategory . $category->getId()))];
        } else {
            return ['type' => 'map', 'url' => $this->getDirectUrl('map', array('cat' => $paramCategory . $category->getId()))];
        }
    }

    /**
     * @param $path
     * @param $params
     * @return string
     */
    private function getDirectUrl($path, $params): string
    {
        $urlParams = '';
        if (isset($params['cat']) && $params['cat'] != '') {
            $urlParams = '/cat/' . $params['cat'];
        }

        return $this->url->getDirectUrl($path, []) . $urlParams;
    }

}