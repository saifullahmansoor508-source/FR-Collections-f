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
    $cart_id = intval($_POST['cart_id']);
    $quantity = intval($_POST['quantity']);
    
    if ($cart_id <= 0 || $quantity < 1) {
        $response['message'] = 'Invalid data';
        echo json_encode($response);
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verify cart item belongs to user
    $stmt = $db->prepare("SELECT id FROM cart WHERE id = ? AND user_id = ?");
    $stmt->execute([$cart_id, $_SESSION['user_id']]);
    
    if (!$stmt->fetch()) {
        $response['message'] = 'Cart item not found';
        echo json_encode($response);
        exit;
    }
    
    // Update quantity
    $stmt = $db->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
    
    if ($stmt->execute([$quantity, $cart_id])) {
        $response['success'] = true;
        $response['message'] = 'Quantity updated successfully';
    } else {
        $response['message'] = 'Error updating quantity';
    }
}

echo json_encode($response);
?>
