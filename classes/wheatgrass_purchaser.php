<?php

// Retrieves the Wordpress Configuration file for database access
require_once($_SERVER['DOCUMENT_ROOT']."/wp-config.php");

class WheatgrassPurchaser {

    protected $shopifyUsername;
    protected $shopifyPassword;
    protected $shopifyUrl = "https://foodstore.myshopify.com/admin/api/2019-04";
    protected $shopifyHeaders;
    protected $shopifyLocationId;

    protected $testMode;

    protected $shopifyOrder;

    protected $shopifyPayload;
    protected $payloadVerification;

    protected $logger;

    protected $lock_file;

    // The Meta Id thatI used to find all WC Orders
    protected $WC_ORDER_ID = 6969696969;

    public function __construct($payload, $hmac_header, $testMode=false){

        $this->testMode = $testMode;

        $this->shopifyPayload = $payload;

        $this->logData( print_r(json_decode($payload)) );

        // Verify the hook. If testmode is true, this statement resolves to false
        // Skipping the hook verification
        if (!$this->verifyHook($hmac_header) && !$testMode){
            $this->logData("Payload Not Verified");
            $this->payloadVerification = false;
        } else {
            $this->logData("Payload Verified");
            $this->payloadVerification = true;
        }

        //Defining this now, we may want to pass the values later in case different users exist.
        $this->shopifyUsername = '249BA36000029BBE97499C03DB5A9001';
        $this->shopifyPassword = '5BAA61E4C9B93F3F0682250B6CF8331B';

        $this->makeShopifyHeaders();
    }

    public function isValid(){
        return $this->payloadVerification;
    }

    protected function logData($data){
        if ( is_null($this->logger) ){
            $logger = fopen('api-debug.log', 'a+');
        }

        fwrite($logger, date("c").": ".$data."\n" );
    }

    // This function verifies the data that was sent by Shopify as being legitimate.
    // It calculates a hash based upon the content of the data and the secret.
    protected function verifyHook($hmac_header){
        $secret = "E5E9FA1BA31ECD1AE84F75CAAA474F3A663F05F4C636E8E238FD7AF97E2E500F";

        $calculated_hmac = base64_encode(hash_hmac('sha256', $this->shopifyPayload, $secret, true));
        $this->logData($calculated_hmac);
        return hash_equals($hmac_header, $calculated_hmac);
    }

