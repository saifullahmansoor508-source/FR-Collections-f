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

if (isset($_POST['user_ids'])) {
    $user_ids = json_decode($_POST['user_ids'], true);
    
    if (empty($user_ids) || !is_array($user_ids)) {
        echo json_encode(['success' => false, 'message' => 'No affiliates selected']);
        exit;
    }
    
    $user_ids = array_map('intval', $user_ids);
    
    try {
        // Begin transaction
        $db->beginTransaction();
        
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        
        // Delete affiliate records
        $stmt = $db->prepare("DELETE FROM affiliates WHERE user_id IN ($placeholders)");
        $stmt->execute($user_ids);
        
        // Delete related withdrawals
        $stmt = $db->prepare("DELETE FROM withdrawals WHERE user_id IN ($placeholders)");
        $stmt->execute($user_ids);
        
        // Commit transaction
        $db->commit();
        
        $count = count($user_ids);
        echo json_encode([
            'success' => true, 
            'message' => "$count affiliate(s) deleted successfully! Their affiliate status and withdrawal records have been removed."
        ]);
    } catch (Exception $e) {
        // Rollback on error
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error deleting affiliates: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
