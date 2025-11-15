<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

$response = ['in_cart' => false];

if (!isset($_SESSION['user_id'])) {
    echo json_encode($response);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Support both GET and POST requests
$product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : (isset($_GET['product_id']) ? intval($_GET['product_id']) : 0);

if ($product_id <= 0) {
    echo json_encode($response);
    exit;
}

try {
    // Check if product with specific variant is in cart
    if (isset($_POST['combination_id']) && !empty($_POST['combination_id'])) {
        // Combination variant
        $combination_id = intval($_POST['combination_id']);
        
        $stmt = $db->prepare("
            SELECT id FROM cart 
            WHERE user_id = ? 
            AND product_id = ? 
            AND variant_combination_id = ?
        ");
        $stmt->execute([$user_id, $product_id, $combination_id]);
        
    } else if (isset($_POST['variant_selections']) && !empty($_POST['variant_selections'])) {
        // Simple variants
        $variant_selections = $_POST['variant_selections'];
        
        $stmt = $db->prepare("
            SELECT id FROM cart 
            WHERE user_id = ? 
            AND product_id = ? 
            AND variant_selections = ?
        ");
        $stmt->execute([$user_id, $product_id, $variant_selections]);
        
    } else {
        // No variants or GET request - check if ANY variant of this product is in cart
        $stmt = $db->prepare("
            SELECT id FROM cart 
            WHERE user_id = ? 
            AND product_id = ?
        ");
        $stmt->execute([$user_id, $product_id]);
    }
    
    if ($stmt->fetch()) {
        $response['in_cart'] = true;
    }
    
} catch (PDOException $e) {
    error_log('Check cart error: ' . $e->getMessage());
}

echo json_encode($response);
