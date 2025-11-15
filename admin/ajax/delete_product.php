<?php
/**
 * AJAX Product Deletion Handler
 * Handles single and bulk product deletion requests
 */

// Set custom error handler to catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Set custom exception handler
set_exception_handler(function($exception) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $exception->getMessage(),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine()
    ]);
    exit;
});

// Prevent any output before headers
ob_start();

// Set JSON headers immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Start session
session_start();

// Include required files
require_once '../../config/config.php';
require_once '../../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Get product IDs from request
$product_ids = isset($_POST['product_ids']) ? json_decode($_POST['product_ids'], true) : [];

// Log the request for debugging
error_log("Delete request received for products: " . print_r($product_ids, true));

if (empty($product_ids)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'No products selected']);
    exit;
}

// Check if database connection is valid
if (!$db) {
    ob_end_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Database connection failed',
        'debug' => 'Could not establish database connection'
    ]);
    exit;
}

try {
    // Check if transaction is already active
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    $db->beginTransaction();
    error_log("Transaction started for product deletion");
    
    foreach ($product_ids as $product_id) {
        $product_id = intval($product_id);
        error_log("Processing deletion for product ID: $product_id");
        
        // STEP 1: Get all image paths for this product before deletion
        $stmt = $db->prepare("SELECT image_path FROM product_images WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $image_paths = $stmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("Found " . count($image_paths) . " images for product $product_id");
        
        // Delete physical image files from uploads folder
        foreach ($image_paths as $image_path) {
            if (!empty($image_path)) {
                $full_path = '../../uploads/products/' . $image_path;
                if (file_exists($full_path)) {
                    @unlink($full_path);
                }
            }
        }
        
        // STEP 2: Delete related records from database
        
        // Delete product images
        $stmt = $db->prepare("DELETE FROM product_images WHERE product_id = ?");
        $stmt->execute([$product_id]);
        
        // Delete product variants
        $stmt = $db->prepare("DELETE FROM product_variants WHERE product_id = ?");
        $stmt->execute([$product_id]);
        
        // Delete product features
        $stmt = $db->prepare("DELETE FROM product_features WHERE product_id = ?");
        $stmt->execute([$product_id]);
        
        // Delete reviews
        $stmt = $db->prepare("DELETE FROM reviews WHERE product_id = ?");
        $stmt->execute([$product_id]);
        
        // Delete from cart
        $stmt = $db->prepare("DELETE FROM cart WHERE product_id = ?");
        $stmt->execute([$product_id]);
        
        // Delete from wishlist
        $stmt = $db->prepare("DELETE FROM wishlist WHERE product_id = ?");
        $stmt->execute([$product_id]);
        
        // Delete from favorites (table might not exist)
        $stmt = $db->prepare("SHOW TABLES LIKE 'favorites'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("DELETE FROM favorites WHERE product_id = ?");
            $stmt->execute([$product_id]);
        }
        
        // Delete from order_items
        $stmt = $db->prepare("DELETE FROM order_items WHERE product_id = ?");
        $stmt->execute([$product_id]);
        
        // Delete from affiliate_earnings
        $stmt = $db->prepare("DELETE FROM affiliate_earnings WHERE product_id = ?");
        $stmt->execute([$product_id]);
        
        // Delete combination attribute mappings (check if tables exist first)
        $stmt = $db->prepare("SHOW TABLES LIKE 'product_variant_combinations'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            // Get all combination IDs for this product
            $stmt = $db->prepare("SELECT id FROM product_variant_combinations WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $combination_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Delete mappings for these combinations
            if (!empty($combination_ids)) {
                $stmt = $db->prepare("SHOW TABLES LIKE 'combination_attribute_map'");
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    $placeholders = str_repeat('?,', count($combination_ids) - 1) . '?';
                    $stmt = $db->prepare("DELETE FROM combination_attribute_map WHERE combination_id IN ($placeholders)");
                    $stmt->execute($combination_ids);
                }
            }
            
            // Delete product variant combinations
            $stmt = $db->prepare("DELETE FROM product_variant_combinations WHERE product_id = ?");
            $stmt->execute([$product_id]);
        }
        
        // STEP 3: Finally, delete the product itself
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
    }
    
    // STEP 4: Reset auto-increment
    $stmt = $db->query("
        SELECT t1.id + 1 AS next_id
        FROM products t1
        LEFT JOIN products t2 ON t1.id + 1 = t2.id
        WHERE t2.id IS NULL
        ORDER BY t1.id
        LIMIT 1
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['next_id']) {
        $next_id = intval($result['next_id']);
        $db->exec("ALTER TABLE products AUTO_INCREMENT = " . $next_id);
    } else {
        $stmt = $db->query("SELECT COUNT(*) as count FROM products");
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            $db->exec("ALTER TABLE products AUTO_INCREMENT = 1");
        } else {
            $stmt = $db->query("SELECT MAX(id) as max_id FROM products");
            $max_id = $stmt->fetchColumn();
            $db->exec("ALTER TABLE products AUTO_INCREMENT = " . ($max_id + 1));
        }
    }
    
    // Only commit if transaction is still active
    if ($db->inTransaction()) {
        $db->commit();
    }
    
    // Success response
    $response = [
        'success' => true, 
        'message' => 'Product(s) deleted successfully', 
        'count' => count($product_ids)
    ];
    
    // Clear buffer and send clean JSON
    ob_end_clean();
    echo json_encode($response);
    exit;
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Log error
    error_log("Product deletion error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // User-friendly error message
    $error_message = 'Error deleting products';
    
    if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
        $error_message = 'Cannot delete products: They are referenced in orders or other records.';
    }
    
    $response = [
        'success' => false, 
        'message' => $error_message,
        'debug' => $e->getMessage(),  // Always show debug info
        'trace' => $e->getTraceAsString()
    ];
    
    ob_end_clean();
    echo json_encode($response);
    exit;
}
