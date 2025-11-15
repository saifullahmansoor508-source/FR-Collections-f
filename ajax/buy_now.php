<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

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
} elseif (isset($_POST['variant_id']) && $_POST['variant_id']) {
    // OLD: Single variant_id
    $variant_id = intval($_POST['variant_id']);
}

$quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;

if ($product_id <= 0 || $quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or quantity']);
    exit;
}

// Verify product exists (don't check status for buy now)
$stmt = $db->prepare("SELECT id, name FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

// Store buy-now item in session (not in cart database)
$_SESSION['buy_now_item'] = [
    'product_id' => $product_id,
    'variant_id' => $variant_id,
    'variant_combination_id' => $variant_combination_id,
    'variant_selections' => $variant_selections_json,
    'quantity' => $quantity,
    'timestamp' => time()
];

echo json_encode(['success' => true, 'message' => 'Redirecting to checkout']);
