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
 * This class defines a select list where the admin can choose an email to send an
 * error notification to.
 */
class Postmates_Shipping_Model_Adminhtml_Emails
{
    public function toOptionArray()
    {
        return array(
            array('value' => 'general', 'label' => 'General Email'),
            array('value' => 'sales',   'label' => 'Sales Email'),
            array('value' => 'support', 'label' => 'Support Email'),
            array('value' => 'custom1', 'label' => 'Custom1 Email'),
            array('value' => 'custom2', 'label' => 'Custom2 Email'),
        );
    }
}
