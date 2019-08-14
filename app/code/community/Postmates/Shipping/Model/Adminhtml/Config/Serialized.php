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
class Postmates_Shipping_Model_Adminhtml_Config_Serialized
    extends Mage_Core_Model_Config_Data
{
    static public function unpack($serialized)
    {
        $objects    = unserialize($serialized);
        $origFormat = array();
        if(count($objects)) {
            foreach($objects as $object) {
                foreach($object as $key => $value) {
                    if(!isset($origFormat[$key])) {
                        $origFormat[$key] = array();
                    }

                    $origFormat[$key][] = $value;
                }
            }
        }

        return $origFormat;
    }

    protected function _afterLoad()
    {   
        if (!is_array($this->getValue())) {
            $data = $this->getValue();

            if(empty($data)) {
                $this->setValue(false);
            } else {
                $origFormat = self::unpack($data);

                if(count($origFormat)) {
                    $this->setValue($origFormat);
                } else {
                    $this->setValue(false);
                }
            }
        }
    }

    protected function _beforeSave()
    {   
        if (is_array($this->getValue())) {
            $value = $this->getValue();

            // Aggregate the values
            $value       = $this->getValue();
            $cleanValues = array();
            foreach($value as $key => $values) {
                foreach($values as $i => $_value) {
                    $cleanValues[$i][$key] = $_value;
                }
            }

            // Now validate
            $finalValues = array();
            foreach($cleanValues as $value) {
                $valid = true;
                foreach($value as $key => $_value) {
                    if($key != 'business' && $key != 'notes' && empty($_value)) {
                        $valid = false;
                        break;
                    }
                }
                if($valid) {
                    $finalValues[] = $value;
                }
            }
            
            $this->setValue(serialize($finalValues));
        }
    }
}
