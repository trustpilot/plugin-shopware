<?php

namespace trus2_Trustpilot_Reviews;

class Orders
{
    public function getInvitation($order, $hook) {
        $config = \TrustpilotConfig::getInstance();
        $invitation = array();
        $invitation['recipientEmail'] = $order->getCustomer()->getEmail();
        $invitation['recipientName'] = $order->getBilling()->getFirstName() . ' ' . $order->getBilling()->getLastName();
        $invitation['referenceId'] = $order->getNumber();
        $invitation['source'] = 'Shopware-' . Shopware()->Config()->version;
        $invitation['pluginVersion'] = $config->version;
        $invitation['hook'] = $hook;
        $invitation['orderStatusId'] = $order->getOrderStatus()->getId();
        $invitation['orderStatusName'] = $order->getOrderStatus()->getName();
        $invitation['templateParams'] = array($order->getShop()->getId());
        return $invitation;
    }
}
