<?php
require_once 'config/config.php';

if (!isLoggedIn()) {
    redirectTo('auth.php');
}

$page_title = "Shopping Cart";
require_once 'includes/header.php';

// Clean up duplicate cart items (same product + variant combination)
$cleanup_stmt = $db->prepare("
    DELETE c1 FROM cart c1
    INNER JOIN cart c2 
    WHERE c1.id > c2.id 
    AND c1.user_id = c2.user_id
    AND c1.product_id = c2.product_id
    AND (c1.variant_id = c2.variant_id OR (c1.variant_id IS NULL AND c2.variant_id IS NULL))
    AND (c1.variant_combination_id = c2.variant_combination_id OR (c1.variant_combination_id IS NULL AND c2.variant_combination_id IS NULL))
    AND (c1.variant_selections = c2.variant_selections OR (c1.variant_selections IS NULL AND c2.variant_selections IS NULL))
    AND c1.user_id = ?
");
$cleanup_stmt->execute([$_SESSION['user_id']]);

// Get cart items
$stmt = $db->prepare("
    SELECT c.*, p.name as product_name, p.original_price, p.discounted_price, p.status, p.delivery_charges,
           pi.image_path, cat.name as category_name,
           pv.variant_name, pv.variant_price, pv.variant_image,
           pvc.price as combination_price, pvc.sku as combination_sku, pvc.image_path as combination_image
    FROM cart c
    JOIN products p ON c.product_id = p.id
    LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
    LEFT JOIN categories cat ON p.category_id = cat.id
    LEFT JOIN product_variants pv ON c.variant_id = pv.id
    LEFT JOIN product_variant_combinations pvc ON c.variant_combination_id = pvc.id
    WHERE c.user_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch variant details and combination details
require_once 'config/variant_helpers.php';

// Check if products have variants and validate selections
$items_missing_variants = [];

foreach ($cart_items as &$item) {
    // Handle simple variant selections (old format)
    if (!empty($item['variant_selections'])) {
        $variant_ids = json_decode($item['variant_selections'], true);
        if (is_array($variant_ids) && !empty($variant_ids)) {
            $placeholders = implode(',', array_fill(0, count($variant_ids), '?'));
            $stmt = $db->prepare("SELECT id, variant_type, variant_name FROM product_variants WHERE id IN ($placeholders)");
            $stmt->execute(array_values($variant_ids));
            $item['all_variants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Handle combination variants (new format)
    if (!empty($item['variant_combination_id'])) {
        // Get combination details
        $stmt = $db->prepare("
            SELECT 
                pvc.id, pvc.sku, pvc.price,
                GROUP_CONCAT(
                    CONCAT(va.attribute_name, ':', vav.value_name)
                    ORDER BY va.display_order
                    SEPARATOR '|'
                ) as combination_string
            FROM product_variant_combinations pvc
            INNER JOIN combination_attribute_map cam ON pvc.id = cam.combination_id
            INNER JOIN variant_attribute_values vav ON cam.attribute_value_id = vav.id
            INNER JOIN variant_attributes va ON vav.attribute_id = va.id
            WHERE pvc.id = ?
            GROUP BY pvc.id
        ");
        $stmt->execute([$item['variant_combination_id']]);
        $combination = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($combination) {
            $item['combination_details'] = parseCombinationString($combination['combination_string']);
            $item['combination_string'] = $combination['combination_string'];
        }
    }
    
    // Check if product requires variant selection
    $product_id = $item['product_id'];
    
    // Check if product uses combinations
    $uses_combinations = productUsesCombinations($db, $product_id);
    
    if ($uses_combinations) {
        // Check if product has combination variants defined
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM variant_attributes WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $has_attributes = $stmt->fetchColumn() > 0;
        
        if ($has_attributes) {
            // Product has combination variants - check if one is selected
            if (empty($item['variant_combination_id'])) {
                $items_missing_variants[] = [
                    'cart_id' => $item['id'],
                    'product_id' => $product_id,
                    'product_name' => $item['product_name'],
                    'type' => 'combination'
                ];
                $item['missing_variant'] = true;
            }
        }
    } else {
        // Check if product has simple variants
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM product_variants WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $has_variants = $stmt->fetchColumn() > 0;
        
        if ($has_variants) {
            // Product has simple variants - check if one is selected
            if (empty($item['variant_id']) && empty($item['variant_selections'])) {
                $items_missing_variants[] = [
                    'cart_id' => $item['id'],
                    'product_id' => $product_id,
                    'product_name' => $item['product_name'],
                    'type' => 'simple'
                ];
                $item['missing_variant'] = true;
            }
        }
    }
    
    // Determine which image to display (priority: combination_image > variant_image > primary image)
    $display_image = null;
    if (!empty($item['combination_image'])) {
        // Use combination variant image if available
        $display_image = 'uploads/products/' . $item['combination_image'];
    } elseif (!empty($item['variant_image'])) {
        // Use simple variant image if available
        $display_image = 'uploads/products/' . $item['variant_image'];
    } elseif (!empty($item['image_path'])) {
        // Use primary product image
        $display_image = PRODUCT_IMAGES_DIR . $item['image_path'];
    } else {
        // Use default no-image
        $display_image = 'assets/images/no-image.jpg';
    }
    $item['display_image'] = $display_image;
}
unset($item);

// Filter out items with missing variants for display
$cart_ids_missing_variants = array_column($items_missing_variants, 'cart_id');
$valid_cart_items = array_filter($cart_items, function($item) use ($cart_ids_missing_variants) {
    return !in_array($item['id'], $cart_ids_missing_variants);
});

// Remove any duplicate cart items (same product + variant combination)
$seen_items = [];
$valid_cart_items = array_filter($valid_cart_items, function($item) use (&$seen_items) {
    // Create unique key based on product, variant, and combination
    $key = $item['product_id'] . '_' . 
           ($item['variant_id'] ?? 'null') . '_' . 
           ($item['variant_combination_id'] ?? 'null') . '_' .
           ($item['variant_selections'] ?? 'null');
    
    if (in_array($key, $seen_items)) {
        return false; // Duplicate found, filter it out
    }
    
    $seen_items[] = $key;
    return true;
});

// Calculate totals (only for valid items)
$subtotal = 0;
$delivery_charges = 0;
$products_with_delivery = []; // Track unique products for delivery charges

foreach ($valid_cart_items as $item) {
    // Priority: combination_price > variant_price > discounted_price > original_price
    $price = $item['combination_price'] ?: ($item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price']));
    $subtotal += $price * $item['quantity'];
    
    // Add delivery charges only once per unique product (not per variant or quantity)
    $product_id = $item['product_id'];
    if (!in_array($product_id, $products_with_delivery)) {
        $delivery_charges += floatval($item['delivery_charges'] ?: 0);
        $products_with_delivery[] = $product_id;
    }
}

$total = $subtotal + $delivery_charges;
?>

<style>
/* Bulk Actions Toolbar */
.bulk-actions-toolbar {
    background: white;
    border-radius: 15px 15px 0 0;
    padding: 20px 25px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 0;
    border-bottom: 2px solid #f1f5f9;
}

.bulk-select-section {
    display: flex;
    align-items: center;
    gap: 20px;
}

.select-all-container {
    display: flex;
    align-items: center;
    cursor: pointer;
    user-select: none;
    gap: 10px;
    position: relative;
}

.select-all-container input[type="checkbox"] {
    display: none;
}

.select-all-container .checkmark {
    width: 24px;
    height: 24px;
    border: 2px solid #cbd5e1;
    border-radius: 6px;
    background: white;
    transition: all 0.3s ease;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.select-all-container input[type="checkbox"]:checked ~ .checkmark {
    background: #1e3a8a;
    border-color: #1e3a8a;
}

.select-all-container .checkmark::after {
    content: '';
    position: absolute;
    display: none;
    width: 6px;
    height: 12px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.select-all-container input[type="checkbox"]:checked ~ .checkmark::after {
    display: block;
}

.select-all-container input[type="checkbox"]:indeterminate ~ .checkmark {
    background: #1e3a8a;
    border-color: #1e3a8a;
}

.select-all-container input[type="checkbox"]:indeterminate ~ .checkmark::after {
    display: block;
    width: 10px;
    height: 2px;
    border: none;
    background: white;
    transform: none;
}

.select-all-container:hover .checkmark {
    border-color: #1e3a8a;
}

.select-all-text {
    font-weight: 600;
    color: #1e293b;
    font-size: 1rem;
}

.selected-count {
    color: #64748b;
    font-size: 0.95rem;
    font-weight: 500;
}

.bulk-actions-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-bulk-action {
    padding: 10px 20px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
}

.btn-order-selected {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.btn-order-selected:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-delete-selected {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.btn-delete-selected:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

/* Cart Item Checkbox */
.cart-item-checkbox {
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    margin-right: 15px;
}

.cart-item-checkbox input[type="checkbox"] {
    display: none;
}

.cart-item-checkbox .checkbox-custom {
    width: 22px;
    height: 22px;
    border: 2px solid #cbd5e1;
    border-radius: 6px;
    background: white;
    transition: all 0.3s ease;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.cart-item-checkbox input[type="checkbox"]:checked ~ .checkbox-custom {
    background: #1e3a8a;
    border-color: #1e3a8a;
}

.cart-item-checkbox .checkbox-custom::after {
    content: '';
    position: absolute;
    display: none;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.cart-item-checkbox input[type="checkbox"]:checked ~ .checkbox-custom::after {
    display: block;
}

.cart-item-checkbox:hover .checkbox-custom {
    border-color: #1e3a8a;
}

/* Cart Page Header */
.cart-page-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    padding: 60px 0;
    margin-bottom: 40px;
    text-align: center;
}

.cart-page-header h1 {
    color: white;
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 10px;
}

.cart-page-header p {
    color: rgba(255, 255, 255, 0.9);
    font-size: 1.1rem;
    margin: 0;
}

/* Empty Cart Styling */
.empty-cart-container {
    max-width: 500px;
    margin: 40px auto;
    padding: 60px 40px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    text-align: center;
}

.empty-cart-icon {
    width: 120px;
    height: 120px;
    margin: 0 auto 30px;
    background: #f1f5f9;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.empty-cart-icon i {
    font-size: 60px;
    color: #94a3b8;
}

.empty-cart-title {
    color: #1e3a8a;
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 15px;
}

.empty-cart-text {
    color: #64748b;
    font-size: 1.1rem;
    margin-bottom: 40px;
}

.btn-continue-shopping {
    background: #10b981;
    color: white;
    padding: 16px 50px;
    border-radius: 50px;
    border: none;
    font-size: 1.1rem;
    font-weight: 600;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-continue-shopping:hover {
    background: #059669;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
    color: white;
}

/* Cart Items Desktop */
.cart-items-section {
    background: white;
    border-radius: 0 0 15px 15px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    padding: 30px;
    margin-bottom: 30px;
}

.cart-item-row {
    display: flex;
    align-items: center;
    padding: 25px 0;
    border-bottom: 1px solid #f1f5f9;
}

.cart-item-row:last-child {
    border-bottom: none;
}

.cart-item-image {
    width: 100px;
    height: 100px;
    border-radius: 10px;
    object-fit: cover;
    margin-right: 20px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.cart-item-image:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.cart-item-details {
    flex: 1;
}

.cart-item-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 5px;
    transition: color 0.3s ease;
}

.cart-item-name:hover {
    color: #1e3a8a;
}

.cart-item-price {
    color: #64748b;
    font-size: 1rem;
}

.quantity-controls {
    display: flex;
    align-items: center;
    gap: 15px;
    background: #f8fafc;
    padding: 8px 15px;
    border-radius: 50px;
    border: 1px solid #e2e8f0;
}

.quantity-controls button {
    width: 30px;
    height: 30px;
    border: none;
    background: white;
    border-radius: 50%;
    font-size: 1.2rem;
    color: #1e3a8a;
    cursor: pointer;
    transition: all 0.3s ease;
}

.quantity-controls button:hover {
    background: #1e3a8a;
    color: white;
}

.quantity-controls input {
    width: 40px;
    text-align: center;
    border: none;
    background: transparent;
    font-weight: 600;
    color: #1e293b;
}

.btn-delete-item {
    background: transparent;
    border: none;
    color: #ef4444;
    font-size: 1.2rem;
    cursor: pointer;
    padding: 10px;
    transition: all 0.3s ease;
}

.btn-delete-item:hover {
    color: #dc2626;
    transform: scale(1.2);
}

/* Order Summary */
.order-summary {
    background: white;
    border-radius: 15px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    padding: 30px;
    position: sticky;
    top: 20px;
}

.order-summary-title {
    color: #1e3a8a;
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 10px;
}

.summary-items-info {
    color: #64748b;
    font-size: 0.95rem;
    margin-bottom: 20px;
    font-weight: 500;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 15px;
    font-size: 1rem;
    color: #64748b;
}

.summary-total {
    display: flex;
    justify-content: space-between;
    padding-top: 20px;
    margin-top: 20px;
    border-top: 2px solid #e2e8f0;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e293b;
}

.btn-checkout {
    width: 100%;
    background: #1e3a8a;
    color: white;
    padding: 16px;
    border-radius: 50px;
    border: none;
    font-size: 1.1rem;
    font-weight: 600;
    margin-top: 25px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: block;
    text-align: center;
}

.btn-checkout:hover {
    background: #1e40af;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(30, 58, 138, 0.3);
    color: white;
}

.btn-continue-link {
    display: block;
    text-align: center;
    color: #1e3a8a;
    font-weight: 600;
    margin-top: 15px;
    text-decoration: none;
}

.btn-continue-link:hover {
    color: #1e40af;
    text-decoration: underline;
}

/* Summary Items List */
.summary-items-list {
    max-height: 300px;
    overflow-y: auto;
    margin-bottom: 20px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
}

.summary-item {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 12px 15px;
    border-bottom: 1px solid #e2e8f0;
    transition: background-color 0.2s ease;
}

.summary-item:last-child {
    border-bottom: none;
}

.summary-item:hover {
    background: #f1f5f9;
}

.summary-item-image {
    flex-shrink: 0;
    width: 50px;
    height: 50px;
    border-radius: 8px;
    overflow: hidden;
    background: #f1f5f9;
}

.summary-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.summary-item-details {
    flex: 1;
    margin-right: 10px;
}

.summary-item-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.9rem;
    margin-bottom: 4px;
    line-height: 1.3;
}

.summary-item-variant {
    margin-top: 4px;
    line-height: 1.4;
}

.summary-item-price {
    font-weight: 600;
    color: #1e3a8a;
    font-size: 0.9rem;
    white-space: nowrap;
}

/* Custom Confirmation Modal */
.confirmation-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 10000;
    justify-content: center;
    align-items: center;
    animation: fadeIn 0.3s ease;
}

.confirmation-modal-overlay.active {
    display: flex;
}

.confirmation-modal {
    background: white;
    border-radius: 20px;
    padding: 0;
    width: 90%;
    max-width: 450px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.3s ease;
    overflow: hidden;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.confirmation-modal-header {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    padding: 25px 30px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.confirmation-modal-icon {
    width: 50px;
    height: 50px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.confirmation-modal-icon i {
    font-size: 24px;
    color: white;
}

.confirmation-modal-title {
    color: white;
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0;
}

.confirmation-modal-body {
    padding: 30px;
}

.confirmation-modal-message {
    color: #475569;
    font-size: 1.05rem;
    line-height: 1.6;
    margin-bottom: 0;
}

.confirmation-modal-message strong {
    color: #1e293b;
    font-weight: 600;
}

.confirmation-modal-footer {
    padding: 20px 30px 30px;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.btn-confirm-cancel,
.btn-confirm-delete {
    padding: 12px 30px;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-confirm-cancel {
    background: #f1f5f9;
    color: #475569;
}

.btn-confirm-cancel:hover {
    background: #e2e8f0;
    transform: translateY(-1px);
}

.btn-confirm-delete {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.btn-confirm-delete:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

/* Mobile Styles */
@media (max-width: 991px) {
    .warning-section-controls {
        flex-direction: column;
        gap: 12px;
        padding: 12px;
    }
    
    .warning-select-all-container {
        width: 100%;
        justify-content: flex-start;
    }
    
    .warning-selected-count {
        width: 100%;
        text-align: center;
    }
    
    .btn-delete-warning-selected {
        width: 100%;
        justify-content: center;
        padding: 12px 20px;
    }
    
    .confirmation-modal {
        width: 95%;
        max-width: 380px;
    }

    .confirmation-modal-header {
        padding: 20px 25px;
    }

    .confirmation-modal-icon {
        width: 45px;
        height: 45px;
    }

    .confirmation-modal-icon i {
        font-size: 20px;
    }

    .confirmation-modal-title {
        font-size: 1.2rem;
    }

    .confirmation-modal-body {
        padding: 25px;
    }

    .confirmation-modal-message {
        font-size: 1rem;
    }

    .confirmation-modal-footer {
        padding: 15px 25px 25px;
        flex-direction: column;
    }

    .btn-confirm-cancel,
    .btn-confirm-delete {
        width: 100%;
        justify-content: center;
        padding: 14px 20px;
    }

    .bulk-actions-toolbar {
        padding: 15px;
        border-radius: 15px 15px 0 0;
    }

    .bulk-select-section {
        flex: 1;
        gap: 15px;
    }

    .select-all-text {
        font-size: 0.9rem;
    }

    .selected-count {
        font-size: 0.85rem;
    }

    .bulk-actions-buttons {
        width: 100%;
        flex-direction: column;
    }

    .btn-bulk-action {
        width: 100%;
        justify-content: center;
        padding: 12px 20px;
    }

    .mobile-item-header {
        display: flex;
        justify-content: flex-start;
        margin-bottom: 15px;
    }

    .cart-page-header {
        padding: 40px 20px;
    }

    .cart-page-header h1 {
        font-size: 1.75rem;
    }

    .cart-page-header p {
        font-size: 0.95rem;
    }

    .empty-cart-container {
        padding: 40px 25px;
        border-radius: 15px;
    }

    .empty-cart-icon {
        width: 100px;
        height: 100px;
    }

    .empty-cart-icon i {
        font-size: 50px;
    }

    .empty-cart-title {
        font-size: 1.5rem;
    }

    .empty-cart-text {
        font-size: 1rem;
    }

    .btn-continue-shopping {
        padding: 14px 40px;
        font-size: 1rem;
        width: 100%;
    }

    /* Mobile Cart Item Card Style */
    .cart-items-section {
        padding: 20px 15px;
    }

    .cart-item-row {
        flex-direction: column;
        align-items: flex-start;
        padding: 20px 0;
    }

    .cart-item-mobile {
        width: 100%;
    }

    .cart-item-image-mobile {
        width: 100%;
        height: 250px;
        border-radius: 15px;
        object-fit: cover;
        margin-bottom: 15px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .cart-item-image-mobile:active {
        transform: scale(0.98);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .cart-item-details {
        width: 100%;
        margin-bottom: 15px;
    }

    .cart-item-name {
        font-size: 1.2rem;
    }

    .cart-item-price {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1e293b;
    }

    .cart-item-actions {
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .quantity-controls {
        flex: 1;
        justify-content: center;
        margin-right: 15px;
    }

    .order-summary {
        position: static;
        margin-top: 30px;
    }

    .order-summary-title {
        font-size: 1.3rem;
    }

    .summary-total {
        font-size: 1.2rem;
    }
}

/* Mobile Cart List Style */
.mobile-action-buttons {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    padding: 0;
}

.mobile-action-btn {
    flex: 1;
    padding: 11px 18px;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.mobile-action-btn.btn-order {
    background: #10b981;
    color: white;
}

.mobile-action-btn.btn-order:hover {
    background: #059669;
    transform: translateY(-1px);
}

.mobile-action-btn.btn-delete {
    background: #ef4444;
    color: white;
}

.mobile-action-btn.btn-delete:hover {
    background: #dc2626;
    transform: translateY(-1px);
}

.mobile-cart-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 15px 12px;
    background: white;
    border-bottom: 1px solid #e5e7eb;
    transition: all 0.3s ease;
    position: relative;
}

.mobile-cart-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.mobile-cart-item:hover {
    background: #f8fafc;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.mobile-cart-item:hover::before {
    opacity: 1;
}

.mobile-checkbox {
    display: flex;
    align-items: center;
    padding-top: 8px;
}

.mobile-checkbox input[type="checkbox"] {
    display: none;
}

.mobile-checkbox-mark {
    width: 20px;
    height: 20px;
    border: 2px solid #cbd5e1;
    border-radius: 4px;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.mobile-checkbox input[type="checkbox"]:checked ~ .mobile-checkbox-mark {
    background: #1e3a8a;
    border-color: #1e3a8a;
}

.mobile-checkbox input[type="checkbox"]:checked ~ .mobile-checkbox-mark::after {
    content: '';
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.mobile-product-image {
    flex-shrink: 0;
    width: 80px;
    height: 80px;
    border-radius: 12px;
    overflow: hidden;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: transform 0.3s ease;
    position: relative;
}

.mobile-product-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(30, 58, 138, 0) 0%, rgba(30, 58, 138, 0.05) 100%);
    pointer-events: none;
}

.mobile-product-image:hover {
    transform: scale(1.05);
}

.mobile-product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.mobile-cart-item:hover .mobile-product-image img {
    transform: scale(1.1);
}

.mobile-product-info {
    flex: 1;
    min-width: 0;
}

.mobile-product-name {
    display: block;
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    transition: color 0.3s ease;
    line-height: 1.3;
}

.mobile-product-name:hover {
    color: #1e3a8a;
}

.mobile-variant-info {
    margin-top: 4px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

/* Select Variant Button - Mobile */
.mobile-select-variant-btn {
    width: 100%;
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    color: white;
    padding: 12px 16px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 10px;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
    transition: all 0.3s ease;
    border: none;
    white-space: nowrap;
}

.mobile-select-variant-btn:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.4);
    color: white;
}

.mobile-select-variant-btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
}

.mobile-select-variant-btn i {
    font-size: 1rem;
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

@keyframes warning-pulse {
    0%, 100% { 
        transform: scale(1);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }
    50% { 
        transform: scale(1.05);
        box-shadow: 0 6px 16px rgba(239, 68, 68, 0.5);
    }
}

@keyframes shake {
    0% { transform: rotate(-3deg); }
    100% { transform: rotate(3deg); }
}

/* Warning Section Styles */
.warning-section-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
    padding: 15px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #fca5a5;
}

.warning-select-all-container {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    user-select: none;
}

.warning-select-all-container input[type="checkbox"] {
    display: none;
}

.warning-checkmark {
    width: 22px;
    height: 22px;
    border: 2px solid #ef4444;
    border-radius: 6px;
    background: white;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.warning-select-all-container input[type="checkbox"]:checked ~ .warning-checkmark {
    background: #ef4444;
    border-color: #ef4444;
    transform: scale(1.1);
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
}

.warning-checkmark::after {
    content: '';
    position: absolute;
    display: none;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2.5px 2.5px 0;
    transform: rotate(45deg);
}

.warning-select-all-container input[type="checkbox"]:checked ~ .warning-checkmark::after {
    display: block;
    animation: checkBounce 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

@keyframes checkBounce {
    0% { transform: rotate(45deg) scale(0); }
    50% { transform: rotate(45deg) scale(1.2); }
    100% { transform: rotate(45deg) scale(1); }
}

.warning-select-all-container input[type="checkbox"]:indeterminate ~ .warning-checkmark {
    background: #ef4444;
    border-color: #ef4444;
}

.warning-select-all-container input[type="checkbox"]:indeterminate ~ .warning-checkmark::after {
    display: block;
    width: 10px;
    height: 2px;
    border: none;
    background: white;
    transform: none;
}

.warning-select-all-text {
    font-weight: 700;
    color: #991b1b;
    font-size: 0.95rem;
}

.warning-selected-count {
    color: #dc2626;
    font-size: 0.85rem;
    font-weight: 600;
    background: #fef2f2;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid #fecaca;
}

.btn-delete-warning-selected {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-delete-warning-selected:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
}

.btn-delete-warning-selected:active {
    transform: translateY(0);
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.btn-delete-warning-selected:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-delete-warning-selected i {
    font-size: 1rem;
    animation: trashShake 2s ease-in-out infinite;
}

@keyframes trashShake {
    0%, 90%, 100% { transform: rotate(0deg); }
    92% { transform: rotate(-10deg); }
    94% { transform: rotate(10deg); }
    96% { transform: rotate(-10deg); }
    98% { transform: rotate(10deg); }
}

.warning-item-checkbox {
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    margin-right: 12px;
}

.warning-item-checkbox input[type="checkbox"] {
    display: none;
}

.warning-checkbox-custom {
    width: 20px;
    height: 20px;
    border: 2px solid #ef4444;
    border-radius: 5px;
    background: white;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.warning-item-checkbox input[type="checkbox"]:checked ~ .warning-checkbox-custom {
    background: #ef4444;
    border-color: #ef4444;
    transform: scale(1.1);
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
}

.warning-checkbox-custom::after {
    content: '';
    position: absolute;
    display: none;
    width: 4px;
    height: 9px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.warning-item-checkbox input[type="checkbox"]:checked ~ .warning-checkbox-custom::after {
    display: block;
    animation: checkBounce 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

.warning-item-checkbox:hover .warning-checkbox-custom {
    border-color: #dc2626;
    box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.1);
}

/* Fade in animation for cart items */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.mobile-cart-item {
    animation: fadeInUp 0.4s ease-out forwards;
}

.mobile-cart-item:nth-child(1) { animation-delay: 0.05s; }
.mobile-cart-item:nth-child(2) { animation-delay: 0.1s; }
.mobile-cart-item:nth-child(3) { animation-delay: 0.15s; }
.mobile-cart-item:nth-child(4) { animation-delay: 0.2s; }
.mobile-cart-item:nth-child(5) { animation-delay: 0.25s; }

/* Missing Variant Card - Compact Horizontal Design */
.missing-variant-card {
    margin-bottom: 12px;
    animation: fadeInUp 0.5s ease-out forwards;
}

.missing-variant-card-inner {
    display: flex;
    align-items: center;
    gap: 12px;
    background: linear-gradient(135deg, #ffffff 0%, #fef5f5 100%);
    border-radius: 16px;
    padding: 12px;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.12);
    border: 2px solid rgba(239, 68, 68, 0.2);
    position: relative;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
}

.missing-variant-card-inner::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, #ef4444 0%, #dc2626 50%, #f87171 100%);
    background-size: 100% 200%;
    animation: gradientShift 3s ease infinite;
}

@keyframes gradientShift {
    0%, 100% { background-position: 50% 0%; }
    50% { background-position: 50% 100%; }
}

.missing-variant-card-inner:hover {
    transform: translateX(2px);
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.18);
    border-color: rgba(239, 68, 68, 0.3);
}

.card-delete-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: none;
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #dc2626;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 10;
    transition: all 0.3s ease;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.15);
}

.card-delete-btn:hover {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    color: white;
    transform: rotate(90deg) scale(1.1);
    box-shadow: 0 4px 10px rgba(220, 38, 38, 0.3);
}

.variant-card-image-wrapper {
    flex-shrink: 0;
    text-decoration: none;
}

.variant-card-image {
    width: 85px;
    height: 85px;
    border-radius: 12px;
    overflow: hidden;
    position: relative;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
}

.variant-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.variant-card-image:hover img {
    transform: scale(1.1);
}

.image-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(30, 58, 138, 0.85) 0%, rgba(37, 99, 235, 0.85) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.image-overlay i {
    color: white;
    font-size: 20px;
    transform: scale(0.5);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.variant-card-image:hover .image-overlay {
    opacity: 1;
}

.variant-card-image:hover .image-overlay i {
    transform: scale(1);
}

.variant-card-content {
    flex: 1;
    min-width: 0;
    padding-right: 8px;
}

.variant-card-title {
    display: block;
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
    text-decoration: none;
    line-height: 1.3;
    transition: color 0.3s ease;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.variant-card-title:hover {
    color: #1e3a8a;
}

.variant-required-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    color: #dc2626;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 700;
    margin-bottom: 6px;
    border: 1px solid rgba(220, 38, 38, 0.2);
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.1);
}

.variant-required-badge i {
    animation: pulse 2s ease-in-out infinite;
    font-size: 0.75rem;
}

.variant-card-price {
    font-size: 1.15rem;
    font-weight: 800;
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 8px;
    letter-spacing: -0.3px;
}

.variant-select-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    color: white;
    padding: 10px 16px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.85rem;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.variant-select-button::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s ease;
}

.variant-select-button:hover::before {
    left: 100%;
}

.variant-select-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.4);
    background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%);
    color: white;
}

.variant-select-button:active {
    transform: translateY(0);
    box-shadow: 0 3px 8px rgba(220, 38, 38, 0.3);
}

.button-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.3s ease;
}

.variant-select-button:hover .button-icon {
    transform: rotate(180deg);
}

.button-icon i {
    font-size: 0.9rem;
}

.button-text {
    font-size: 0.9rem;
    letter-spacing: 0.2px;
    white-space: nowrap;
}

.button-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.3s ease;
}

.variant-select-button:hover .button-arrow {
    transform: translateX(3px);
}

.button-arrow i {
    font-size: 0.75rem;
}

.variant-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 700;
    margin-bottom: 4px;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.variant-badge i {
    font-size: 0.65rem;
}

.variant-details {
    font-size: 0.8rem;
    color: #6b7280;
    font-weight: 500;
    line-height: 1.4;
}

.variant-details strong {
    color: #374151;
    font-weight: 700;
}

.mobile-price-qty {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 10px;
    padding: 8px 0;
}

.mobile-price {
    font-size: 1.1rem;
    font-weight: 800;
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.mobile-qty-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 10px;
    padding: 6px 10px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.mobile-qty-controls button {
    width: 28px;
    height: 28px;
    border: none;
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 700;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    box-shadow: 0 2px 6px rgba(30, 58, 138, 0.2);
}

.mobile-qty-controls button:hover {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    transform: scale(1.1);
    box-shadow: 0 4px 10px rgba(30, 58, 138, 0.3);
}

.mobile-qty-controls button:active {
    transform: scale(0.95);
}

.mobile-qty-controls .qty-value {
    font-weight: 700;
    color: #1e293b;
    min-width: 24px;
    text-align: center;
    font-size: 0.95rem;
}

.mobile-qty-controls .qty-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #1f2937;
    min-width: 20px;
    text-align: center;
}

.mobile-delete-btn {
    width: 36px;
    height: 36px;
    border: none;
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #dc2626;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    flex-shrink: 0;
    margin-top: 6px;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.15);
    position: relative;
    overflow: hidden;
}

.mobile-delete-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.mobile-delete-btn:hover {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    color: white;
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}

.mobile-delete-btn:hover::before {
    opacity: 1;
}

.mobile-delete-btn i {
    position: relative;
    z-index: 1;
    transition: transform 0.3s ease;
}

.mobile-delete-btn:hover i {
    transform: scale(1.1);
}

.mobile-delete-btn:active {
    transform: scale(0.95);
}

@media (max-width: 767px) {
    .bulk-actions-toolbar {
        padding: 12px;
    }
    
    .mobile-cart-item {
        padding: 10px 8px;
    }
    
    .mobile-product-image {
        width: 60px;
        height: 60px;
    }
}
</style>

<!-- Cart Page Header -->
<div class="cart-page-header">
    <div class="container">
        <h1>Shopping Cart</h1>
        <p>Review your items and proceed to checkout</p>
    </div>
</div>
<div class="container my-5">
    <?php if (!empty($cart_items)): ?>
        <div class="row">
            <!-- Cart Items -->
            <div class="col-lg-7 col-xl-8">
                <!-- Bulk Actions Toolbar -->
                <div class="bulk-actions-toolbar">
                    <!-- Mobile Action Buttons (shown above select all when items selected) -->
                    <div class="mobile-action-buttons d-md-none" id="mobileActionButtons" style="display: none;">
                        <button class="mobile-action-btn btn-order" onclick="orderSelected()">
                            <i class="fas fa-shopping-bag"></i> Order Selected
                        </button>
                        <button class="mobile-action-btn btn-delete" onclick="deleteSelected()">
                            <i class="fas fa-trash-alt"></i> Delete Selected
                        </button>
                    </div>
                    
                    <div class="bulk-select-section">
                        <label class="select-all-container">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()">
                            <span class="checkmark"></span>
                            <span class="select-all-text">Select All</span>
                        </label>
                        <span class="selected-count" id="selectedCount">0 selected</span>
                    </div>
                    
                    <!-- Desktop Action Buttons (shown on the right) -->
                    <div class="bulk-actions-buttons d-none d-md-flex" id="bulkActionsButtons" style="display: none;">
                        <button class="btn-bulk-action btn-order-selected" onclick="orderSelected()">
                            <i class="fas fa-shopping-bag me-2"></i>Order Selected
                        </button>
                        <button class="btn-bulk-action btn-delete-selected" onclick="deleteSelected()">
                            <i class="fas fa-trash-alt me-2"></i>Delete Selected
                        </button>
                    </div>
                </div>

                <div class="cart-items-section">
                    <?php foreach ($valid_cart_items as $item): ?>
                        <?php 
                        // Priority: combination_price > variant_price > discounted_price > original_price
                        $price = $item['combination_price'] ?: ($item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price']));
                        ?>
                        
                        <!-- Desktop View -->
                        <div class="cart-item-row d-none d-md-flex" data-cart-id="<?php echo $item['id']; ?>">
                            <label class="cart-item-checkbox">
                                <input type="checkbox" class="item-checkbox" value="<?php echo $item['id']; ?>">
                                <span class="checkbox-custom"></span>
                            </label>
                            
                            <a href="product.php?id=<?php echo $item['product_id']; ?>" style="text-decoration: none;">
                                <img src="<?php echo $item['display_image']; ?>" 
                                     class="cart-item-image" alt="<?php echo htmlspecialchars($item['product_name']); ?>" style="cursor: pointer;">
                            </a>
                            
                            <div class="cart-item-details">
                                <a href="product.php?id=<?php echo $item['product_id']; ?>" style="text-decoration: none; color: inherit;">
                                    <div class="cart-item-name" style="cursor: pointer;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                </a>
                                
                                <?php if (!empty($item['combination_details'])): ?>
                                    <!-- NEW: Combination Variants Display -->
                                    <div class="cart-item-variant" style="font-size: 0.875rem; color: #6b7280; margin-top: 4px;">
                                        <span class="badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; margin-right: 4px;">
                                            <i class="fas fa-layer-group" style="font-size: 0.7rem;"></i> Combination
                                        </span>
                                        <?php 
                                        $combo_display = [];
                                        foreach ($item['combination_details'] as $attr => $value) {
                                            $combo_display[] = '<strong>' . htmlspecialchars($attr) . ':</strong> ' . htmlspecialchars($value);
                                        }
                                        echo implode(' <span style="color: #d1d5db;">|</span> ', $combo_display);
                                        ?>
                                    </div>
                                <?php elseif (!empty($item['all_variants'])): ?>
                                    <!-- Simple Multiple Variants Display -->
                                    <div class="cart-item-variant" style="font-size: 0.875rem; color: #6b7280; margin-top: 4px;">
                                        <?php 
                                        $variant_display = [];
                                        foreach ($item['all_variants'] as $variant) {
                                            $variant_display[] = ucfirst($variant['variant_type']) . ': ' . htmlspecialchars($variant['variant_name']);
                                        }
                                        echo implode(', ', $variant_display);
                                        ?>
                                    </div>
                                <?php elseif (!empty($item['variant_name'])): ?>
                                    <!-- Simple Single Variant Display -->
                                    <div class="cart-item-variant" style="font-size: 0.875rem; color: #6b7280; margin-top: 4px;">
                                        <?php echo htmlspecialchars($item['variant_name']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="cart-item-price"><?php echo formatPrice($price); ?></div>
                            </div>
                            
                            <div class="quantity-controls">
                                <button type="button" onclick="updateQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity'] - 1; ?>)">-</button>
                                <input type="number" value="<?php echo $item['quantity']; ?>" readonly>
                                <button type="button" onclick="updateQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity'] + 1; ?>)">+</button>
                            </div>
                            
                            <button class="btn-delete-item" onclick="removeFromCart(<?php echo $item['id']; ?>)" 
                                    title="Remove item">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                        
                        <!-- Mobile View - List Style -->
                        <div class="mobile-cart-item d-md-none" data-cart-id="<?php echo $item['id']; ?>">
                            <label class="mobile-checkbox">
                                <input type="checkbox" class="item-checkbox" value="<?php echo $item['id']; ?>">
                                <span class="mobile-checkbox-mark"></span>
                            </label>
                            
                            <a href="product.php?id=<?php echo $item['product_id']; ?>" class="mobile-product-image">
                                <img src="<?php echo $item['display_image']; ?>" 
                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                            </a>
                            
                            <div class="mobile-product-info">
                                <a href="product.php?id=<?php echo $item['product_id']; ?>" class="mobile-product-name">
                                    <?php echo htmlspecialchars($item['product_name']); ?>
                                </a>
                                
                                <?php if (!empty($item['combination_details'])): ?>
                                    <div class="mobile-variant-info">
                                        <span class="variant-badge">
                                            <i class="fas fa-layer-group"></i> Combination
                                        </span>
                                        <span class="variant-details">
                                            <?php echo 'Size: ' . htmlspecialchars($item['combination_details']['Size'] ?? '') . ' | Design: ' . htmlspecialchars($item['combination_details']['Design'] ?? '') . ' | Color: ' . htmlspecialchars($item['combination_details']['Color'] ?? ''); ?>
                                        </span>
                                    </div>
                                <?php elseif (!empty($item['all_variants'])): ?>
                                    <div class="mobile-variant-info">
                                        <span class="variant-details">
                                            <?php 
                                            $parts = [];
                                            foreach ($item['all_variants'] as $v) {
                                                $parts[] = ucfirst($v['variant_type']) . ': ' . $v['variant_name'];
                                            }
                                            echo implode(' | ', $parts);
                                            ?>
                                        </span>
                                    </div>
                                <?php elseif (!empty($item['variant_name'])): ?>
                                    <div class="mobile-variant-info">
                                        <span class="variant-details"><?php echo htmlspecialchars($item['variant_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mobile-price-qty">
                                    <span class="mobile-price"><?php echo formatPrice($price); ?></span>
                                    <div class="mobile-qty-controls">
                                        <button onclick="updateQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity'] - 1; ?>)">-</button>
                                        <span class="qty-value"><?php echo $item['quantity']; ?></span>
                                        <button onclick="updateQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity'] + 1; ?>)">+</button>
                                    </div>
                                </div>
                            </div>
                            
                            <button class="mobile-delete-btn" onclick="removeFromCart(<?php echo $item['id']; ?>)" title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Items with Missing Variants (shown with warning) -->
                    <?php if (!empty($items_missing_variants)): ?>
                        <div class="missing-variants-section" style="margin-top: 20px; padding: 25px; background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); border: 2px dashed #ef4444; border-radius: 16px; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15); position: relative; overflow: hidden;">
                            <div style="position: absolute; top: -20px; right: -20px; width: 100px; height: 100px; background: radial-gradient(circle, rgba(239, 68, 68, 0.1) 0%, transparent 70%); border-radius: 50%;"></div>
                            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 18px; position: relative; z-index: 1;">
                                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); animation: warning-pulse 2s ease-in-out infinite;">
                                    <i class="fas fa-exclamation-triangle" style="color: white; font-size: 22px; animation: shake 0.5s ease-in-out infinite alternate;"></i>
                                </div>
                                <div>
                                    <h4 style="margin: 0; color: #991b1b; font-size: 1.2rem; font-weight: 800;">Action Required</h4>
                                    <p style="margin: 0; color: #7f1d1d; font-size: 0.95rem; font-weight: 500;">These items cannot be ordered without selecting variants</p>
                                </div>
                            </div>
                            
                            <!-- Warning Section Controls -->
                            <div class="warning-section-controls">
                                <label class="warning-select-all-container">
                                    <input type="checkbox" id="warningSelectAll">
                                    <span class="warning-checkmark"></span>
                                    <span class="warning-select-all-text">Select All</span>
                                </label>
                                
                                <span class="warning-selected-count" id="warningSelectedCount" style="display: none;">
                                    <i class="fas fa-check-circle"></i>
                                    <span id="warningSelectedCountText">0</span> selected
                                </span>
                                
                                <button class="btn-delete-warning-selected" id="deleteWarningSelectedBtn" onclick="deleteSelectedWarningItems()" disabled>
                                    <i class="fas fa-trash-alt"></i>
                                    Delete Selected
                                </button>
                            </div>
                            
                            <?php 
                            // Get full details of items with missing variants
                            $missing_cart_ids = array_column($items_missing_variants, 'cart_id');
                            $items_with_missing_variants = array_filter($cart_items, function($item) use ($missing_cart_ids) {
                                return in_array($item['id'], $missing_cart_ids);
                            });
                            
                            foreach ($items_with_missing_variants as $item): 
                                $price = $item['combination_price'] ?: ($item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price']));
                            ?>
                                <div class="cart-item-row d-none d-md-flex" style="background: white; margin-bottom: 10px; border: 1px solid #fca5a5;" data-warning-item-id="<?php echo $item['id']; ?>">
                                    <label class="warning-item-checkbox">
                                        <input type="checkbox" class="warning-item-check" value="<?php echo $item['id']; ?>" data-product-name="<?php echo htmlspecialchars($item['product_name']); ?>">
                                        <span class="warning-checkbox-custom"></span>
                                    </label>
                                    
                                    <a href="product.php?id=<?php echo $item['product_id']; ?>" style="text-decoration: none;">
                                        <img src="<?php echo $item['display_image']; ?>" 
                                             class="cart-item-image" alt="<?php echo htmlspecialchars($item['product_name']); ?>" style="cursor: pointer;">
                                    </a>
                                    
                                    <div class="cart-item-details">
                                        <a href="product.php?id=<?php echo $item['product_id']; ?>" style="text-decoration: none; color: inherit;">
                                            <div class="cart-item-name" style="cursor: pointer;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        </a>
                                        <div style="color: #dc2626; font-size: 0.85rem; font-weight: 600; margin-top: 4px;">
                                            <i class="fas fa-exclamation-circle"></i> Variant selection required
                                        </div>
                                        <div class="cart-item-price"><?php echo formatPrice($price); ?></div>
                                    </div>
                                    
                                    <div class="cart-item-actions">
                                        <a href="product.php?id=<?php echo $item['product_id']; ?>" class="btn" style="background: #dc2626; color: white; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 0.85rem;">
                                            <i class="fas fa-cog me-1"></i> Select Variant
                                        </a>
                                        <button class="btn-delete-item" onclick="removeFromCart(<?php echo $item['id']; ?>)" style="margin-top: 10px;">
                                            <i class="fas fa-trash-alt"></i>
                                            Delete
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Mobile View for Missing Variant Items -->
                                <div class="missing-variant-card d-md-none" data-cart-id="<?php echo $item['id']; ?>" data-warning-item-id="<?php echo $item['id']; ?>">
                                    <div class="missing-variant-card-inner">
                                        <label class="warning-item-checkbox" style="position: absolute; top: 12px; left: 12px; z-index: 11; background: white; border-radius: 8px; padding: 4px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);">
                                            <input type="checkbox" class="warning-item-check" value="<?php echo $item['id']; ?>" data-product-name="<?php echo htmlspecialchars($item['product_name']); ?>">
                                            <span class="warning-checkbox-custom"></span>
                                        </label>
                                        
                                        <button class="card-delete-btn" onclick="removeFromCart(<?php echo $item['id']; ?>)" title="Remove item">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        
                                        <a href="product.php?id=<?php echo $item['product_id']; ?>" class="variant-card-image-wrapper">
                                            <div class="variant-card-image">
                                                <img src="<?php echo $item['display_image']; ?>" 
                                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                                <div class="image-overlay">
                                                    <i class="fas fa-eye"></i>
                                                </div>
                                            </div>
                                        </a>
                                        
                                        <div class="variant-card-content">
                                            <a href="product.php?id=<?php echo $item['product_id']; ?>" class="variant-card-title">
                                                <?php echo htmlspecialchars($item['product_name']); ?>
                                            </a>
                                            
                                            <div class="variant-required-badge">
                                                <i class="fas fa-exclamation-circle"></i>
                                                <span>Variant selection required</span>
                                            </div>
                                            
                                            <div class="variant-card-price">
                                                <?php echo formatPrice($price); ?>
                                            </div>
                                            
                                            <a href="product.php?id=<?php echo $item['product_id']; ?>" class="variant-select-button">
                                                <span class="button-icon">
                                                    <i class="fas fa-cog"></i>
                                                </span>
                                                <span class="button-text">Click to select variant</span>
                                                <span class="button-arrow">
                                                    <i class="fas fa-arrow-right"></i>
                                                </span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Order Summary -->
            <div class="col-lg-5 col-xl-4">
                <div class="order-summary">
                    <h3 class="order-summary-title">Order Summary</h3>
                    <p class="summary-items-info" id="summaryItemsInfo">Selected items (<span id="selectedItemsCount">0</span>)</p>
                    
                    <!-- Order Items List -->
                    <div class="summary-items-list" id="summaryItemsList">
                        <?php foreach ($valid_cart_items as $item): ?>
                            <div class="summary-item" data-cart-id="<?php echo $item['id']; ?>">
                                <div class="summary-item-image">
                                    <img src="<?php echo $item['display_image']; ?>" 
                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                </div>
                                <div class="summary-item-details">
                                    <div class="summary-item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    <?php if (!empty($item['combination_details'])): ?>
                                        <div class="summary-item-variant">
                                            <span class="badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2px 6px; border-radius: 8px; font-size: 0.65rem; margin-right: 4px;">
                                                <i class="fas fa-layer-group"></i> Combination
                                            </span>
                                            <span style="font-size: 0.8rem; color: #6b7280;">
                                                <?php 
                                                $combo_display = [];
                                                foreach ($item['combination_details'] as $attr => $value) {
                                                    $combo_display[] = '<strong>' . htmlspecialchars($attr) . ':</strong> ' . htmlspecialchars($value);
                                                }
                                                echo implode(' | ', $combo_display);
                                                ?>
                                            </span>
                                        </div>
                                    <?php elseif (!empty($item['all_variants'])): ?>
                                        <div class="summary-item-variant">
                                            <span style="font-size: 0.8rem; color: #6b7280;">
                                                <?php 
                                                $variant_display = [];
                                                foreach ($item['all_variants'] as $variant) {
                                                    $variant_display[] = '<strong>' . ucfirst($variant['variant_type']) . ':</strong> ' . htmlspecialchars($variant['variant_name']);
                                                }
                                                echo implode(', ', $variant_display);
                                                ?>
                                            </span>
                                        </div>
                                    <?php elseif (!empty($item['variant_name'])): ?>
                                        <div class="summary-item-variant">
                                            <span style="font-size: 0.8rem; color: #6b7280;">
                                                <?php echo htmlspecialchars($item['variant_name']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="summary-item-price">
                                    <?php 
                                    $price = $item['combination_price'] ?: ($item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price']));
                                    echo formatPrice($price * $item['quantity']);
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span id="summarySubtotal"><?php echo formatPrice($subtotal); ?></span>
                    </div>
                    
                    <div class="summary-row">
                        <span>Delivery Charges:</span>
                        <span id="summaryDelivery"><?php echo formatPrice($delivery_charges); ?></span>
                    </div>
                    
                    <div class="summary-total">
                        <span>Total:</span>
                        <span id="summaryTotal"><?php echo formatPrice($total); ?></span>
                    </div>
                    
                    <?php if (!empty($items_missing_variants)): ?>
                        <button class="btn-checkout" id="checkoutBtn" style="background: #ef4444; cursor: not-allowed;" disabled>
                            <i class="fas fa-exclamation-circle me-2"></i>Variant Selection Required
                        </button>
                        <div id="variantErrorMessage" style="margin-top: 15px; padding: 15px; background: #fee2e2; border-left: 4px solid #ef4444; border-radius: 8px;">
                            <p style="color: #991b1b; font-weight: 600; margin-bottom: 10px;">
                                <i class="fas fa-exclamation-triangle"></i> Please select variants for the following items:
                            </p>
                            <ul style="color: #991b1b; margin: 0; padding-left: 20px;">
                                <?php foreach ($items_missing_variants as $missing): ?>
                                    <li style="margin-bottom: 8px;">
                                        <a href="product.php?id=<?php echo $missing['product_id']; ?>" 
                                           style="color: #dc2626; font-weight: 600; text-decoration: underline;"
                                           target="_blank">
                                            <?php echo htmlspecialchars($missing['product_name']); ?>
                                        </a>
                                        <span style="font-size: 0.85rem; color: #7f1d1d;"> - Click to select variant</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="checkout.php" class="btn-checkout" id="checkoutBtn">
                            Proceed to Checkout <i class="fas fa-arrow-right ms-2"></i>
                        </a>
                    <?php endif; ?>
                    
                    <a href="shop.php" class="btn-continue-link">
                        Continue Shopping
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Empty Cart -->
        <div class="row">
            <div class="col-12">
                <div class="empty-cart-container">
                    <div class="empty-cart-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <h2 class="empty-cart-title">Your cart is empty</h2>
                    <p class="empty-cart-text">Add some products to get started!</p>
                    <a href="shop.php" class="btn-continue-shopping">
                        Continue Shopping
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Custom Confirmation Modal -->
<div class="confirmation-modal-overlay" id="confirmationModal">
    <div class="confirmation-modal">
        <div class="confirmation-modal-header">
            <div class="confirmation-modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="confirmation-modal-title">Confirm Deletion</h3>
        </div>
        <div class="confirmation-modal-body">
            <p class="confirmation-modal-message" id="confirmationMessage">
                Are you sure you want to delete this item?
            </p>
        </div>
        <div class="confirmation-modal-footer">
            <button class="btn-confirm-cancel" onclick="closeConfirmationModal()">
                <i class="fas fa-times"></i>
                Cancel
            </button>
            <button class="btn-confirm-delete" id="confirmDeleteBtn">
                <i class="fas fa-trash-alt"></i>
                Delete
            </button>
        </div>
    </div>
</div>

<script>
// Store cart items data for calculation
const cartItemsData = {
    <?php foreach ($valid_cart_items as $index => $item): ?>
        <?php 
        // Priority: combination_price > variant_price > discounted_price > original_price
        $price = $item['combination_price'] ?: ($item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price'])); 
        ?>
        <?php echo $item['id']; ?>: {
            price: <?php echo $price; ?>,
            quantity: <?php echo $item['quantity']; ?>,
            name: "<?php echo addslashes(htmlspecialchars($item['product_name'])); ?>",
            delivery_charges: <?php echo floatval($item['delivery_charges'] ?: 0); ?>,
            product_id: <?php echo $item['product_id']; ?>,
            combination_details: <?php echo !empty($item['combination_details']) ? json_encode($item['combination_details']) : 'null'; ?>
        }<?php echo ($index < count($cart_items) - 1) ? ',' : ''; ?>
    <?php endforeach; ?>
};

// Toggle select all checkbox
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const itemCheckboxes = document.querySelectorAll('.item-checkbox:not([disabled])');
    
    // Track which cart IDs we've already selected to avoid duplicates (desktop + mobile views)
    const selectedIds = new Set();
    
    itemCheckboxes.forEach(checkbox => {
        const cartId = checkbox.value;
        
        // Only select if not already selected and checkbox is visible
        if (!selectedIds.has(cartId)) {
            const parent = checkbox.closest('.cart-item-row, .mobile-cart-item');
            if (parent && parent.offsetParent !== null) { // Check if visible
                checkbox.checked = selectAllCheckbox.checked;
                selectedIds.add(cartId);
            }
        }
    });
    
    updateSelectedCount();
    updateOrderSummary();
}

// Update selected count and show/hide bulk action buttons
function updateSelectedCount() {
    const itemCheckboxes = document.querySelectorAll('.item-checkbox:not([disabled])');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const selectedCount = document.getElementById('selectedCount');
    const bulkActionsButtons = document.getElementById('bulkActionsButtons');
    
    // Count unique cart IDs (avoid counting desktop + mobile duplicates)
    const selectedCartIds = new Set();
    let totalCartIds = new Set();
    
    itemCheckboxes.forEach(checkbox => {
        const parent = checkbox.closest('.cart-item-row, .mobile-cart-item');
        if (parent && parent.offsetParent !== null) { // Only count visible items
            const cartId = checkbox.value;
            totalCartIds.add(cartId);
            if (checkbox.checked) {
                selectedCartIds.add(cartId);
            }
        }
    });
    
    const checkedCount = selectedCartIds.size;
    const totalCount = totalCartIds.size;
    
    // Update count text
    selectedCount.textContent = `${checkedCount} selected`;
    
    // Update select all checkbox state
    if (checkedCount === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (checkedCount === totalCount) {
        selectAllCheckbox.checked = true;
        selectAllCheckbox.indeterminate = false;
    } else {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = true;
    }
    
    // Show/hide bulk action buttons (desktop)
    if (checkedCount > 0) {
        bulkActionsButtons.style.display = 'flex';
    } else {
        bulkActionsButtons.style.display = 'none';
    }
    
    // Show/hide mobile action buttons
    const mobileActionButtons = document.getElementById('mobileActionButtons');
    if (mobileActionButtons) {
        if (checkedCount > 0) {
            mobileActionButtons.style.display = 'flex';
        } else {
            mobileActionButtons.style.display = 'none';
        }
    }
    
    // Update order summary
    updateOrderSummary();
}

// Update order summary based on selected items
function updateOrderSummary() {
    const selectedCheckboxes = document.querySelectorAll('.item-checkbox:not([disabled]):checked');
    const summaryItemsInfo = document.getElementById('summaryItemsInfo');
    const summarySubtotal = document.getElementById('summarySubtotal');
    const summaryDelivery = document.getElementById('summaryDelivery');
    const summaryTotal = document.getElementById('summaryTotal');
    const checkoutBtn = document.getElementById('checkoutBtn');
    
    // Return early if required elements don't exist
    if (!summaryItemsInfo || !summarySubtotal || !summaryDelivery || !summaryTotal || !checkoutBtn) {
        console.warn('Order summary elements not found on page');
        return;
    }
    
    let subtotal = 0;
    let itemCount = 0;
    
    let deliveryCharges = 0;
    let uniqueProducts = new Set(); // Track unique products for delivery charges
    
    // Get unique selected cart IDs (avoid desktop + mobile duplicates)
    const selectedCartIds = new Set();
    selectedCheckboxes.forEach(checkbox => {
        const parent = checkbox.closest('.cart-item-row, .cart-item-mobile');
        if (parent && parent.offsetParent !== null) { // Only visible items
            selectedCartIds.add(parseInt(checkbox.value));
        }
    });
    
    if (selectedCartIds.size > 0) {
        // Calculate for selected items only
        selectedCartIds.forEach(cartId => {
            if (cartItemsData[cartId]) {
                const item = cartItemsData[cartId];
                subtotal += item.price * item.quantity;
                
                // Add delivery charges only once per unique product
                if (!uniqueProducts.has(item.product_id)) {
                    deliveryCharges += item.delivery_charges;
                    uniqueProducts.add(item.product_id);
                }
                
                itemCount++;
            }
        });
        
        // Update summary info
        const selectedItemsCount = document.getElementById('selectedItemsCount');
        if (selectedItemsCount) {
            selectedItemsCount.textContent = itemCount;
        }
        summaryItemsInfo.textContent = itemCount === 1 
            ? 'Selected item (1)' 
            : `Selected items (${itemCount})`;
        
        // Update checkout button
        checkoutBtn.href = 'javascript:orderSelected()';
        checkoutBtn.onclick = function(e) {
            e.preventDefault();
            orderSelected();
        };
    } else {
        // Calculate for all items
        Object.values(cartItemsData).forEach(item => {
            subtotal += item.price * item.quantity;
            
            // Add delivery charges only once per unique product
            if (!uniqueProducts.has(item.product_id)) {
                deliveryCharges += item.delivery_charges;
                uniqueProducts.add(item.product_id);
            }
            
            itemCount++;
        });
        
        // Update summary info
        const selectedItemsCount = document.getElementById('selectedItemsCount');
        if (selectedItemsCount) {
            selectedItemsCount.textContent = itemCount;
        }
        summaryItemsInfo.textContent = itemCount === 1 
            ? 'All items (1)' 
            : `All items (${itemCount})`;
        
        // Update checkout button
        checkoutBtn.href = 'checkout.php';
        checkoutBtn.onclick = null;
    }
    
    const total = subtotal + deliveryCharges;
    
    // Format and update prices
    summarySubtotal.textContent = formatPriceJS(subtotal);
    summaryDelivery.textContent = formatPriceJS(deliveryCharges);
    summaryTotal.textContent = formatPriceJS(total);
    
    // Show/hide summary items based on selection
    const summaryItems = document.querySelectorAll('.summary-item');
    if (selectedCartIds.size > 0) {
        // Show only selected items (use the unique Set we already created)
        summaryItems.forEach(item => {
            const cartId = parseInt(item.getAttribute('data-cart-id'));
            item.style.display = selectedCartIds.has(cartId) ? 'flex' : 'none';
        });
    } else {
        // Show all items
        summaryItems.forEach(item => {
            item.style.display = 'flex';
        });
    }
}

// Format price for JavaScript
function formatPriceJS(price) {
    return 'PKR ' + Math.round(price).toLocaleString('en-US');
}

// Get selected cart IDs
function getSelectedCartIds() {
    const selectedCheckboxes = document.querySelectorAll('.item-checkbox:not([disabled]):checked');
    
    // Get unique cart IDs only (avoid desktop + mobile duplicates)
    const uniqueIds = new Set();
    selectedCheckboxes.forEach(checkbox => {
        const parent = checkbox.closest('.cart-item-row, .mobile-cart-item');
        if (parent && parent.offsetParent !== null) { // Only visible items
            uniqueIds.add(parseInt(checkbox.value));
        }
    });
    
    return Array.from(uniqueIds);
}

// Store items missing variants data
const itemsMissingVariants = <?php echo json_encode($items_missing_variants); ?>;

// Order selected items
function orderSelected() {
    const selectedIds = getSelectedCartIds();
    
    if (selectedIds.length === 0) {
        showNotification('Please select items to order', 'warning');
        return;
    }
    
    // Check if any selected items are missing variants
    const selectedMissingVariants = itemsMissingVariants.filter(item => 
        selectedIds.includes(item.cart_id)
    );
    
    if (selectedMissingVariants.length > 0) {
        let errorMsg = 'The following items require variant selection:<br><br>';
        selectedMissingVariants.forEach(item => {
            errorMsg += `<strong>• ${item.product_name}</strong> - <a href="product.php?id=${item.product_id}" target="_blank" style="color: #dc2626; text-decoration: underline;">Select Variant</a><br>`;
        });
        showNotification(errorMsg, 'error');
        return;
    }
    
    // Store selected items in session and redirect to checkout
    $.ajax({
        url: 'ajax/set_selected_items.php',
        method: 'POST',
        data: { cart_ids: selectedIds },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                window.location.href = 'checkout.php?selected=1';
            } else {
                showNotification(data.message || 'Error processing request', 'error');
            }
        },
        error: function() {
            showNotification('Error processing request', 'error');
        }
    });
}

// Delete selected items
function deleteSelected() {
    const selectedIds = getSelectedCartIds();
    
    if (selectedIds.length === 0) {
        showNotification('Please select items to delete', 'warning');
        return;
    }
    
    const confirmMsg = selectedIds.length === 1 
        ? 'Are you sure you want to delete this item from your cart?' 
        : `Are you sure you want to delete <strong>${selectedIds.length} items</strong> from your cart?`;
    
    showConfirmationModal(confirmMsg, function() {
        $.ajax({
            url: 'ajax/delete_selected_items.php',
            method: 'POST',
            data: { cart_ids: selectedIds },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    showNotification(data.message || 'Items deleted successfully', 'success');
                    
                    // Remove deleted items from DOM with staggered animation
                    selectedIds.forEach((id, index) => {
                        setTimeout(() => {
                            const itemRows = document.querySelectorAll(`[data-cart-id="${id}"]`);
                            itemRows.forEach(row => {
                                row.style.transition = 'all 0.4s ease';
                                row.style.opacity = '0';
                                row.style.transform = 'translateX(-30px) scale(0.9)';
                                setTimeout(() => {
                                    row.remove();
                                    
                                    // Remove from local data
                                    delete cartItemsData[id];
                                }, 400);
                            });
                        }, index * 80);
                    });
                    
                    // Update UI after all animations
                    setTimeout(() => {
                        // Update cart count
                        updateCartCount();
                        
                        // Update order summary
                        updateOrderSummary();
                        
                        // Reset select all checkbox
                        const selectAllCheckbox = document.getElementById('selectAll');
                        if (selectAllCheckbox) selectAllCheckbox.checked = false;
                        
                        // Update selected count
                        updateSelectedCount();
                        
                        // Check if cart is empty
                        checkIfCartEmpty();
                    }, (selectedIds.length * 80) + 500);
                } else {
                    showNotification(data.message || 'Error deleting items', 'error');
                }
            },
            error: function() {
                showNotification('Error deleting items', 'error');
            }
        });
    });
}

// Show notification
function showNotification(message, type = 'info') {
    // Remove existing notification
    const existingNotif = document.querySelector('.cart-notification');
    if (existingNotif) {
        existingNotif.remove();
    }
    
    // Create notification
    const notification = document.createElement('div');
    notification.className = 'cart-notification';
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'error' ? '#fee2e2' : type === 'success' ? '#d1fae5' : type === 'warning' ? '#fef3c7' : '#dbeafe'};
        color: ${type === 'error' ? '#991b1b' : type === 'success' ? '#065f46' : type === 'warning' ? '#92400e' : '#1e3a8a'};
        padding: 15px 20px;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 14px;
        font-weight: 500;
        animation: slideInRight 0.3s ease;
        border-left: 4px solid ${type === 'error' ? '#dc2626' : type === 'success' ? '#10b981' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
    `;
    
    const icon = type === 'error' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle';
    notification.innerHTML = `
        <i class="fas fa-${icon}" style="font-size: 18px;"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Add animation styles
if (!document.getElementById('cart-notification-styles')) {
    const style = document.createElement('style');
    style.id = 'cart-notification-styles';
    style.textContent = `
        @keyframes slideInRight {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(400px);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);
}

// Synchronize checkbox states between desktop and mobile views
function syncCheckboxes(changedCheckbox) {
    const cartId = changedCheckbox.value;
    const isChecked = changedCheckbox.checked;
    
    // Find all checkboxes with the same cart ID
    const allCheckboxes = document.querySelectorAll(`.item-checkbox[value="${cartId}"]`);
    allCheckboxes.forEach(checkbox => {
        if (checkbox !== changedCheckbox) {
            checkbox.checked = isChecked;
        }
    });
}

// Add event listeners to all checkboxes for synchronization
document.addEventListener('DOMContentLoaded', function() {
    const allCheckboxes = document.querySelectorAll('.item-checkbox:not([disabled])');
    allCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            syncCheckboxes(this);
            updateSelectedCount();
        });
    });
    
    updateOrderSummary();
    initializeWarningSection();
});

// Initialize warning section functionality
function initializeWarningSection() {
    const warningSelectAll = document.getElementById('warningSelectAll');
    const warningCheckboxes = document.querySelectorAll('.warning-item-check');
    
    if (warningSelectAll && warningCheckboxes.length > 0) {
        // Select all functionality
        warningSelectAll.addEventListener('change', function() {
            const isChecked = this.checked;
            warningCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateWarningSelectedCount();
        });
        
        // Individual checkbox change
        warningCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                syncWarningCheckboxes(this);
                updateWarningSelectedCount();
                updateWarningSelectAllState();
            });
        });
    }
}

// Sync warning checkboxes between desktop and mobile views
function syncWarningCheckboxes(changedCheckbox) {
    const cartId = changedCheckbox.value;
    const isChecked = changedCheckbox.checked;
    
    // Find all checkboxes with the same cart ID
    const allWarningCheckboxes = document.querySelectorAll(`.warning-item-check[value="${cartId}"]`);
    allWarningCheckboxes.forEach(checkbox => {
        if (checkbox !== changedCheckbox) {
            checkbox.checked = isChecked;
        }
    });
}

// Update warning section selected count
function updateWarningSelectedCount() {
    const checkedCheckboxes = document.querySelectorAll('.warning-item-check:checked');
    
    // Get unique cart IDs to avoid counting desktop and mobile duplicates
    const uniqueIds = new Set();
    checkedCheckboxes.forEach(checkbox => {
        uniqueIds.add(checkbox.value);
    });
    
    const checkedCount = uniqueIds.size;
    const countElement = document.getElementById('warningSelectedCount');
    const countText = document.getElementById('warningSelectedCountText');
    const deleteBtn = document.getElementById('deleteWarningSelectedBtn');
    
    if (countElement && countText && deleteBtn) {
        if (checkedCount > 0) {
            countElement.style.display = 'inline-block';
            countText.textContent = checkedCount;
            deleteBtn.disabled = false;
        } else {
            countElement.style.display = 'none';
            deleteBtn.disabled = true;
        }
    }
}

// Update select all checkbox state (checked, indeterminate, or unchecked)
function updateWarningSelectAllState() {
    const warningSelectAll = document.getElementById('warningSelectAll');
    const warningCheckboxes = document.querySelectorAll('.warning-item-check');
    const checkedCount = document.querySelectorAll('.warning-item-check:checked').length;
    
    if (warningSelectAll && warningCheckboxes.length > 0) {
        if (checkedCount === 0) {
            warningSelectAll.checked = false;
            warningSelectAll.indeterminate = false;
        } else if (checkedCount === warningCheckboxes.length) {
            warningSelectAll.checked = true;
            warningSelectAll.indeterminate = false;
        } else {
            warningSelectAll.checked = false;
            warningSelectAll.indeterminate = true;
        }
    }
}

// Delete selected warning items
function deleteSelectedWarningItems() {
    const selectedCheckboxes = document.querySelectorAll('.warning-item-check:checked');
    
    if (selectedCheckboxes.length === 0) {
        return;
    }
    
    // Get unique IDs and names to avoid duplicates from desktop/mobile views
    const uniqueItems = new Map();
    selectedCheckboxes.forEach(cb => {
        if (!uniqueItems.has(cb.value)) {
            uniqueItems.set(cb.value, cb.dataset.productName);
        }
    });
    
    const selectedIds = Array.from(uniqueItems.keys());
    const productNames = Array.from(uniqueItems.values());
    
    let message = '';
    if (selectedIds.length === 1) {
        message = `Are you sure you want to remove <strong>${productNames[0]}</strong> from your cart?`;
    } else {
        const namesList = productNames.slice(0, 3).map(name => `<strong>${name}</strong>`).join(', ');
        const remaining = selectedIds.length - 3;
        if (remaining > 0) {
            message = `Are you sure you want to remove ${namesList} and <strong>${remaining} more item${remaining > 1 ? 's' : ''}</strong> from your cart?`;
        } else {
            message = `Are you sure you want to remove ${namesList} from your cart?`;
        }
    }
    
    showEnhancedConfirmationModal(
        message,
        selectedIds.length,
        function() {
            // Delete items in sequence
            let deletedCount = 0;
            const totalToDelete = selectedIds.length;
            
            selectedIds.forEach((cartId, index) => {
                $.post('ajax/remove_from_cart.php', {
                    cart_id: cartId
                }, function(data) {
                    if(data.success) {
                        deletedCount++;
                        
                        // Remove from DOM with animation
                        const itemRows = document.querySelectorAll(`[data-warning-item-id="${cartId}"]`);
                        itemRows.forEach(row => {
                            row.style.transition = 'all 0.4s ease';
                            row.style.opacity = '0';
                            row.style.transform = 'translateX(-30px) scale(0.95)';
                            setTimeout(() => row.remove(), 400);
                        });
                        
                        // Remove from local data
                        delete cartItemsData[cartId];
                        
                        // If all items deleted
                        if (deletedCount === totalToDelete) {
                            showNotification(`${deletedCount} item${deletedCount > 1 ? 's' : ''} removed from cart`, 'success');
                            
                            setTimeout(() => {
                                // Update cart count
                                updateCartCount();
                                
                                // Update order summary
                                updateOrderSummary();
                                
                                // Reset warning section controls
                                const warningSelectAll = document.getElementById('warningSelectAll');
                                if (warningSelectAll) warningSelectAll.checked = false;
                                updateWarningSelectedCount();
                                
                                // Check if warning section is empty
                                const remainingWarningItems = document.querySelectorAll('.warning-item-check');
                                if (remainingWarningItems.length === 0) {
                                    const warningSection = document.querySelector('.missing-variants-section');
                                    if (warningSection) {
                                        warningSection.style.transition = 'all 0.5s ease';
                                        warningSection.style.opacity = '0';
                                        warningSection.style.transform = 'scale(0.95)';
                                        setTimeout(() => {
                                            warningSection.remove();
                                            
                                            // Check if entire cart is empty AFTER warning section is removed
                                            setTimeout(() => {
                                                checkIfCartEmpty();
                                            }, 100);
                                        }, 500);
                                    } else {
                                        // If no warning section, check immediately
                                        checkIfCartEmpty();
                                    }
                                } else {
                                    // If there are still warning items, just check if cart is empty
                                    checkIfCartEmpty();
                                }
                            }, 500);
                        }
                    } else {
                        showNotification(data.message || 'Error removing from cart', 'error');
                    }
                }, 'json');
            });
        }
    );
}

// Enhanced confirmation modal for better UX
function showEnhancedConfirmationModal(message, itemCount, onConfirm) {
    const modal = document.getElementById('confirmationModal');
    const messageEl = document.getElementById('confirmationMessage');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    const modalTitle = document.querySelector('.confirmation-modal-title');
    
    // Check if modal exists
    if (!modal || !messageEl || !confirmBtn) {
        console.warn('Modal elements not found');
        return;
    }
    
    // Update title based on count
    if (modalTitle) {
        modalTitle.textContent = itemCount > 1 ? `Delete ${itemCount} Items?` : 'Delete Item?';
    }
    
    // Set message
    messageEl.innerHTML = message;
    
    // Remove old event listeners by cloning
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    // Add new event listener with animation
    newConfirmBtn.addEventListener('click', function() {
        // Add loading state
        newConfirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        newConfirmBtn.disabled = true;
        
        setTimeout(() => {
            closeConfirmationModal();
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        }, 300);
    });
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function updateQuantity(cartId, newQuantity) {
    if (newQuantity < 1) {
        removeFromCart(cartId);
        return;
    }
    
    // Update local data
    if (cartItemsData[cartId]) {
        cartItemsData[cartId].quantity = newQuantity;
    }
    
    updateCartQuantity(cartId, newQuantity);
    updateOrderSummary();
}

function clearCart() {
    // Check if modal function exists
    if (typeof showConfirmationModal !== 'function') {
        console.warn('showConfirmationModal not available');
        return;
    }
    
    showConfirmationModal(
        'Are you sure you want to clear <strong>all items</strong> from your cart? This action cannot be undone.',
        function() {
            $.post('ajax/clear_cart.php', function(data) {
                if (data.success) {
                    showNotification('Cart cleared successfully', 'success');
                    
                    // Remove all cart items with animation
                    const allItems = document.querySelectorAll('[data-cart-id], [data-warning-item-id]');
                    allItems.forEach((item, index) => {
                        setTimeout(() => {
                            item.style.transition = 'all 0.4s ease';
                            item.style.opacity = '0';
                            item.style.transform = 'translateX(-30px) scale(0.9)';
                            setTimeout(() => item.remove(), 400);
                        }, index * 50);
                    });
                    
                    // Clear local data
                    if (typeof cartItemsData !== 'undefined') {
                        cartItemsData = {};
                    }
                    
                    setTimeout(() => {
                        // Update cart count
                        updateCartCount();
                        
                        // Show empty cart message
                        if (typeof checkIfCartEmpty === 'function') {
                            checkIfCartEmpty();
                        }
                    }, 600);
                } else {
                    showNotification(data.message || 'Error clearing cart', 'error');
                }
            }, 'json');
        }
    );
}

// Check if cart is empty and show empty cart message
function checkIfCartEmpty() {
    // Use a timeout to ensure DOM is updated
    setTimeout(() => {
        const cartItems = document.querySelectorAll('[data-cart-id]');
        const warningItems = document.querySelectorAll('[data-warning-item-id]');
        
        // If no items at all (both regular and warning items)
        if (cartItems.length === 0 && warningItems.length === 0) {
            
            // Get sections to remove (NOT the header - keep it like original)
            const cartItemsSection = document.querySelector('.cart-items-section');
            const orderSummary = document.querySelector('.order-summary');
            const bulkToolbar = document.querySelector('.bulk-actions-toolbar');
            const warningSection = document.querySelector('.missing-variants-section');
            const leftColumn = document.querySelector('.col-lg-8');
            const rightColumn = document.querySelector('.col-lg-4');
            
            // Hide sections with animation (NOT header)
            [cartItemsSection, orderSummary, bulkToolbar, warningSection, leftColumn, rightColumn].forEach(element => {
                if (element) {
                    element.style.transition = 'all 0.4s ease';
                    element.style.opacity = '0';
                    element.style.transform = 'scale(0.95)';
                }
            });
            
            // Remove sections and show empty cart message
            setTimeout(() => {
                [cartItemsSection, orderSummary, bulkToolbar, warningSection, leftColumn, rightColumn].forEach(element => {
                    if (element) element.remove();
                });
                
                // Find the main container (after the header)
                const cartPageHeader = document.querySelector('.cart-page-header');
                const mainContainer = cartPageHeader ? cartPageHeader.nextElementSibling : null;
                
                if (mainContainer && mainContainer.classList.contains('container')) {
                    // Replace container content with empty cart message (matching original PHP structure)
                    mainContainer.innerHTML = `
                        <div class="row">
                            <div class="col-12">
                                <div class="empty-cart-container" style="animation: fadeInUp 0.6s ease;">
                                    <div class="empty-cart-icon">
                                        <i class="fas fa-shopping-cart"></i>
                                    </div>
                                    <h2 class="empty-cart-title">Your cart is empty</h2>
                                    <p class="empty-cart-text">Add some products to get started!</p>
                                    <a href="shop.php" class="btn-continue-shopping">
                                        Continue Shopping
                                    </a>
                                </div>
                            </div>
                        </div>
                    `;
                }
            }, 450);
        }
    }, 100);
}

// Show custom confirmation modal
function showConfirmationModal(message, onConfirm) {
    const modal = document.getElementById('confirmationModal');
    const messageEl = document.getElementById('confirmationMessage');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    // Check if modal exists
    if (!modal || !messageEl || !confirmBtn) {
        console.warn('Modal elements not found');
        return;
    }
    
    // Set message
    messageEl.innerHTML = message;
    
    // Remove old event listeners by cloning
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    // Add new event listener
    newConfirmBtn.addEventListener('click', function() {
        closeConfirmationModal();
        if (typeof onConfirm === 'function') {
            onConfirm();
        }
    });
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close confirmation modal
function closeConfirmationModal() {
    const modal = document.getElementById('confirmationModal');
    if (modal) {
        modal.classList.remove('active');
    }
    document.body.style.overflow = 'auto';
}

// Close modal on overlay click
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('confirmationModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeConfirmationModal();
            }
        });
    }
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeConfirmationModal();
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
