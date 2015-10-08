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
            if (! $subscription->canCreateOrder()) {
                Mage::helper('adyen_subscription')->logOrderCron("Not allowed to create order from quote");
                Adyen_Subscription_Exception::throwException(
                    Mage::helper('adyen_subscription')->__('Not allowed to create order from quote')
                );
            }
            foreach ($quote->getAllItems() as $item) {
                /** @var Mage_Sales_Model_Quote_Item $item */
                $item->getProduct()->setData('is_created_from_subscription_item', $item->getData('subscription_item_id'));
            }

            $quote->collectTotals();
            $service = Mage::getModel('sales/service_quote', $quote);
            $service->submitAll();
            $order = $service->getOrder();

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

            $orderAdditional = $subscription->getOrderAdditional($order, true)->save();
            $quoteAdditional = $subscription->getActiveQuoteAdditional()->setOrder($order)->save();

            $subscription->setErrorMessage(null);
            $subscriptionHistory = null;

            //Save history
            if ($subscription->getStatus() == $subscription::STATUS_ORDER_ERROR ||
                $subscription->getStatus() == $subscription::STATUS_PAYMENT_ERROR
            ) {
                $subscription->setStatus($subscription::STATUS_ACTIVE);

                $subscriptionHistory = Mage::getModel('adyen_subscription/subscription_history');
                $subscriptionHistory->createHistoryFromSubscription($subscription);
            }

            $subscription->setScheduledAt($subscription->calculateNextScheduleDate());

            $transaction = Mage::getModel('core/resource_transaction');
            $transaction->addObject($subscription)
                        ->addObject($orderAdditional)
                        ->addObject($quoteAdditional)
                        ->addObject($order);

            if($subscriptionHistory) {
                $transaction->addObject($subscriptionHistory);
            }
            $transaction->save();

            Mage::helper('adyen_subscription')->logOrderCron(sprintf(
                "Successful created order (#%s) for subscription (#%s)",
                $order->getId(), $subscription->getId()
            ));

            $order = $service->getOrder();
            Mage::dispatchEvent('adyen_subscription_quote_createorder_after', array(
                'subscription' => $subscription,
                'quote' => $quote,
                'order' => $order
            ));

            return $order;

        } catch (Mage_Payment_Exception $e) {
            Mage::helper('adyen_subscription')->logOrderCron(sprintf(
                "Error in subscription (#%s) creating order from quote (#%s) error is: %s",
                $subscription->getId(), $quote->getId(), $e->getMessage()
            ));

            if (isset($order)) {
                $order->delete();
            }
            $subscription->setStatus($subscription::STATUS_PAYMENT_ERROR);
            $subscription->setErrorMessage($e->getMessage());
            $subscription->save();

            Mage::dispatchEvent('adyen_subscription_quote_createorder_fail', array(
                'subscription' => $subscription,
                'status' => $subscription::STATUS_PAYMENT_ERROR,
                'error' => $e->getMessage()
            ));
            throw $e;
        } catch (Exception $e) {
            Mage::helper('adyen_subscription')->logOrderCron(sprintf(
                "Error in subscription (#%s) creating order from quote (#%s) error is: %s",
                $subscription->getId(), $quote->getId(), $e->getMessage()
            ));

            if (isset($order)) {
                $order->delete();
            }
            $subscription->setStatus($subscription::STATUS_ORDER_ERROR);
            $subscription->setErrorMessage($e->getMessage());
            $subscription->save();

            Mage::dispatchEvent('adyen_subscription_quote_createorder_fail',array(
                'subscription' => $subscription,
                'status' => $subscription->getStatus(),
                'error' => $e->getMessage()
            ));
            throw $e;
        }
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

        $this->updateQuotePayment($quote, $billingAgreement);

        if (!$quote->getShippingAddress()->getShippingMethod()) {
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
        Mage::getModel('adyen_subscription/subscription_address')
            ->getSubscriptionAddress($subscription, self::ADDRESS_TYPE_BILLING)
            ->initAddress($subscription, $quote->getBillingAddress())
            ->save();

        Mage::getModel('adyen_subscription/subscription_address')
            ->getSubscriptionAddress($subscription, self::ADDRESS_TYPE_SHIPPING)
            ->initAddress($subscription, $quote->getShippingAddress())
            ->save();

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

            if (!$productSubscription) {
                // No product subscription found, no subscription needs to be created
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
                ->setLabel($productSubscription->getLabel())
                ->setPrice($quoteItem->getPrice())
                ->setPriceInclTax($quoteItem->getPriceInclTax())
                ->setQty($quoteItem->getQty())
                ->setOnce(0)
                // Currently not in use
//                ->setMinBillingCycles($productSubscription->getMinBillingCycles())
//                ->setMaxBillingCycles($productSubscription->getMaxBillingCycles())
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
        Adyen_Payment_Model_Billing_Agreement $billingAgreement
    ) {
        Mage::dispatchEvent('adyen_subscription_quote_updatequotepayment_before', array(
            'billingAgreement' => $billingAgreement,
            'quote' => $quote
        ));

        $subscriptionDetailReference = str_replace('adyen_oneclick_', '', $quote->getPayment()->getData('method'));

        $quote->getPayment()->setAdditionalInformation('recurring_detail_reference', $subscriptionDetailReference);

        $agreementData = $billingAgreement->getAgreementData();
        if(isset($agreementData['variant'])) {
            $quote->getPayment()->setCcType($agreementData['variant']);
        } else {
            $quote->getPayment()->setCcType(null);
        }

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
