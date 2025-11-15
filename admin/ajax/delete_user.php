<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session_manager.php';

header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Handle single user deletion
if (isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // Clear user sessions before deletion
        clearUserSessions($user_id);
        flagUserForLogout($user_id);
        
        // Delete related records first (foreign key constraints)
        // Delete affiliates
        $stmt = $db->prepare("DELETE FROM affiliates WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Delete withdrawals
        $stmt = $db->prepare("DELETE FROM withdrawals WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Delete product requests
        $stmt = $db->prepare("DELETE FROM product_requests WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Note: Reviews table doesn't have user_id/customer_id, only stores user_name
        // Reviews will remain but are not linked to user account
        
        // Get user's orders to delete order items
        $stmt = $db->prepare("SELECT id FROM orders WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $order_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($order_ids)) {
            $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
            // Delete order items
            $stmt = $db->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
            $stmt->execute($order_ids);
        }
        
        // Delete orders
        $stmt = $db->prepare("DELETE FROM orders WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Finally, delete the user
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        // Commit transaction
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'User and all related records deleted successfully']);
    } catch (Exception $e) {
        // Rollback on error
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error deleting user: ' . $e->getMessage()]);
    }
}
// Handle bulk user deletion
elseif (isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
    $user_ids = array_map('intval', $_POST['user_ids']);
    
    if (empty($user_ids)) {
        echo json_encode(['success' => false, 'message' => 'No users selected']);
        exit;
    }
    
    try {
        // Begin transaction
        $db->beginTransaction();
        
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        
        // Clear sessions for all users
        foreach ($user_ids as $uid) {
            clearUserSessions($uid);
            flagUserForLogout($uid);
        }
        
        // Delete related records
        $stmt = $db->prepare("DELETE FROM affiliates WHERE user_id IN ($placeholders)");
        $stmt->execute($user_ids);
        
        $stmt = $db->prepare("DELETE FROM withdrawals WHERE user_id IN ($placeholders)");
        $stmt->execute($user_ids);
        
        $stmt = $db->prepare("DELETE FROM product_requests WHERE user_id IN ($placeholders)");
        $stmt->execute($user_ids);
        
        // Note: Reviews table doesn't have user_id/customer_id, only stores user_name
        // Reviews will remain but are not linked to user account
        
        // Get all orders
        $stmt = $db->prepare("SELECT id FROM orders WHERE user_id IN ($placeholders)");
        $stmt->execute($user_ids);
        $order_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($order_ids)) {
            $order_placeholders = implode(',', array_fill(0, count($order_ids), '?'));
            $stmt = $db->prepare("DELETE FROM order_items WHERE order_id IN ($order_placeholders)");
            $stmt->execute($order_ids);
        }
        
        $stmt = $db->prepare("DELETE FROM orders WHERE user_id IN ($placeholders)");
        $stmt->execute($user_ids);
        
        // Delete the users
        $stmt = $db->prepare("DELETE FROM users WHERE id IN ($placeholders)");
        $stmt->execute($user_ids);
        
        // Commit transaction
        $db->commit();
        
        $count = count($user_ids);
        echo json_encode(['success' => true, 'message' => "$count user(s) and all related records deleted successfully"]);
    } catch (Exception $e) {
        // Rollback on error
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error deleting users: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
