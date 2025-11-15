<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$cart_ids = isset($_POST['cart_ids']) ? $_POST['cart_ids'] : [];

if (empty($cart_ids) || !is_array($cart_ids)) {
    echo json_encode(['success' => false, 'message' => 'No items selected']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Verify that all cart items belong to the current user
    $placeholders = implode(',', array_fill(0, count($cart_ids), '?'));
    $params = array_merge($cart_ids, [$_SESSION['user_id']]);
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM cart 
        WHERE id IN ($placeholders) AND user_id = ?
    ");
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] != count($cart_ids)) {
        echo json_encode(['success' => false, 'message' => 'Invalid cart items']);
        exit;
    }
    
    // Store selected cart IDs in session for checkout
    $_SESSION['selected_cart_items'] = $cart_ids;
    
    echo json_encode([
        'success' => true, 
        'message' => 'Items selected successfully',
        'count' => count($cart_ids)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
