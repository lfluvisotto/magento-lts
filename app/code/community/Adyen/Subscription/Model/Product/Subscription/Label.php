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

/**
 * Class Adyen_Subscription_Model_Product_Subscription_Label
 *
 * @method int getSubscriptionId()
 * @method $this setSubscriptionId(int $value)
 * @method int getStoreId()
 * @method $this setStoreId(int $value)
 * @method string getLabel()
 * @method $this setLabel(string $value)
 */
class Adyen_Subscription_Model_Product_Subscription_Label extends Mage_Core_Model_Abstract
{
    protected function _construct ()
    {
        $this->_init('adyen_subscription/product_subscription_label');
    }

    /**
     * @param Adyen_Subscription_Model_Product_Subscription $subscription
     * @param Mage_Core_Model_Store|int $store
     * @return $this
     */
    public function loadBySubscription(Adyen_Subscription_Model_Product_Subscription $subscription, $store)
    {
        $labels = $this->getCollection()
            ->addFieldToFilter('subscription_id', $subscription->getId());

        if ($store instanceof Mage_Core_Model_Store) {
            $storeId = $store->getId();
        }
        else {
            $storeId = $store;
        }

        $labels->addFieldToFilter('store_id', $storeId);

        return $labels->getFirstItem();
    }
}
