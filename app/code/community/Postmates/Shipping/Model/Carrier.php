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
 * Postmates will implement delivery via crowd-sourced options.
 */
class Postmates_Shipping_Model_Carrier
    extends Mage_Shipping_Model_Carrier_Abstract
    implements Mage_Shipping_Model_Carrier_Interface
{
    // @note Various attributes marked protected -
    //       set them yourself in a subclass only if you know what you're doing!
    protected
        $_code                          = 'postmates_shipping',
        $_sApiKey                       = '',
        $_sCustomerId                   = '',
        $_bEstimatesEnabled             = false,
        $_sCustomTitle                  = '',
        $_sCustomMethod                 = '',
        $_fFlatRate                     = '',
        $_bQuoteNotificationsEnabled    = false,
        $_sQuoteNotificationEmail       = '',
        $_iQuoteEmailTemplateId         = -1,
        $_bDeliveryNotificationsEnabled = false,
        $_sDeliveryNotificationEmail    = '',
        $_iDeliveryEmailTemplateId      = -1,
        $_aPickupAddrs                  = array();

    /* array representation of the dropoff address */
    private $_aDropoffAddr;

    private $_oMageSession;

    public function getAllowedMethods()
    {
        return array('postmates' => 'Postmates Delivery');
    }

    /**
     * Declare the carrier title and method. Note unlike other built-in carriers like UPS and USPS etc,
     * there is only one method for Postmates. Admins are able to customize the carrier title and name
     * of the method in the admin if they wish.
     *
     * This method will determine the optimal pickup address(es) if there are more than one entered in the admin
     * then get quotes for all of them and present the user with the least expensive quote.
     *
     * This method is marked final on purpose, it's an incarnation of the template-method design pattern.
     * The algorithm is defined here, various methods it relies upon that may be safely overridden have been
     * marked protected.
     */
    final public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        //---------------------------------------------------
        // Load the configuration, perform initial validation
        //---------------------------------------------------
        $this->_oMageSession = Mage::getSingleton('core/session');

        $this->_readConfigData();

        // Get the currently selected shipping address and convert it to an array which will
        // make it compatible with our validation and string conversion methods
        $oShipAddr = Mage::getSingleton('checkout/session')->getQuote()->getShippingAddress();

        // Get the shipping address and convert it to an array for convenience
        $this->_aDropoffAddr = self::_addrObjectToArray($oShipAddr);

        // Bail if the shipping address is invalid
        // @note We allow the address component to be empty because the
        //       estimation widget does not allow entry of one
        if(!self::_addrValid($this->_aDropoffAddr, $this->_bEstimatesEnabled)) {
            return false;
        }

        //--------------------
        // Magento boilerplate
        //--------------------
        // Mage_Shipping_Model_Rate_Result
        $oResult = Mage::getModel('shipping/rate_result');

        /** @var Mage_Shipping_Model_Rate_Result_Method $oRate */
        $oRate = Mage::getModel('shipping/rate_result_method');
 
        $oRate->setCarrier($this->_code);

        //---------------------------------
        // Customize Carrier Title & Method
        //---------------------------------
        $this->_customizeCarrierTitle($oRate);
        $this->_customizeCarrierMethod($oRate);

        //-------------------------------------------------------------------
        // Dynamically populate the delivery price via a quote from Postmates
        // @note The first call here is overridable, so you may modify the
        //       logic by which the ideal pickup address is selected
        //-------------------------------------------------------------------
        $aIdealPickupAddresses = $this->_selectPickupAddressesToQuote();
        $aQuotesAndPickupAddrs = $this->_fetchQuotes($aIdealPickupAddresses);

        //-----------------------------------------------------------------
        // If we fail to determine the best quote,
        // then indicate to Magento not to display anything for this module
        //-----------------------------------------------------------------
        if(!$aQuotesAndPickupAddrs) {
            Mage::log('Failed to get a quote from Postmates', Zend_Log::ERR);
            return false;
        }

        $aQuotesAndPickupAddr = $this->_determineBestQuote($aQuotesAndPickupAddrs);
        $oQuote               = $aQuotesAndPickupAddr['quote'];
        $fRate                = $oQuote['fee'];
        $aPickupAddr          = $aQuotesAndPickupAddr['pickup-addr'];

        if(!$oQuote || !is_array($aPickupAddr)) {
            Mage::log('Failed to determine the best quote from Postmates', Zend_Log::ERR);
            return false;
        }

        // Finally, persist the chosen Postmates quote and pickup address
        $this->_persistPostmatesQuote($oQuote);
        $this->_persistPickupAddr($aPickupAddr);

        //------------------------------------------------------------------
        // Set the price and the cost of this rate
        // @note Intentionally allowing flat rates of $0 if the admin wishes
        //------------------------------------------------------------------
        $oRate->setCost($fRate);
        if(is_numeric($this->_fFlatRate)) {
            $oRate->setPrice($this->_fFlatRate);
        } else {
            $oRate->setPrice($fRate);
        }

        //---------------------------
        // Append and return the rate
        //---------------------------
        return $oResult->append($oRate);
    }

    /**
     * Create the delivery order with Postmates!
     * Use prior quote and selected pickup point stored in the session.
     */
    final public function createDelivery(Mage_Sales_Model_Order $oOrder, $sManifest, $sDropoffNotes='')
    {
        $oShipAddr = $oOrder->getShippingAddress();
        $aShipAddr = self::_addrObjectToArray($oShipAddr);

        // Build drop off address
        $sDropoffName    = $oShipAddr->getFirstname();
        $sDropoffPhone   = $oShipAddr->getTelephone();
        $sDropoffCompany = $oShipAddr->getCompany();
        $sDropoffAddr    = self::_addressString($aShipAddr);

        // Fetch the persisted quote and pickup address
        $this->_readConfigData();
        $this->_oMageSession = Mage::getSingleton('core/session');
        $aPostmatesQuote     = $this->_retrievePostmatesQuote();
        $aPickupAddr         = $this->_retrievePickupAddr();

        if(!is_array($aPostmatesQuote)) {
            Mage::log('Failed to retrieve the Postmates quote');
            return false;
        }

        if(!is_array($aPickupAddr)) {
            Mage::log('Failed to retrieve the Pickup address');
            return false;
        }

        // If the quote has expired we'll have to send the request for delivery without the quote_id
        $oExpires          = $aPostmatesQuote['expires'];
        $oNow              = new \DateTime('now', $aPostmatesQuote['expires']->getTimezone());
        $iPostmatesQuoteId = $aPostmatesQuote['id'];
        if($oNow > $oExpires) {
            $iPostmatesQuoteId = null;
        }

        // Place the delivery order with Postmates
        $oClient   = $this->_createClient();
        $oDelivery = $oClient->createDelivery(
            $sManifest,
            $aPickupAddr['name'],
            self::_addressString($aPickupAddr),
            $aPickupAddr['phone'],
            $sDropoffName,
            $sDropoffAddr,
            $sDropoffPhone,
            $sDropoffCompany,
            '', // $sManifestReference
            '', // $sPickupBusinessName - TODO maybe populate off the store name or something else ??
            $aPickupAddr['notes'],
            $sDropoffNotes,
            $iPostmatesQuoteId
        );

        // This is a real edge case, but it's better to provide handling for it than to let orders
        // slip through without having deliveries scheduled with Postmates. The situation is this,
        // we've done our best to determine if the quote is already expired before sending the delivery
        // request. Who knows, maybe there's some clock skew or some other reason, but Postmates could
        // still come back and tell us the first attempt (with a quote_id supplied) was expired. Testing
        // has shown that immediately sending another delivery request with identical data except for
        // the omitting the quote ID results in the same error. Likely we're running into some sort of
        // caching response from the Postmates server. What we'll do then is generate another quote,
        // then use that to create the delivery request. If that doesn't bust the cache, nothing will!
        if($oDelivery->isError() && $oDelivery['code'] == 'expired_quote') {
            // Get a new quote for the already chosen pickup address
            $aError              = array();
            $this->_aDropoffAddr = $aShipAddr;
            $oQuote              = $this->_getQuote($aPickupAddres, $aError);

            if($oQuote && !$oQuote->isError()) {
                $oDelivery = $oClient->createDelivery(
                    $sManifest,
                    $aPickupAddr['name'],
                    self::_addressString($aPickupAddr),
                    $aPickupAddr['phone'],
                    $sDropoffName,
                    $sDropoffAddr,
                    $sDropoffPhone,
                    $sDropoffCompany,
                    '', // $sManifestReference
                    '', // $sPickupBusinessName - TODO maybe populate off the store name or something else ??
                    $aPickupAddr['notes'],
                    $sDropoffNotes,
                    $oQuote['id']
                );
            }
        }

        // If the delivery request was a success,
        // clear the stuff we had stored in the session
        if(!$oDelivery->isError()) {
            $this->_cleanupPersistedQuote();
            $this->_cleanupPersistedPickupAddr();
        } else {
            Mage::log('Failed to create Postmates Delivery request: ' . $oDelivery['message'], Zend_Log::ERR);

            $this->_sendDeliveryNotification(
                $oOrder,
                self::_addressString($aPickupAddr),
                self::_addressString($aShipAddr),
                $oQuote);
        }

        return $oDelivery;
    }

    /**
     * Using the Postmates delivery ID from an existing order,
     * fetch the status from Postmates, then pass along the DAO to client code.
     */
    public function getStatus($sDeliveryId)
    {
        $this->_readConfigData();
        $oClient = $this->_createClient();
        return $oClient->getDeliveryStatus($sDeliveryId);
    }

    //==================================================================================
    // Extensible methods --------------------------------------------------------------
    // ---------------------------------------------------------------------------------
    // The following set of methods are open for you to override in a subclass.
    // Before you override a particular method, make sure to read the comment about
    // what it does, so you understand what you are doing by changing the implementation
    //==================================================================================

    /**
     * Read data from the configuration.
     * @note Override this method at your own risk!
     * An overridden version must correctly set all the properties this stock implementation does.
     */
    protected function _readConfigData()
    {
        $this->_sApiKey                       = $this->getConfigData('api_key'); 
        $this->_sCustomerId                   = $this->getConfigData('customer_id');
        $this->_bEstimatesEnabled             = (bool)$this->getConfigData('estimates_enabled');
        $this->_sCustomTitle                  = trim($this->getConfigData('title'));
        $this->_sCustomMethod                 = $this->getConfigData('custom_carrier_method');
        $this->_fFlatRate                     = $this->getConfigData('flat_rate');
        $this->_bQuoteNotificationsEnabled    = (bool)$this->getConfigData('quote_notifications_enabled');
        $this->_sQuoteNotificationEmail       = $this->getConfigData('quote_notification_email');
        $this->_iQuoteEmailTemplateId         = (int)$this->getConfigData('quote_email_template');
        $this->_bDeliveryNotificationsEnabled = (bool)$this->getConfigData('delivery_notifications_enabled');
        $this->_sDeliveryNotificationEmail    = $this->getConfigData('delivery_notification_email');
        $this->_iDeliveryEmailTemplateId      = (int)$this->getConfigData('delivery_email_template');
        $this->_aPickupAddrs                  = unserialize($this->getConfigData('pickup_addresses'));
    }

    /**
     * Select a subset of pickup addresses to request quotes from Postmates for.
     * The pickup addresses you return should be ideal, based upon the shipping address of the customr,
     * which is stored in $this->_aPickupAddrs.
     *
     * If you override this method, it must return at minimum one entry from $this->_aPickupAddrs.
     * Be careful not to return too many. Each of them will be passed to the Postmates API individually,
     * (there is no bulk quote endpoint), incurring a decent wait for your customer based on the performance
     * of their internet connection. The stock implementation does its best to widdle the pickup addresses
     * down to one, but will return at most three to have quotes generated against.
     */
    protected function _selectPickupAddressesToQuote()
    {
        // Convert the shipping address to a string
        $this->_sDropoffAddr = self::_addressString($this->_aDropoffAddr);

        // Get the list of configured pickup addresses
        $iNumPickupAddrs = count($this->_aPickupAddrs);

        // If we have no pickup addresses, just bail...
        if($iNumPickupAddrs < 1) {
            return false;
        }
        // If there's only one pickup address, we can only quote against that
        elseif($iNumPickupAddrs == 1) {
            return $this->_aPickupAddrs;
        }

        // Last case, there are multiple pickup addresses. In this case we don't want to
        // get quotes on all of them, let's take a look at them and try to come up with a
        // subset that we can get quotes against. We'll end up sticking with the cheapest one.

        // Start by determining which addresses look the most desirable
        usort($this->_aPickupAddrs, array($this, '_rankPickupAddresses'));

        // If the winner is vastly higher than the second entry, let's save a lot of time
        // (in network overhead) and only fetch a quote for the best match.
        // Otherwise get quotes against the top 3 addresses.
        $aFirstTwoAddrs     = array_slice($this->_aPickupAddrs, 0, 2);
        $iTopScore          = $aFirstTwoAddrs[0]['rank'];
        $iSecondScore       = $aFirstTwoAddrs[0]['rank'];
        $iNumAddressesToTry = 3;
        if($iTopScore >= $iSecondScore * 2) {
            $iNumAddressesToTry = 1;
        }

        return array_slice($this->_aPickupAddrs, 0, $iNumAddressesToTry);
    }

    /**
     * Determine the best quote. By default, this selects the quote with the lowest fee.
     * You may override to use whatever logic you wish instead. You must return only one
     * group of quote/pickup address if you do.
     */
    protected function _determineBestQuote(array $aQuotesAndPickupAddrs)
    {
        $oQuote      = null;
        $aPickupAddr = null;
        $fFee        = 0;
        $fLowestRate = (float)PHP_INT_MAX;
        foreach($aQuotesAndPickupAddrs as $aQuoteAndPickupAddr) {
            $_oQuote = $aQuoteAndPickupAddr['quote'];
            $fFee    = $_oQuote['fee'];
            if($fFee < $fLowestRate) {
                $fLowestRate = $fFee;
                $oQuote      = $_oQuote;
                $aPickupAddr = $aQuoteAndPickupAddr['pickup-addr'];
            }
        }

        return array('quote' => $oQuote, 'pickup-addr' => $aPickupAddr);
    }

    //==================================================================================
    // Quote / Pickup Address persistence methods --------------------------------------
    // ---------------------------------------------------------------------------------
    // Persiste a Postmates quote and associated pickup address.
    // Currently this module uses the PHP session to persist these values.
    // This is moderately brittle, however in most cases if the session has expired, the
    // customer's cart has been emptied, and in the vast majority of cases, customers
    // will complete a checkout while their session is active. These methods may be
    // overridden, so if you want a stronger persistence mechanism before a future
    // release of the module, you may implement it yourself. Please note
    // WE WILL NOT BE GIVING REFUNDS BECAUSE THE MODULE PERSISTS THESE VALUES IN THE SESSION
    // That has been clearly stated online and you have been given fair warning. That said
    // there are plans to store these values on the Magento quote in a subsequent release
    // in the near future! Notes for the future release follow.
    // 
    // Store the Postmates quote on the Magento quote, that way we won't ever lose one
    // Furthermore, we need to check the expiration time of the quote and if it has expired,
    // tee up a fresh quote. The sad thing is the customer will have already checked out, so
    // if we get to that scenario the store owner will run the risk of the delivery costing
    // a bit more than the original quote.
    //==================================================================================
    /**
     * Fetch a persisted Postmates Quote
     * @returns Postmates_Shipping_Client_Dao_DeliveryQuote
     */
    protected function _retrievePostmatesQuote()
    {
        return $this->_oMageSession->getPostmatesQuote();
    }

    /**
     * Fetch persisted pickup address
     * @returns array
     */
    protected function _retrievePickupAddr()
    {
        return $this->_oMageSession->getPostmatesPickupAddr();
    }

    /**
     * Store a Postmates quote
     */
    protected function _persistPostmatesQuote(Postmates_Shipping_Client_Dao_DeliveryQuote $oQuote)
    {
        $this->_oMageSession->setPostmatesQuote($oQuote->getArrayCopy());
    }

    /**
     * Store a pickup address
     */
    protected function _persistPickupAddr(array $aPickupAddress)
    {
        $this->_oMageSession->setPostmatesPickupAddr($aPickupAddress);
    }

    /**
     * 'Cleanup' (read: remove) a persisted Postmates quote
     * Likely you won't need this if you're using database persistence, but perhaps you'll find it relevant
     */
    protected function _cleanupPersistedQuote()
    {
        $this->_oMageSession->unsPostmatesQuote();
    }

    /**
     * 'Cleanup' (read: remove) a persisted pickup address
     * Likely you won't need this if you're using database persistence, but perhaps you'll find it relevant
     */
    protected function _cleanupPersistedPickupAddr()
    {
        $this->_oMageSession->unsPostmatesPickupAddr();
    }

    /**
     *  Customize carrier title  in the display if the admin would like us to
     *
     *  Default Carrier Title is "Postmates"
     *  Otherwise, take it from the admin config
     *
     *  @note The default value default value in config.xml work as expected
     *        because Magento takes an empty value from the admin in system.xml and rolls with it!!
     *        We'll protect ourselves in this case and ensure the default is used if the admin has
     *        not chosen to customize the carrier title
     */
    protected function _customizeCarrierTitle(Mage_Shipping_Model_Rate_Result_Method $rate)
    {
        if(empty($this->_sCustomTitle)) {
            Mage::app()->getStore()->setConfig('carriers/postmates_shipping/title', 'Postmates');
        }
    }

    /**
     * Customize shipping method in the display if the admin would like us to
     */
    protected function _customizeCarrierMethod(Mage_Shipping_Model_Rate_Result_Method $rate)
    {
        $method = 'Postmates Delivery';
        if(!empty($this->_sCustomMethod)) {
            $method = $this->_sCustomMethod;
        }
        $rate->setMethod('delivery');
        $rate->setMethodTitle($method);
    }

    //==================================================================================
    // Closed  methods -----------------------------------------------------------------
    // ---------------------------------------------------------------------------------
    // The following set of methods are available for you to call from a subclass,
    // however they are not available for you to override. Doing so would potentially
    // break the template method and main method of this class, collectRates()
    //==================================================================================

    /**
     * Rank the pickup addresses based on the shipping address of the quote.
     * This is the comparison callback for usort().
     */
    final protected function _rankPickupAddresses(array &$aPickupAddr1, array &$aPickupAddr2)
    {
        $iAddr1Rank = $this->_getPickupAddrRank($this->_aDropoffAddr, $aPickupAddr1);
        $iAddr2Rank = $this->_getPickupAddrRank($this->_aDropoffAddr, $aPickupAddr2);

        $aPickupAddr1['rank'] = $iAddr1Rank;
        $aPickupAddr2['rank'] = $iAddr2Rank;

        if($iAddr1Rank == $iAddr2Rank) {
            return 0;
        }

        return ($iAddr1Rank > $iAddr2Rank) ? -1 : 1;
    }

    /**
     * Compare a pickup address to a shipipng adddress to determine a rank for the pickup address.
     *
     * @param array $aShipAddr An array representation of the shipping address
     * @param array $aPickupAddr An array representation of the pickup address
     * @returns int
     */
    final protected function _getPickupAddrRank(array $aShipAddr, array $aPickupAddr)
    {
        $sShipCity  = strtolower($aShipAddr['city']);
        $sShipState = strtolower($aShipAddr['state']);
        $iShipZip   = (int)$aShipAddr['zip'];

        $sPickupCity  = strtolower($aPickupAddr['city']);
        $sPickupState = strtolower($aPickupAddr['state']);
        $iPickupZip   = (int)$aPickupAddr['zip'];

        $iResult = 0;

        if($sShipState == $sPickupState) {
            if($sShipCity == $sPickupCity) {
                $iResult += 10;
            }

            if($iShipZip == $iPickupZip) {
                $iResult += 7;
            }
            elseif(abs($iShipZip - $iPickupZip) < 5) {
                $iResult += 6;
            }
            elseif(abs($iShipZip - $iPickupZip) < 10) {
                $iResult += 5;
            }
            elseif(abs($iShipZip - $iPickupZip) < 20) {
                $iResult += 4;
            }
            elseif(abs($iShipZip - $iPickupZip) < 100) {
                $iResult += 3;
            }
            elseif(abs($iShipZip - $iPickupZip) < 200) {
                $iResult += 2;
            }
            elseif(abs($iShipZip - $iPickupZip) < 500) {
                $iResult += 1;
            }
        }

        return $iResult;
    }

    /**
     * Request one or more quotes from Postmates using the provided pickup addresses.
     * The pickup addresses to use are determined by the _selectPickupAddressesToQuote()
     * method which you may override if you wish. Once one or more quotes are received,
     * this method selects the least expensive one, and persists it, along with the pickup
     * address that drove the least expensive quote.
     */
    final protected function _fetchQuotes(array $aPickupAddrs)
    {
        // Loop over the provided pickup addresses getting quotes from Postmates for each one
        $aErrors               = array();
        $aQuotesAndPickupAddrs = array();
        foreach($aPickupAddrs as $aPickupAddr) {
            $aError = array();
            $oQuote = $this->_getQuote($aPickupAddr, $aError);

            // Bail if we couldn't get a quote
            if(!$oQuote) {
                $aErrors[] = $aError;
                continue;
            }

            // Append the quote to our result along with the associated pickup address
            $aQuotesAndPickupAddrs[] = array(
                'quote'       => $oQuote,
                'pickup-addr' => $aPickupAddr);
        }

        // Send an email notification if enabled by the admin
        if(count($aQuotesAndPickupAddrs) == 0) {
            $this->_sendQuoteNotification($aErrors, self::_addressString($this->_aDropoffAddr));

            return false;
        }

        return $aQuotesAndPickupAddrs;
    }

    /**
     * Instantiate the Postmates API client via credentials supplied in the admin configuration.
     */
    final protected function _createClient()
    {
        $aCreds = array(
            'customer_id' => $this->_sCustomerId,
            'api_key'     => $this->_sApiKey
        );
        return new Postmates_Shipping_Client_Client('', $aCreds);
    }

    /**
     * Get a single quote from Postmates.
     * @note Since the dropoff address will be the same every time, we expect an already validated,
     *       string representation to be supplied here.
     */
    final protected function _getQuote(array $aPickupAddress, array &$aError)
    {
        // Bail if the pikcup address is invalid
        if(!self::_addrValid($aPickupAddress)) {
            return false;
        }

        // Convert the pickup address to a string
        $sPickupAddress = self::_addressString($aPickupAddress);

        // Request a quote from Postmates
        try {
            $oClient = $this->_createClient();
            $oQuote  = $oClient->requestDeliveryQuote($sPickupAddress, $this->_sDropoffAddr);
            if(!$oQuote->isError()) {
                return $oQuote;
            }

            // Pass error information back to the caller
            $aError['pickup-address'] = $sPickupAddress;
            $aError['code']           = $oQuote['code'];
            $aError['message']        = $oQuote['message'];

            // We failed to fetch the quote return false
            $sErrMsg =
                'Error from Postmates API; Code: ' . $oQuote['code'] .
                '; Message: ' . $oQuote['message'];

            Mage::log($sErrMsg, Zend_Log::ERR);

            return false;
        }
        // We failed to fetch the quote return false
        catch(Exception $e) {
            $sErrMsg = 'Exception thrown trying to create a Postmates quote - ' . $e->getMessage();
            Mage::log($sErrMsg, Zend_Log::ERR);

            return false;
        }
    }

    /**
     * Convert interesting parts of a Magento address object to an array for our purposes.
     */
    static final protected function _addrObjectToArray(Mage_Customer_Model_Address_Abstract $oAddr)
    {
        return array(
            'address' => $oAddr->getStreetFull(),
            'city'    => $oAddr->getCity(),
            'state'   => $oAddr->getRegionCode(),
            'zip'     => $oAddr->getPostcode()
        );
    }

    /**
     * Convert a pickup address from the carrier configuration to a string representation.
     */
    static final protected function _addressString(array $aAddress)
    {
        $sAddr = '';
        if(!empty($aAddress['address'])) {
            $sAddr = $aAddress['address'] . ', ';
        }

        return
            $sAddr .
            $aAddress['city']    . ', ' .
            $aAddress['state']   . ' '  .
            $aAddress['zip'];
    }

    /**
     * Determine if an address is valid. Our only criteria here is that the state and the zip are non-empty.
     * If $bMinimalAddrOk == false (the default), then the street address and the city must also be non-empty.
     * $bMinimalAddrOk is provided to support fetching quotes when only a state and zip are provided by the
     * estimation widget on the cart page.
     */
    static final protected function _addrValid(array $addr, $bMinimalAddrOk=false)
    {
        if(!$bMinimalAddrOk && (empty($addr['address']) || empty($addr['city']))) {
            return false;
        }

        return
            !empty($addr['state']) &&
            !empty($addr['zip']);
    }

    /**
     * Send an email to the configured address if we've failed to create a Postmates quote.
     * This behavior is configurable.
     */
    protected function _sendQuoteNotification(array $aErrors, $sShipAddr)
    {
        // Bail if quote notifications are disabled
        if(!$this->_bQuoteNotificationsEnabled) {
            return;
        }

        // Coalesce error information into an HTML format
        $sErrors = '';
        foreach($aErrors as $aError) {
            $sErrors .=
                '<p><ul>' .
                '<li><strong>Pickup Address</strong> <em>' . $aError['pickup-address'] . '</em></li>' .
                '<li><strong>Postmates Error Message</strong> <em>' . $aError['message'] . '</em></li>' .
                '<li><strong>Postmates Error Code</strong> <em>' . $aError['code'] . '</em></li>' .
                '</ul></p>';
        }

        // If this was for an estimate, strip off the missing street component
        if($sShipAddr[0] == ',') {
            $sShipAddr = substr($sShipAddr, 2);
        }

        $aTemplateParams = array(
            'quoteErrors'     => $sErrors,
            'shippingAddress' => $sShipAddr
        );

        $this->_sendNotificationEmail(
            $this->_iQuoteEmailTemplateId, $this->_sQuoteNotificationEmail, $aTemplateParams);
    }

    /**
     * Send an email to the configured address if we've failed to create a Postmates delivery request.
     * This behavior is configurable.
     */
    protected function _sendDeliveryNotification(
        $oOrder, $sPickupAddr, $sShipAddr, Postmates_Shipping_Client_BaseDao $oError
    ) {
        // Bail if error notifications are disabled
        if(!$this->_bDeliveryNotificationsEnabled) {
            return;
        }

        $aTemplateParams = array(
            'pickupAddress'   => $sPickupAddr,
            'shippingAddress' => $sShipAddr,
            'errorCode'       => $oError['code'],
            'errorMessage'    => $oError['message'],
            'order'           => $oOrder
        );

        $this->_sendNotificationEmail(
            $this->_iDeliveryEmailTemplateId, $this->_sDeliveryNotificationEmail, $aTemplateParams);
    }

    /**
     * Send a notification email. This is largely configurable and overridable.
     */
    protected function _sendNotificationEmail($iTemplateId, $sEmailHandle, array $aTemplateParams)
    {
        try {
            $oMailer = Mage::getModel('core/email_template_mailer');

            // Target is configurable
            $oEmailInfo = Mage::getModel('core/email_info');
            $oEmailInfo->addTo(
                $this->_getStoreEmailAddressSenderOption($sEmailHandle, 'email'),
                $this->_getStoreEmailAddressSenderOption($sEmailHandle, 'name'));
            $oMailer->addEmailInfo($oEmailInfo);

            // Sender always uses the general handle
            $oMailer->setSender(array(
                'name'  => $this->_getStoreEmailAddressSenderOption('general', 'name'),
                'email' => $this->_getStoreEmailAddressSenderOption('general', 'email'),
            ));

            // TODO Support multiple stores
            //      The extension is known not to support multiple websites / stores yet.
            //      REFUNDS WILL NOT BE ISSUED FOR THIS REASON
            // $oMailer->setStoreId($storeId);
            $oMailer->setTemplateId($iTemplateId);
            $oMailer->setTemplateParams($aTemplateParams);

            $oMailer->send();
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * @note This method taken from open source extension
     *       https://github.com/ajzele/Inchoo_AdminOrderNotifier
     * @param $identType ('general' or 'sales' or 'support' or 'custom1' or 'custom2')
     * @param $option ('name' or 'email')
     * @return string
     */
    private function _getStoreEmailAddressSenderOption($identType, $option)
    {
        if (!$generalContactName = Mage::getSingleton('core/config_data')->getCollection()->getItemByColumnValue('path', 'trans_email/ident_'.$identType.'/'.$option)) {
            $conf = Mage::getSingleton('core/config')->init()->getXpath('/config/default/trans_email/ident_'.$identType.'/'.$option);
            $generalContactName = array_shift($conf);
        } else {
            $generalContactName = $generalContactName->getValue();
        }

        return (string)$generalContactName;
    }
}
