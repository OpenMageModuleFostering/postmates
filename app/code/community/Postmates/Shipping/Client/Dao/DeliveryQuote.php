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
class Postmates_Shipping_Client_Dao_DeliveryQuote extends Postmates_Shipping_Client_BaseDao
{
    protected function _map(array $input)
    {
        // Map raw date times to objects
        $input['created']     = self::mapDateTime($input['created']);
        $input['expires']     = self::mapDateTime($input['expires']);
        $input['dropoff_eta'] = self::mapDateTime($input['dropoff_eta']);

        // Postmates passes the fee in cents
        $input['fee'] = $input['fee'] / 100;

        return $input;
    }
}
  
