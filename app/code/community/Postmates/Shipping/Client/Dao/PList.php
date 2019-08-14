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
class Postmates_Shipping_Client_Dao_PList extends Postmates_Shipping_Client_BaseDao
{
    protected function _map(array $input)
    {
        // Map all the children in the list
        $_aInput = array();
        foreach($input['data'] as $_aObject)
            $_aInput[] = Postmates_Shipping_Client_Factory::create($_aObject);

        return $_aInput;
    }
}
