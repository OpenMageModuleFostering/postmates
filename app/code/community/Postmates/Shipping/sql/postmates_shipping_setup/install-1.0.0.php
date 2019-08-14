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
 * Add a field to the order model to track Postmates delivery IDs.
 */
$installer = $this;

$installer->startSetup();
$installer->addAttribute("order", "postmates_delivery_id", array("type"=>"varchar"));
$installer->endSetup();
