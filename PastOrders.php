<?php

namespace trus2_Trustpilot_Reviews;

use Doctrine\DBAL\Connection;
include_once TP_PATH_ROOT . '/TrustpilotConfig.php';
define('WITH_PRODUCT_DATA', 'WITH_PRODUCT_DATA');
define('WITHOUT_PRODUCT_DATA', 'WITHOUT_PRODUCT_DATA');
define('PAST_ORDER_STATUSES', array(2, 7));
define('BATCH_SIZE', 20);

class PastOrders
{

    public function __construct()
    {
        $this->container = Shopware()->Container();
        $this->config = \TrustpilotConfig::getInstance();
        $this->trustpilot_api = $this->container->get('trustpilot.trustpilot_http_client');
    }

    public function sync($period_in_days) {
        $this->config->writeConfig('sync_in_progress', 'true');
        $this->config->writeConfig('show_past_orders_initial', 'false');
        try {
            $masterSettings = $this->config->getConfig('master_settings');
            $key = $masterSettings->general->key;
            $collect_product_data = WITHOUT_PRODUCT_DATA;
            if (!is_null($key)) {
                $this->config->writeConfig('past_orders', 0);
                $pageId = 0;
                $post_batch = $this->getOrdersForPeriod($period_in_days, $pageId);
                while ($post_batch) {
                    set_time_limit(30);
                    $batch = null;
                    if (!is_null($post_batch)) {
                        $batch['invitations'] = $post_batch;
                        $batch['type'] = $collect_product_data;
                        $response = $this->trustpilot_api->postBatchInvitations($key, $batch);
                        $code = $this->handleTrustpilotResponse($response, $batch);
                        if ($code == 202) {
                            $collect_product_data = WITH_PRODUCT_DATA;
                            $batch['invitations'] = $this->getOrdersForPeriod($period_in_days, $pageId);
                            $batch['type'] = $collect_product_data;
                            $response = $this->trustpilot_api->postBatchInvitations($key, $batch);
                            $this->handleTrustpilotResponse($response, $batch);
                        }
                        if ($code < 200 || $code > 202) {
                            $this->config->writeConfig('show_past_orders_initial', 'true');
                            $this->config->writeConfig('sync_in_progress', 'false');
                            $this->config->writeConfig('past_orders', 0);
                            $this->config->writeConfig('failed_orders', '{}');
                        }
                    }
                    $pageId = $pageId + 1;
                    $post_batch = $this->getOrdersForPeriod($period_in_days, $pageId);
                }
            }
        } catch (Exception $e) { }
        $this->config->writeConfig('sync_in_progress', 'false');
        $this->config->writeConfig('total_orders', 0);
    }

    public function resync() {
        $this->config->writeConfig('past_orders', 0);
        $this->config->writeConfig('total_orders', 0);
        $this->config->writeConfig('sync_in_progress', 'true');
        try {
            $masterSettings = $this->config->getConfig('master_settings');
            $failed_orders = json_decode($this->config->getConfig('failed_orders', false));
            $key = $masterSettings->general->key;
            $collect_product_data = WITHOUT_PRODUCT_DATA;
            if (!is_null($key)) {
                $failed_orders_array = array();
                foreach ($failed_orders as $id => $value) {
                    array_push($failed_orders_array, $id);
                }

                $this->config->writeConfig('failed_orders', '{}');
                $this->config->writeConfig('total_orders', count($failed_orders_array));
                $chunked_failed_orders = array_chunk($failed_orders_array, BATCH_SIZE, true);
                foreach ($chunked_failed_orders as $failed_orders_chunk) {
                    set_time_limit(30);
                    $post_batch = $this->trustpilotGetOrdersByIds($collect_product_data, $failed_orders_chunk);

                    $batch = null;
                    $batch['invitations'] = $post_batch;
                    $batch['type'] = $collect_product_data;
                    $response = $this->trustpilot_api->postBatchInvitations($key, $batch);
                    $code = $this->handleTrustpilotResponse($response, $batch);

                    if ($code == 202) {
                        $collect_product_data = WITH_PRODUCT_DATA;
                        $batch['invitations'] = $this->trustpilotGetOrdersByIds($collect_product_data, $failed_orders_chunk);
                        $batch['type'] = $collect_product_data;
                        $response = $this->trustpilot_api->postBatchInvitations($key, $batch);
                        $code = $this->handleTrustpilotResponse($response, $batch);
                    }
                    if ($code < 200 || $code > 202) {
                        $this->config->writeConfig('sync_in_progress', 'false');
                        return;
                    }
                }
            }
        } catch (Exception $e) { }
        $this->config->writeConfig('sync_in_progress', 'false');
        $this->config->writeConfig('total_orders', 0);
    }

    public function trustpilotGetOrdersByIds($collect_product_data, $failed_orders_chunk) {
        $builder = Shopware()->Models()->createQueryBuilder();
        $builder->select(array('orders', 'details', 'customer', 'billing', 'shipping'))
            ->from('Shopware\Models\Order\Order', 'orders')
            ->leftJoin('orders.details', 'details')
            ->leftJoin('orders.customer', 'customer')
            ->leftJoin('orders.billing', 'billing')
            ->leftJoin('orders.shipping', 'shipping')
            ->where('orders.number IN (:ids)')
            ->setParameter('ids', $failed_orders_chunk, Connection::PARAM_INT_ARRAY);
        $result = $builder->getQuery()->getResult();
        $invitations = array();
        $trustpilot_orders = $this->container->get('trustpilot.orders');
        foreach ($result as $order) {
            $invitation = $trustpilot_orders->getInvitation($order, 'past-orders');
            if (!is_null($invitation)) {
                array_push($invitations, $invitation);
            }
        }
        return $invitations;
    }

