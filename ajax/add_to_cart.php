<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    if (!isLoggedIn()) {
        $response['message'] = 'Please login to add items to cart';
        echo json_encode($response);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $product_id = intval($_POST['product_id']);
        
        // Handle combination variants, simple variants, or no variants
        $variant_combination_id = null;
        $variant_selections_json = null;
        $variant_id = null;
        
        if (isset($_POST['variant_combination_id']) && $_POST['variant_combination_id'] > 0) {
            // NEWEST: Combination variant
            $variant_combination_id = intval($_POST['variant_combination_id']);
        } elseif (isset($_POST['variant_selections']) && !empty($_POST['variant_selections'])) {
            // NEW: Multiple simple variants as JSON
            $variant_selections_json = $_POST['variant_selections'];
            $variant_selections = json_decode($variant_selections_json, true);
            if (!empty($variant_selections)) {
                // Use first variant ID for backward compatibility
                $variant_id = intval(reset($variant_selections));
            }
        } elseif (isset($_POST['variant_id']) && $_POST['variant_id'] > 0) {
            // OLD: Single variant_id
            $variant_id = intval($_POST['variant_id']);
        }
        
        $quantity = intval($_POST['quantity']) ?: 1;
        
        if ($product_id <= 0) {
            $response['message'] = 'Invalid product';
            echo json_encode($response);
            exit;
        }
        
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if product exists and is in stock
        $stmt = $db->prepare("SELECT status, stock_count FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            $response['message'] = 'Product not found';
            echo json_encode($response);
            exit;
        }
        
        if ($product['status'] == 'Out of Stock') {
            $response['message'] = 'Product is out of stock';
            echo json_encode($response);
            exit;
        }
        
        if ($product['status'] == 'Limited' && $quantity > $product['stock_count']) {
            $response['message'] = 'Only ' . $product['stock_count'] . ' items available';
            echo json_encode($response);
            exit;
        }
        
        // If adding with variant, first check and remove any variant-less version of same product
        if ($variant_combination_id || $variant_selections_json || $variant_id) {
            // Check if there's a version without variant in cart
            $stmt = $db->prepare("SELECT id FROM cart WHERE user_id = ? AND product_id = ? AND variant_id IS NULL AND variant_combination_id IS NULL AND (variant_selections IS NULL OR variant_selections = '')");
            $stmt->execute([$_SESSION['user_id'], $product_id]);
            $variant_less_item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($variant_less_item) {
                // Remove the variant-less version
                $stmt = $db->prepare("DELETE FROM cart WHERE id = ?");
                $stmt->execute([$variant_less_item['id']]);
            }
        }
        
        // Check if item already exists in cart
        if ($variant_combination_id) {
            // NEW: Check using combination ID
            $stmt = $db->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ? AND variant_combination_id = ?");
            $stmt->execute([$_SESSION['user_id'], $product_id, $variant_combination_id]);
        } elseif ($variant_selections_json) {
            // Check using variant_selections JSON
            $stmt = $db->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ? AND variant_selections = ?");
            $stmt->execute([$_SESSION['user_id'], $product_id, $variant_selections_json]);
        } elseif ($variant_id) {
            $stmt = $db->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ? AND variant_id = ?");
            $stmt->execute([$_SESSION['user_id'], $product_id, $variant_id]);
        } else {
            $stmt = $db->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ? AND variant_id IS NULL");
            $stmt->execute([$_SESSION['user_id'], $product_id]);
        }
        $existing_item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_item) {
            // Product already in cart - don't add again
            $response['success'] = false;
            $response['message'] = 'The product is already in your cart';
            echo json_encode($response);
            exit;
        } else {
            // Add new item with variant_combination_id or variant_selections
            $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, variant_id, variant_selections, variant_combination_id, quantity) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'], 
                $product_id, 
                $variant_id === 0 ? null : $variant_id, 
                $variant_selections_json,
                $variant_combination_id,
                $quantity
            ]);
            
            $response['success'] = true;
            $response['message'] = 'Added to Cart';
            $response['variant_replaced'] = isset($variant_less_item);
        }
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>
