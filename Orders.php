<?php

namespace trus2_Trustpilot_Reviews;

use Shopware\Models\Order\Order;
use Doctrine\ORM\AbstractQuery;

class Orders
{
    private $articleRepository = null;

    public function __construct()
    {
        $this->config = \TrustpilotConfig::getInstance();
        $this->articleRepository = Shopware()->Container()->get('models')->getRepository('Shopware\Models\Article\Article');
    }

    public function getInvitation($order, $hook,  $collect_product_data = WITH_PRODUCT_DATA)
    {
        $invitation = array();
        $invitation['recipientEmail'] = $order->getCustomer()->getEmail();
        if (!empty($order->getBilling()) && !empty($order->getBilling()->getFirstName())) {
            $invitation['recipientName'] = $order->getBilling()->getFirstName() . ' ' . $order->getBilling()->getLastName();
        } else {
            $invitation['recipientName'] = $order->getCustomer()->getFirstName() . ' ' . $order->getCustomer()->getLastName();
        }
        $invitation['referenceId'] = $order->getNumber();
        $invitation['source'] = 'Shopware-' . Shopware()->Config()->version;
        $invitation['pluginVersion'] = $this->config->version;
        $invitation['hook'] = $hook;
        $invitation['orderStatusId'] = $order->getOrderStatus()->getId();
        $invitation['orderStatusName'] = $order->getOrderStatus()->getName();
        $invitation['templateParams'] = array($order->getShop()->getId());
        if ($collect_product_data == WITH_PRODUCT_DATA) {
            $products = $this->getProducts($order);
            $invitation['products'] = $products;
            $invitation['productSkus'] = $this->getSkus($products);
        }
        return $invitation;
    }

    private function getProducts($order)
    {
        $products = array();
        try {
            $settings = $this->config->getConfig('master_settings');
            $skuSelector = $settings->skuSelector;
            $gtinSelector = $settings->gtinSelector;
            $mpnSelector = $settings->mpnSelector;
            foreach ($order->getDetails() as $detail) {
                if ($detail->getMode() === 0) {
                    $product_data = array();
                    $product_data['name'] = $detail->getArticleName();
                    $product_data['productId'] = $detail->getArticleId();
                    $product_data['price'] = $detail->getPrice() * $detail->getQuantity();
                    $product_data['productUrl'] = 
                        $this->getDomainName('//' . $order->getShop()->getHost() . $order->getShop()->getBasePath()) . '/detail/index/sArticle/' . $detail->getArticleId();
                    $article = $this->getArticleWithImages($detail->getArticleId());
                    $imageUrls = $this->getArticleImagesUrls($article);
                    $product_data['imageUrl'] = isset($imageUrls[0]) ? $imageUrls[0] : null;
                    $product_data['images'] = $imageUrls;
                    $product_data['videos'] = null;
                    $manufacturer = $this->getManufacturer($article);
                    $product_data['brand'] = $manufacturer;
                    $product_data['manufacturer'] = $manufacturer;
                    $product_data['categories'] = $this->getArticleCategories($detail->getArticleId());
                    $articleObj = $this->articleRepository
                        ->getArticleBaseDataQuery($detail->getArticleId())
                        ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
                    $articleDetail = $articleObj->getMainDetail();
                    $product_data['sku'] = $this->getInventoryAttribute($skuSelector, $articleDetail);
                    $product_data['gtin'] = $this->getInventoryAttribute($gtinSelector, $articleDetail);
                    $product_data['mpn'] = $this->getInventoryAttribute($mpnSelector, $articleDetail);
                    $product_data['meta'] = $this->getArticleMeta($detail->getArticleId());
                    $product_data['tags'] = null;
                    $product_data['currency'] = $order->getCurrency();
                    $product_data['description'] = $this->stripAllTags($article['descriptionLong'], true);
                    array_push($products, $product_data);
                }
            }
        } catch (\Throwable $e) {
            $message = 'Unable to get products.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
        } catch (\Exception $e) {
            $message = 'Unable to get products.';
            Shopware()->Container()->get('pluginlogger')->error($e, ['message' => $message]);
        }
        return $products;
    }

    public function getInventoryAttribute($attribute, $articleDetail) {
        switch ($attribute) {
            case 'number':
                return $articleDetail->getNumber();
            case 'ean':
                return $articleDetail->getEan();
            case 'supplierNumber':
                return $articleDetail->getSupplierNumber();
            case '':
            case 'none':
                return '';
            default:
                $attributeValues = Shopware()->Models()->toArray($articleDetail->getAttribute());
                return isset($attributeValues[$attribute]) ? $attributeValues[$attribute] : '';
        }
    }

    private function getManufacturer($article) {
        if (empty($article['supplierId'])) {
            return null;
        }
        $supplier = $this->articleRepository->getSupplierQuery($article['supplierId'])->getArrayResult();
        return isset($supplier[0]) ? $supplier[0]['name'] : null;
    }

    public function getArticleMeta($articleId)
    {
        $result = $this->articleRepository
                ->getArticleBaseDataQuery($articleId)
                ->getArrayResult();

        
        if (!empty($result[0])) {
            $meta = array(
                'title' => $result[0]['metaTitle'] ?: $result[0]['name'],
                'keywords' => $result[0]['keywords'] ?: '',
                'description' => $result[0]['description'] ?: substr($this->stripAllTags($result[0]['descriptionLong'], true), 0, 255) ?: '',
            );

            return $meta;
        }

        return null;
    }

