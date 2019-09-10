<?php
/**
 * Trustpilot
 * Copyright (c) Trustpilot
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace trus2_Trustpilot_Reviews;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;

if (!defined('TP_PATH_ROOT')) {
    define('TP_PATH_ROOT', dirname(__FILE__));
}

include_once TP_PATH_ROOT . '/TrustpilotConfig.php';

class trus2_Trustpilot_Reviews extends Plugin
{
    /**
     * Executed on install plugin
     *
     * @param InstallContext $context
     */
    public function install(InstallContext $context)
    {
        parent::install($context);
    }

    /**
     * Executed on uninstall plugin
     *
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context)
    {
        $context->scheduleClearCache(UninstallContext::CACHE_LIST_ALL);

        if($context->keepUserData()) {
            return;
        }

        parent::uninstall($context);
    }

    /**
     * Executed on activate plugin
     *
     * @param ActivateContext $context
     */
    public function activate(ActivateContext $context)
    {
        $context->scheduleClearCache(ActivateContext::CACHE_LIST_ALL);

        parent::activate($context);
    }

    /**
     * Executed on deactivate plugin
     *
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context)
    {
        $context->scheduleClearCache(DeactivateContext::CACHE_LIST_ALL);

        parent::deactivate($context);
    }

    /**
     * Add event to Shopware loading process
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Action_Frontend_Detail_index' => 'onCheckout',
            'Enlight_Controller_Dispatcher_ControllerPath_Backend_TrustpilotModule' => 'onGetBackendController',
            'Enlight_Controller_Action_PostDispatch_Frontend_Checkout' => 'onFrontendPostDispatch',
            'Enlight_Controller_Action_PreDispatch_Frontend' => ['onFrontend',-100],
            'Enlight_Controller_Action_PostDispatchSecure_Frontend' => 'onFrontend',
            'Enlight_Controller_Action_PostDispatch_Backend_Index' => 'onPostDispatchBackendIndex'
        ];
    }

    /**
     * @param \Enlight_Controller_ActionEventArgs $args
     */
    public function onPostDispatchBackendIndex(\Enlight_Controller_ActionEventArgs $args)
    {
        $request = $args->getRequest();
        $view = $args->getSubject()->View();

        $view->addTemplateDir($this->getPath() . '/Resources/views');

        // if the controller action name equals "index" we have to extend the backend article application
        if ($request->getActionName() === 'index') {
            $view->extendsTemplate('backend/trustpilot_module/menuitem.tpl');
        }
    }

    /**
     * Hook into template
     *
     * @param \Enlight_Event_EventArgs $args
     */

    public function onCheckout(\Enlight_Event_EventArgs $args)
    {
        $config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName($this->getName(), Shopware()->Shop());
        $view = $args->getSubject()->View();

        $view->addTemplateDir($this->getPath() . '/Resources/views');
        $view->assign('IntegratioKey', $config['IntegratioKey']);
        $view->assign('language_iso', 'en');
    }

    public function onFrontendPostDispatch(\Enlight_Event_EventArgs $args)
    {
        $controller = $args->getSubject();
        $view = $controller->View();

        $config = \TrustpilotConfig::getInstance();
        $masterSettings = $config->getConfig('master_settings');

        $sOrderVariables = Shopware()->Session()->sOrderVariables;

        $orders = Shopware()->Container()->get('trustpilot.orders');
        $order = Shopware()->Models()->getRepository('Shopware\Models\Order\Order')->findByNumber($sOrderVariables['sOrderNumber']);
        if (isset($order[0])){
            $order = $order[0];

            $invitation = $orders->getInvitation($order, 'shopware_thankyou');

            if (!in_array('trustpilotOrderConfirmed', $masterSettings->general->mappedInvitationTrigger)) {
                $invitation['payloadType'] = 'OrderStatusUpdate';
            }

            $this->sendBackendInvitation($masterSettings->general->key, $invitation);

            try {
                /**
                 * ROI data
                 */
                $invitation['totalCost'] = strval($order->getInvoiceAmount());
                $invitation['currency'] = $order->getCurrency();
            } catch (Exception $ex) {}
            $view->assign('order', json_encode($invitation));
        }
    }

    public function sendBackendInvitation($key, $invitation) {
        try {
            $httpClient = Shopware()->Container()->get('trustpilot.trustpilot_http_client');
            $invitation['hook'] = 'shopware_order_status_changed';
            $httpClient->postInvitation($key, $invitation);
        } catch (\Exception $ex) {}
    }

    /**
     * @return string
     */
    public function onGetBackendController()
    {
        return __DIR__ . '/Controllers/Backend/TrustpilotModule.php';
    }

    /**
     * @param \Enlight_Event_EventArgs $args
     * @throws \Exception
     */
    public function onFrontend(\Enlight_Event_EventArgs $args)
    {
        $config = \TrustpilotConfig::getInstance();
        $this->container->get('Template')->addTemplateDir(
            $this->getPath() . '/Resources/views/'
        );
        $masterSettings = $config->getConfig('master_settings');
        $view = $args->getSubject()->View();
        $view->assign(array(
            'previewShopwareUrl'=> $config->preview_shopware_url . '/js/header_bigcommerce.js',
            'widgetScriptUrl' => $config->widget_script_url,
            'page' => $this->getPageByController($args->getSubject()->Request()->getControllerName()),
            'integrationKey' => $masterSettings->general->key,
            'trustpilotTrustboxSettings' => json_encode($masterSettings->trustbox),
        ));
    }

    private function getPageByController($controllerName) {
        switch($controllerName) {
            case 'detail':
                return 'product';
            case 'listing':
                return 'category';
            case 'index':
                return 'landing';
            default:
                return '';
        }
    }
}
