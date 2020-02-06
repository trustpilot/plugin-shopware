<?php

use Shopware\Components\CSRFWhitelistAware;
use Shopware\Components\Plugin;
use Shopware\Components\CacheManager;
use Shopware\Models\Shop\Shop;
use Shopware\Models\Category\Category;

class Shopware_Controllers_Backend_TrustpilotModule extends Enlight_Controller_Action implements CSRFWhitelistAware
{
    protected $cacheManager;

    public function preDispatch()
    {
        $this->cacheManager = $this->get('shopware.cache_manager');
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

    private function getCategoryChildren($category)
    {
        if ($category['childrenCount'] == 0 && !$category['blog'] && !$category['external'] && $category['articleCount'] > 0) {
            return $category;
        } else {
            foreach ($category['sub'] as $sub) {
                $child = $this->getCategoryChildren($sub);
                if ($child) {
                    return $child;
                }
            }
        }
    }

    private function getFirstCategory($shopId) {
        $categoryRepo = Shopware()->Models()->getRepository('\Shopware\Models\Category\Category');
        $shopRepo = Shopware()->Container()->get('models')->getRepository('Shopware\Models\Shop\Shop');

        $shop = $shopRepo->getById($shopId);
        $categories = $categoryRepo->getActiveChildrenTree($shop->getCategory()->getId());

        foreach ($categories as $category) {
            $category = $this->getCategoryChildren($category);
            if ($category) {
                $categoryId = $category['id'];
                $category = $categoryRepo->find($categoryId);
                return $category;
            }
        }
        return null;
    }

    private function getCategoryUrl($shopId) {
        try {
            $baseFile = Shopware()->Container()->get('config')->get('baseFile');
            $baseUrl = $baseFile . '?sViewport=cat&sCategory=';

            $category = $this->getFirstCategory($shopId);
            if ($category === null) {
                return;
            }
            else {
                return $baseUrl . $category->getId();
            }
        } catch (\Throwable $e) {
            $message = 'Unable to get Category url.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
            return;
        } catch (\Exception $e) {
            $message = 'Unable to get Category url.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
            return;
        }
    }

    private function getProductUrl($shopId) {
        try {
            $query = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
            $query->select(['articles.id'])
                ->from('s_articles', 'articles')
                ->innerJoin('articles', 's_articles_details', 'article_details', 'articles.id = article_details.articleID')
                ->innerJoin('articles', 's_articles_categories', 'article_categories', 'articles.id = article_categories.articleID')
                ->innerJoin('article_categories', 's_categories', 'categories', 'article_categories.categoryID = categories.id')
                ->where('articles.active = 1')
                ->andWhere('articles.available_to is NULL')
                ->andWhere('article_details.kind = 1')
                ->andWhere('article_details.active = 1')
                ->andWhere('categories.active = 1')
                ->andWhere('categories.active = 1')
                ->andWhere('categories.blog = 0')
                ->addOrderBy('articles.id')
                ->setMaxResults(1);
            $result = $query->execute()->fetchAll(\PDO::FETCH_COLUMN);
            foreach($result as $productId) {
                return 'detail/index/sArticle/' . $productId;
            }
        } catch (\Throwable $e) {
            $message = 'Unable to get Product url.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
        } catch (\Exception $e) {
            $message = 'Unable to get Product url.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
        }
        return $this->getProductUrl2($shopId); // plan B if we fail to find with db query
    }

    private function getProductUrl2($shopId) {
        try {
            $contextService = Shopware()->Container()->get('shopware_storefront.context_service');
            $context = $contextService->createShopContext($shopId);

            $criteria = new \Shopware\Bundle\SearchBundle\Criteria();
            $criteria->limit(1);

            $service = Shopware()->Container()->get('shopware_search.product_number_search');
            $result = $service->search($criteria, $context);

            $productId = 1;
            foreach($result->getProducts() as $product) {
                $productId = $product->getId();
            }

            return 'detail/index/sArticle/' . $productId;
        } catch (\Throwable $e) {
            $message = 'Unable to get Product url.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
            return;
        } catch (\Exception $e) {
            $message = 'Unable to get Product url.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
            return;
        }
    }

    private function getPageUrls($host, $shopId, $config) {
        $pageUrls = new \stdClass();
        $mainShopLink = '__shop=' . $shopId;
        $pageUrls->landing = $host . '?' . $mainShopLink;
        $pageUrls->category = $host . '/' . $this->getCategoryUrl($shopId) . '&' . $mainShopLink;
        $pageUrls->product = $host . '/' . $this->getProductUrl($shopId) . '?' . $mainShopLink;
        $urls = json_decode($config->getConfig('page_urls', false));
        $pageUrls = (object) array_merge((array) $urls, (array) $pageUrls);
        return base64_encode(json_encode($pageUrls));
    }

    private function getLocale() {
        $locale = \Shopware()->Container()->get('auth')->getIdentity()->locale;
        $code = $locale->getLocale();
        return str_replace('_', '-', $code);
    }

    private function dispatchGet() {
        $repository = Shopware()->Container()->get('models')->getRepository('Shopware\Models\Shop\Shop');
        $shopId = $repository->findOneBy(['active' => true])->getId();
        $shop = $repository->getById($shopId);

        $config = TrustpilotConfig::getInstance();
        $csrfToken = $this->container->get('BackendSession')->offsetGet('X-CSRF-Token');
        $host = $this->revomeProtocol($shop->getHost());
        $base = $shop->getBasePath();

        $masterSettings = $config->getConfig('master_settings');
        $customTrustboxes = $config->getConfig('custom_trustboxes');
        $pluginStatus = $config->getConfig('plugin_status', false);
        $this->View()->assign(array(
            'csrfToken' => $csrfToken,
            'version' => \Shopware()->Config()->get('Version'),
            'pluginVersion' => $config->version,
            'settings' => base64_encode(json_encode($masterSettings)),
            'pastOrders' => $this->getPastOrdersInfo(),
            'integrationAppUrl' => $this->getDomainName($config->integration_app_url),
            'pageUrls' => $this->getPageUrls($this->getDomainName('//' . $host . $base), $shopId, $config),
            'productIdentificationOptions' => $this->getProductIdentificationOptions(),
            'isFromMarketplace' => $config->is_from_marketplace,
            'configurationScopeTree' => base64_encode(json_encode($this->getConfigurationScopeTree())),
            'ajaxUrl' => $base . '/backend/TrustpilotModule',
            'shop' => $host . $base,
            'customTrustBoxes' => $customTrustboxes,
            'pluginStatus' => base64_encode($pluginStatus),
            'locale' => $this->getLocale(),
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
                    $this->clearCache();
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
                return;
            }
            if ($request->has('type') && $request->getParam('type') == 'get_category_product_info') {
                $result = new stdClass();
                $result->categoryProductsData = $this->loadFirstCategoryProductInfo();
                $this->createResponse($result);
                return;
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

    private function clearCache() {
        $this->cacheManager->clearHttpCache();
        $this->cacheManager->clearTemplateCache();
        $this->cacheManager->clearConfigCache();
        $this->cacheManager->clearSearchCache();
        $this->cacheManager->clearProxyCache();
    }

    public function getProductIdentificationOptions() {
        $fields = array('none', 'number', 'ean', 'supplierNumber');
        $dynamicFields = array('sku', 'mpn', 'gtin');

        try {
            $service = Shopware()->Container()->get('shopware_attribute.crud_service');
            $attributes = $service->getList('s_articles_attributes');

            $attrs = array_map(function ($t) { return $t->getColumnName(); }, $attributes);
            foreach ($attrs as $attr) {
                foreach ($dynamicFields as $field) {
                    if (stripos($attr, $field) !== false) {
                        array_push($fields, $attr);
                    }
                }
            }
        } catch (\Throwable $e) {
            $message = 'Unable to get product identification options.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
        } catch (\Exception $e) {
            $message = 'Unable to get product identification options.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
        }

        return json_encode($fields);
    }

    private function loadFirstCategoryProductInfo() {
        try {
            $config = TrustpilotConfig::getInstance();
            $masterSettings = $config->getConfig('master_settings');
            $trustpilot_orders = $this->container->get('trustpilot.orders');

            $shopRepository = $this->get('models')->getRepository(Shop::class);
            $shop = $shopRepository->getActiveByRequest($this->Request());

            $category = $this->getFirstCategory($shop->getId());

            if ($category === null) {
                return array();
            } else {
                return $trustpilot_orders->loadCategoryProductInfo($masterSettings, $category->getArticles()->toArray(), $shop);
            }
        } catch (\Throwable $e) {
            $message = 'Unable to get Category products info.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
            return;
        } catch (\Exception $e) {
            $message = 'Unable to get Category products info.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
            return;
        }
    }
}
