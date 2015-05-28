<?php

/* @var $installer Mage_Core_Model_Resource_Setup */
$installer = $this;

$installer->startSetup();

/** @var Magento_Db_Adapter_Pdo_Mysql $connection */
$connection = $installer->getConnection();

$productProfileTable = $installer->getTable('ho_recurring/profile_item');

$connection->addColumn($productProfileTable, 'product_options', [
    'type'      => Varien_Db_Ddl_Table::TYPE_TEXT,
    'after'     => 'product_id',
    'comment'   => 'Product Options',
]);

$installer->endSetup();
