<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$data = json_decode(file_get_contents('php://input'), true);
$image_id = isset($data['image_id']) ? intval($data['image_id']) : 0;

if ($image_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid image ID']);
    exit;
}

try {
    // Get image details before deleting
    $stmt = $db->prepare("SELECT image_path, product_id FROM product_images WHERE id = ?");
    $stmt->execute([$image_id]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$image) {
        echo json_encode(['success' => false, 'message' => 'Image not found']);
        exit;
    }
    
    // Check if this is the only image for the product
    $stmt = $db->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ?");
    $stmt->execute([$image['product_id']]);
    $image_count = $stmt->fetchColumn();
    
    if ($image_count <= 1) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete the last image. Product must have at least one image.']);
        exit;
    }
    
    // Delete from database
    $stmt = $db->prepare("DELETE FROM product_images WHERE id = ?");
    $stmt->execute([$image_id]);
    
    // Delete physical file
    $file_path = '../../' . PRODUCT_IMAGES_DIR . $image['image_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    
    echo json_encode(['success' => true, 'message' => 'Image deleted successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error deleting image: ' . $e->getMessage()]);
}
?>
