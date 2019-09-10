<?php

namespace trus2_Trustpilot_Reviews\Subscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Shopware\Models\Order\Order;

define('WITH_PRODUCT_DATA', 'WITH_PRODUCT_DATA');
define('WITHOUT_PRODUCT_DATA', 'WITHOUT_PRODUCT_DATA');

include_once TP_PATH_ROOT . '/TrustpilotConfig.php';

class OrderSubscriber implements EventSubscriber
{
    public function getSubscribedEvents()
    {
        return [
           Events::postPersist,
           Events::preUpdate,
       ];
    }

    public function preUpdate(PreUpdateEventArgs $args)
    {
        $order = $args->getEntity();
        if ($order instanceof Order) {
            Shopware()->Container()->get('pluginlogger')->info('Received event: OrderSubscriber.preUpdate for order ' . $order->getNumber());
            if ($args->hasChangedField('orderStatus')) {
                $this->orderStatusChange($order);
            }
        }
    }

    public function postPersist(LifecycleEventArgs $args)
    {
        $order = $args->getEntity();
        if ($order instanceof Order) {
            Shopware()->Container()->get('pluginlogger')->info('Received event: OrderSubscriber.postPersist for order ' . $order->getNumber());
            $this->orderStatusChange($order);
        }
    }

    public function orderStatusChange(Order $order) {
        $config = \TrustpilotConfig::getInstance();
        $masterSettings = $config->getConfig('master_settings');

        if (isset($masterSettings->general->key)) {
            $httpClient = Shopware()->Container()->get('trustpilot.trustpilot_http_client');
            $orders = Shopware()->Container()->get('trustpilot.orders');

            $invitation = $orders->getInvitation($order, 'shopware_order_status_changed');
            $key = $masterSettings->general->key;

            if (in_array($order->getOrderStatus()->getId(), $masterSettings->general->mappedInvitationTrigger)) {
                $httpClient->postInvitation($key, $invitation);
            } else {
                $invitation['payloadType'] = 'OrderStatusUpdate';
                $httpClient->postInvitation($key, $invitation);
            }
        }
    }
}
