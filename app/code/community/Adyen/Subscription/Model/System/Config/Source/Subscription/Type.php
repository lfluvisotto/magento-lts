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

//@todo move to Product_Subscription?
class Adyen_Subscription_Model_System_Config_Source_Subscription_Type
    extends Mage_Eav_Model_Entity_Attribute_Source_Abstract
{

    public function getAllOptions()
    {
        if ($this->_options === null) {
            $this->_options = [
                [
                    'label' => Mage::helper('adyen_subscription')->__('Adyen Subscription Disabled'),
                    'value' => Adyen_Subscription_Model_Product_Subscription::TYPE_DISABLED
                ],[
                    'label' => Mage::helper('adyen_subscription')->__('Adyen Subscription Enabled + Standalone purchase possible'),
                    'value' => Adyen_Subscription_Model_Product_Subscription::TYPE_ENABLED_ALLOW_STANDALONE
                ],[
                    'label' => Mage::helper('adyen_subscription')->__('Adyen Subscription Enabled + Subscription option selection required'),
                    'value' => Adyen_Subscription_Model_Product_Subscription::TYPE_ENABLED_ONLY_SUBSCRIPTION
                ]
            ];
        }
        return $this->_options;
    }

    /**
     * Retrieve flat column definition
     *
     * @return array
     */
    public function getFlatColums()
    {
        return array(
            $this->getAttribute()->getAttributeCode() => array(
                'type'      => 'tinyint',
                'unsigned'  => true,
                'is_null'   => true,
                'default'   => null,
                'extra'     => null,
        ));
    }

    /**
     * Retrieve Select For Flat Attribute update
     *
     * @param int $store
     * @return Varien_Db_Select|null
     */
    public function getFlatUpdateSelect($store)
    {
        return Mage::getResourceSingleton('eav/entity_attribute')
            ->getFlatUpdateSelect($this->getAttribute(), $store);
    }
}