    // This function creates and saves the headers that are used to authenticate
    // any Shopify API call.
    protected function makeShopifyHeaders(){
        $this->shopifyHeaders =  array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Basic ".base64_encode($this->shopifyUsername.":".$this->shopifyPassword),
                "Content-Type: application/json",
            ),
        );

        return $this->shopifyHeaders;
    }

    // Retrieves the ID of the location of the store. This is used for fulfillment
    protected function getShopifyLocation(){
        $url = $this->shopifyUrl."/locations.json";
        $curl_opts = $this->shopifyHeaders;
        $ch = curl_init($url);
        curl_setopt_array($ch, $curl_opts);

        $results = curl_exec($ch);
        curl_close($ch);

        $this->shopifyLocationId = json_decode($results)->locations[0]->id;

        return $this->shopifyLocationId;
    }

    // Checks to determine if the order has already been fulfilled.
    // Sometimes Shopify sends multiple requests per order. We can't
    // Fulfill the same order multiple times
    protected function checkOrderFulfillment($orderId){
        $url = $this->shopifyUrl."/orders/".$orderId.".json";
        $curl_opts = $this->shopifyHeaders;
        $ch = curl_init($url);
        curl_setopt_array($ch, $curl_opts);

        $results = json_decode(curl_exec($ch));
        curl_close($ch);

        foreach($results->order->note_attributes as $note){
            if ($note->name == "Store Fulfillment"){
                $this->logData( "Order Already Fulfilled" );
                return true;
            }
        }

        return false;
    }

    // Updates the order's notes to mark the order as having been fulfilled.
    // Accepts the order's current notes, adds the fulfillment notes to the 
    // existing notes and sends that to the order.
    protected function modifyOrderFulfillment($orderId, $notes=[]){
        $url = $this->shopifyUrl."/orders/".$orderId.".json";

        $deliveryDate = $this->getDeliveryDate($this->shopifyOrder);
        $deliveryDate = date( "m/d/Y", strtotime($deliveryDate) );
    
        $payload = array(
            'order' => array(
                'note_attributes' => array(
                    array (
                        'name' => 'Store Fulfillment',
                        'value' => 'True'
                    ),
                    array(
                        'name' => 'WheatgrassDeliveryDate',
                        'value' => $deliveryDate,
                    ),
                ),
            )
        );
    
        $ch = curl_init($url);
    
        $curl_opts = $this->shopifyHeaders;
        $curl_opts[CURLOPT_POST] = true;
        $curl_opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        $curl_opts[CURLOPT_RETURNTRANSFER] = true;
        $curl_opts[CURLOPT_CUSTOMREQUEST] = "PUT";
        curl_setopt_array($ch, $curl_opts);
    
        $results = curl_exec($ch);
    
        curl_close($ch);

        return true;
    }

    // This function accepts an orderId and an array of line items
    // The function fulfills the line items specified in the array provided
    protected function fulfillLineItem($orderId, $wg){

        $ids = $this->getNonBundles($wg);

        if ( count($ids) < 1 ){
            return;
        }
        
        $url = $this->shopifyUrl."/orders/".$orderId."/fulfillments.json";

        $lineItems = array();
        foreach($ids as $id){
            $lineItems[] = array("id" => $id);
        }

        $payload = array(
            "fulfillment" => array(
                "location_id" => $this->shopifyLocationId,
                "tracking_number" => null,
                "notify_customer" => false,
                "line_items" => $lineItems,
            ),
        );

        $ch = curl_init($url);

        $curl_opts = $this->shopifyHeaders;
        $curl_opts[CURLOPT_POST] = true;
        $curl_opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        curl_setopt_array($ch, $curl_opts);

        $results = curl_exec($ch);
        curl_close($ch);
    }

    // Gets the metadata of the product. Returns all wheatgrass related metadata
    protected function getVariantMeta($productId, $variantId){
        $url = $this->shopifyUrl."/products/".$productId."/variants/".$variantId."/metafields.json";

        $ch = curl_init($url);
        $curl_opts = $this->shopifyHeaders;
        curl_setopt_array($ch, $curl_opts);

        $results = json_decode( curl_exec($ch) );
        curl_close($ch);
        $wgmeta = array();

        foreach ($results->metafields as $m){
            if ($m->key == "storeid"){
                $wgmeta['wcid'] = $m->value;
            }

            if ($m->key == "wheatgrassBundle"){
                $wgmeta['bundle'] = $m->value;
            }
        }

        // logData( print_r($wgmeta, true) );

        return $wgmeta;
    }

    // This function picks a delivery date for the frozen wheatgrass
    // Delivery cannot be Monday, Saturday or Sunday or UPS Holidays
    // TODO Implement UPS Holidays
    protected function getDeliveryDate($orderData){
        date_default_timezone_set('America/New_York');

        if ($orderData){
            foreach($orderData->note_attributes as $a){
                if ($a->name == "WheatgrassDeliveryDate") {
                    return date( "c", strtotime($a->value) );
                }
            }
        }

        //If we get here, a delivery date was NOT selected. Time to make a date.
        $ONEDAY = (24*60*60);

        //Cutoff for next day delivery is noon, EST
        $cutoff = strtotime(  date('m/d/Y')  ) + (60*60*12);

        //If we're past noon, $delivery date is 2 days later
        if (time() < $cutoff){
            $delivery = $cutoff + $ONEDAY;
        } else {
            $delivery = $cutoff + ($ONEDAY * 2);
        }

        $okDate = false;

        //Not Monday or Saturday or Sunday
        while(!$okDate){
            if ( date("N", $delivery) == 1 ||  date("N", $delivery) > 5){
                $delivery += $ONEDAY;
            } else {
                $okDate = true;
            }
        }

        return date("c", $delivery);
    }

    // Retrieves the order data directly from Shopify
    protected function getOrder($orderId){
        // logData($orderId);

        $url = $this->shopifyUrl."/orders/".$orderId.".json";
        $curl_opts = $this->shopifyHeaders;
        $ch = curl_init($url);
        curl_setopt_array($ch, $curl_opts);

        $results = curl_exec($ch);
        // logData("Get Order Results");
        // logData($results);

        curl_close($ch);

        // $this->

        return json_decode($results)->order;
    }

    // This function takes an order object and sends it to Store
    protected function sendToStore($wcOrder){
        $url = "https://www.store.com/wp-json/wc/v3/orders";
        $consumer_key = 'ck_A94A8FE5CCB19BA61C4C0873D391E987982FBBD3';
        $consumer_secret = 'cs_109F4B3C50D7B0DF729D299BC6F8E9EF9066971F';

        $ch = curl_init($url);
        $curl_opts = array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Basic ".base64_encode($consumer_key.":".$consumer_secret),
                "Content-Type: application/json",
            ),
            CURLOPT_POSTFIELDS => json_encode($wcOrder),
        );

        curl_setopt_array($ch, $curl_opts);
        $results = curl_exec($ch);
        curl_close($ch);

        $this->logData("Data sent to Store");
        $this->logData( print_r(json_decode($results), true) );

    }

    // WooCommerce doesn't like null data being sent. This function recursively searches
    // an array and replaces all null values with an empty strings.
    protected function nullRemover($array){
        
        //If the element is an array, recursively run the same function
        foreach ($array as &$el){
            if ( is_array($el) ){
                $el = $this->nullRemover($el);
            }

            if ( is_null($el) ){
                $el = "";
            }
        }

        return $array;
    }

    // Adds orderId to the database.
    protected function queueOrder($orderId){
        //Add the order to the database.
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $wpdb->postmeta VALUES(null, $this->WC_ORDER_ID, 'order', %d)",
                $orderId
            )
        );
    }

    // Gets all orders from the database.
    protected function getQueueOrders(){
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM $wpdb->postmeta WHERE post_id = $this->WC_ORDER_ID"
        );
    }

    protected function deleteOrder($metaId){
        global $wpdb;

        return $wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $metaId ) );

    }

    // Returns false if the file is locked (Worker is not free).
    // Returns true if the file is open
    protected function isFileLocked(){
        $this->lock_file = fopen('wclock.pid', "c");

        if ( !flock($this->lock_file, LOCK_EX | LOCK_NB) ){
            return true;
        }

        return false;
    }

    protected function findWheatgrass($orderData){
        //Step 2, get the meta data of each individual product
        //Step 3, determine if any of the products are wheatgrass products.
        $orders = array();
        foreach($orderData->line_items as $i){
            $meta = $this->getVariantMeta($i->product_id, $i->variant_id);
            if ( count($meta) > 0 ){
                $orders[] = array(
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'variant_id' => $i->variant_id,
                    'quantity' => $i->quantity,
                    'title' => $i->title,
                    'price' => $i->price,
                    'wcid' => (int)$meta['wcid'],
                    'bundle' => $meta['bundle'],
                );
            }
        }

        //Step 4, if there is no wheatgrass, return false
        if ( count($orders) < 1){
            $this->logData( "No Wheatgrass" );
            return false;
        }

        // Step 5, if the order has already been fulfilled, return false
        if ($this->checkOrderFulfillment($orderData->id)){
            return false;
        }

        return $orders;

    }

    // Generates an order array that WooCommerce finds acceptable
    protected function makeWCOrder($orderData, $wg){
        //Step 5, if there is wheatgrass, build the order data

        // Go through the array of Store indexes and find the purchase that it
        // corresponds to so that we can grab the quantity

        $deliveryDate = $this->getDeliveryDate($orderData);

        $wcOrder = array();

        //transaction_id is shopify's order number
        $wcOrder['payment_method'] = 'paypal_pro_payflow';
        $wcOrder['payment_method_title'] = 'Credit Card';

        $wcOrder['transaction_id'] = (string)$orderData->order_number;
        $wcOrder['cart_hash'] = $orderData->cart_token;

        //status needs to be on-hold
        $wcOrder['status'] = "on-hold";

        $wcOrder['billing'] = array(
            'first_name' => $orderData->billing_address->first_name,
            'last_name'  => $orderData->billing_address->last_name,
            'address_1'  => $orderData->billing_address->address1,
            'address_2'  => $orderData->billing_address->address2,
            'city'       => $orderData->billing_address->city,
            'state'      => $orderData->billing_address->province_code,
            'postcode'   => $orderData->billing_address->zip,
            'country'    => $orderData->billing_address->country_code,
            'email'      => $orderData->email,
            'phone'      => $orderData->billing_address->phone,
        );

        $wcOrder['shipping'] = array(
            'first_name' => $orderData->shipping_address->first_name,
            'last_name'  => $orderData->shipping_address->last_name,
            'address_1'  => $orderData->shipping_address->address1,
            'address_2'  => $orderData->shipping_address->address2,
            'city'       => $orderData->shipping_address->city,
            'state'      => $orderData->shipping_address->province_code,
            'postcode'   => $orderData->shipping_address->zip,
            'country'    => $orderData->shipping_address->country_code,
            'email'      => $orderData->email,
            'phone'      => $orderData->shipping_address->phone,
        );

        $wcOrder['shipping_lines'][] = array(
            'method_id' => "free_shipping",
            'method_title' => "No Charge",
        );

        $wcOrder['meta_data'][] = array(
            'key' => 'arrival_date',
            'value' => $deliveryDate,
        );

        $wcOrder['meta_data'][] = array(
            'key' => '_billing_affiliate_id',
            'value' => "foodstore",
        );

        $wcOrder['meta_data'][] = array(
            'key' => 'affiliate_order_id',
            'value' => $orderData->order_number,
        );

        $wcOrder['meta_data'][] = array(
            'key' => '_shipping_email',
            'value' => $orderData->email,
        );

        $wcOrder['meta_data'][] = array(
            'key' => '_shipping_phone',
            'value' => $orderData->shipping_address->phone,
        );

        $wcOrder['meta_data'][] = array(
            'key' => 'created_via_api',
            'value' => 1,
        );

        $wcOrder['customer_ip_address'] = $orderData->client_details->browser_ip;
        $wcOrder['customer_user_agent'] = $orderData->client_details->user_agent;

        foreach($wg as $o){
            $wcOrder['line_items'][] = array(
                'product_id' => $o['wcid'],
                'quantity' => $o['quantity'],
                'total' => $o['price'],
            );
        }

        $wcOrder = $this->nullRemover($wcOrder);

        return $wcOrder;
    }

    // Determines if the product is part of a bundle. Returns an 
    // Array of non-bundle products
    protected function getNonBundles($wg){
        $fulfillmentIds = array();
        
        foreach($wg as $o){
            if ($o['bundle'] == "false"){
                $fulfillmentIds[] = $o['id'];
            }
        }

        return $fulfillmentIds;
    }

    /*******************************************************************

    Receiving new orders from Shopify.
    Basic Workflow:

    1. Verify the validty of the order.
    2. Add Order to the database

    2. Check the lock file. If the file is locked, another process is running. Exit.

    3. If the file is NOT locked, become a fulfillment worker.

    4. Retrieve all orders from the database.
    5. Fulfill the orders.
    6. Loop the above until no orders exist.

    7. Unlock the lock file

    *******************************************************************/

    public function processOrder(){

        // Add Order to the database
        $orderId = json_decode($this->shopifyPayload)->id;

        // Add the order id to the database
        $this->queueOrder($orderId);

        // Check if the file is locked, i.e. is another worker working on the file
        // Returns if the file is locked, otherwise moves on to processing the order
        if ($this->isFileLocked()){
            return true;
        }

        $results = [];
        global $wpdb;

        $counter = 0;

        do {
            $results = $this->getQueueOrders();

            foreach ($results as $result){
                // Get the Shopify Order data
                // $result->meta_value is the ID of the order
                $shopifyOrder = $this->getOrder($result->meta_value);

                $this->logData("Shopify Order");
                $this->logData(print_r($shopifyOrder, true));

                // Determine if any unfilled wheatgrass is in the order
                $wg = $this->findWheatgrass($shopifyOrder);

                $this->logData( "Wheatgrass Data" );
                $this->logData( print_r($wg, true) );

                //If unfulfilled Wheatgrass does not exist, this doesn't run
                if ($wg !== false){

                    $this->logData( "Wheatgrass Found" );

                    //Make a WooCommerce order object
                    $wcOrder = $this->makeWCOrder($shopifyOrder, $wg);
                    
                    // Determines if this is a test so that I don't send an order to WC
                    if (!$this->testMode){
                        //Step 6 transmit data to Store
                        $this->sendToStore($wcOrder);
                    }
                    
                    //Step 7 Mark the Order As Having been Sent to Store
                    $this->modifyOrderFulfillment($shopifyOrder->id, $shopifyOrder->note_attributes);

                    //Step 7 Fulfill non-bundle products on Shopify
                    $this->fulfillLineItem($shopifyOrder->id, $wg);

                }

                $this->deleteOrder($result->meta_id);
            }

            ++$counter;

        } while( count ($results) > 0 || $counter > 5);

    }

}

class WheatgrassPurchaserTest extends WheatgrassPurchaser {

    public function invokeMethod(&$object, $methodName, array $parameters = array())
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    public function runTests(){

    }
    
}

?>