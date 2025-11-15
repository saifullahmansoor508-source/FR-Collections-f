<?php
session_start();
header('Content-Type: application/json');

require_once '../../config/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No image uploaded or upload error']);
    exit;
}

$productNo = $_POST['product_no'] ?? '';
$imageType = $_POST['image_type'] ?? 'primary'; // primary or variant
$variantIndex = $_POST['variant_index'] ?? 0;

if (empty($productNo)) {
    echo json_encode(['success' => false, 'message' => 'Product number is required']);
    exit;
}

// Validate file type
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
$fileType = $_FILES['image']['type'];

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and WebP are allowed']);
    exit;
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024;
if ($_FILES['image']['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
    exit;
}

try {
    // Create upload directory if it doesn't exist
    $uploadDir = '../../' . PRODUCT_IMAGES_DIR;
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    
    if ($imageType === 'primary') {
        $fileName = $productNo . '.' . $extension;
    } else {
        $fileName = $productNo . '(' . ($variantIndex + 1) . ').' . $extension;
    }
    
    $targetPath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
        // Return relative path for database storage
        $relativePath = PRODUCT_IMAGES_DIR . $fileName;
        
        echo json_encode([
            'success' => true,
            'path' => $relativePath,
            'fileName' => $fileName,
            'message' => 'Image uploaded successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
