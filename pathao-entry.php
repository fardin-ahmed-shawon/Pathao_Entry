<?php
// Include Database Connection


$invoice_no = $_GET['invoice_no'] ?? '';

if (empty($invoice_no) || $invoice_no == '') {
   echo "Invoice number missing!";
}

//////////////////////////////////////////////////////////
// Sandbox //////////////////////////////////////////////

$base_url = "https://courier-api-sandbox.pathao.com";
$client_id = "7N1aMJQbWm";
$client_secret = "wRcaibZkUdSNz2EI9ZyuXLlNrnAv0TdPUPXMnD39";
$username = "test@pathao.com";
$password = "lovePathao";
$grant_type = "password";
$store_id = "107467";

// END //////////////////////////////////////////////
////////////////////////////////////////////////////


//////////////////////////////////////////////////
// Production ///////////////////////////////////

// $base_url = "https://api-hermes.pathao.com";
// $client_id = "olej0GzbjN";
// $client_secret = "jjMgo2VTdaS9mzsSXhXli6QTocDRGgS9d7FXoEN3";
// $username = "rintu.syl.bd@gmail.com";
// $password = "@Rintupathao2025";
// $grant_type = "password";
// $store_id = "107467";

// END //////////////////////////////////////////////
////////////////////////////////////////////////////


///////////////////////////////////////////////////
// Issue An Access Token /////////////////////////
function get_access_token() {
    global $base_url, $client_id, $client_secret, $username, $password, $grant_type;

    $endpoint = "/aladdin/api/v1/issue-token";
    $api_url = $base_url . $endpoint;

    // Prepare request payload
    $payload = [
        "client_id" => $client_id,
        "client_secret" => $client_secret,
        "username" => $username,
        "password" => $password,
        "grant_type" => $grant_type
    ];

    // Initialize cURL
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    // Execute request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        die("cURL Error: " . $error);
    }

    curl_close($ch);

    // Decode JSON response
    $data = json_decode($response, true);

    // Check response
    if ($http_code == 200 && isset($data['access_token'])) {
        return $data['access_token']; // You can also return full $data if needed
    } else {
        // Log or handle error
        error_log("Failed to get access token. Response: " . $response);
        return '';
    }
}
// END //////////////////////////////////////////////
////////////////////////////////////////////////////


//////////////////////////////////////////////////
// Create Consignment/Order /////////////////////
function create_pathao_consignment($invoice_no = '') {
    global $conn, $base_url, $store_id;

    if (empty($invoice_no)) {
        return "Invoice number missing!";
    }

    $endpoint = "/aladdin/api/v1/orders";
    $api_url = $base_url . $endpoint;

    // Get Access Token
    $access_token = get_access_token();
    if (empty($access_token)) {
        return "Access token not found!";
    }

    // Fetch Customer Information
    $sql = "SELECT user_full_name, user_phone, user_address, total_price, payment_method 
            FROM order_info WHERE invoice_no = '$invoice_no'";
    $result = mysqli_query($conn, $sql);
    
    if (!$result || mysqli_num_rows($result) == 0) {
        return "Customer Information Not Found!";
    }

    $data = mysqli_fetch_assoc($result);
    $recipient_name = $data['user_full_name'];
    $recipient_phone = $data['user_phone'];
    $recipient_address = $data['user_address'];
    $order_amount = $data['total_price'];
    $payment_method = $data['payment_method'];
    // END

    // Start Dummy Data
    // $recipient_name = "Test Name";
    // $recipient_phone = "01944667882";
    // $recipient_address = "Apishpara, Dhaka, Bangladesh";
    // $order_amount = "350";
    // $payment_method = "Cash On Delivery";
    // End Dummy Data

    // Determine amount to collect
    $amount_to_collect = ($payment_method == "Cash On Delivery") ? $order_amount : 0;

    // Build payload
    $payload = [
        "store_id" => $store_id,
        "merchant_order_id" => $invoice_no,      
        "recipient_name" => $recipient_name,
        "recipient_phone" => $recipient_phone,
        "recipient_address" => $recipient_address,
        "delivery_type" => "48",       // 48 = Normal delivery
        "item_type" => "2",            // 1 = Documents, 2 = Parcel
        "special_instruction" => "",    
        "item_quantity" => 1,
        "item_weight" => "0.5",        // Example: 0.5 kg
        "item_description" => "This is my item",
        "amount_to_collect" => $amount_to_collect
    ];

    // Initialize cURL
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    // Execute
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return "cURL Error: " . $error;
    }

    curl_close($ch);

    // Decode response
    $decoded = json_decode($response, true);

    if ($httpcode == 200 || $httpcode == 201) {
        // success response
        //return $decoded; 

        // Save the response to the application database
        return save_parcel_info_to_database($decoded);
    } else {
        return [
            "status" => "error",
            "http_code" => $httpcode,
            "response" => $decoded
        ];
    }
}
// END /////////////////////////////////////////////
///////////////////////////////////////////////////


