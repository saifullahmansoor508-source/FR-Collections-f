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
    $database = new Database();
    $db = $database->getConnection();
    
    // Clear all cart items for user
    $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ?");
    
    if ($stmt->execute([$_SESSION['user_id']])) {
        $response['success'] = true;
        $response['message'] = 'Cart cleared successfully';
    } else {
        $response['message'] = 'Error clearing cart';
    }
}

echo json_encode($response);
?>
