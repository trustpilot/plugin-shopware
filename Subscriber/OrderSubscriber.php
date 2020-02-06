<?php

namespace trus2_Trustpilot_Reviews\Subscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Shopware\Models\Order\Order;

include_once TP_PATH_ROOT . '/TrustpilotConfig.php';

class OrderSubscriber implements EventSubscriber
{
    public function getSubscribedEvents()
    {
        return [
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

    public function orderStatusChange($order) {
        try {
            $orders = Shopware()->Container()->get('trustpilot.orders');
            $orders->sendInvitation($order);
        } catch (\Throwable $e) {
            Shopware()->Container()->get('pluginlogger')->error($e);
        } catch (\Exception $e) {
            Shopware()->Container()->get('pluginlogger')->error($e);
        }
    }
}
