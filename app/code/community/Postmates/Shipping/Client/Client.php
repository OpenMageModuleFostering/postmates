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
class Postmates_Shipping_Client_Client extends Zend_Http_Client
{
    const STATUS_PENDING         = 'pending';         // We've accepted the delivery and will be assigning it to a courier.
    const STATUS_PICKUP          = 'pickup';          // Courier is assigned and is en route to pick up the items
    const STATUS_PICKUP_COMPLETE = 'pickup_complete'; // Courier has picked up the items
    const STATUS_DROPOFF         = 'dropoff';         // Courier is moving towards the dropoff
    const STATUS_CANCELED        = 'canceled';        // Items won't be delivered. Deliveries are either canceled by the customer or by our customer service team.
    const STATUS_DELIVERED       = 'delivered';       // Items were delivered successfully.
    const STATUS_RETURNED        = 'returned';        // The delivery was canceled and a new job created to return items to sender. (See related_deliveries in delivery object.)

    const API_VERSION = 'v1';
    
    static private $_aValidStatuses = array(
        self::STATUS_PENDING, self::STATUS_PICKUP, self::STATUS_PICKUP_COMPLETE, self::STATUS_DROPOFF,
        self::STATUS_CANCELED, self::STATUS_DELIVERED, self::STATUS_RETURNED
    );

    private $_sCustomerId;

    public function __construct($sUri='', array $config=array())
    {
        // Validate Postmates config values, these are required for the Postmates Client
        if(!isset($config['customer_id']))
            throw new \InvalidArgumentException('Missing the Postmates Customer ID');
        if(!isset($config['api_key']))
            throw new \InvalidArgumentException('Missing the Postmates API Key');

        // Construct the underlying Zend_Http_Client
        parent::__construct();

        // Store the customer id on the instance for URI generation
        $this->_sCustomerId = $config['customer_id'];

        // Optional Postmates version
        $aHeaders = array();
        if(isset($config['postmates_version']))
            $this->setHeaders('X-Postmates-Version', $config['postmates_version']);

        // HTTP Basic auth header, username is api key, password is blank
        $this->setAuth($config['api_key'], '');
    }

    /**
     * The first step in using the Postmates API is get a quote on a delivery.
     * This allows you to make decisions about the appropriate cost and availability
     * for using the postmates platform, which can vary based on distance and demand.
     *
     * A Delivery Quote is only valid for a limited duration. After which, referencing
     * the quote while creating a delivery will not be allowed.
     *
     * You'll receive a DeliveryQuote response.
     */
    public function requestDeliveryQuote($sPickupAddress, $sDropoffAddress)
    {
        return $this->_postRequest(
            "/customers/{$this->_sCustomerId}/delivery_quotes",
            array('pickup_address' => $sPickupAddress, 'dropoff_address' => $sDropoffAddress));
    }

    /**
     * Once a delivery is accepted, the delivery fee will be deducted from your account.
     * Providing the previously generated quote id is optional, but ensures the costs
     * and etas are consistent with the quote.
     */
    public function createDelivery(
        $sManifest,
        $sPickupName,
        $sPickupAddress,
        $sPickupPhoneNumber,
        $sDropoffName,
        $sDropoffAddress,
        $sDropoffPhoneNumber,
        $sDropoffBusinessName='',
        $sManifestReference='',
        $sPickupBusinessName='',
        $sPickupNotes='',
        $sDropoffNotes='',
        $iQuoteId=null
    ) {
        // Add the required arguments
        $aParams = array(
            'manifest'             => $sManifest,
            'pickup_name'          => $sPickupName,
            'pickup_address'       => $sPickupAddress,
            'pickup_phone_number'  => $sPickupPhoneNumber,
            'dropoff_name'         => $sDropoffName,
            'dropoff_address'      => $sDropoffAddress,
            'dropoff_phone_number' => $sDropoffPhoneNumber,
        );

        // Add optional arguments
        if(!empty($sDropffBusinessName))
            $aParams['dropoff_business_name'] = $sDropoffBusinessName;
        if(!empty($sManifestReference))
            $aParams['manifest_reference'] = $sManifestReference;
        if(!empty($sPickupBusinessName))
            $aParams['pickup_business_name'] = $sPickupBusinessName;
        if(!empty($sPickupNotes))
            $aParams['pickup_notes'] = $sPickupNotes;
        if($iQuoteId !== null)
            $aParams['quote_id'] = $iQuoteId;

        // Configure and send the request
        return $this->_postRequest("/customers/{$this->_sCustomerId}/deliveries", $aParams);
    }

    /**
     * List all deliveries for a customer optionally restricted by a provided status.
     */
    public function listDeliveries($sStatusFilter='')
    {        
        $aOptions = array();
        if($sStatusFilter != '' && in_array($sStatusFilter, self::$_aValidStatuses))
            $aOptions['filter'] = $sStatusFilter;

        return $this->_getRequest(
            "/customers/{$this->_sCustomerId}/deliveries",
            $aOptions);
    }

    /**
     * Retrieve updated details about a delivery.
     * Returns: Delivery Object
     */
    public function getDeliveryStatus($iDeliveryId)
    {
        return $this->_getRequest("/customers/{$this->_sCustomerId}/deliveries/{$iDeliveryId}");
    }

    /**
     * Cancel an ongoing delivery.
     * Returns: Delivery Object
     * A delivery can only be canceled prior to a courier completing pickup. Delivery fees still apply.
     */
    public function cancelDelivery($iDeliveryId)
    {
        return $this->_postRequest("/customers/{$this->_sCustomerId}/deliveries/{$iDeliveryId}/cancel");
    }

    /**
     * Cancel an ongoing delivery that was already picked up
     * and create a delivery that is a reverse of the original.
     * The items will get returned to the original pickup location.
     *
     * A delivery can only be reversed once the courier completed pickup and before the
     * courier has completed dropoff. Delivery fees apply to both the cancelled delivery
     * and new returned delivery.
     *
     * Returns: Delivery Object (the new return delivery)
     */
    public function returnDelivery($iDeliveryId)
    {
        $oRq = $this->createRequest('POST', "customers/{$this->_sCustomerId}/deliveries/{$iDeliveryId}/return");
        return $this->_request($oRq);
    }


    private function _setUri($sUri)
    {
        $this->setUri('https://api.postmates.com/' . self::API_VERSION . $sUri);
    }

    private function _getRequest($sUri, array $aParams=array())
    {
        $this->_setUri($sUri);

        if(count($aParams))
            $this->setParameterGet($aParams);

        return $this->_request('GET');
    }

    private function _postRequest($sUri, array $aParams=array())
    {
        $this->_setUri($sUri);

        if(count($aParams))
            $this->setParameterPost($aParams);

        return $this->_request('POST');
    }

    /**
     * Make the API request and hydrates Dao(s) from the ressponse data.
     */
    private function _request($sMethod)
    {
        $oRsp  = $this->request($sMethod);
        $aData = Zend_Json::decode($oRsp->getBody());

        return Postmates_Shipping_Client_Factory::create($aData);
    }
}
