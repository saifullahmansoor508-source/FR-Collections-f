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
    
    // Delete cart items that belong to the current user
    $placeholders = implode(',', array_fill(0, count($cart_ids), '?'));
    $params = array_merge($cart_ids, [$_SESSION['user_id']]);
    
    $stmt = $db->prepare("
        DELETE FROM cart 
        WHERE id IN ($placeholders) AND user_id = ?
    ");
    $stmt->execute($params);
    
    $deletedCount = $stmt->rowCount();
    
    if ($deletedCount > 0) {
        $message = $deletedCount === 1 ? '1 item deleted' : "$deletedCount items deleted";
        echo json_encode([
            'success' => true, 
            'message' => $message,
            'count' => $deletedCount
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No items were deleted']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
