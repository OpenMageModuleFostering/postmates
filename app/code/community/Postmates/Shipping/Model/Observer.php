<?php
/**
 * NOTICE OF COPYRIGHT
 *
 * This file is copywritten material only to be used if it has been purchased.
 * See the Magento Connect website for information to pay if you have not already
 * and any warranty or support information.
 *
 * @category    Postmates
 * @package     Postmates_Shipping
 * @copyright   Copyright (c) 2015 Moxune LLC (http://moxune.com)
 */
/**
 * Schedule a delivery request with Postmates for a recently completed order.
 */
class Postmates_Shipping_Model_Observer
{
    /**
     * Once an order is successfully placed, this method will create a delivery
     * for orders that have chosen the postmates_shipping shipping method.
     */
    final public function createDelivery(Varien_Event_Observer $oObserver)
    {
        $oOrder = $oObserver->getData('order');

        // Determine if the shipping method is postmates_shipping_delivery
        $sShipMethod = $oOrder->getShippingMethod();
        if($sShipMethod != 'postmates_shipping_delivery') {
            Mage::log('Failed to find the Postmates quote in the session', Zend_Log::ERR);
            return;
        }

        // Create the delivery request with Postmates
        $oCarrier  = Mage::getModel('postmates_shipping/carrier');
        $oDelivery = $oCarrier->createDelivery(
            $oOrder,
            $this->_buildManifest($oOrder),
            $this->_getDropoffNotes($oOrder));

        // Bail if we failed to create a delivery request to Postmates.
        // Error handling for this scenario resides in the Carrier class.
        if(!is_object($oDelivery) || $oDelivery->isError()) {
            return $this;
        }

        //------------------------------------------
        // Track the postmates delivery on the order
        //------------------------------------------
        $oOrder->setPostmatesDeliveryId($oDelivery['id']);
        $oOrder->save();

        return $this;
    }

    /**
     * TODO Ability to capture dropoff notes from customer during checkout.
     *      Feel free to implement this in a subclass for now if you wish.
     */
    protected function _getDropoffNotes(Mage_Sales_Model_Order $oOrder)
    {

    }

    /**
     * Build the manifest for the Postmates Delivery request.
     * All we're doing here is concatenating the names of each item from the order by newlines.
     */
    protected function _buildManifest(Mage_Sales_Model_Order $oOrder)
    {
        $sManifest = '';
        $aManifest = array();
        foreach($oOrder->getAllItems() as $oItem) {
            $aManifest[] = $oItem->getName();
        }

        if(count($aManifest) == 1) {
            $sManifest = $aManifest[0];
        } else {
            $sManifest = implode(",\n", $aManifest);
        }

        if(empty($sManifest)) {
            Mage::log($sManifest, Zend_Log::ERR);
        }

        return $sManifest;
    }
}
