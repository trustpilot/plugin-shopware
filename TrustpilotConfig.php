<?php
/**
 * Trustpilot
 * Copyright (c) Trustpilot
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

include_once TP_PATH_ROOT . '/globals.php';
use Shopware\Components\Plugin;

class TrustpilotConfig
{
    protected static $instance = null;

    public static function getInstance()
    {
        if (null == self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->settings_prefix        = 'tp_';
        $this->version                = TRUSTPILOT_PLUGIN_VERSION;
        $this->plugin_url             = TRUSTPILOT_PLUGIN_URL;
        $this->apiUrl                 = TRUSTPILOT_API_URL;
        $this->widget_script_url      = TRUSTPILOT_WIDGET_SCRIPT_URL;
        $this->integration_app_url    = TRUSTPILOT_INTEGRATION_APP_URL;
        $this->is_from_marketplace    = TRUSTPILOT_IS_FROM_MARKETPLACE;
        $this->preview_shopware_url   = TRUSTPILOT_PREVIEW_SHOPWARE_URL;
    }

    public function getConfig($field, $tryDecode = true) {
        $repository = Shopware()->Container()->get('models')->getRepository('Shopware\Models\Shop\Shop');
        $shop = $repository->findOneBy(['active' => true]);
        $settings = Shopware()->Container()->get('shopware.plugin.config_reader')->getByPluginName('Trustpilot', $shop);
        if (array_key_exists($field, $settings) && $settings[$field] !== '') {
            $setting = $settings[$field];
            return $tryDecode && $this->isJson($setting) ? json_decode($setting) : $setting;
        } else {
            return $this->getDefaultConfigValues($field);
        }
    }

    /**
     * Write value to the config
     *
     * @param $key
     * @param $value
     * @throws \Exception
     */
    public function writeConfig($key, $value) {
        try {
            $plugin = Shopware()->Container()->get('shopware.plugin_manager')->getPluginByName('Trustpilot');
            $modelManager = Shopware()->Container()->get('models');
            $shops = $modelManager->getRepository(\Shopware\Models\Shop\Shop::class)->findBy([]);
            $configWriter = new Plugin\ConfigWriter(Shopware()->Models());
            foreach ($shops as $shop) {
                $configWriter->saveConfigElement(
                    $plugin,
                    $key,
                    $value,
                    $shop
                );
            }
        }
        catch (\Exception $ex) {
            Shopware()->Container()->get('pluginlogger')->info($ex->getMessage());
        }
    }

    private function getDefaultConfigValues($field) {
        $config = array(
            'master_settings' => array(
                'general' => array(
                    'key' => '',
                    'invitationTrigger' => 'orderConfirmed',
                    'mappedInvitationTrigger' => array('0', '1', 'trustpilotOrderConfirmed'),
                ),
                'trustbox' => array('trustboxes' => array()),
                'skuSelector' => 'none',
                'mpnSelector' => 'none',
                'gtinSelector' => 'none',
                'pastOrderStatuses' => array(),
                'productIdentificationOptions' => array('none','sku','id'),
            ),
            'sync_in_progress' => 'false',
            'show_past_orders_initial' => 'true',
            'past_orders' => 0,
            'failed_orders' => '{}',
            'custom_trustboxes' => '{}',
            'page_urls' => '{}',
            'total_orders' => 0,
        );
        if (array_key_exists($field, $config)) {
            return $config[$field];
        } else {
            return false;
        }
    }

    private function isJson($string) {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }
}
