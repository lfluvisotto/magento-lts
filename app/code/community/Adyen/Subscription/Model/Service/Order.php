<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Subscription module (https://www.adyen.com/)
 *
 * Copyright (c) 2015 H&O E-commerce specialists B.V. (http://www.h-o.nl/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>, H&O E-commerce specialists B.V. <info@h-o.nl>
 */

class Adyen_Subscription_Model_Service_Order
{
    /**
     * Create subscription(s) for given order.
     *
     * Order items that have the same term and term type are saved
     * in the same subscription.
     *
     * @param Mage_Sales_Model_Order $order
     * @return array
     */
    public function createSubscription(Mage_Sales_Model_Order $order)
    {
        Mage::dispatchEvent('adyen_subscription_order_createsubscription_before', array('order' => $order));

        $subscriptions = array();

        if ($order->getSubscriptionId()) {
            $msg = "Don't create subscription, since this order %s is created by a subscription";
            Mage::helper('adyen_subscription')->logSubscriptionCron(sprintf($msg, $order->getIncrementId()));
            return $subscriptions;
        }

        $productTerms = array();
        foreach ($order->getAllVisibleItems() as $orderItem) {
            /** @var Mage_Sales_Model_Order_Item $orderItem */

            /** @var Adyen_Subscription_Model_Product_Subscription $productSubscription */
            $productSubscription = $this->_getProductSubscription($orderItem);

            if (!$productSubscription) {
                Mage::helper('adyen_subscription')
                    ->logSubscriptionCron(sprintf("No subscription found for order %s with orderItem %s", $order->getIncrementId(), $orderItem->getSku()));
                continue;
            }

            $arrayKey = $productSubscription->getTerm().$productSubscription->getTermType();

            $productTerms[$arrayKey]['term'] = $productSubscription->getTerm();
            $productTerms[$arrayKey]['type'] = $productSubscription->getTermType();
            $productTerms[$arrayKey]['order_items'][] = $orderItem;
        }

        // Create a subscription for each term
        foreach ($productTerms as $productTerm) {
            $billingAgreement = $this->_getBillingAgreement($order);

            $stockId = $order->getStockId() ?: 1;

            $createdAt = $order->getScheduledAt() ?: $order->getCreatedAt();

            // Create subscription
            /** @var Adyen_Subscription_Model_Subscription $subscription */
            $subscription = Mage::getModel('adyen_subscription/subscription')
                ->setCreatedAt($createdAt)
                ->setStatus(Adyen_Subscription_Model_Subscription::STATUS_ACTIVE)
                ->setStockId($stockId)
                ->setCustomerId($order->getCustomerId())
                ->setCustomerName($order->getCustomerName())
                ->setOrderId($order->getId())
                ->setBillingAgreementId($billingAgreement ? $billingAgreement->getId(): null)
                ->setStoreId($order->getStoreId())
                ->setTerm($productTerm['term'])
                ->setTermType($productTerm['type'])
                ->setShippingMethod($order->getShippingMethod())
                ->setUpdatedAt(now());

            if (!$billingAgreement) {
                Mage::helper('adyen_subscription')
                    ->logSubscriptionCron(sprintf("No billing agreement could be found, subscription is created but with error"));

                // but set subscription directly to error
                $subscription->setErrorMessage(
                    Mage::helper('adyen_subscription')->__('No billing agreement found')
                );
                $subscription->setStatus($subscription::STATUS_SUBSCRIPTION_ERROR);
            }

            $subscription->save();

            $transactionItems = array();
            foreach ($productTerm['order_items'] as $orderItem) {
                /** @var Adyen_Subscription_Model_Product_Subscription $productSubscription */
                $productSubscription = $this->_getProductSubscription($orderItem);

                // Ordered qty is divided by product subscription qty to get 'real' ordered qty
                $qty = $orderItem->getQtyInvoiced() / $productSubscription->getQty();

                // Create subscription item
                /** @var Adyen_Subscription_Model_Subscription_Item $subscriptionItem */
                $subscriptionItem = Mage::getModel('adyen_subscription/subscription_item')
                    ->setSubscriptionId($subscription->getId())
                    ->setStatus(Adyen_Subscription_Model_Subscription_Item::STATUS_ACTIVE)
                    ->setProductId($orderItem->getProductId())
                    ->setProductOptions(serialize($orderItem->getProductOptions()))
                    ->setSku($orderItem->getSku())
                    ->setName($orderItem->getName())
                    ->setProductSubscriptionId($productSubscription->getId())
                    ->setLabel($productSubscription->getLabel())
                    ->setPrice($orderItem->getRowTotal() / $qty)
                    ->setPriceInclTax($orderItem->getRowTotalInclTax() / $qty)
                    ->setQty($qty)
                    ->setOnce(0)
                    // Currently not in use
//                    ->setMinBillingCycles($productSubscription->getMinBillingCycles())
//                    ->setMaxBillingCycles($productSubscription->getMaxBillingCycles())
                    ->setCreatedAt(now());

                Mage::dispatchEvent(
                    'adyen_subscription_order_createsubscription_add_item',
                    array('subscription' => $subscription, 'item' => $subscriptionItem)
                );

                $transactionItems[] = $subscriptionItem;
            }

            // Create subscription addresses
            $subscriptionBillingAddress = Mage::getModel('adyen_subscription/subscription_address')
                ->initAddress($subscription, $order->getBillingAddress())
                ->save();

            /**
             * Order with only virtual product(s) do(es) not have shipping address
             *
             * Check the order if it is virtual avoiding throw exception
             */
            if ($order->getIsNotVirtual()) {
                $subscriptionShippingAddress = Mage::getModel('adyen_subscription/subscription_address')
                    ->initAddress($subscription, $order->getShippingAddress())
                    ->save();
            }

            /** @var Mage_Sales_Model_Quote $quote */
            $quote = Mage::getModel('sales/quote')
                ->setStore($order->getStore())
                ->load($order->getQuoteId());

            $subscription->setActiveQuote($quote);
            $orderAdditional = $subscription->getOrderAdditional($order, true)->save();
            $quoteAdditional = $subscription->getActiveQuoteAdditional(true)
                ->setOrder($order);

            $scheduleDate = $subscription->calculateNextScheduleDate();
            $subscription->setScheduledAt($scheduleDate);

            $transaction = Mage::getModel('core/resource_transaction')
                ->addObject($subscription)
                ->addObject($orderAdditional)
                ->addObject($quoteAdditional);

            foreach ($transactionItems as $item) {
                $transaction->addObject($item);
            }

            $transaction->save();

            $subscriptions[] = $subscription;

            Mage::dispatchEvent(
                'adyen_subscription_order_createsubscription_after',
                array('order' => $order, 'subscription' => $subscription)
            );
        }

        if(!empty($subscription) && $subscription->getId()) {
            $order->setCreatedAdyenSubscription(true);
        }

        return $subscriptions;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @return Mage_Sales_Model_Billing_Agreement
     */
    protected function _getBillingAgreement(Mage_Sales_Model_Order $order)
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');
        $select = $connection->select();

        $select->from($resource->getTableName('sales/billing_agreement_order'));
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns('agreement_id');
        $select->where('order_id = ?', $order->getId());

        $billingAgreementId = $connection->fetchOne($select);
        if (! $billingAgreementId) {
            Adyen_Subscription_Exception::logException(
                new Adyen_Subscription_Exception('Could not find billing agreement for order '.$order->getIncrementId())
            );
            return false;
        }

        $billingAgreement = Mage::getModel('sales/billing_agreement')->load($billingAgreementId);

        Mage::dispatchEvent(
            'adyen_subscription_order_getbillingagreement',
            array('billingAgreement' => $billingAgreement, 'order' => $order)
        );

        return $billingAgreement;
    }

    /**
     * @param Mage_Sales_Model_Order_Item $orderItem
     * @return Adyen_Subscription_Model_Product_Subscription
     */
    protected function _getProductSubscription(Mage_Sales_Model_Order_Item $orderItem)
    {
        $subscriptionId = $orderItem->getBuyRequest()->getData('adyen_subscription');
        if (! $subscriptionId) {
            return false;
        }

        $subscriptionProductSubscription = Mage::getModel('adyen_subscription/product_subscription')
            ->load($subscriptionId);

        if (!$subscriptionProductSubscription->getId()) {
            return false;
        }

        Mage::dispatchEvent(
            'adyen_subscription_order_getproductsubscription',
            array('productSubscription' => $subscriptionProductSubscription, 'item' => $orderItem)
        );

        return $subscriptionProductSubscription;
    }
}
