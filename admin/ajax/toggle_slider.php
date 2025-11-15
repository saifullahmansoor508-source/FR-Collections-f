<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $slider_id = intval($_POST['id']);
    
    if ($slider_id <= 0) {
        $response['message'] = 'Invalid slider ID';
        echo json_encode($response);
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Toggle slider status
    $stmt = $db->prepare("UPDATE slider_images SET is_active = NOT is_active WHERE id = ?");
    
    if ($stmt->execute([$slider_id])) {
        $response['success'] = true;
        $response['message'] = 'Slider status updated successfully';
    } else {
        $response['message'] = 'Error updating slider status';
    }
}

echo json_encode($response);
?>
