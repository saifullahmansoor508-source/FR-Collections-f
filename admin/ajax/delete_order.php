<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Handle single order deletion
if (isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);
    
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // Delete order items first (foreign key constraint)
        $stmt = $db->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // Delete the order
        $stmt = $db->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        
        // Commit transaction
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);
    } catch (Exception $e) {
        // Rollback on error
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error deleting order: ' . $e->getMessage()]);
    }
}
// Handle bulk order deletion
elseif (isset($_POST['order_ids']) && is_array($_POST['order_ids'])) {
    $order_ids = array_map('intval', $_POST['order_ids']);
    
    if (empty($order_ids)) {
        echo json_encode(['success' => false, 'message' => 'No orders selected']);
        exit;
    }
    
    try {
        // Begin transaction
        $db->beginTransaction();
        
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        
        // Delete order items first
        $stmt = $db->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
        $stmt->execute($order_ids);
        
        // Delete the orders
        $stmt = $db->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
        $stmt->execute($order_ids);
        
        // Commit transaction
        $db->commit();
        
        $count = count($order_ids);
        echo json_encode(['success' => true, 'message' => "$count order(s) deleted successfully"]);
    } catch (Exception $e) {
        // Rollback on error
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error deleting orders: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
