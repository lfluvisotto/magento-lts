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

class Adyen_Subscription_Model_Service_Quote
{
    const ADDRESS_SOURCE_QUOTE = Adyen_Subscription_Model_Subscription_Address::ADDRESS_SOURCE_QUOTE;
    const ADDRESS_TYPE_SHIPPING = Adyen_Subscription_Model_Subscription_Address::ADDRESS_TYPE_SHIPPING;
    const ADDRESS_TYPE_BILLING = Adyen_Subscription_Model_Subscription_Address::ADDRESS_TYPE_BILLING;

    /**
     * @param Mage_Sales_Model_Quote     $quote
     * @param Adyen_Subscription_Model_Subscription $subscription
     *
     * @return Mage_Sales_Model_Order
     * @throws Adyen_Subscription_Exception|Exception
     */
    public function createOrder(
        Mage_Sales_Model_Quote $quote,
        Adyen_Subscription_Model_Subscription $subscription
    ) {
        Mage::dispatchEvent('adyen_subscription_quote_createorder_before', array(
            'subscription' => $subscription,
            'quote' => $quote
        ));

        try {
            $subscription->getResource()->beginTransaction();

            if (! $subscription->canCreateOrder()) {
                Mage::helper('adyen_subscription')->logOrderCron("Not allowed to create order from quote");
                Adyen_Subscription_Exception::throwException(
                    Mage::helper('adyen_subscription')->__('Not allowed to create order from quote')
                );
            }

            /**
             * only go into the visible items because bundles should use default final price
             */
            foreach ($quote->getAllVisibleItems() as $item) {
                /** @var Mage_Sales_Model_Quote_Item $item */
                $item->getProduct()->setData('is_created_from_subscription_item', $item->getData('subscription_item_id'));
            }

            $quote->collectTotals();
            $service = Mage::getModel('sales/service_quote', $quote);
            $service->submitAll();
            $order = $service->getOrder();

            if (!$order instanceof Mage_Sales_Model_Order) {
                Adyen_Subscription_Exception::throwException(
                    Mage::helper('adyen_subscription')->__("Couldn't create order from quote, probably no visible items")
                );
            }

            // Save order addresses at subscription when they're currently quote addresses
            $subscriptionBillingAddress = Mage::getModel('adyen_subscription/subscription_address')
                ->getSubscriptionAddress($subscription, self::ADDRESS_TYPE_BILLING);

            if ($subscriptionBillingAddress->getSource() == self::ADDRESS_SOURCE_QUOTE) {
                $subscriptionBillingAddress
                    ->initAddress($subscription, $order->getBillingAddress())
                    ->save();
            }

            $subscriptionShippingAddress = Mage::getModel('adyen_subscription/subscription_address')
                ->getSubscriptionAddress($subscription, self::ADDRESS_TYPE_SHIPPING);

            if ($subscriptionShippingAddress->getSource() == self::ADDRESS_SOURCE_QUOTE) {
                $subscriptionShippingAddress
                    ->initAddress($subscription, $order->getShippingAddress())
                    ->save();
            }

            $subscription->getOrderAdditional($order, true)->save();
            $subscription->getActiveQuoteAdditional()->setOrder($order)->save();

            $subscription->setActive();
            $subscription->setScheduledAt($subscription->calculateNextScheduleDate());
            $subscription->save();

            Mage::helper('adyen_subscription')->logOrderCron(sprintf(
                "Successful created order (%s) for subscription (%s)",
                $order->getId(), $subscription->getId()
            ));

            $order->save();
            $subscription->getResource()->commit();
        } catch (Adyen_Payment_Exception $e) {
            // 1. rollback everything
            $subscription->getResource()->rollBack();

            // 2. log the error to the debuglog
            Mage::helper('adyen_subscription')->logOrderCron(sprintf(
                "Error in subscription (%s) creating order from quote (%s) error is: %s",
                $subscription->getId(), $quote->getId(), $e->getMessage()
            ));

            // 3. save the error on the subscription
            $subscription->setStatus($subscription::STATUS_PAYMENT_ERROR);
            $subscription->setErrorMessage($e->getMessage());
            $subscription->save();

            // 4. dispatch the failure event
            Mage::dispatchEvent('adyen_subscription_quote_createorder_fail', array(
                'subscription' => $subscription,
                'status' => $subscription->getStatus(),
                'error' => $e->getMessage()
            ));
            throw $e;
        } catch (Exception $e) {
            // 1. rollback everything
            $subscription->getResource()->rollBack();

            // 2. log the error to the debuglog
            Mage::helper('adyen_subscription')->logOrderCron(sprintf(
                "Error in subscription (%s) creating order from quote (%s) error is: %s",
                $subscription->getId(), $quote->getId(), $e->getMessage()
            ));

            // 3. save the error on the subscription
            $subscription->setStatus($subscription::STATUS_ORDER_ERROR);
            $subscription->setErrorMessage($e->getMessage());
            $subscription->save();

            // 4. dispatch the failure event
            Mage::dispatchEvent('adyen_subscription_quote_createorder_fail',array(
                'subscription' => $subscription,
                'quote' => $quote,
                'status' => $subscription->getStatus(),
                'error' => $e->getMessage()
            ));
            throw $e;
        }

        Mage::dispatchEvent('adyen_subscription_quote_createorder_after', array(
            'subscription' => $subscription,
            'quote' => $quote,
            'order' => $order
        ));

        return $order;
    }

