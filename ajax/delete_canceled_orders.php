<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

try {
    // Delete all canceled orders for the user
    $stmt = $db->prepare("DELETE FROM orders WHERE user_id = ? AND status = 'Canceled'");
    $stmt->execute([$_SESSION['user_id']]);
    
    // Redirect back to profile with success message
    $_SESSION['success_message'] = 'All canceled orders have been deleted successfully.';
    header('Location: ../profile.php?tab=orders');
    exit;
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error deleting canceled orders. Please try again.';
    header('Location: ../profile.php?tab=orders');
    exit;
}
?>
