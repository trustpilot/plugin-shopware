<?php

use Shopware\Components\CSRFWhitelistAware;
use Shopware\Components\Plugin;

class Shopware_Controllers_Backend_TrustpilotModule extends Enlight_Controller_Action implements CSRFWhitelistAware
{
    public function preDispatch()
    {
        $this->get('template')->addTemplateDir(__DIR__ . '/../../Resources/views/');
    }

    public function postDispatch()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->dispatchGet();
        } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->dispatchPost();
        }
    }

    public function indexAction()
    {
    }

    public function getWhitelistedCSRFActions()
    {
        return ['index'];
    }

    private function getCategoryPath($id)
    {
        $query = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
        $query->select(['category.path'])
            ->from('s_categories', 'category')
            ->where('category.id = :id')
            ->setParameter(':id', $id);

        $statement = $query->execute();

        $path = $statement->fetch(\PDO::FETCH_COLUMN);

        $ids = [$id];

        if (!$path) {
            return $ids;
        }

        $pathIds = explode('|', $path);

        return array_filter(array_merge($ids, $pathIds));
    }

    private function getCategoryIdsWithParent($ids, $shopId)
    {
        $query = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
        $query->select(['category.id', 'category.parent']);

        $query->from('s_categories', 'category')
            ->where('(category.parent IN( :parentId ) OR category.id IN ( :parentId ))')
            ->andWhere('category.active = 1')

            ->orderBy('category.position', 'ASC')
            ->addOrderBy('category.id')
            ->setParameter(':parentId', $ids, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
            ->setParameter(':shopId', '%|' . $shopId . '|%');
        $statement = $query->execute();
        return $statement->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private function getCategoryChildren($category)
    {
        if ($category->isLeaf()) {
            return $category;
        } else {
            return $this->getCategoryChildren($category->getChildren()[0]);
        }
    }

    private function getCategoryUrl($shopId) {
        $contextService = Shopware()->Container()->get('shopware_storefront.context_service');
        $categoryClass = Shopware()->Models()->getRepository(\Shopware\Models\Category\Category::class);
        $categoryService = Shopware()->Container()->get('shopware_storefront.category_service');
        $baseFile = Shopware()->Container()->get('config')->get('baseFile');

        $categoryId = $this->getCategoryChildren($categoryClass->findOneBy(['active' => true, 'blog' => false]))->getId();
        $pathIds = $this->getCategoryPath($categoryId);
        $grouped = $this->getCategoryIdsWithParent($pathIds, $shopId);
        $ids = array_merge($pathIds, array_keys($grouped));
        $context = $contextService->createShopContext($shopId);
        $categories = $categoryService->getList($ids, $context);
        $category = $categories[$categoryId];
        $baseUrl = $baseFile . '?sViewport=cat&sCategory=';
        return $category->getExternalLink() ?: $baseUrl . $category->getId();
    }

    private function getProductUrl($shopId, $shop) {
        $categoryClass = Shopware()->Models()->getRepository(\Shopware\Models\Category\Category::class);
        $contextService = Shopware()->Container()->get('shopware_storefront.context_service');
        $context = $contextService->createShopContext($shopId);
        $service = Shopware()->Container()->get('shopware_search.product_number_search');

        $sCategoryID = $this->getCategoryChildren($categoryClass->findOneBy(['active' => true, 'blog' => false]))->getId();

        $criteria = new \Shopware\Bundle\SearchBundle\Criteria();
        $criteria->limit(1);
        $criteria->addCondition(new \Shopware\Bundle\SearchBundle\Condition\CategoryCondition([$sCategoryID]));

        $result = $service->search( $criteria, $context);

        $productId = 1;
        foreach($result->getProducts() as $product) {
            $productId = $product->getId();
        }

        return 'detail/index/sArticle/' . $productId;
    }

    private function getPageUrls($host, $shopId, $shop, $config) {
        $pageUrls = new \stdClass();
        $mainShopLink = '__shop=' . $shopId;
        $pageUrls->landing = $host . '?' . $mainShopLink;
        $pageUrls->category = $host . '/' . $this->getCategoryUrl($shopId) . '&' . $mainShopLink;
        $pageUrls->product = $host . '/' . $this->getProductUrl($shopId, $shop) . '?' . $mainShopLink;
        $urls = json_decode($config->getConfig('page_urls', false));
        $pageUrls = (object) array_merge((array) $urls, (array) $pageUrls);
        return base64_encode(json_encode($pageUrls));
    }

    private function dispatchGet() {
        $repository = Shopware()->Container()->get('models')->getRepository('Shopware\Models\Shop\Shop');
        $shopId = $repository->findOneBy(['active' => true])->getId();
        $shop = $repository->getById($shopId);

        $config = TrustpilotConfig::getInstance();
        $csrfToken = $this->container->get('BackendSession')->offsetGet('X-CSRF-Token');
        $host = $this->revomeProtocol($shop->getHost());


        $masterSettings = $config->getConfig('master_settings');
        $customTrustboxes = $config->getConfig('custom_trustboxes');
        $this->View()->assign(array(
            'csrfToken' => $csrfToken,
            'version' => \Shopware()->Config()->get('Version'),
            'pluginVersion' => $config->version,
            'settings' => base64_encode(json_encode($masterSettings)),
            'pastOrders' => $this->getPastOrdersInfo(),
            'integrationAppUrl' => $this->getDomainName($config->integration_app_url),
            'pageUrls' => $this->getPageUrls($this->getDomainName('//' . $host), $shopId, $shop, $config),
            'productIdentificationOptions' => json_encode($masterSettings->productIdentificationOptions),
            'isFromMarketplace' => $config->is_from_marketplace,
            'trustBoxPreviewUrl' => $config->trustbox_preview_url,
            'configurationScopeTree' => base64_encode(json_encode($this->getConfigurationScopeTree())),
            'ajaxUrl' => '/backend/TrustpilotModule',
            'shop' => $host,
            'customTrustBoxes' => $customTrustboxes,
        ));
    }

    private function dispatchPost() {
        $request = $this->Request();
        $config = TrustpilotConfig::getInstance();

        try {
            if ($request->has('type') && $request->getParam('type') == 'handle_save_changes') {
                if ($request->has('settings')) {
                    $post_settings = htmlspecialchars_decode($request->getParam('settings'));
                    $config->writeConfig('master_settings', $post_settings);
                    $this->createResponse($post_settings);
                }
                if ($request->has('pageUrls')) {
                    $pageUrls = htmlspecialchars_decode($request->getParam('pageUrls'));
                    $config->writeConfig('page_urls', $pageUrls);
                    die(json_encode($pageUrls));
                }
                if ($request->has('customTrustBoxes')) {
                    $customTrustBoxes = htmlspecialchars_decode($request->getParam('customTrustBoxes'));
                    $config->writeConfig('custom_trustboxes', $customTrustBoxes);
                    die(json_encode($customTrustBoxes));
                }
                return;
            }
            $pastOrders = $this->container->get('trustpilot.past_orders');
            if ($request->has('type') && $request->getParam('type') == 'handle_past_orders') {
                if ($request->has('sync')) {
                    $pastOrders->sync($request->getParam('sync'));
                    $output = $pastOrders->getPastOrdersInfo();
                    $output['pastOrders']['showInitial'] = false;
                    $this->createResponse($output, 'plugin');
                }
                if ($request->has('resync')) {
                    $pastOrders->resync();
                    $output = $pastOrders->getPastOrdersInfo();
                    $this->createResponse($output, 'plugin');
                }
                if ($request->has('issynced')) {
                    $output = $pastOrders->getPastOrdersInfo();
                    $this->createResponse($output, 'plugin');
                }
                if ($request->has('showPastOrdersInitial')) {
                    $config->writeConfig('show_past_orders_initial', $request->getParam('showPastOrdersInitial'));
                    die();
                }
                if ($request->has('showTotalOrders')) {
                    $total = (int)$pastOrders->getTotalOrdersCount($request->getParam('showTotalOrders'));
                    $config->writeConfig('total_orders', $total);
                    $response = array(
                        'pastOrders' => array(
                            'total' => $total,
                        )
                    );
                    $this->createResponse($response, 'plugin');
                }
            }
        } catch (Exception $e) {
            $message = 'ajax: Failed to process request. Error: ' . $e->getMessage();
            $output = array(
                'error' => $message,
            );
            $this->createResponse($output, 'plugin');
            return;
        }
    }

    private function createResponse($output = null, $basis = null) {
        if (isset($output)) {
            if (isset($basis)) {
                $output['basis'] = $basis;
            }
            die(json_encode($output));
        }
    }

    private function getDomainName($base_url)
    {
        $protocol = (!empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off' ||
            $_SERVER['SERVER_PORT'] == 443) ? "https:" : "http:";
        $domainName = $protocol . $base_url;
        return $domainName;
    }

    private function revomeProtocol($url) {
        $disallowed = array('http://', 'https://');
        foreach($disallowed as $d) {
           if(strpos($url, $d) === 0) {
              return str_replace($d, '', $url);
           }
        }
        return $url;
    }

    private function getPastOrdersInfo() {
        $info = $this->container->get('trustpilot.past_orders')->getPastOrdersInfo();
        $info['basis'] = 'plugin';
        return json_encode($info);
    }

    public function getConfigurationScopeTree() {
        $repository = Shopware()->Container()->get('models')->getRepository('Shopware\Models\Shop\Shop');
        $shops = $repository->getActiveShops();
        $configurationScopeTree = array();
        foreach ($shops as $shop) {
            $names = array(
                'site' => '',
                'store' => $shop->getName(),
                'view' => ''
            );
            $config = array(
                'ids' => [$shop->getId()],
                'names' => $names,
                'domain' => $shop->getHost() ? $shop->getHost() : ($shop->getMain() ? $shop->getMain()->getHost() : null),
            );
            array_push($configurationScopeTree,  $config);
        }
        return $configurationScopeTree;
    }
}
