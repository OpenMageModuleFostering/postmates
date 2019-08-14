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
 * TODO
 *   - Cleanup; removal is only emptying the values of existing items rather than deleting the entire item...
 *   - Add attribute to indicate location is active / inactive 
 * @note This file adapted from
 *       https://raw.githubusercontent.com/OpenMage/magento-mirror/1.7.0.1/app/code/core/Mage/GoogleCheckout/Block/Adminhtml/Shipping/Merchant.php
 */
class Postmates_Shipping_Block_Adminhtml_Pickupaddress
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected $_addRowButtonHtml = array();
    protected $_removeRowButtonHtml = array();

    private $_addresses, $_cities, $_states, $_zips;

    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $this->setElement($element);

        // Build the template from which new addresses can be added
        $html = $this->_inputContainer(
            '<div id="merchant_pickup_addresses_template" style="display:none">', '</div>');

        // List the values of existing addresses
        $that = $this;
        $html .= $this->_inputContainer(
            '<ul id="merchant_pickup_addresses_container">' .
            $this->_getNextStepJs(),
            '</ul>',
            function() use ($that) {
                $html      = '';
                $addresses = $that->_getValue('address');
                if(count($addresses)) {
                    foreach($addresses as $i => $f) {
                        $html .= $that->_inputBlock($i);
                    }
                }
                return $html;
            });

        $html .= $this->_getAddRowButtonHtml(
            'merchant_pickup_addresses_container',
            'merchant_pickup_addresses_template',
            $this->__('Add Pickup Address'));

        return $html;
    }

    private function _getNextStepJs()
    {
        return
<<<JS
<script>
$$('#merchant_pickup_addresses_container .pickup_address').each(function(e) {
    e.childElements().each(function(input) {
        console.log(input.name);
    });
});
</script>
JS;
    }

    private function _inputContainer($containerOpen, $containerClose, $blockCallback=null)
    {
        $html = $containerOpen;

        if(is_callable($blockCallback)) {
            $html .= $blockCallback();
        } else {
            $html .= $this->_inputBlock(-1);
        }
            
        return $html . $containerClose;
    }

    private function _inputBlock($i=0)
    {
        return
            '<div class="pickup_address">'        .
            '<input type="hidden" name="pickup_address[]"/>' .
            $this->_getPickupNameInputHtml($i) .
            $this->_getPickupAddressInputHtml($i) .
            $this->_getPickupCityInputHtml($i)    .
            $this->_getPickupStateInputHtml($i)   .
            $this->_getPickupZipInputHtml($i)     .
            $this->_getPickupPhoneInputHtml($i)   .
            $this->_getPickupBizNameInputHtml($i) .
            $this->_getPickupNotesInputHtml($i)   .
            $this->_getRemoveRowButtonHtml()      .
            '</div>';
    }

    protected function _getPickupNameInputHtml($rowIndex = 0)
    {
        return $this->_getInputHtml('name', '* Name', $rowIndex, 200);
    }

    /**
     * Retrieve html template for pickup address row
     *
     * @param int $rowIndex
     * @return string
     */
    protected function _getPickupAddressInputHtml($rowIndex = 0)
    {
        return $this->_getInputHtml('address', '* Address', $rowIndex, 200);
    }

    protected function _getPickupCityInputHtml($rowIndex=0)
    {
        return $this->_getInputHtml('city', '* City', $rowIndex, 50);
    }

    protected function _getPickupStateInputHtml($rowIndex=0)
    {
        return $this->_getInputHtml('state', '* State', $rowIndex, 50);
    }

    protected function _getPickupZipInputHtml($rowIndex=0)
    {
        return $this->_getInputHtml('zip', '* Zip', $rowIndex, 50);
    }

    protected function _getPickupPhoneInputHtml($rowIndex=0)
    {
        return $this->_getInputHtml('phone', '* Phone', $rowIndex, 50);
    }

    protected function _getPickupBizNameInputHtml($rowIndex=0)
    {
        return $this->_getInputHtml('business', 'Business', $rowIndex, 50);
    }

    protected function _getPickupNotesInputHtml($rowIndex=0)
    {
        return $this->_getInputHtml('notes', 'Pickup Notes', $rowIndex, 50);
    }

    private function _getInputHtml($field, $label, $index=0, $width=50)
    {
        $value = '';
        if($index >= 0) {
            $value = $this->_getValue($field . '/' . $index);
        }

        $html = '<li>';
        $html .= '<div style="margin:5px 0 10px;">';
        $html .= '<label style="width:50px;">' . $this->__($label . ':') . '</label> ';
        $html .= '<input class="input-text postmates-input" style="width:100%;" name="'
            . $this->getElement()->getName() . '[' . $field . '][]" value="'
            . $value . '" ' . $this->_getDisabled() . '/> ';

        $html .= '</div>';
        $html .= '</li>';

        return $html;
    }

    protected function _getDisabled()
    {
        return $this->getElement()->getDisabled() ? ' disabled' : '';
    }

    protected function _getValue($key)
    {
        return $this->getElement()->getData('value/' . $key);
    }

    protected function _getAddRowButtonHtml($container, $template, $title='Add')
    {
        if (!isset($this->_addRowButtonHtml[$container])) {
            $this->_addRowButtonHtml[$container] = $this->getLayout()->createBlock('adminhtml/widget_button')
                    ->setType('button')
                    ->setClass('add ' . $this->_getDisabled())
                    ->setLabel($this->__($title))
                    ->setOnClick("Element.insert($('" . $container . "'), {bottom: $('" . $template . "').innerHTML})")
                    ->setDisabled($this->_getDisabled())
                    ->toHtml();
        }
        return $this->_addRowButtonHtml[$container];
    }

    protected function _getRemoveRowButtonHtml($selector = 'div', $title = 'Remove')
    {
        if (!$this->_removeRowButtonHtml) {
            $this->_removeRowButtonHtml = $this->getLayout()->createBlock('adminhtml/widget_button')
                    ->setType('button')
                    ->setClass('delete v-middle ' . $this->_getDisabled())
                    ->setLabel($this->__($title))
                    ->setOnClick("Element.remove($(this).up('" . $selector . "'))")
                    ->setDisabled($this->_getDisabled())
                    ->toHtml();
        }
        return $this->_removeRowButtonHtml;
    }
}
