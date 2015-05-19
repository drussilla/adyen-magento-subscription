<?php
/**
 * Ho_Recurring
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the H&O Commercial License
 * that is bundled with this package in the file LICENSE_HO.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.h-o.nl/license
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@h-o.com so we can send you a copy immediately.
 *
 * @category    Ho
 * @package     Ho_Recurring
 * @copyright   Copyright © 2015 H&O (http://www.h-o.nl/)
 * @license     H&O Commercial License (http://www.h-o.nl/license)
 * @author      Maikel Koek – H&O <info@h-o.nl>
 */

class Ho_Recurring_Model_Resource_Profile extends Mage_Core_Model_Resource_Db_Abstract
{
    public function _construct()
    {
        $this->_init('ho_recurring/profile', 'entity_id');
    }

    public function loadByOrder(
        Ho_Recurring_Model_Profile $object,
        Mage_Sales_Model_Order $order
    ) {
        $orderSelect = Mage::getResourceModel('ho_recurring/profile_order_collection')
            ->addFieldToFilter('order_id', $order->getId())
            ->getSelect();

        $orderSelect->reset($orderSelect::COLUMNS);
        $orderSelect->columns('profile_id');

        $profileId = $this->_getConnection('read')->fetchOne($orderSelect);

        $this->load($object, $profileId);

        return $this;
    }
}