    public function getPastOrdersInfo() {
        $syncInProgress = $this->config->getConfig('sync_in_progress', false);
        $showInitial = $this->config->getConfig('show_past_orders_initial', false);
        $synced_orders = (int)$this->config->getConfig('past_orders', false);
        $total_orders = (int)$this->config->getConfig('total_orders', false);
        $failed_orders = json_decode($this->config->getConfig('failed_orders', false));

        if ($syncInProgress === 'false') {
            $failed_orders_result = array();
            foreach ($failed_orders as $key => $value) {
                $item = array(
                    'referenceId' => $key,
                    'error' => $value
                );
                array_push($failed_orders_result, $item);
            }

            return array(
                'pastOrders' => array(
                    'synced' => $synced_orders,
                    'unsynced' => count($failed_orders_result),
                    'failed' => $failed_orders_result,
                    'syncInProgress' => $syncInProgress === 'true',
                    'showInitial' => $showInitial === 'true',
                    'total' => $total_orders,
                )
            );
        } else {
            return array(
                'pastOrders' => array(
                    'syncInProgress' => $syncInProgress === 'true',
                    'showInitial' => $showInitial === 'true',
                    'total' => $total_orders,
                    'synced' => $synced_orders,
                    'unsynced' => count(array_keys((array)$failed_orders)),
                )
            );
        }
    }

    private function handleTrustpilotResponse($response, $post_batch) {
        $synced_orders = (int)$this->config->getConfig('past_orders', false);
        $failed_orders = json_decode($this->config->getConfig('failed_orders', false));

        // all succeeded
        if ($response['code'] == 201 && count($response['data']) == 0) {
            Shopware()->Container()->get('pluginlogger')->info('All succeded' . sizeof($post_batch['invitations']));
            $this->trustpilot_save_synced_orders($synced_orders, $post_batch['invitations']);
            $this->trustpilot_save_failed_orders($failed_orders, $post_batch['invitations']);
        }
        // all/some failed
        if ($response['code'] == 201 && count($response['data']) > 0) {
            $failed_order_ids = array_column($response['data'], 'referenceId');
            $succeeded_orders = array_filter($post_batch['invitations'], function ($invitation) use ($failed_order_ids)  {
                return !(in_array($invitation['referenceId'], $failed_order_ids));
            });
            $this->trustpilot_save_synced_orders($synced_orders, $succeeded_orders);
            $this->trustpilot_save_failed_orders($failed_orders, $succeeded_orders, $response['data']);
        }

        return $response['code'];
    }

    private function trustpilot_save_synced_orders($synced_orders, $new_orders) {
        if (sizeof($new_orders) > 0) {
            $newCount = $synced_orders + sizeof($new_orders);
            $this->config->writeConfig('past_orders', $newCount);
        }
    }

    private function trustpilot_save_failed_orders($failed_orders, $succeeded_orders, $new_failed_orders = array()) {
        $update_needed = false;
        if (count($succeeded_orders) > 0) {
            $update_needed = true;
            foreach ($succeeded_orders as $order) {
                if (isset($failed_orders->{$order['referenceId']})) {
                    unset($failed_orders->{$order['referenceId']});
                }
            }
        }

        if (count($new_failed_orders) > 0) {
            $update_needed = true;
            foreach ($new_failed_orders as $failed_order) {
                $failed_orders->{$failed_order->referenceId} = base64_encode($failed_order->error);
            }
        }

        if ($update_needed) {
            $this->config->writeConfig('failed_orders', json_encode($failed_orders));
        }
    }

    private function getOrdersForPeriod($periodInDays, $page = 0) {
        $builder = Shopware()->Models()->createQueryBuilder();
        $builder->select(array('orders', 'details', 'customer', 'billing', 'shipping'))
            ->from('Shopware\Models\Order\Order', 'orders')
            ->leftJoin('orders.details', 'details')
            ->leftJoin('orders.customer', 'customer')
            ->leftJoin('orders.billing', 'billing')
            ->leftJoin('orders.shipping', 'shipping')
            ->setFirstResult($page * BATCH_SIZE)
            ->setMaxResults(BATCH_SIZE)
            ->where('orders.status IN (:statuses)')
            ->setParameter('statuses', PAST_ORDER_STATUSES, Connection::PARAM_INT_ARRAY)
            ->andWhere('orders.orderTime > :minDatetime')
            ->setParameter('minDatetime', date("Y-m-d H:i:s", time() - (86400 * $periodInDays)))
            ->orderBy('details.number')
            ->groupBy('details.number');
        $result = $builder->getQuery()->getResult();
        $invitations = array();
        $trustpilot_orders = $this->container->get('trustpilot.orders');
        foreach ($result as $order) {
            $invitation = $trustpilot_orders->getInvitation($order, 'past-orders');
            if (!is_null($invitation)) {
                array_push($invitations, $invitation);
            }
        }
        return $invitations;
    }

    public function getTotalOrdersCount($periodInDays) {
        $builder = Shopware()->Models()->createQueryBuilder();

        $builder->select('COUNT(orders) as ordersCount')
            ->from('Shopware\Models\Order\Order', 'orders')
            ->where('orders.status IN (:statuses)')
            ->setParameter('statuses', PAST_ORDER_STATUSES, Connection::PARAM_INT_ARRAY)
            ->andWhere('orders.orderTime > :minDatetime')
            ->setParameter('minDatetime', date("Y-m-d H:i:s", time() - (86400 * $periodInDays)));

        return $builder->getQuery()->getSingleScalarResult();
    }
}
