<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (!isLoggedIn()) {
    $response['message'] = 'Please login first';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $coupon_code = sanitizeInput($_POST['coupon_code']);
    
    if (empty($coupon_code)) {
        $response['message'] = 'Please enter a coupon code';
        echo json_encode($response);
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if coupon exists, is active, not expired, and within usage limit
    $stmt = $db->prepare("
        SELECT * FROM coupons 
        WHERE code = ? 
        AND status = 'active' 
        AND (expiry_date IS NULL OR expiry_date >= CURDATE())
        AND (usage_limit IS NULL OR used_count < usage_limit)
    ");
    $stmt->execute([$coupon_code]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$coupon) {
        $response['message'] = 'Invalid or expired coupon code';
        echo json_encode($response);
        exit;
    }
    
    // Calculate subtotal based on checkout type (buy-now, selected items, or cart)
    $subtotal = 0;
    
    // Check if this is a buy-now checkout
    if (isset($_SESSION['buy_now_item'])) {
        $buy_now = $_SESSION['buy_now_item'];
        $stmt = $db->prepare("
            SELECT p.original_price, p.discounted_price, pv.variant_price
            FROM products p
            LEFT JOIN product_variants pv ON pv.id = ?
            WHERE p.id = ?
        ");
        $stmt->execute([$buy_now['variant_id'], $buy_now['product_id']]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            $price = $item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price']);
            $subtotal = $price * $buy_now['quantity'];
        }
    }
    // Check if this is a selected items checkout
    elseif (isset($_SESSION['selected_cart_items']) && !empty($_SESSION['selected_cart_items'])) {
        $selected_ids = $_SESSION['selected_cart_items'];
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $params = array_merge($selected_ids, [$_SESSION['user_id']]);
        
        $stmt = $db->prepare("
            SELECT SUM(
                CASE 
                    WHEN pv.variant_price IS NOT NULL THEN pv.variant_price * c.quantity
                    WHEN p.discounted_price IS NOT NULL THEN p.discounted_price * c.quantity
                    ELSE p.original_price * c.quantity
                END
            ) as subtotal
            FROM cart c
            JOIN products p ON c.product_id = p.id
            LEFT JOIN product_variants pv ON c.variant_id = pv.id
            WHERE c.id IN ($placeholders) AND c.user_id = ?
        ");
        $stmt->execute($params);
        $subtotal = floatval($stmt->fetchColumn());
    }
    // Regular cart checkout
    else {
        $stmt = $db->prepare("
            SELECT SUM(
                CASE 
                    WHEN pv.variant_price IS NOT NULL THEN pv.variant_price * c.quantity
                    WHEN p.discounted_price IS NOT NULL THEN p.discounted_price * c.quantity
                    ELSE p.original_price * c.quantity
                END
            ) as subtotal
            FROM cart c
            JOIN products p ON c.product_id = p.id
            LEFT JOIN product_variants pv ON c.variant_id = pv.id
            WHERE c.user_id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $subtotal = floatval($stmt->fetchColumn());
    }
    
    if ($subtotal <= 0) {
        $response['message'] = 'Your cart is empty';
        echo json_encode($response);
        exit;
    }
    
    // Check minimum order amount
    if ($coupon['min_order_amount'] > 0 && $subtotal < $coupon['min_order_amount']) {
        $response['message'] = 'Minimum order amount of Rs ' . number_format($coupon['min_order_amount'], 2) . ' required';
        echo json_encode($response);
        exit;
    }
    
    // Calculate discount amount based on type
    if ($coupon['discount_type'] === 'percentage') {
        $discount_amount = ($subtotal * $coupon['discount_value']) / 100;
        
        // Apply max discount limit if set
        if ($coupon['max_discount_amount'] && $discount_amount > $coupon['max_discount_amount']) {
            $discount_amount = $coupon['max_discount_amount'];
        }
    } else {
        // Fixed discount
        $discount_amount = $coupon['discount_value'];
        
        // Don't allow discount to exceed subtotal
        if ($discount_amount > $subtotal) {
            $discount_amount = $subtotal;
        }
    }
    
    // Store discount in session
    $_SESSION['coupon_code'] = $coupon_code;
    $_SESSION['coupon_discount'] = $discount_amount;
    $_SESSION['coupon_applied'] = true; // Flag to indicate coupon is applied in current session
    
    $response['success'] = true;
    $response['message'] = 'Coupon applied successfully! You saved Rs ' . number_format($discount_amount, 2);
    $response['discount_amount'] = $discount_amount;
}

echo json_encode($response);
?>
