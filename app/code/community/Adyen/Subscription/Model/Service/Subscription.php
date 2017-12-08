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

class Adyen_Subscription_Model_Service_Subscription
{

    /**
     * @param Adyen_Subscription_Model_Subscription $subscription
     *
     * @return Mage_Sales_Model_Quote
     * @throws Adyen_Subscription_Exception|Exception
     */
    public function createQuote(Adyen_Subscription_Model_Subscription $subscription)
    {
        Mage::dispatchEvent('adyen_subscription_service_createquote_before', array('subscription' => $subscription));

        try {
            if (! $subscription->canCreateQuote()) {
                Mage::helper('adyen_subscription')->logQuoteCron('Can not create quote from subscription');
                Adyen_Subscription_Exception::throwException('Can not create quote from subscription');
            }

            if ($quote = $subscription->getActiveQuote()) {
                Mage::helper('adyen_subscription')->logQuoteCron('There is already an active quote present for this subscription');
                Adyen_Subscription_Exception::throwException('There is already an active quote present for this subscription');
            }

            $storeId = $subscription->getStoreId();

            $appEmulation = Mage::getSingleton('core/app_emulation');
            $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation($storeId);

            Mage::getSingleton('adminhtml/session_quote')->setStoreId($storeId);
            $customer = $subscription->getCustomer();
            $quote = Mage::getModel('sales/quote')->assignCustomer($customer);
            $quote->setStoreId($storeId);
            $quote->setIsSuperMode(true);
            $quote->setIsActive(false); //always create an inactive quote, else it shows up on the frontend.
            $quote->setSubscriptionId($subscription->getId());
            $subscription->setActive();

            // Add order items to quote
            foreach ($subscription->getItemCollection() as $subscriptionItem) {
                /** @var Adyen_Subscription_Model_Subscription_Item $subscriptionItem */
                $productId = $subscriptionItem->getProductId();
                $product = Mage::getModel('catalog/product')->load($productId);
                $product->setData('is_created_from_subscription_item', $subscriptionItem->getId());

                // add buyRequest to varien_object so we can support configurable products
                $buyRequest = $subscriptionItem->getBuyRequest();
                $varienObject = new Varien_Object();
                foreach ($buyRequest as $code => $value) {
                    $varienObject->setData($code, $value);
                }

                $quoteItem = $quote->addProduct($product, $varienObject);

                if (! $quoteItem instanceof Mage_Sales_Model_Quote_Item) {
                    Mage::helper('adyen_subscription')->logQuoteCron(sprintf('An error occurred while adding a product to the quote: %s', $quoteItem));
                    Adyen_Subscription_Exception::throwException(Mage::helper('adyen_subscription')->__(
                        'An error occurred while adding a product to the quote: %s', $quoteItem
                    ));
                }

                $quoteItem->setData('subscription_item_id', $subscriptionItem->getId());

                $quoteItem->setNoDiscount(false);
                $quoteItem->getProduct()->setIsSuperMode(true);

                $additionalData = Mage::getModel('sales/quote_item_option')->setData([
                    'code'       => 'additional_options',
                    'product_id' => $quoteItem->getProductId(),
                    'value'      => serialize($subscriptionItem->getAdditionalOptions())
                ]);
                $quoteItem->addOption($additionalData);

                $buyRequest = Mage::getModel('sales/quote_item_option')->setData([
                    'code'       => 'info_buyRequest',
                    'product_id' => $quoteItem->getProductId(),
                    'value'      => serialize($subscriptionItem->getBuyRequest())
                ]);
                $quoteItem->addOption($buyRequest);

                $quoteItem->checkData();

                Mage::dispatchEvent('adyen_subscription_service_createquote_add_item', array('item' => $quoteItem, 'quote' => $quote));
            }

            // Set billing address data
            $billingAddressData = $subscription->getBillingAddressData();
            unset($billingAddressData['address_type']);
            $quote->getBillingAddress()
                ->addData($billingAddressData)
                ->setData('email', $customer->getEmail());

            if (! $subscription->getBillingAddress() instanceof Mage_Customer_Model_Address) {
                $quote->getBillingAddress()->setCustomerAddressId(null);
            }

            // Set shipping address data
            $shippingAddressData = $subscription->getShippingAddressData();
            unset($shippingAddressData['address_type']);

            // Collect totals, since we could need this when retrieving shipping rates
            $quote->collectTotals();

            $quote->getShippingAddress()
                ->addData($shippingAddressData)
                ->setData('email', $customer->getEmail())
                ->setStockId($subscription->getStockId())
                ->setCollectShippingRates(true)
                ->collectShippingRates();

            $quote->setTotalsCollectedFlag(false);
            $quote->getShippingAddress()->collectTotals();

            if (! $subscription->getShippingAddress() instanceof Mage_Customer_Model_Address) {
                $quote->getShippingAddress()->setCustomerAddressId(null);
            }

            // Set shipping method
            $shippingMethod = $subscription->getShippingMethod();
            $quote->getShippingAddress()->setShippingMethod($shippingMethod)->save();

            if (! $subscription->getBillingAgreement()->getId()) {
                Mage::helper('adyen_subscription')->logQuoteCron(sprintf('No billing agreement found', $quote));
                Adyen_Subscription_Exception::throwException(Mage::helper('adyen_subscription')->__(
                    'No billing agreement found'
                ));
            }

            // Set payment method
            $methodInstance = $subscription->getBillingAgreement()->getPaymentMethodInstance();

            if (! method_exists($methodInstance, 'initBillingAgreementPaymentInfo')) {
                Mage::helper('adyen_subscription')->logQuoteCron(sprintf('Payment method %s does not support Adyen_Subscription', $methodInstance->getCode()));
                Adyen_Subscription_Exception::throwException(Mage::helper('adyen_subscription')->__(
                    'Payment method %s does not support Adyen_Subscription', $methodInstance->getCode()
                ));
            }


            // Set billing agreement data
            /** @noinspection PhpUndefinedMethodInspection */
            try {
                /** @noinspection PhpUndefinedMethodInspection */
                $methodInstance->initBillingAgreementPaymentInfo($subscription->getBillingAgreement(), $quote->getPayment());
            } catch(Mage_Core_Exception $e) {
                Mage::helper('adyen_subscription')->logQuoteCron(sprintf('Failed to set billing agreement data %s', $e->getMessage()));
                $subscription->setErrorMessage($e->getMessage());
                $subscription->setStatus($subscription::STATUS_QUOTE_ERROR);
            }

            $quote->collectTotals();
            $subscription->setActiveQuote($quote);
            $quoteAdditional = $subscription->getActiveQuoteAdditional(true);

            Mage::getModel('core/resource_transaction')
                ->addObject($quote)
                ->addObject($subscription)
                ->save();

            //we save in a second step because
            $quoteAdditional->setQuote($quote)->save();

            if ($subscription->getStatus() == $subscription::STATUS_QUOTE_ERROR) {
                $subscription->setStatus($subscription::STATUS_ACTIVE);
            }
            $subscription->save();

            $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);

            Mage::helper('adyen_subscription')->logQuoteCron(sprintf('Created quote (#%s) for subscription (#%s)', $quote->getId(), $subscription->getId()));

            Mage::dispatchEvent('adyen_subscription_service_createquote_after', array('subscription' => $subscription, 'quote' => $quote));

            return $quote;
        } catch (Exception $e) {
            Mage::helper('adyen_subscription')->logQuoteCron(sprintf('Exception in creating quote: %s', $e->getMessage()));
            $subscription->setStatus($subscription::STATUS_QUOTE_ERROR);
            $subscription->setErrorMessage($e->getMessage());
            $subscription->save();

            Mage::dispatchEvent('adyen_subscription_service_createquote_fail', array('subscription' => $subscription, 'status' => $subscription::STATUS_QUOTE_ERROR, 'error' => $e->getMessage()));
            throw $e;
        }
    }

    public function updateQuotePayment(
        Adyen_Subscription_Model_Subscription $subscription,
        Mage_Sales_Model_Quote $quote
    )
    {
        Mage::dispatchEvent('adyen_subscription_service_updatequotepayment_before', array('subscription' => $subscription, 'quote' => $quote));

        // Set payment method
        $methodInstance = $subscription->getBillingAgreement()->getPaymentMethodInstance();

        if (!method_exists($methodInstance, 'initBillingAgreementPaymentInfo')) {
            Mage::helper('adyen_subscription')->logQuoteCron(sprintf('Payment method %s does not support Adyen_Subscription', $methodInstance->getCode()));
            Adyen_Subscription_Exception::throwException(Mage::helper('adyen_subscription')->__(
                'Payment method %s does not support Adyen_Subscription', $methodInstance->getCode()
            ));
        }
        // Set billing agreement data
        /* @noinspection PhpUndefinedMethodInspection */
        try {
            /* @noinspection PhpUndefinedMethodInspection */
            $methodInstance->initBillingAgreementPaymentInfo($subscription->getBillingAgreement(), $quote->getPayment());
            // importan $quote->save() will not update payment object so use this:
            $quote->getPayment()->save();

            Mage::dispatchEvent('adyen_subscription_service_updatequotepayment_after', array('subscription' => $subscription, 'quote' => $quote));

        } catch (Mage_Core_Exception $e) {
            Mage::helper('adyen_subscription')->logQuoteCron(sprintf('Failed to set billing agreement data %s', $e->getMessage()));
            $subscription->setErrorMessage($e->getMessage());
            $subscription->setStatus($subscription::STATUS_QUOTE_ERROR);

            Mage::getModel('core/resource_transaction')
                ->addObject($quote)
                ->addObject($subscription)
                ->save();

            Mage::dispatchEvent('adyen_subscription_service_updatequotepayment_fail', array('subscription' => $subscription, 'status' => $subscription::STATUS_QUOTE_ERROR, 'error' => $e->getMessage()));
        }
    }
}

