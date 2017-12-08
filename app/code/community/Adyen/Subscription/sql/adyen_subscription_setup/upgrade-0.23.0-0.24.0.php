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

/* @var $installer Mage_Core_Model_Resource_Setup */
$installer = $this;

$installer->startSetup();

/** @var Magento_Db_Adapter_Pdo_Mysql $connection */
$connection = $installer->getConnection();

$subscriptionLabelTable = $installer->getTable('adyen_subscription/product_subscription_label');

$connection->dropTable($subscriptionLabelTable);

$table = $connection
    ->newTable($subscriptionLabelTable)
    ->addColumn('label_id', Varien_Db_Ddl_Table::TYPE_INTEGER, 11, [
        'unsigned'  => true,
        'nullable'  => false,
        'primary'   => true,
        'auto_increment' => true,
    ], 'Label ID')
    ->addColumn('subscription_id', Varien_Db_Ddl_Table::TYPE_INTEGER, 11, [
        'unsigned'  => true,
        'nullable'  => false,
    ], 'Subscription ID')
    ->addColumn('store_id', Varien_Db_Ddl_Table::TYPE_INTEGER, 5, [
        'unsigned'  => true,
        'nullable'  => false,
    ], 'Store ID')
    ->addColumn('label', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, [
        'unsigned'  => true,
        'nullable'  => true,
    ], 'Label')
    ->addForeignKey(
        $installer->getFkName(
            'adyen_subscription/product_subscription_label',
            'entity_id',
            'adyen_subscription/subscription',
            'entity_id'
        ),
        'subscription_id', $installer->getTable('adyen_subscription/product_subscription'), 'entity_id',
        Varien_Db_Ddl_Table::ACTION_CASCADE, Varien_Db_Ddl_Table::ACTION_CASCADE
    )
    ->addForeignKey(
        $installer->getFkName(
            'adyen_subscription/product_subscription_label',
            'store_id',
            'core/store',
            'store_id'
        ),
        'store_id', $installer->getTable('core/store'), 'store_id',
        Varien_Db_Ddl_Table::ACTION_CASCADE, Varien_Db_Ddl_Table::ACTION_CASCADE
    )
    ->setComment('Adyen Subscription Product Subscription Label');

$connection->createTable($table);

$installer->endSetup();
