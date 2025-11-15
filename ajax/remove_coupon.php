<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (!isLoggedIn()) {
    $response['message'] = 'Please login first';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Clear coupon from session
    if (isset($_SESSION['coupon_code'])) {
        unset($_SESSION['coupon_code']);
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_applied']);

        $response['success'] = true;
        $response['message'] = 'Coupon removed successfully';
    } else {
        $response['message'] = 'No coupon applied';
    }
}

echo json_encode($response);
?>
