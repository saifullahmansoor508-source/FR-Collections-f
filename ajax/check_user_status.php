<?php
/**
 * AJAX Endpoint - Check User Block Status
 * 
 * This endpoint checks if the currently logged-in user is blocked
 * and returns the status. Used for real-time validation.
 * 
 * @package    FR Collections
 * @version    1.0
 */

session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => true,
        'logged_in' => false,
        'blocked' => false
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check user block status
    $stmt = $db->prepare("SELECT is_blocked FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        if ($user['is_blocked']) {
            // User is blocked - clear session
            session_unset();
            session_destroy();
            clearRememberMeCookie();
            
            echo json_encode([
                'success' => true,
                'logged_in' => false,
                'blocked' => true,
                'message' => 'Your account has been blocked by the administrator.'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'logged_in' => true,
                'blocked' => false
            ]);
        }
    } else {
        // User not found - clear session
        session_unset();
        session_destroy();
        clearRememberMeCookie();
        
        echo json_encode([
            'success' => true,
            'logged_in' => false,
            'blocked' => false,
            'message' => 'User account not found.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred.'
    ]);
}
