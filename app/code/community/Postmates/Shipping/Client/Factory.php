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
class Postmates_Shipping_Client_Factory
{
    /**
     * This is a factory method that will instantiate the appropriate PHP
     * object by inspecting the response payload.
     */
    static public function create(array $aJson)
    {
        // Seems Postmates sometimes uses the key 'object' when
        // they pass a list kind, although the docs say it will be in kind...
        if(isset($aJson['object']) && $aJson['object'] == 'list')
            return new Postmates_Shipping_Client_Dao_PList($aJson);

        // Now try to hydrate a known object
        $sKind = $aJson['kind'];
        switch($sKind) {
            case 'list':
                return new Postmates_Shipping_Client_Dao_PList($aJson);
                break;
            case 'delivery_quote':
                return new Postmates_Shipping_Client_Dao_DeliveryQuote($aJson);
                break;
            case 'delivery':
                return new Postmates_Shipping_Client_Dao_Delivery($aJson);
                break;
            case 'error':
                return new Postmates_Shipping_Client_Dao_Error($aJson);                
                break;
            default;
                throw new \UnexpectedValueException("Unsupported type $sKind");
                break;
        }

        // If no type was provided return the bare array
        return $aJson;
    }
}
