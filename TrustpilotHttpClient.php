<?php

namespace trus2_Trustpilot_Reviews;

use Shopware;
use Shopware\Models\Shop\Shop;

include_once TP_PATH_ROOT . '/TrustpilotConfig.php';

class TrustpilotHttpClient
{
    private $config = null;
    private $pluginStatus = null;
    private $apiUrl = null;

    const HTTP_REQUEST_TIMEOUT = 3;

    public function __construct()
    {
        $this->pluginStatus = Shopware()->Container()->get('trustpilot.trustpilot_plugin_status');
        $this->config = \TrustpilotConfig::getInstance();
        $this->apiUrl = $this->config->apiUrl;
    }

    public function post($url, $data, $origin)
    {
        $httpRequest = "POST";

        $response = $this->request(
            $url,
            $httpRequest,
            $origin,
            $data
        );

        if ($response['code'] > 250 && $response['code'] < 254) {
            $this->pluginStatus->setPluginStatus($response);
        }

        return $response;
    }

    public function buildUrl($key, $endpoint)
    {
        return $this->apiUrl . $key . $endpoint;
    }

    public function checkStatusAndPost($url, $shop, $data)
    {
        if (isset($shop)) {
            $origin = ($shop->getSecure() ? 'https://' : 'http://') . $shop->getHost();
        } else {
            //TODO: this part used by past orders for now, but needs to be solved
            $repository = Shopware()->Container()->get('models')->getRepository('Shopware\Models\Shop\Shop');
            $shop = $repository->getActiveShops()[0];
            $origin = ($shop->getSecure() ? 'https://' : 'http://') . $shop->getHost();
        }

        $code = $this->pluginStatus->checkPluginStatus($origin);
        if ($code > 250 && $code < 254) {
            return array(
                'code' => $code,
            );
        }

        return $this->post($url, $data, $origin);
    }

    public function postInvitation($key, Shop $shop, $data = array())
    {
        return $this->checkStatusAndPost($this->buildUrl($key, '/invitation'), $shop, $data);
    }

    // TODO: get shop for past orders
    public function postBatchInvitations($key, $data = array(), Shop $shop = null)
    {
        return $this->checkStatusAndPost($this->buildUrl($key, '/batchinvitations'), $shop, $data);
    }

    public function request($url, $httpRequest, $origin, $data = null, $params = array(), $timeout = self::HTTP_REQUEST_TIMEOUT)
    {
        $ch = curl_init();
        $this->setCurlOptions($ch, $httpRequest, $data, $timeout, $origin);
        $url = $this->buildParams($url, $params);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($ch);
        $responseData = $this->jsonDecoder($content);
        $responseInfo = curl_getinfo($ch);
        $responseCode = $responseInfo['http_code'];
        curl_close($ch);
        $response = array();
        $response['code'] = $responseCode;
        if (is_object($responseData) || is_array($responseData)) {
            $response['data'] = $responseData;
        }
        return $response;
    }

    private function jsonEncoder($data)
    {
        if (function_exists('json_encode')) {
            return json_encode($data);
        } elseif (method_exists('Tools', 'jsonEncode')) {
            return \Tools::jsonEncode($data);
        }
    }

    private function jsonDecoder($data)
    {
        if (function_exists('json_decode')) {
            return json_decode($data);
        } elseif (method_exists('Tools', 'jsonDecode')) {
            return \Tools::jsonDecode($data);
        }
    }

    private function setCurlOptions($ch, $httpRequest, $data, $timeout, $origin)
    {
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($httpRequest == 'POST') {
            $encoded_data = $this->jsonEncoder($data);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'content-type: application/json',
                'Content-Length: ' . strlen($encoded_data),
                'Origin: ' . $origin,
            ));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded_data);
        } elseif ($httpRequest == 'GET') {
            curl_setopt($ch, CURLOPT_POST, false);
        }
    }

    private function buildParams($url, $params = array())
    {
        if (!empty($params) && is_array($params)) {
            $url .= '?'.http_build_query($params);
        }
        return $url;
    }
}
