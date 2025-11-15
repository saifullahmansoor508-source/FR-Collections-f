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
    $order_id = intval($_POST['order_id']);
    
    if ($order_id <= 0) {
        $response['message'] = 'Invalid order';
        echo json_encode($response);
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verify order belongs to user and can be canceled
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND status IN ('Pending', 'Confirmed')");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $response['message'] = 'Order not found or cannot be canceled';
        echo json_encode($response);
        exit;
    }
    
    // Update order status
    $stmt = $db->prepare("UPDATE orders SET status = 'Canceled', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    
    if ($stmt->execute([$order_id])) {
        $response['success'] = true;
        $response['message'] = 'Order canceled successfully';
    } else {
        $response['message'] = 'Error canceling order';
    }
}

echo json_encode($response);
?>