    public function getArticleCategories($articleId)
    {
        $result = $this->articleRepository
                ->getArticleCategoriesQuery($articleId)
                ->getArrayResult();
        
        if (!empty($result[0]) && !empty($result[0]['categories'])) {
            $categories = array();
            foreach ($result[0]['categories'] as $category) {
                if ($category['active']) {
                    array_push($categories, $category['name']);
                }
            }
            return $categories;
        }

        return array();
    }

    public function getArticleImagesUrls($article)
    {
        $mediaService = Shopware()->Container()->get('shopware_media.media_service');
        $result = isset($article['images']) ? $article['images'] : array();
        $images = array();
        foreach ($result as $item) {
            array_push($images, $mediaService->getUrl('media/image/' . $item['path'] . '.' . $item['extension']));
        }
        return $images;
    }

    public function sendInvitation(Order $order) {
        try {
            $config = \TrustpilotConfig::getInstance();
            $masterSettings = $config->getConfig('master_settings');

            if (isset($masterSettings->general->key)) {
                $httpClient = Shopware()->Container()->get('trustpilot.trustpilot_http_client');

                $invitation = $this->getInvitation($order, 'shopware_order_status_changed', WITHOUT_PRODUCT_DATA);
                $key = $masterSettings->general->key;
                $shop = $order->getShop();

                if (in_array($order->getOrderStatus()->getId(), $masterSettings->general->mappedInvitationTrigger)) {
                    $response = $httpClient->postInvitation($key, $shop, $invitation);
                    if ($response['code'] == 202) {
                        $invitation = $this->getInvitation($order, 'shopware_order_status_changed', WITH_PRODUCT_DATA);
                        $response = $httpClient->postInvitation($key, $shop, $invitation);
                    }

                    $this->handle_single_response($response, $invitation);
                } else {
                    $invitation['payloadType'] = 'OrderStatusUpdate';
                    $httpClient->postInvitation($key, $shop, $invitation);
                }
            }
        } catch (\Throwable $e) {
            Shopware()->Container()->get('pluginlogger')->error($e);
        } catch (\Exception $e) {
            Shopware()->Container()->get('pluginlogger')->error($e);
        }
    }

    private function handle_single_response($response, $order) {
        $config = \TrustpilotConfig::getInstance();
        try {
            $synced_orders = (int)$config->getConfig('past_orders', false);
            $failed_orders = json_decode($config->getConfig('failed_orders', false));
            if ($response['code'] == 201) {
                $this->updateConfigWithSql('past_orders', $synced_orders + 1, 'integer');
                if (isset($failed_orders->{$order['referenceId']})) {
                    unset($failed_orders->{$order['referenceId']});
                    $this->updateConfigWithSql('failed_orders', json_encode($failed_orders), 'string');
                }
            } else {
                $failed_orders->{$order['referenceId']} = base64_encode('Automatic invitation sending failed');
                $this->updateConfigWithSql('failed_orders', json_encode($failed_orders), 'string');
            }
        } catch (\Throwable $e) {
            Shopware()->Container()->get('pluginlogger')->error($e);
        } catch (\Exception $e) {
            Shopware()->Container()->get('pluginlogger')->error($e);
        }
    }

    private function updateConfigWithSql($field, $value, $type) {
        if ($type == 'string') {
            $sqlValue = 's:' . strlen($value) . ':"' . $value . '";';
        } else if ($type == 'integer') {
            $sqlValue = 'i:' . $value . ';';
        }
        $sql = 'UPDATE s_core_config_values val LEFT JOIN s_core_config_elements elem ON val.element_id = elem.id SET val.value=? WHERE elem.name=?';
        return Shopware()->Db()->query($sql, [
                $sqlValue,
                $field,
            ]
        );
    }

    private function getArticleWithImages($articleId)
    {
        $result = $this->articleRepository
                ->getArticleWithImagesQuery($articleId)
                ->getArrayResult();
        if (empty($result[0])) {
            return array();
        } else {
            return $result[0];
        }

        return array();
    }

    private function getDomainName($base_url)
    {
        $protocol = (!empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off' ||
            $_SERVER['SERVER_PORT'] == 443) ? "https:" : "http:";
        $domainName = $protocol . $base_url;
        return $domainName;
    }

    private function getSkus($products) {
        $skus = array();
        foreach ($products as $product) {
            $sku = isset($product['sku']) ? $product['sku'] : '';
            array_push($skus, $sku);
        }
        return $skus;
    }

    public function loadCategoryProductInfo($settings, $products, $shop) {
        $skuSelector = $settings->skuSelector;
        $productList = $variationSkus = $variationIds = array();

        try {
            foreach ($products as $product) {
                $productId = is_array($product) ? $product['articleID'] : $product->getId();
                $article = $this->articleRepository
                    ->getArticleBaseDataQuery($productId)
                    ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
                $sku = $skuSelector != 'id' ? $article->getId() : $this->getInventoryAttribute($skuSelector, $article->getMainDetail());
                $id = $article->getId();

                array_push($productList, array(
                    "sku" => $sku,
                    "id" => $id,
                    "variationIds" => $variationIds,
                    "variationSkus" => $variationSkus,
                    "productUrl" => $this->getDomainName('//' . $shop->getHost() . $shop->getPath()) . '/detail/index/sArticle/' . $article->getId(),
                    "name" => $article->getName(),
                ));
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
        return $productList;
    }

    // Same as in WordPress
    private function stripAllTags($string, $remove_breaks = false) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
        $string = strip_tags( $string );

        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }

        return trim( $string );
    }
}
