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
class Postmates_Shipping_Client_ListResponse extends \ArrayObject
{
    public function getTotalCount()
    {
        if(!isset($this['total_count']))
            return 1;
        return $this['total_count'];
    }

    public function getNextHref()
    {
        if(!isset($this['next_href']))
            return null;
        return $this['next_href'];
    }

    public function getData()
    {
        return $this['data'];
    }
}