    /**
     * Update subscription based on given quote
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param Adyen_Subscription_Model_Subscription $subscription
     * @return Adyen_Subscription_Model_Subscription $subscription
     */
    public function updateSubscription(
        Mage_Sales_Model_Quote $quote,
        Adyen_Subscription_Model_Subscription $subscription
    ) {
        Mage::dispatchEvent('adyen_subscription_quote_updatesubscription_before', array(
            'subscription' => $subscription,
            'quote' => $quote
        ));

        try {
            $subscription->getResource()->beginTransaction();

            $term = $termType = $stockId = null;
            foreach ($quote->getItemsCollection() as $quoteItem) {
                /** @var Mage_Sales_Model_Quote_Item $quoteItem */
                $productSubscription = $this->_getProductSubscription($quoteItem);

                if (!$productSubscription) {
                    // No product subscription found, no subscription needs to be created
                    continue;
                }

                if (is_null($stockId)) {
                    $stockId = $quoteItem->getStockId();
                }

                if (is_null($term)) {
                    $term = $productSubscription->getTerm();
                }
                if (is_null($termType)) {
                    $termType = $productSubscription->getTermType();
                }
                if ($term != $productSubscription->getTerm() || $termType != $productSubscription->getTermType()) {
                    Adyen_Subscription_Exception::throwException(
                        'Adyen Subscription options of products in quote have different terms'
                    );
                }
            }

            $billingAgreement = $this->getBillingAgreement($quote);

            $this->updateQuotePayment($quote, $billingAgreement, $subscription->getData('payment'));

            /**
             * Quote with only virtual product(s) do(es) not have shipping address
             *
             * Check the quote if it is virtual avoiding throw exception
             */
            if (!$quote->isVirtual() && !$quote->getShippingAddress()->getShippingMethod()) {
                Adyen_Subscription_Exception::throwException('No shipping method selected');
            }

            // Update subscription
            $subscription->setStatus(Adyen_Subscription_Model_Subscription::STATUS_ACTIVE)
                ->setStockId($stockId)
                ->setBillingAgreementId($billingAgreement->getId())
                ->setTerm($term)
                ->setTermType($termType)
                ->setShippingMethod($quote->getShippingAddress()->getShippingMethod())
                ->setUpdatedAt(now())
                ->save();

            // Create subscription addresses
            $billingAddress = $quote->getBillingAddress();
            $billingAddress
                ->setCustomerAddressId($subscription->getData('billing_customer_address_id'))
                ->setSaveInAddressBook($subscription->getData('billing_address_save_in_address_book'));
            Mage::getModel('adyen_subscription/subscription_address')
                ->getSubscriptionAddress($subscription, self::ADDRESS_TYPE_BILLING)
                ->initAddress($subscription, $billingAddress)
                ->save();

            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress
                ->setCustomerAddressId($subscription->getData('shipping_customer_address_id'))
                ->setSaveInAddressBook($subscription->getData('shipping_address_save_in_address_book'))
                ->setSameAsBilling($billingAddress->getSaveInAddressBook() && $subscription->getData('shipping_as_billing'));
            Mage::getModel('adyen_subscription/subscription_address')
                ->getSubscriptionAddress($subscription, self::ADDRESS_TYPE_SHIPPING)
                ->initAddress($subscription, $shippingAddress)
                ->save();

            // Save addresses at customer when 'Save in address book' is selected
            if ($billingAddress->getCustomerAddressId() && $billingAddress->getSaveInAddressBook()) {
                $customerBillingAddress = Mage::getModel('customer/address')->load($billingAddress->getCustomerAddressId());
                Mage::helper('core')->copyFieldset('sales_convert_quote_address', 'to_customer_address', $billingAddress, $customerBillingAddress);
                $customerBillingAddress->save();
            }

            if (! $subscription->getBillingAddress() instanceof Mage_Customer_Model_Address) {
                $quote->getBillingAddress()->setCustomerAddressId(null)->save();
            }

            if ($shippingAddress->getCustomerAddressId() && $shippingAddress->getSaveInAddressBook()) {
                $customerShippingAddress = Mage::getModel('customer/address')->load($shippingAddress->getCustomerAddressId());
                Mage::helper('core')->copyFieldset('sales_convert_quote_address', 'to_customer_address', $shippingAddress, $customerShippingAddress);
                $customerShippingAddress->save();
            }

            if (! $subscription->getShippingAddress() instanceof Mage_Customer_Model_Address) {
                $quote->getShippingAddress()->setCustomerAddressId(null)->save();
            }

            // Delete current subscription items
            foreach ($subscription->getItemCollection() as $subscriptionItem) {
                /** @var Adyen_Subscription_Model_Subscription_Item $subscriptionItem */
                $subscriptionItem->delete();
            }

            $i = 0;
            // Create new subscription items
            foreach ($quote->getItemsCollection() as $quoteItem) {
                /** @var Mage_Sales_Model_Quote_Item $quoteItem */

                /** @var Adyen_Subscription_Model_Product_Subscription $productSubscription */
                $productSubscription = $this->_getProductSubscription($quoteItem);

                if (!$productSubscription || $quoteItem->getParentItemId()) {
                    // No product subscription found, no subscription needs to be created
                    // or item is child of bundle/configurable
                    continue;
                }

                $productOptions = array(
                    'info_buyRequest' => unserialize($quoteItem->getOptionByCode('info_buyRequest')->getValue()),
                    'additional_options' => unserialize($quoteItem->getOptionByCode('additional_options')->getValue())
                );

                /** @var Adyen_Subscription_Model_Subscription_Item $subscriptionItem */
                $subscriptionItem = Mage::getModel('adyen_subscription/subscription_item')
                    ->setSubscriptionId($subscription->getId())
                    ->setStatus(Adyen_Subscription_Model_Subscription_Item::STATUS_ACTIVE)
                    ->setProductId($quoteItem->getProductId())
                    ->setProductOptions(serialize($productOptions))
                    ->setSku($quoteItem->getSku())
                    ->setName($quoteItem->getName())
                    ->setProductSubscriptionId($productSubscription->getId())
                    ->setLabel($productSubscription->getLabel())
                    ->setPrice($quoteItem->getPrice())
                    ->setPriceInclTax($quoteItem->getPriceInclTax())
                    ->setQty($quoteItem->getQty())
                    ->setOnce(0)
                    // Currently not in use
//                    ->setMinBillingCycles($productSubscription->getMinBillingCycles())
//                    ->setMaxBillingCycles($productSubscription->getMaxBillingCycles())
                    ->setCreatedAt(now())
                    ->save();

                Mage::dispatchEvent('adyen_subscription_quote_updatesubscription_add_item', array(
                    'subscription' => $subscription,
                    'item' => $subscriptionItem
                ));

                $i++;
            }

            if ($i <= 0) {
                Adyen_Subscription_Exception::throwException('No subscription products in the subscription');
            }

            $subscription->getResource()->commit();

            Mage::helper('adyen_subscription')->logOrderCron(sprintf(
                "Successfully updated subscription (%s) from quote (%s)",
                $subscription->getId(), $quote->getId()
            ));

        } catch (Exception $e) {
            // 1. rollback everything
            $subscription->getResource()->rollBack();

            // 2. log the error to the debuglog
            Mage::helper('adyen_subscription')->logOrderCron(sprintf(
                "Error while updating subscription (%s) from quote (%s) error is: %s",
                $subscription->getId(), $quote->getId(), $e->getMessage()
            ));

            // 3. save the error on the subscription
            $subscription->setErrorMessage($e->getMessage());
            $subscription->setStatus($subscription::STATUS_SUBSCRIPTION_ERROR);
            $subscription->save();

            // 4. dispatch the failure event
            Mage::dispatchEvent('adyen_subscription_quote_updatesubscription_fail',array(
                'subscription' => $subscription,
                'quote' => $quote,
                'status' => $subscription->getStatus(),
                'error' => $e->getMessage()
            ));
            throw $e;
        }

        Mage::dispatchEvent('adyen_subscription_quote_updatesubscription_after', array(
            'subscription' => $subscription,
            'quote' => $quote
        ));

        return $subscription;
    }

