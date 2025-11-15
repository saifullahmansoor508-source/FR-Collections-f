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

// Handle bulk user blocking
if (isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
    $user_ids = array_map('intval', $_POST['user_ids']);
    $is_blocked = isset($_POST['is_blocked']) ? intval($_POST['is_blocked']) : 1;
    
    if (empty($user_ids)) {
        echo json_encode(['success' => false, 'message' => 'No users selected']);
        exit;
    }
    
    try {
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        
        // Update user status
        $stmt = $db->prepare("UPDATE users SET is_blocked = $is_blocked WHERE id IN ($placeholders)");
        $stmt->execute($user_ids);
        
        // If blocking users, clear their sessions
        if ($is_blocked) {
            foreach ($user_ids as $uid) {
                clearUserSessions($uid);
                flagUserForLogout($uid);
            }
        }
        
        $count = count($user_ids);
        $action = $is_blocked ? 'blocked' : 'unblocked';
        echo json_encode(['success' => true, 'message' => "$count user(s) $action successfully"]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating user status: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
