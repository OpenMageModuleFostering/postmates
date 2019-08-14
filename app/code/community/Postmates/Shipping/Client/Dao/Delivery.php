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
class Postmates_Shipping_Client_Dao_Delivery extends Postmates_Shipping_Client_BaseDao
{
    protected function _map(array $input)
    {
        // Map raw date times to objects
        $input['created']          = self::mapDateTime($input['created']);
        $input['updated']          = self::mapDateTime($input['updated']);
        $input['pickup_eta']       = self::mapDateTime($input['pickup_eta']);
        $input['dropoff_eta']      = self::mapDateTime($input['dropoff_eta']);
        $input['dropoff_deadline'] = self::mapDateTime($input['dropoff_deadline']);

        return $input;
    }
}