    /**
     * The additional info and cc type of a quote payment are not updated when
     * selecting another payment method while editing a subscription or subscription quote,
     * but they have to be updated for the payment method to be valid
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param Adyen_Payment_Model_Billing_Agreement $billingAgreement
     * @return Mage_Sales_Model_Quote
     * @throws Exception
     */
    public function updateQuotePayment(
        Mage_Sales_Model_Quote $quote,
        Adyen_Payment_Model_Billing_Agreement $billingAgreement,
        $postPaymentData
    ) {
        Mage::dispatchEvent('adyen_subscription_quote_updatequotepayment_before', array(
            'billingAgreement' => $billingAgreement,
            'quote' => $quote
        ));

        // if there is payment data assign the data
        if ($postPaymentData != null) {
            $quote->getPayment()->getMethodInstance()->assignData($postPaymentData);
        }

        // customer interaction not needed for subscription so set this to false
        $quote->getPayment()->setAdditionalInformation('customer_interaction', false);

        $quote->getPayment()->save();

        Mage::dispatchEvent('adyen_subscription_quote_updatequotepayment_after', array(
            'billingAgreement' => $billingAgreement,
            'quote' => $quote
        ));

        return $quote;
    }

    /**
     * @param Mage_Sales_Model_Quote $quote
     * @return Adyen_Payment_Model_Billing_Agreement
     */
    public function getBillingAgreement(Mage_Sales_Model_Quote $quote)
    {
        $billingAgreement = $quote->getPayment()->getMethodInstance()->getBillingAgreement();

        if (! $billingAgreement) {
            Adyen_Subscription_Exception::throwException(
                'Could not find billing agreement for quote ' . $quote->getId()
            );
        }

        Mage::dispatchEvent('adyen_subscription_quote_getbillingagreement', array(
            'billingAgreement' => $billingAgreement,
            'quote' => $quote
        ));

        return $billingAgreement;
    }

    /**
     * @param Mage_Sales_Model_Quote_Item $quoteItem
     * @return Adyen_Subscription_Model_Product_Subscription
     */
    protected function _getProductSubscription(Mage_Sales_Model_Quote_Item $quoteItem)
    {
        $subscriptionId = $quoteItem->getBuyRequest()->getData('adyen_subscription');
        if (! $subscriptionId) {
            return false;
        }

        $subscriptionProductSubscription = Mage::getModel('adyen_subscription/product_subscription')
            ->load($subscriptionId);

        if (!$subscriptionProductSubscription->getId()) {
            return false;
        }

        Mage::dispatchEvent('adyen_subscription_quote_getproductsubscription', array(
            'productSubscription' => $subscriptionProductSubscription,
            'item' => $quoteItem
        ));

        return $subscriptionProductSubscription;
    }
}
