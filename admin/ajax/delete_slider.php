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
    
    // Get slider image path before deletion
    $stmt = $db->prepare("SELECT image_path FROM slider_images WHERE id = ?");
    $stmt->execute([$slider_id]);
    $slider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($slider) {
        // Delete slider from database
        $stmt = $db->prepare("DELETE FROM slider_images WHERE id = ?");
        
        if ($stmt->execute([$slider_id])) {
            // Delete image file
            $image_path = '../../' . SLIDER_IMAGES_DIR . $slider['image_path'];
            if (file_exists($image_path)) {
                unlink($image_path);
            }
            
            $response['success'] = true;
            $response['message'] = 'Slider deleted successfully';
        } else {
            $response['message'] = 'Error deleting slider';
        }
    } else {
        $response['message'] = 'Slider not found';
    }
}

echo json_encode($response);
?>
