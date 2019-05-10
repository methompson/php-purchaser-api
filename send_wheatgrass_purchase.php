<?php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Try to prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Allow CORS requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

// Get our Wheatgrass Class
require_once('./classes/wheatgrass_purchaser.php');

// Get the payload from the request body
// And the hash from the header
$payload = file_get_contents('php://input');
$hmac_header = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'];

// Output the header to a file for debugging purposes
// $headerHandler = fopen('file-header.txt', 'w');
// fwrite($headerHandler, $hmac_header);
// fclose($headerHandler);

// Output the payload to a file for debugging purposes
// $orderFileHandler = fopen('received-order.json', 'w');
// fwrite($orderFileHandler, $payload);
// fclose($orderFileHandler);

// Prevents errors, but allows testing.
if (is_null($hmac_header)){
    $hmac_header = "";
}

// Instantiate the Class
$wg = new WheatgrassPurchaser($payload, $hmac_header);

// 
if ($wg->isValid()){
    $wg->processOrder();
}

?>