/////////////////////////////////////////////////////////////////////////
// Save Consignment/Order Response to the database /////////////////////
function save_parcel_info_to_database($response = '') {
    global $conn;

    if (is_array($response) && isset($response['data'])) {
        // Extract the data from API response
        $consignment_id = $response['data']['consignment_id'] ?? '';
        $delivery_fee = $response['data']['delivery_fee'] ?? 0;
        $invoice_no = $response['data']['merchant_order_id'] ?? '';

        // Prepare SQL to insert into database
        $stmt = $conn->prepare("INSERT INTO pathao_parcel_info (invoice_no, consignment_id, delivery_fee) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $invoice_no, $consignment_id, $delivery_fee);

        if ($stmt->execute()) {
            echo "Parcel Successfully Created.";
        } else {
            echo "Error Creating Parcel: " . $stmt->error;
        }

        $stmt->close();
    } else {
        echo "API Error: " . json_encode($response);
    }

}
/////////////////////////////////////////////////////////////////////////
// End /////////////////////////////////////////////////////////////////


////////////////////////////////////////////////////////
// Get Parcel/Consignment ID //////////////////////////
function get_consignment_id($invoice_no = '') {
    global $conn;

    // Fetch Consignment ID
    $sql = "SELECT consignment_id FROM pathao_parcel_info WHERE invoice_no = '$invoice_no'";
    $result = mysqli_query($conn, $sql);
    
    if (!$result || mysqli_num_rows($result) == 0) {
        return "Consignment Not Found!";
    }

    $data = mysqli_fetch_assoc($result);
    $consignment_id = $data['consignment_id'];

    return $consignment_id;
}
// END //////////////////////////////////////////////
////////////////////////////////////////////////////


////////////////////////////////////////////////////////
// Get Parcel Status //////////////////////////////////
function get_parcel_status($invoice_no = '') {
    global $base_url;

    if (empty($invoice_no)) {
        return "Invoice number missing!";
    }

    // Fetch Consignment ID
    $consignment_id = get_consignment_id($invoice_no);

    // API Endpoint
    $endpoint = "/aladdin/api/v1/orders/" . $consignment_id . "/info";
    $api_url = $base_url . $endpoint;

    // Get Access Token
    $access_token = get_access_token();
    if (empty($access_token)) {
        return "Access token not found!";
    }

    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ]);

    // Execute GET Request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return "cURL Error: " . $error_msg;
    }

    curl_close($ch);

    // Decode JSON
    $decoded = json_decode($response, true);

    if ($http_code !== 200 || empty($decoded['data'])) {
        return [
            "status" => "error",
            "http_code" => $http_code,
            "response" => $decoded
        ];
    }

    // Extract useful info
    $info = $decoded['data'];
    $order_status = $info['order_status'] ?? '';

    return $order_status;
}
// END //////////////////////////////////////////////
////////////////////////////////////////////////////


///////////////////////////////////////////////
// Track Parcel //////////////////////////////
function get_track_parcel_url($invoice_no= '') {

    // Fetch Consignment ID
    $consignment_id = get_consignment_id($invoice_no);

    $url = 'https://merchant.pathao.com/tracking?consignment_id='.$consignment_id.'';

    return $url;
}
//////////////////////////////////////////
// End //////////////////////////////////


print_r(create_pathao_consignment($invoice_no));

?>