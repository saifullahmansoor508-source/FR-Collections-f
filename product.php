<?php
// Test if PHP is working
if (isset($_GET['test_php'])) {
    echo 'PHP is working correctly!';
    exit;
}
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($product_id <= 0) {
    redirectTo('shop.php');
}

// Get user data if logged in
$user = null;
$is_affiliate = false;
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user is an affiliate
    $stmt = $db->prepare("SELECT * FROM affiliates WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_affiliate = !empty($affiliate);
}

// Get product details
$stmt = $db->prepare("
    SELECT p.*, c.name as category_name,
           (SELECT COUNT(*) FROM reviews WHERE product_id = p.id) as review_count,
           (SELECT AVG(rating) FROM reviews WHERE product_id = p.id) as avg_rating
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    redirectTo('shop.php');
}

// Get product images
$stmt = $db->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC");
$stmt->execute([$product_id]);
$product_images = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if product uses combination variants
require_once 'config/variant_helpers.php';
$uses_combinations = productUsesCombinations($db, $product_id);

if ($uses_combinations) {
    // Get combination variants
    $product_combinations = getProductCombinations($db, $product_id);
    $product_attributes = getProductAttributes($db, $product_id);
    $product_variants = []; // Empty for backward compatibility
} else {
    // Get simple variants (old format)
    try {
        $stmt = $db->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY variant_type, variant_name");
        $stmt->execute([$product_id]);
        $product_variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If column doesn't exist, try without it
        $stmt = $db->prepare("SELECT id, product_id, variant_type, variant_name, variant_price, variant_original_price, variant_image, stock_count, created_at FROM product_variants WHERE product_id = ? ORDER BY variant_type, variant_name");
        $stmt->execute([$product_id]);
        $product_variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add null/default values for missing columns if they don't exist
        foreach ($product_variants as &$variant) {
            if (!isset($variant['variant_original_price'])) {
                $variant['variant_original_price'] = null;
            }
            if (!isset($variant['stock_count'])) {
                $variant['stock_count'] = 1000; // Default stock count only if column missing
            }
        }
    }
    $product_combinations = [];
    $product_attributes = [];
}

// Get product features
$stmt = $db->prepare("SELECT * FROM product_features WHERE product_id = ?");
$stmt->execute([$product_id]);
$product_features = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get reviews
$stmt = $db->prepare("SELECT * FROM reviews WHERE product_id = ? AND is_approved = 1 ORDER BY created_at DESC");
$stmt->execute([$product_id]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if product is in user's wishlist
$is_in_wishlist = false;
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$_SESSION['user_id'], $product_id]);
    $is_in_wishlist = $stmt->fetch() !== false;
}

// Get related products (You May Also Like) - Based on name keywords and category
// Extract keywords from product name
$product_name_words = explode(' ', strtolower($product['name']));
// Remove common words
$stop_words = ['the', 'a', 'an', 'and', 'or', 'but', 'for', 'with', 'of', 'in', 'on', 'at'];
$keywords = array_diff($product_name_words, $stop_words);
$keywords = array_filter($keywords, function($word) {
    return strlen($word) > 3; // Only words longer than 3 characters
});

// Build LIKE conditions for keyword matching
$like_conditions = [];
$like_params = [];
foreach ($keywords as $keyword) {
    $like_conditions[] = "LOWER(p.name) LIKE ?";
    $like_params[] = '%' . $keyword . '%';
}

if (!empty($like_conditions)) {
    $keyword_where = '(' . implode(' OR ', $like_conditions) . ') OR';
    $keyword_case = implode(' OR ', $like_conditions);
    // Build params: product_id, LIKE params (twice - once for CASE, once for WHERE), category_id
    $params = array_merge([$product_id], $like_params, $like_params, [$product['category_id']]);
} else {
    $keyword_where = '';
    $keyword_case = '1=0';
    $params = [$product_id, $product['category_id']];
}

$stmt = $db->prepare("
    SELECT p.*, pi.image_path, c.name as category_name,
           (CASE 
               WHEN {$keyword_case} THEN 1
               ELSE 0
           END) as keyword_match
    FROM products p 
    LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id != ? AND (
        {$keyword_where}
        p.category_id = ?
    )
    ORDER BY keyword_match DESC, RAND()
    LIMIT 6
");
$stmt->execute($params);
$related_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get cart product IDs (for both logged-in users and guests)
$cart_product_ids = [];
if (isset($_SESSION['user_id'])) {
    // Logged-in users: Get from database
    $stmt = $db->prepare("SELECT DISTINCT product_id FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_product_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    // Guest users: Get from session
    if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        $cart_product_ids = array_column($_SESSION['cart'], 'product_id');
    }
}

$page_title = $product['name'];
require_once 'includes/header.php';

// Group variants by type
$variants_by_type = [];
if (!empty($product_variants) && is_array($product_variants)) {
    foreach ($product_variants as $variant) {
        if (is_array($variant) && !empty($variant['variant_type']) && !empty($variant['variant_name'])) {
            // Ensure all required fields exist
            $variant['variant_original_price'] = $variant['variant_original_price'] ?? null;
            $variant['variant_price'] = $variant['variant_price'] ?? null;
            $variant['variant_image'] = $variant['variant_image'] ?? null;

            $variants_by_type[$variant['variant_type']][] = $variant;
        }
    }
}

// Debug: Log variant data if in development
if (isset($_GET['debug'])) {
    error_log('Product Variants: ' . print_r($product_variants, true));
    error_log('Variants by Type: ' . print_r($variants_by_type, true));
}
?>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
.product-detail-page {
    background: #f8f9fa;
    padding: 20px 0;
}

.product-main-section {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.product-gallery-container {
    position: relative;
    width: 100%;
    overflow: hidden;
    touch-action: pan-y pinch-zoom;
}

.image-slider-wrapper {
    position: relative;
    width: 100%;
    overflow: hidden;
    border-radius: 12px;
    margin-bottom: 15px;
}

.image-slider {
    display: flex;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    will-change: transform;
}

.image-slider.dragging {
    transition: none;
}

.main-product-image {
    width: 100%;
    min-width: 100%;
    object-fit: cover;
    aspect-ratio: 1/1;
    user-select: none;
    -webkit-user-drag: none;
    pointer-events: none;
}

.thumbnail-images {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
    padding: 5px 0;
}

.thumbnail-images::-webkit-scrollbar {
    height: 6px;
}

.thumbnail-images::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.thumbnail-images::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.thumbnail-images::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.thumbnail-item {
    width: 70px;
    height: 70px;
    min-width: 70px;
    border-radius: 8px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.3s;
    object-fit: cover;
}

.thumbnail-item.active {
    border-color: #1e3a8a;
    box-shadow: 0 0 0 2px rgba(30, 58, 138, 0.2);
}

.thumbnail-item:hover {
    border-color: #3b82f6;
    transform: scale(1.05);
}

.image-counter {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px 16px;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    width: fit-content;
    margin-left: auto;
    margin-right: auto;
}

.image-counter i {
    color: #3b82f6;
}

.swipe-instruction {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px 0;
    font-size: 0.8rem;
    color: #94a3b8;
    margin-top: 8px;
    opacity: 0.8;
    transition: opacity 0.3s;
}

.swipe-instruction i {
    color: #cbd5e1;
    animation: pointPulse 2s ease-in-out infinite;
}

@keyframes pointPulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.2);
    }
}

@media (min-width: 768px) {
    .swipe-instruction {
        display: none;
    }
}

/* Product Info */
.product-category-badge {
    display: inline-block;
    color: #94a3b8;
    font-weight: 400;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: -14px;
}

.product-title {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 0;
    margin-top: -24px;
    line-height: 2.7;
}

.product-rating {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: -5px;
    margin-bottom: 20px;
}

.rating-stars {
    color: #fbbf24;
    font-size: 0.9rem;
}

.rating-stars i {
    margin-right: 1px;
}

.rating-text {
    color: #1e3a8a;
    font-size: 0.85rem;
}

.product-price-section {
    margin-bottom: 20px;
}

.current-price {
    font-size: 1.8rem;
    font-weight: 700;
    color: #1e3a8a;
}

.original-price {
    font-size: 1rem;
    color: #94a3b8;
    text-decoration: line-through;
    margin-left: 10px;
    font-weight: 400;
}

.product-description {
    color: #475569;
    line-height: 1.6;
    margin-bottom: 25px;
    font-size: 0.9rem;
}

/* Variant Selection */
.variant-section {
    margin-bottom: 25px;
}

.variant-label {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 12px;
    font-size: 0.95rem;
}

.variant-options {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.variant-box {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    background: white;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    min-width: 120px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.variant-box:hover {
    border-color: #1e3a8a;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}

.variant-box.active {
    background: #1e3a8a;
    color: white;
    border-color: #1e3a8a;
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
}

.variant-image {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f1f5f9;
    flex-shrink: 0;
}

.variant-box.active .variant-image {
    border-color: white;
}

.variant-name {
    font-weight: 600;
    font-size: 0.9rem;
    color: #475569;
    text-transform: capitalize;
}

.variant-box.active .variant-name {
    color: white;
}

/* Legacy styles for backward compatibility */
.color-variants {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.color-variant-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    cursor: pointer;
    border: 3px solid transparent;
    transition: all 0.3s;
    background-size: cover;
    background-position: center;
    position: relative;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.color-variant-circle:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.color-variant-circle.active {
    border-color: #1e3a8a;
    box-shadow: 0 0 0 2px white, 0 0 0 4px #1e3a8a;
}

.size-variants {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.size-variant-btn {
    padding: 10px 20px;
    border: 2px solid #e2e8f0;
    background: white;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    font-weight: 600;
    color: #475569;
}

.size-variant-btn:hover {
    border-color: #1e3a8a;
    color: #1e3a8a;
}

.size-variant-btn.active {
    background: #1e3a8a;
    color: white;
    border-color: #1e3a8a;
}

/* Quantity and Status Row */
.quantity-status-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    gap: 20px;
}

.quantity-section {
    display: flex;
    align-items: center;
    gap: 15px;
}

.variant-label {
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 0;
    font-size: 0.95rem;
}

.quantity-selector {
    display: inline-flex;
    align-items: center;
    border: none;
    border-radius: 8px;
    overflow: hidden;
    background: transparent;
    gap: 0;
}

.quantity-btn {
    background: #00bcd4;
    border: none;
    padding: 8px 16px;
    font-size: 1.2rem;
    cursor: pointer;
    color: white;
    font-weight: 700;
    transition: all 0.2s;
    border-radius: 0;
    min-width: 40px;
}

.quantity-btn:first-child {
    border-top-left-radius: 8px;
    border-bottom-left-radius: 8px;
}

.quantity-btn:last-child {
    border-top-right-radius: 8px;
    border-bottom-right-radius: 8px;
}

.quantity-btn:hover {
    background: #00acc1;
}

.quantity-input {
    border: none;
    border-top: 2px solid #00bcd4;
    border-bottom: 2px solid #00bcd4;
    width: 60px;
    text-align: center;
    font-weight: 600;
    font-size: 1rem;
    padding: 6px 5px;
    margin: 0;
    border-radius: 0;
    background: white;
}

.quantity-input:focus {
    outline: none;
}

.status-wrapper {
    display: flex;
    align-items: center;
}

/* Action Buttons */
.action-buttons {
    margin-bottom: 30px;
    display: flex;
    gap: 10px;
}

.btn-add-cart {
    background: #7ed957;
    color: #1e3a8a;
    border: none;
    padding: 15px 30px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
    flex: 1;
    transition: all 0.3s;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-add-cart:hover {
    background: #6ec847;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(126, 217, 87, 0.3);
}

.btn-buy-now {
    background: #1e3a8a;
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
    flex: 1;
    transition: all 0.3s;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-buy-now:hover {
    background: #1e40af;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(30, 58, 138, 0.3);
}

.btn-add-wishlist {
    background: white;
    color: #1e3a8a;
    border: 2px solid #1e3a8a;
    padding: 15px 30px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
    width: 100%;
    transition: all 0.3s;
    cursor: pointer;
}

.btn-add-wishlist:hover {
    background: #1e3a8a;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(30, 58, 138, 0.3);
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 8px 20px;
    border-radius: 25px;
    font-weight: 500;
    font-size: 0.85rem;
    margin-bottom: 0;
}

.status-in-stock {
    background: #d1fae5;
    color: #059669;
}

.status-limited {
    background: #fef3c7;
    color: #92400e;
}

.status-out-stock {
    background: #fee2e2;
    color: #991b1b;
}

/* Features Section */
.features-section {
    background: transparent;
    border-radius: 0;
    padding: 0;
    margin-bottom: 20px;
    box-shadow: none;
}

.features-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 20px;
    letter-spacing: 0.3px;
    font-family: 'Quicksand', 'Visby Round CF', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.feature-item {
    padding: 12px 0;
    border-bottom: none;
    display: grid;
    grid-template-columns: auto auto 1fr;
    gap: 15px;
    align-items: center;
}

.feature-item:last-child {
    border-bottom: none;
}

.feature-name {
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 0;
    font-size: 1.2rem;
    font-family: 'Quicksand', 'Visby Round CF', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    line-height: 1;
    text-align: right;
}

.feature-arrow {
    font-weight: 700;
    color: #1e3a8a;
    font-size: 1.3rem;
    font-family: 'Quicksand', 'Visby Round CF', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    line-height: 1;
    padding: 0 5px;
}

.feature-value {
    color: #475569;
    font-size: 1rem;
    font-weight: 400;
    line-height: 1;
    font-family: 'Quicksand', 'Visby Round CF', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* You May Also Like */
.related-products-section {
    margin-top: 40px;
}

.section-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 25px;
}

/* Related product cards now use .product-card-modern class from style.css */

/* Customize related products section to match shop page exactly */
.related-products-section .product-title {
    font-size: 0.85rem;
    font-weight: 400;
    line-height: 1.4;
    height: auto;
    margin-top: 0;
}

.related-products-section .current-price {
    font-size: 0.95rem;
    color: #ef4444;
    font-weight: 600;
}

.related-products-section .original-price {
    font-size: 0.75rem;
}

@media (max-width: 768px) {
    .product-main-section {
        padding: 20px;
    }
    
    .product-title {
        font-size: 1.8rem;
    }
    
    .current-price {
        font-size: 1.5rem;
    }
    
    .product-gallery-container {
        position: static;
        width: 100%;
        margin-bottom: 30px;
    }
    
    .variant-options {
        gap: 8px;
    }
    
    .variant-box {
        min-width: 100px;
        padding: 10px 12px;
        gap: 8px;
    }
    
    .variant-image {
        width: 25px;
        height: 25px;
    }
    
    .variant-name {
        font-size: 0.85rem;
    }
    
    .quantity-status-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .feature-item {
        grid-template-columns: auto auto 1fr;
        gap: 8px;
    }
}

/* Reviews Section Styles */
.reviews-section {
    background: white;
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.reviews-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #f1f5f9;
}

.reviews-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.reviews-container {
    max-width: 900px;
    margin: 0 auto;
}

.review-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s;
}

.review-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-color: #cbd5e1;
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.reviewer-info {
    display: flex;
    gap: 12px;
    align-items: center;
}

.reviewer-avatar {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.2rem;
}

.reviewer-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 1rem;
}

.review-date {
    color: #94a3b8;
    font-size: 0.85rem;
    margin-top: 2px;
}

.review-rating {
    display: flex;
    gap: 3px;
}

.star-filled {
    color: #fbbf24;
}

.star-empty {
    color: #e2e8f0;
}

.review-text {
    color: #475569;
    line-height: 1.7;
    font-size: 0.95rem;
}

.no-reviews {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

.no-reviews h5 {
    color: #64748b;
    margin-bottom: 8px;
}

.add-review-button-container {
    text-align: center;
    margin-top: 30px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
}

.btn-add-review {
    background: #1e3a8a;
    color: white;
    border: none;
    padding: 15px 40px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-add-review:hover {
    background: #1e40af;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(30, 58, 138, 0.3);
}

.btn-back-to-product {
    background: white;
    color: #1e3a8a;
    border: 2px solid #1e3a8a;
    padding: 12px 35px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-back-to-product:hover {
    background: #1e3a8a;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
}

/* Review Modal Styles */
.review-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    animation: fadeIn 0.3s;
}

.review-modal-overlay.active {
    display: flex;
}

.review-modal {
    background: white;
    border-radius: 15px;
    width: 90%;
    max-width: 550px;
    max-height: 90vh;
    overflow-y: auto;
    animation: slideUp 0.3s;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.review-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 25px 30px;
    border-bottom: 2px solid #f1f5f9;
}

.review-modal-header h3 {
    margin: 0;
    color: #1e293b;
    font-weight: 700;
    font-size: 1.4rem;
}

.review-modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #94a3b8;
    cursor: pointer;
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s;
}

.review-modal-close:hover {
    background: #f1f5f9;
    color: #1e293b;
}

#reviewForm {
    padding: 30px;
}

.review-form-group {
    margin-bottom: 25px;
}

.review-form-label {
    display: block;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 10px;
    font-size: 0.95rem;
}

.review-form-input {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.3s;
}

.review-form-input:focus {
    outline: none;
    border-color: #1e3a8a;
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
}

.star-rating-select {
    position: relative;
}

.custom-select-rating {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    background: white;
}

.custom-select-rating:hover {
    border-color: #1e3a8a;
}

.selected-rating {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.95rem;
    color: #64748b;
}

.dropdown-arrow {
    color: #94a3b8;
    font-size: 0.85rem;
    transition: transform 0.3s;
}

.custom-select-rating.open .dropdown-arrow {
    transform: rotate(180deg);
}

.rating-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    margin-top: 5px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    display: none;
    z-index: 10;
    overflow: hidden;
}

.rating-dropdown.active {
    display: block;
    animation: slideDown 0.3s;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.rating-option {
    padding: 12px 15px;
    display: flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    transition: all 0.2s;
}

.rating-option:hover {
    background: #f8fafc;
}

.rating-option i {
    color: #fbbf24;
    font-size: 1rem;
}

.rating-option i.fas {
    color: #fbbf24;
}

.selected-rating i.fas {
    color: #fbbf24;
}

.rating-option span {
    margin-left: 10px;
    color: #64748b;
    font-size: 0.9rem;
}

.review-form-textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    font-family: inherit;
    resize: vertical;
    transition: all 0.3s;
}

.review-form-textarea:focus {
    outline: none;
    border-color: #1e3a8a;
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
}

.review-form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 30px;
}

.btn-cancel-review {
    background: white;
    color: #64748b;
    border: 2px solid #e2e8f0;
    padding: 12px 30px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-cancel-review:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}

.btn-submit-review {
    background: #1e3a8a;
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-submit-review:hover {
    background: #1e40af;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
}

/* Product Title Row with Action Buttons */
.product-title-row {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 0;
}

.product-title-row .product-title {
    margin-bottom: 0;
    flex: 1;
}

.product-action-buttons {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 8px;
}

/* Copy Details Button */
.copy-details-btn {
    background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
    border: none;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(139, 92, 246, 0.3);
    flex-shrink: 0;
}

.copy-details-btn:hover {
    transform: scale(1.15);
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.5);
}

.copy-details-btn i {
    color: white;
    font-size: 16px;
}

/* Download Images Button */
.download-images-btn {
    background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
    border: none;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
    flex-shrink: 0;
}

.download-images-btn:hover {
    transform: scale(1.15);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.5);
}

.download-images-btn i {
    color: white;
    font-size: 16px;
}

@media (max-width: 768px) {
    .copy-details-btn,
    .download-images-btn {
        width: 32px;
        height: 32px;
    }
    
    .copy-details-btn i,
    .download-images-btn i {
        font-size: 14px;
    }
    
    .product-title-row {
        gap: 10px;
    }
    
    .product-action-buttons {
        gap: 8px;
        margin-top: 5px;
    }
}
</style>

<div class="product-detail-page">
    <div class="container">
        <div class="product-main-section">
            <div class="row">
                <!-- Product Images Gallery -->
                <div class="col-lg-5 mb-4 mb-lg-0">
                    <div class="product-gallery-container">
                        <?php if (!empty($product_images)): ?>
                            <?php 
                            // Collect all images in desired order: Primary -> Variants -> Additional
                            $all_images = [];
                            $variant_image_paths = [];
                            
                            // 1. Add primary image first
                            $primary_image_added = false;
                            foreach ($product_images as $img) {
                                if ($img['is_primary']) {
                                    $all_images[] = [
                                        'image_path' => $img['image_path'], 
                                        'is_primary' => $img['is_primary'],
                                        'variant_type' => null,
                                        'variant_id' => null,
                                        'combination_id' => null
                                    ];
                                    $primary_image_added = true;
                                    break;
                                }
                            }
                            
                            // 2. Add all variant images (simple variants)
                            if (!empty($product_variants)) {
                                foreach ($product_variants as $variant) {
                                    if (!empty($variant['variant_image'])) {
                                        $variant_image_paths[] = $variant['variant_image'];
                                        $all_images[] = [
                                            'image_path' => $variant['variant_image'], 
                                            'is_primary' => 0,
                                            'variant_type' => 'simple',
                                            'variant_id' => $variant['id'],
                                            'combination_id' => null
                                        ];
                                    }
                                }
                            }
                            
                            // 3. Add all combination variant images
                            if (!empty($product_combinations)) {
                                foreach ($product_combinations as $combo) {
                                    if (!empty($combo['image_path']) && !in_array($combo['image_path'], $variant_image_paths)) {
                                        $variant_image_paths[] = $combo['image_path'];
                                        $all_images[] = [
                                            'image_path' => $combo['image_path'], 
                                            'is_primary' => 0,
                                            'variant_type' => 'combination',
                                            'variant_id' => null,
                                            'combination_id' => $combo['id'],
                                            'combination_string' => $combo['combination_string']
                                        ];
                                    }
                                }
                            }
                            
                            // 4. Add remaining product images (non-primary)
                            foreach ($product_images as $img) {
                                if (!$img['is_primary']) {
                                    $all_images[] = [
                                        'image_path' => $img['image_path'], 
                                        'is_primary' => $img['is_primary'],
                                        'variant_type' => null,
                                        'variant_id' => null,
                                        'combination_id' => null
                                    ];
                                }
                            }
                            ?>
                            
                            <!-- Image Slider -->
                            <div class="image-slider-wrapper" id="imageSliderWrapper">
                                <div class="image-slider" id="imageSlider">
                                    <?php foreach ($all_images as $index => $image): ?>
                                        <img src="<?php echo PRODUCT_IMAGES_DIR . $image['image_path']; ?>" 
                                             class="main-product-image" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?> - Image <?php echo $index + 1; ?>">
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <?php if (count($all_images) > 1): ?>
                                <div class="thumbnail-images">
                                    <?php foreach ($all_images as $index => $image): ?>
                                        <img src="<?php echo PRODUCT_IMAGES_DIR . $image['image_path']; ?>" 
                                             class="thumbnail-item <?php echo $index === 0 ? 'active' : ''; ?>"
                                             data-index="<?php echo $index; ?>"
                                             onclick="changeMainImage(this, '<?php echo PRODUCT_IMAGES_DIR . $image['image_path']; ?>')"
                                             alt="Thumbnail <?php echo $index + 1; ?>">
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Swipe Instructions -->
                                <div class="swipe-instruction">
                                    <i class="fas fa-hand-pointer me-2"></i>Swipe or tap thumbnails to view more
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <img src="assets/images/no-image.jpg" class="main-product-image" alt="No Image">
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Product Details -->
                <div class="col-lg-7">
                    <div class="product-category-badge"><?php echo htmlspecialchars($product['category_name']); ?></div>
                    
                    <div class="product-title-row">
                        <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>
                        <div class="product-action-buttons">
                            <?php if ($is_affiliate): ?>
                                <button class="copy-details-btn" onclick="copyProductDetails()" title="Copy Product Details">
                                    <i class="fas fa-copy"></i>
                                </button>
                                <button class="download-images-btn" onclick="downloadAllImages()" title="Download All Images">
                                    <i class="fas fa-download"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="product-rating">
                        <div class="rating-stars" onclick="scrollToReviews()" style="cursor: pointer;">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star"></i>
                            <?php endfor; ?>
                        </div>
                        <span class="rating-text" onclick="scrollToReviews()" style="cursor: pointer;">
                            (Reviews)
                        </span>
                    </div>
                    
                    <div class="product-price-section"
                         data-main-original-price="<?php echo $product['original_price']; ?>"
                         data-main-discounted-price="<?php echo $product['discounted_price']; ?>"
                         data-has-discount="<?php echo $product['discounted_price'] && $product['discounted_price'] < $product['original_price'] ? 'true' : 'false'; ?>">
                        <span class="current-price" id="displayPrice">
                            Rs <?php echo number_format($product['discounted_price'] ?: $product['original_price'], 2); ?>
                        </span>
                        <?php if ($product['discounted_price'] && $product['discounted_price'] < $product['original_price']): ?>
                            <span class="original-price" id="displayOriginalPrice">
                                Rs <?php echo number_format($product['original_price'], 2); ?>
                            </span>
                        <?php else: ?>
                            <span class="original-price" id="displayOriginalPrice" style="display: none;">
                                Rs <?php echo number_format($product['original_price'], 2); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (isset($product['description']) && $product['description']): ?>
                        <p class="product-description"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                    <?php endif; ?>
                    
                    <!-- Combination Variants (NEW) -->
                    <?php if ($uses_combinations && !empty($product_attributes)): ?>
                        <div class="combination-variants-section" id="combinationVariants">
                            <?php foreach ($product_attributes as $attr_name => $attr_data): ?>
                                <div class="variant-section" data-attribute-name="<?php echo htmlspecialchars($attr_name); ?>">
                                    <div class="variant-label"><?php echo htmlspecialchars($attr_name); ?>:</div>
                                    <div class="variant-error-message" id="error-<?php echo htmlspecialchars(strtolower($attr_name)); ?>" style="display: none; color: #ef4444; font-size: 12px; margin-top: 5px;">
                                        <i class="fas fa-exclamation-circle"></i> Please select <?php echo htmlspecialchars($attr_name); ?>
                                    </div>
                                    <div class="variant-options">
                                        <?php foreach ($attr_data['values'] as $value): ?>
                                            <div class="variant-box combination-attr-box"
                                                 data-attribute-name="<?php echo htmlspecialchars($attr_name); ?>"
                                                 data-value-id="<?php echo htmlspecialchars($value['id']); ?>"
                                                 data-value-name="<?php echo htmlspecialchars($value['name']); ?>"
                                                 onclick="selectCombinationAttribute(this)">
                                                <?php echo htmlspecialchars($value['name']); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- Hidden: Store combination data -->
                            <input type="hidden" id="selectedCombinationId" name="combination_id" value="">
                            <input type="hidden" id="selectedCombinationPrice" value="">
                            <input type="hidden" id="selectedCombinationStock" value="">
                            
                            <script>
                            // Store all combinations as JSON for JS access
                            window.productCombinations = <?php echo json_encode($product_combinations); ?>;
                            window.productAttributes = <?php echo json_encode($product_attributes); ?>;
                            console.log('💎 Combination product loaded:', window.productCombinations);
                            </script>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Simple Variants (OLD - Backward Compatible) -->
                    <?php if (!$uses_combinations && !empty($variants_by_type) && is_array($variants_by_type)): ?>
                        <?php foreach ($variants_by_type as $type => $variants): ?>
                            <?php if (!empty($variants) && is_array($variants)): ?>
                                <div class="variant-section" data-variant-type="<?php echo htmlspecialchars(strtolower($type)); ?>">
                                    <div class="variant-label"><?php echo htmlspecialchars(ucfirst($type)); ?>:</div>
                                    <div class="variant-error-message" id="error-<?php echo htmlspecialchars(strtolower($type)); ?>" style="display: none; color: #ef4444; font-size: 12px; margin-top: 5px;">
                                        <i class="fas fa-exclamation-circle"></i> Please select a <?php echo htmlspecialchars($type); ?>
                                    </div>
                                    <div class="variant-options">
                                        <?php foreach ($variants as $variant): ?>
                                            <?php if (is_array($variant)): ?>
                                                <div class="variant-box"
                                                     data-variant-id="<?php echo htmlspecialchars($variant['id'] ?? ''); ?>"
                                                     data-variant-price="<?php echo htmlspecialchars($variant['variant_price'] ?? ''); ?>"
                                                     data-variant-original-price="<?php echo htmlspecialchars($variant['variant_original_price'] ?? ''); ?>"
                                                     data-variant-stock="<?php echo htmlspecialchars($variant['stock_count'] ?? 1000); ?>"
                                                     data-variant-type="<?php echo htmlspecialchars(strtolower($variant['variant_type'] ?? '')); ?>"
                                                     data-variant-name="<?php echo htmlspecialchars($variant['variant_name'] ?? ''); ?>"
                                                     data-variant-image="<?php echo !empty($variant['variant_image']) ? htmlspecialchars(PRODUCT_IMAGES_DIR . $variant['variant_image']) : ''; ?>"
                                                     onclick="selectVariant(this)">

                                                    <?php if (!empty($variant['variant_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars(PRODUCT_IMAGES_DIR . $variant['variant_image']); ?>"
                                                             class="variant-image"
                                                             alt="<?php echo htmlspecialchars($variant['variant_name'] ?? 'Variant'); ?>">
                                                    <?php else: ?>
                                                        <div class="variant-image" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);"></div>
                                                    <?php endif; ?>

                                                    <span class="variant-name"><?php echo htmlspecialchars($variant['variant_name'] ?? 'Unnamed Variant'); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Debug: Show if no variants found -->
                        <?php if (isset($_GET['debug'])): ?>
                            <div class="alert alert-info">
                                <strong>Debug:</strong> No variants found for this product.
                                <br>Product ID: <?php echo htmlspecialchars($product_id); ?>
                                <br>Variants Array: <?php echo htmlspecialchars(print_r($variants_by_type, true)); ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- Quantity and Status Row -->
                    <div class="quantity-status-row">
                        <div class="quantity-section">
                            <div class="variant-label">Quantity:</div>
                            <div class="quantity-selector">
                                <button class="quantity-btn" onclick="decreaseQuantity()">−</button>
                                <input type="number" class="quantity-input" id="productQuantity" value="1" min="1" readonly>
                                <button class="quantity-btn" onclick="increaseQuantity()">+</button>
                            </div>
                        </div>
                        
                        <!-- Status Badge -->
                        <div class="status-wrapper">
                            <?php if ($product['status'] === 'In Stock'): ?>
                                <span class="status-badge status-in-stock">In Stock</span>
                            <?php elseif ($product['status'] === 'Limited'): ?>
                                <span class="status-badge status-limited">Limited Stock</span>
                            <?php else: ?>
                                <span class="status-badge status-out-stock">Out of Stock</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <?php if ($product['status'] === 'In Stock' || $product['status'] === 'Limited'): ?>
                            <button class="btn-add-cart" onclick="addToCartProduct()">
                                <i class="fas fa-shopping-cart"></i> Add to Cart
                            </button>
                            <button class="btn-buy-now" onclick="buyNowProduct()">
                                <i class="fas fa-bolt"></i> Buy Now
                            </button>
                        <?php else: ?>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <button class="btn-add-wishlist <?php echo $is_in_wishlist ? 'in-wishlist' : ''; ?>" 
                                        id="wishlistBtn" 
                                        onclick="toggleWishlistButton(<?php echo $product_id; ?>)"
                                        style="<?php echo $is_in_wishlist ? 'background: #ef4444; color: white; border-color: #ef4444;' : ''; ?>">
                                    <i class="<?php echo $is_in_wishlist ? 'fas' : 'far'; ?> fa-heart me-2"></i><?php echo $is_in_wishlist ? 'Remove from Wishlist' : 'Add to Wishlist'; ?>
                                </button>
                            <?php else: ?>
                                <button class="btn-add-wishlist" onclick="window.location.href='auth.php'">
                                    <i class="far fa-heart me-2"></i>Login to Add to Wishlist
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Features Section -->
                    <?php if (!empty($product_features)): ?>
                        <div class="features-section">
                            <h2 class="features-title">Features</h2>
                            
                            <?php foreach ($product_features as $feature): ?>
                                <div class="feature-item">
                                    <div class="feature-name"><?php echo htmlspecialchars($feature['feature_name']); ?></div>
                                    <div class="feature-arrow">→</div>
                                    <div class="feature-value"><?php echo htmlspecialchars($feature['feature_description']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Reviews Section (Initially Hidden) -->
        <div class="reviews-section" id="reviewsSection" style="display: none;">
            <div class="reviews-header">
                <h2 class="reviews-title">
                    <i class="fas fa-star" style="color: #fbbf24;"></i>
                    Customer Reviews (<?php echo $product['review_count']; ?>)
                </h2>
            </div>
            
            <div class="reviews-container">
                <?php if (!empty($reviews)): ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="reviewer-info">
                                    <div class="reviewer-avatar">
                                        <?php echo strtoupper(substr($review['user_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="reviewer-name"><?php echo htmlspecialchars($review['user_name']); ?></div>
                                        <div class="review-date"><?php echo date('F d, Y', strtotime($review['created_at'])); ?></div>
                                    </div>
                                </div>
                                <div class="review-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?php echo $i <= $review['rating'] ? 'star-filled' : 'star-empty'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="review-text">
                                <?php echo nl2br(htmlspecialchars($review['review_text'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-reviews">
                        <i class="fas fa-comments" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 15px;"></i>
                        <h5>No Reviews Yet</h5>
                        <p>Be the first to review this product!</p>
                    </div>
                <?php endif; ?>
                
                <div class="add-review-button-container">
                    <button class="btn-add-review" onclick="openReviewModal()">
                        <i class="fas fa-pen me-2"></i>Write a Review
                    </button>
                    <button class="btn-back-to-product" onclick="toggleReviewsSection()">
                        <i class="fas fa-arrow-left me-2"></i>Back to Product
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Product Content (Will be hidden when reviews shown) -->
        <div id="productContent">
        
        <!-- You May Also Like -->
        <?php if (!empty($related_products)): ?>
            <div class="related-products-section">
                <h2 class="section-title">You May Also Like</h2>
                <div class="row">  
                    <?php foreach ($related_products as $related): ?>
                        <div class="col-lg-4 col-md-6 col-6 mb-4">
                            <div class="product-card-modern" onclick="window.location.href='product.php?id=<?php echo $related['id']; ?>'" style="cursor: pointer;">
                                <div class="product-image">
                                    <img src="<?php echo $related['image_path'] ? PRODUCT_IMAGES_DIR . $related['image_path'] : 'assets/images/no-image.jpg'; ?>" 
                                         alt="<?php echo htmlspecialchars($related['name']); ?>">
                                    
                                    <!-- Favorite Button -->
                                    <?php if (isset($_SESSION['user_id'])): ?>
                                        <button class="favorite-btn" data-product-id="<?php echo $related['id']; ?>" onclick="event.stopPropagation(); toggleFavorite(<?php echo $related['id']; ?>)" title="Add to favorites">
                                            <i class="<?php echo in_array($related['id'], $favorite_product_ids ?? []) ? 'fas' : 'far'; ?> fa-star"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="product-info">
                                    <!-- Stock Status and Sold Stats inline -->
                                    <div class="product-meta-row">
                                        <span class="stock-text <?php echo $related['status'] === 'Out of Stock' ? 'out-of-stock' : ($related['status'] === 'Limited' ? 'limited' : 'in-stock'); ?>">
                                            <?php echo $related['status'] === 'Out of Stock' ? 'Out of Stock' : ($related['status'] === 'Limited' ? 'Limited Stock' : 'In Stock'); ?>
                                        </span>
                                        <span class="sold-stat"><?php echo $related['sales_count'] ?? 0; ?> Sold</span>
                                    </div>
                                    
                                    <!-- Product Title -->
                                    <h6 class="product-title"><?php echo htmlspecialchars($related['name']); ?></h6>
                                    
                                    <!-- Price -->
                                    <div class="product-price">
                                        <span class="current-price">Rs.<?php echo number_format($related['discounted_price'] ?: $related['original_price'], 0); ?></span>
                                        <?php if ($related['discounted_price'] && $related['discounted_price'] < $related['original_price']): ?>
                                            <span class="original-price">Rs.<?php echo number_format($related['original_price'], 0); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="product-actions">
                                        <?php 
                                        $isInCart = in_array($related['id'], $cart_product_ids);
                                        $cartBtnClass = $isInCart ? 'btn btn-in-cart' : 'btn btn-cart';
                                        $cartBtnText = $isInCart ? '<i class="fas fa-check-circle me-1"></i>In Cart' : '<i class="fas fa-shopping-cart me-1"></i>Cart';
                                        ?>
                                        <button class="<?php echo $cartBtnClass; ?>" onclick="event.stopPropagation(); <?php echo $isInCart ? 'window.location.href=\'cart.php\'' : 'addToCart(' . $related['id'] . ', null, 1, $(this))'; ?>">
                                            <?php echo $cartBtnText; ?>
                                        </button>
                                        <a href="product.php?id=<?php echo $related['id']; ?>" class="btn btn-buy" onclick="event.stopPropagation();">
                                            Buy
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        </div><!-- End Product Content -->
    </div>
</div>

<!-- Review Modal -->
<div class="review-modal-overlay" id="reviewModalOverlay" onclick="closeReviewModal()">
    <div class="review-modal" onclick="event.stopPropagation()">
        <div class="review-modal-header">
            <h3>Write a Review</h3>
            <button class="review-modal-close" onclick="closeReviewModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="reviewForm" onsubmit="submitReview(event)">
            <div class="review-form-group">
                <label class="review-form-label">Your Name</label>
                <input type="text" 
                       class="review-form-input" 
                       id="reviewerName" 
                       value="<?php echo isset($_SESSION['user_id']) ? htmlspecialchars($user['full_name'] ?? '') : ''; ?>" 
                       placeholder="Enter your name" 
                       required>
            </div>
            
            <div class="review-form-group">
                <label class="review-form-label">Your Rating</label>
                <div class="star-rating-select">
                    <div class="custom-select-rating" onclick="toggleRatingDropdown()">
                        <div class="selected-rating" id="selectedRating">
                            <i class="fas fa-star" style="color: #cbd5e1;"></i>
                            <span>Select Rating</span>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </div>
                    <div class="rating-dropdown" id="ratingDropdown">
                        <div class="rating-option" onclick="selectRating(5)">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <span>Excellent</span>
                        </div>
                        <div class="rating-option" onclick="selectRating(4)">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="far fa-star"></i>
                            <span>Good</span>
                        </div>
                        <div class="rating-option" onclick="selectRating(3)">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="far fa-star"></i>
                            <i class="far fa-star"></i>
                            <span>Average</span>
                        </div>
                        <div class="rating-option" onclick="selectRating(2)">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="far fa-star"></i>
                            <i class="far fa-star"></i>
                            <i class="far fa-star"></i>
                            <span>Poor</span>
                        </div>
                        <div class="rating-option" onclick="selectRating(1)">
                            <i class="fas fa-star"></i>
                            <i class="far fa-star"></i>
                            <i class="far fa-star"></i>
                            <i class="far fa-star"></i>
                            <i class="far fa-star"></i>
                            <span>Terrible</span>
                        </div>
                    </div>
                    <input type="hidden" id="ratingValue" name="rating" required>
                </div>
            </div>
            
            <div class="review-form-group">
                <label class="review-form-label">Your Review</label>
                <textarea class="review-form-textarea" 
                          id="reviewText" 
                          rows="5" 
                          placeholder="Share your experience with this product..." 
                          required></textarea>
            </div>
            
            <div class="review-form-actions">
                <button type="button" class="btn-cancel-review" onclick="closeReviewModal()">Cancel</button>
                <button type="submit" class="btn-submit-review">
                    <i class="fas fa-paper-plane me-2"></i>Submit Review
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let selectedVariants = {};
let basePrice = <?php echo $product['discounted_price'] ?: $product['original_price']; ?>;
let isAutoSelecting = false; // Flag to prevent recursive auto-selection

// Store image metadata for auto-selection
const imageMetadata = <?php 
    $image_meta = [];
    $img_index = 0;
    $variant_image_paths = [];
    
    // 1. Add primary image first
    foreach ($product_images as $img) {
        if ($img['is_primary']) {
            $image_meta[] = [
                'index' => $img_index++,
                'image_path' => $img['image_path'],
                'is_primary' => (int)$img['is_primary'],
                'type' => 'product'
            ];
            break;
        }
    }
    
    // 2. Add simple variant images
    if (!empty($product_variants)) {
        foreach ($product_variants as $variant) {
            if (!empty($variant['variant_image'])) {
                $variant_image_paths[] = $variant['variant_image'];
                $image_meta[] = [
                    'index' => $img_index++,
                    'image_path' => $variant['variant_image'],
                    'is_primary' => 0,
                    'type' => 'simple_variant',
                    'variant_id' => $variant['id'],
                    'variant_type' => $variant['variant_type'],
                    'variant_name' => $variant['variant_name']
                ];
            }
        }
    }
    
    // 3. Add combination variant images
    if (!empty($product_combinations)) {
        $added_combo_images = [];
        foreach ($product_combinations as $combo) {
            if (!empty($combo['image_path']) && !in_array($combo['image_path'], $variant_image_paths)) {
                $added_combo_images[] = $combo['image_path'];
                $variant_image_paths[] = $combo['image_path'];
                $image_meta[] = [
                    'index' => $img_index++,
                    'image_path' => $combo['image_path'],
                    'is_primary' => 0,
                    'type' => 'combination',
                    'combination_id' => $combo['id'],
                    'combination_string' => $combo['combination_string']
                ];
            }
        }
    }
    
    // 4. Add remaining product images (non-primary)
    foreach ($product_images as $img) {
        if (!$img['is_primary']) {
            $image_meta[] = [
                'index' => $img_index++,
                'image_path' => $img['image_path'],
                'is_primary' => (int)$img['is_primary'],
                'type' => 'product'
            ];
        }
    }
    
    echo json_encode($image_meta);
?>;

// Auto-select variant based on current image
function autoSelectVariantForImage(imageIndex) {
    console.log('Auto-selecting variant for image index:', imageIndex);
    
    if (!imageMetadata || !imageMetadata[imageIndex]) {
        console.log('No metadata for this image');
        return;
    }
    
    const imgMeta = imageMetadata[imageIndex];
    console.log('Image metadata:', imgMeta);
    
    // Set flag to prevent image update during auto-selection
    isAutoSelecting = true;
    
    // If it's the primary image, deselect all variants
    if (imgMeta.is_primary === 1 || imgMeta.type === 'product') {
        console.log('Primary image - deselecting all variants');
        deselectAllVariants();
        isAutoSelecting = false;
        return;
    }
    
    // If it's a simple variant
    if (imgMeta.type === 'simple_variant' && imgMeta.variant_id) {
        console.log('Simple variant detected:', imgMeta.variant_type, imgMeta.variant_name);
        
        // Find and click the variant box
        const variantBox = document.querySelector(
            `.variant-box[data-variant-id="${imgMeta.variant_id}"]`
        );
        
        if (variantBox) {
            // Deselect if already selected, otherwise select it
            if (!variantBox.classList.contains('active')) {
                variantBox.click();
                console.log('Auto-selected simple variant');
            }
        }
    }
    
    // If it's a combination variant
    if (imgMeta.type === 'combination' && imgMeta.combination_string) {
        console.log('Combination variant detected:', imgMeta.combination_string);
        
        // Parse the combination string
        const comboAttrs = parseCombinationString(imgMeta.combination_string);
        console.log('Parsed attributes:', comboAttrs);
        
        // Select each attribute
        for (let attrName in comboAttrs) {
            const attrValue = comboAttrs[attrName];
            
            // Find the combination attribute box
            const attrBox = document.querySelector(
                `.combination-attr-box[data-attribute-name="${attrName}"][data-value-name="${attrValue}"]`
            );
            
            if (attrBox && !attrBox.classList.contains('active')) {
                attrBox.click();
                console.log(`Auto-selected ${attrName}: ${attrValue}`);
            }
        }
    }
    
    // Reset flag after auto-selection is complete
    setTimeout(() => {
        isAutoSelecting = false;
    }, 100);
}

// Deselect all variants (for primary image)
function deselectAllVariants() {
    // Deselect simple variants
    document.querySelectorAll('.variant-box.active').forEach(box => {
        box.classList.remove('active');
    });
    selectedVariants = {};
    
    // Deselect combination variants
    document.querySelectorAll('.combination-attr-box.active').forEach(box => {
        box.classList.remove('active');
    });
    selectedCombinationAttrs = {};
    
    // Reset combination data
    const combinationId = document.getElementById('selectedCombinationId');
    const combinationPrice = document.getElementById('selectedCombinationPrice');
    const combinationStock = document.getElementById('selectedCombinationStock');
    
    if (combinationId) combinationId.value = '';
    if (combinationPrice) combinationPrice.value = '';
    if (combinationStock) combinationStock.value = '';
    
    // Reset to base price
    updatePrice(parseFloat(basePrice));
    
    // Reset original price
    const priceSection = document.querySelector('.product-price-section');
    const mainOriginalPrice = priceSection ? priceSection.dataset.mainOriginalPrice : null;
    const mainHasDiscount = priceSection ? priceSection.dataset.hasDiscount === 'true' : false;
    
    if (mainHasDiscount && mainOriginalPrice) {
        updateOriginalPrice(mainOriginalPrice);
    } else {
        updateOriginalPrice(null);
    }
    
    // Reset stock display to product default
    const productStatus = '<?php echo $product["status"]; ?>';
    if (productStatus === 'Out of Stock') {
        updateStockDisplay(0);
    } else {
        updateStockDisplay(<?php echo $product["stock_count"] ?? 1; ?>);
    }
    
    console.log('All variants deselected');
}

// Change main image when thumbnail is clicked (for compatibility)
function changeMainImage(thumbnail, imageUrl, skipAnimation = false) {
    // Get thumbnail index
    const thumbnails = document.querySelectorAll('.thumbnail-item');
    const index = Array.from(thumbnails).indexOf(thumbnail);
    
    if (index !== -1) {
        // Use the slider navigation if available
        const slider = document.getElementById('imageSlider');
        const sliderWrapper = document.getElementById('imageSliderWrapper');
        if (slider && sliderWrapper && thumbnails.length > 1) {
            const sliderWidth = sliderWrapper.offsetWidth;
            slider.classList.remove('dragging');
            slider.style.transform = `translateX(${-index * sliderWidth}px)`;
            
            // CRITICAL FIX: Force update the image source in the slider
            // This ensures the main image updates even after variant selection
            const mainImages = slider.querySelectorAll('.main-product-image');
            if (mainImages[index] && imageUrl) {
                // Add cache buster for primary image to force reload
                const isPrimary = thumbnail.classList.contains('primary') || index === 0;
                const finalUrl = isPrimary ? imageUrl + '?t=' + new Date().getTime() : imageUrl;
                
                // Update the specific slide image
                mainImages[index].src = finalUrl;
                
                // Also update any standalone main image (for non-slider layouts)
                const standaloneMainImg = document.getElementById('mainProductImage');
                if (standaloneMainImg) {
                    standaloneMainImg.src = finalUrl;
                }
            }
            
            // AUTO-SELECT: Select variant based on current image
            autoSelectVariantForImage(index);
        } else {
            // For non-slider layouts, directly update main image
            const mainImage = document.querySelector('.main-product-image');
            if (mainImage && imageUrl) {
                const isPrimary = thumbnail.classList.contains('primary') || index === 0;
                const finalUrl = isPrimary ? imageUrl + '?t=' + new Date().getTime() : imageUrl;
                mainImage.src = finalUrl;
            }
            
            // AUTO-SELECT: Select variant based on current image
            autoSelectVariantForImage(index);
        }
    }
    
    // Update active state
    thumbnails.forEach(item => {
        item.classList.remove('active');
    });
    if (thumbnail) {
        thumbnail.classList.add('active');
        
        // Update image counter
        const imageIndex = thumbnail.dataset.index;
        if (imageIndex !== undefined) {
            const counterElement = document.getElementById('currentImageIndex');
            if (counterElement) {
                counterElement.textContent = parseInt(imageIndex) + 1;
            }
        }
    }
}

// Touch/Swipe functionality for product images with smooth sliding
function initImageSwipe() {
    const sliderWrapper = document.getElementById('imageSliderWrapper');
    const slider = document.getElementById('imageSlider');
    const thumbnails = document.querySelectorAll('.thumbnail-item');
    
    if (!slider || thumbnails.length <= 1) return;
    
    let startX = 0;
    let startY = 0;
    let currentX = 0;
    let currentIndex = 0;
    let isDragging = false;
    let isScrolling = false;
    let sliderWidth = sliderWrapper.offsetWidth; // Full width of one image
    
    // Update slider position
    const updateSliderPosition = (index, smooth = true) => {
        if (smooth) {
            slider.classList.remove('dragging');
        } else {
            slider.classList.add('dragging');
        }
        const translateX = -index * sliderWidth;
        slider.style.transform = `translateX(${translateX}px)`;
        currentIndex = index;
        
        // Update counter
        const counterElement = document.getElementById('currentImageIndex');
        if (counterElement) {
            counterElement.textContent = index + 1;
        }
        
        // Update thumbnails
        thumbnails.forEach((thumb, i) => {
            if (i === index) {
                thumb.classList.add('active');
                thumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            } else {
                thumb.classList.remove('active');
            }
        });
        
        // AUTO-SELECT: Select variant when swiping to different image
        autoSelectVariantForImage(index);
    };
    
    // Get current index
    const updateCurrentIndex = () => {
        const activeThumbnail = document.querySelector('.thumbnail-item.active');
        currentIndex = Array.from(thumbnails).indexOf(activeThumbnail);
        if (currentIndex === -1) currentIndex = 0;
    };
    
    // Navigate to specific image
    const navigateToImage = (index) => {
        if (index < 0 || index >= thumbnails.length) return;
        updateSliderPosition(index, true);
    };
    
    // Touch start
    sliderWrapper.addEventListener('touchstart', (e) => {
        updateCurrentIndex();
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        currentX = startX;
        isDragging = true;
        isScrolling = false;
        slider.classList.add('dragging');
    }, { passive: true });
    
    // Touch move
    sliderWrapper.addEventListener('touchmove', (e) => {
        if (!isDragging) return;
        
        currentX = e.touches[0].clientX;
        const currentY = e.touches[0].clientY;
        const deltaX = currentX - startX;
        const deltaY = currentY - startY;
        
        // Determine if this is a horizontal or vertical scroll
        if (!isScrolling && Math.abs(deltaX) > 5) {
            isScrolling = Math.abs(deltaY) > Math.abs(deltaX);
        }
        
        // If it's vertical scrolling, don't interfere
        if (isScrolling) {
            isDragging = false;
            slider.classList.remove('dragging');
            return;
        }
        
        // Prevent page scrolling when swiping horizontally
        if (Math.abs(deltaX) > Math.abs(deltaY)) {
            e.preventDefault();
        }
        
        // Move slider with finger
        const baseTranslate = -currentIndex * sliderWidth;
        slider.style.transform = `translateX(${baseTranslate + deltaX}px)`;
    }, { passive: false });
    
    // Touch end
    sliderWrapper.addEventListener('touchend', (e) => {
        if (!isDragging) {
            return;
        }
        
        if (isScrolling) {
            isDragging = false;
            isScrolling = false;
            slider.classList.remove('dragging');
            return;
        }
        
        const deltaX = currentX - startX;
        const threshold = sliderWidth * 0.25; // 25% of image width
        
        slider.classList.remove('dragging');
        
        if (Math.abs(deltaX) > threshold) {
            if (deltaX > 0 && currentIndex > 0) {
                // Swipe right - previous image
                navigateToImage(currentIndex - 1);
            } else if (deltaX < 0 && currentIndex < thumbnails.length - 1) {
                // Swipe left - next image
                navigateToImage(currentIndex + 1);
            } else {
                // At boundary, bounce back
                updateSliderPosition(currentIndex, true);
            }
        } else {
            // Didn't swipe far enough, bounce back
            updateSliderPosition(currentIndex, true);
        }
        
        isDragging = false;
        isScrolling = false;
        currentX = 0;
        startX = 0;
    }, { passive: true });
    
    // Mouse support for desktop
    let mouseDown = false;
    
    sliderWrapper.addEventListener('mousedown', (e) => {
        updateCurrentIndex();
        startX = e.clientX;
        mouseDown = true;
        isDragging = true;
        slider.classList.add('dragging');
        e.preventDefault();
    });
    
    sliderWrapper.addEventListener('mousemove', (e) => {
        if (!mouseDown) return;
        
        currentX = e.clientX;
        const deltaX = currentX - startX;
        
        // Move slider with mouse
        const baseTranslate = -currentIndex * sliderWidth;
        slider.style.transform = `translateX(${baseTranslate + deltaX}px)`;
    });
    
    sliderWrapper.addEventListener('mouseup', (e) => {
        if (!mouseDown) return;
        
        const deltaX = currentX - startX;
        const threshold = sliderWidth * 0.3;
        
        slider.classList.remove('dragging');
        
        if (Math.abs(deltaX) > threshold) {
            if (deltaX > 0 && currentIndex > 0) {
                navigateToImage(currentIndex - 1);
            } else if (deltaX < 0 && currentIndex < thumbnails.length - 1) {
                navigateToImage(currentIndex + 1);
            } else {
                updateSliderPosition(currentIndex, true);
            }
        } else {
            updateSliderPosition(currentIndex, true);
        }
        
        mouseDown = false;
        isDragging = false;
        currentX = 0;
        startX = 0;
    });
    
    sliderWrapper.addEventListener('mouseleave', () => {
        if (mouseDown) {
            slider.classList.remove('dragging');
            updateSliderPosition(currentIndex, true);
            mouseDown = false;
            isDragging = false;
        }
    });
    
    // Thumbnail click support - Enhanced to force image reload
    thumbnails.forEach((thumb, index) => {
        thumb.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            
            // Navigate to the image
            navigateToImage(index);
            
            // CRITICAL FIX: Force update the actual image source
            // This ensures the main image updates even after variant selection
            const mainImages = slider.querySelectorAll('.main-product-image');
            if (mainImages[index]) {
                const imageUrl = thumb.src.replace(/-thumb\.(jpg|jpeg|png|gif|webp)$/i, '.$1');
                const isPrimary = thumb.classList.contains('primary') || index === 0;
                
                // Add cache buster for primary image or use current timestamp
                const finalUrl = isPrimary ? imageUrl + '?t=' + new Date().getTime() : imageUrl;
                
                // Force reload the image
                mainImages[index].src = finalUrl;
                
                console.log(`🖼️ Thumbnail ${index} clicked - Updated main image to:`, finalUrl);
            }
        });
    });
    
    // Window resize handler
    window.addEventListener('resize', () => {
        sliderWidth = sliderWrapper.offsetWidth;
        updateSliderPosition(currentIndex, false);
    });
    
    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft' && currentIndex > 0) {
            updateCurrentIndex();
            navigateToImage(currentIndex - 1);
        } else if (e.key === 'ArrowRight' && currentIndex < thumbnails.length - 1) {
            updateCurrentIndex();
            navigateToImage(currentIndex + 1);
        }
    });
}

// Select variant (new unified function)
function selectVariant(element) {
    const variantSection = element.closest('.variant-section');
    const variantType = element.dataset.variantType || 'variant';
    const isCurrentlyActive = element.classList.contains('active');
    
    // If clicking on already selected variant, deselect it
    if (isCurrentlyActive) {
        element.classList.remove('active');
        
        // Remove from selected variants
        delete selectedVariants[variantType];
        
        // Reset to base price
        updatePrice(parseFloat(basePrice));
        
        // Reset original price to main product price
        const priceSection = document.querySelector('.product-price-section');
        const mainOriginalPrice = priceSection ? priceSection.dataset.mainOriginalPrice : null;
        const mainHasDiscount = priceSection ? priceSection.dataset.hasDiscount === 'true' : false;
        
        if (mainHasDiscount && mainOriginalPrice) {
            updateOriginalPrice(mainOriginalPrice);
        } else {
            updateOriginalPrice(null);
        }
        
        // Reset stock display to product default
        const productStatus = '<?php echo $product["status"]; ?>';
        if (productStatus === 'Out of Stock') {
            updateStockDisplay(0);
        } else {
            updateStockDisplay(<?php echo $product["stock_count"] ?? 1; ?>);
        }
        
        return;
    }
    
    // Remove active class from all variant boxes in the same section
    variantSection.querySelectorAll('.variant-box').forEach(item => {
        item.classList.remove('active');
    });
    
    // Add active class to selected
    element.classList.add('active');
    
    // Store selected variant
    selectedVariants[variantType] = element.dataset.variantId;
    
    // Hide error message when variant is selected
    const errorElement = variantSection.querySelector('.variant-error-message');
    if (errorElement) {
        errorElement.style.display = 'none';
    }
    
    // Remove error styling
    variantSection.style.borderLeft = 'none';
    variantSection.style.paddingLeft = '0';
    variantSection.style.marginLeft = '0';
    
    // Change main image if variant has an image (skip during auto-selection)
    const variantImage = element.dataset.variantImage;
    if (variantImage && variantImage !== '' && !isAutoSelecting) {
        // Find the matching thumbnail and trigger proper image update
        const thumbnails = document.querySelectorAll('.thumbnail-item');
        let matchingThumb = null;
        
        thumbnails.forEach(thumb => {
            if (thumb.src === variantImage || thumb.src.includes(variantImage)) {
                matchingThumb = thumb;
            }
        });
        
        if (matchingThumb) {
            // Use the existing changeMainImage function for consistency
            changeMainImage(matchingThumb, variantImage, false);
        } else {
            // Fallback: directly update if no matching thumbnail found
            const mainImages = document.querySelectorAll('.main-product-image');
            mainImages.forEach(img => {
                img.src = variantImage;
            });
            
            // Also update standalone main image if it exists
            const standaloneImg = document.getElementById('mainProductImage');
            if (standaloneImg) {
                standaloneImg.src = variantImage;
            }
            
            // Update thumbnail active state
            thumbnails.forEach(thumb => {
                thumb.classList.remove('active');
            });
        }
    }
    
    // Update price if variant has different price
    if (element.dataset.variantPrice && element.dataset.variantPrice !== '') {
        updatePrice(parseFloat(element.dataset.variantPrice));
    } else {
        // Reset to base price if no variant price
        updatePrice(parseFloat(basePrice));
    }
    
    // Update original price display
    updateOriginalPrice(element.dataset.variantOriginalPrice);
    
    // Update stock display based on variant stock count
    const variantStock = parseInt(element.dataset.variantStock) || 1000;
    console.log('📦 Updating stock display for variant:', element.dataset.variantName, 'Stock:', variantStock);
    updateStockDisplay(variantStock);
    
    // Check if this variant is in cart
    setTimeout(() => checkVariantInCart(), 100);
}

// NEW: Select combination attribute
let selectedCombinationAttrs = {};

function selectCombinationAttribute(element) {
    const attrName = element.dataset.attributeName;
    const valueId = element.dataset.valueId;
    const valueName = element.dataset.valueName;
    const isCurrentlyActive = element.classList.contains('active');
    const variantSection = element.closest('.variant-section');
    
    // If clicking on already selected variant, deselect it
    if (isCurrentlyActive) {
        element.classList.remove('active');
        
        // Remove from selected attributes
        delete selectedCombinationAttrs[attrName];
        
        // Reset combination data
        document.getElementById('selectedCombinationId').value = '';
        document.getElementById('selectedCombinationPrice').value = '';
        document.getElementById('selectedCombinationStock').value = '';
        
        // Reset to base price
        updatePrice(parseFloat(basePrice));
        
        // Reset original price to main product price
        const priceSection = document.querySelector('.product-price-section');
        const mainOriginalPrice = priceSection ? priceSection.dataset.mainOriginalPrice : null;
        const mainHasDiscount = priceSection ? priceSection.dataset.hasDiscount === 'true' : false;
        
        if (mainHasDiscount && mainOriginalPrice) {
            updateOriginalPrice(mainOriginalPrice);
        } else {
            updateOriginalPrice(null);
        }
        
        console.log('Deselected attribute:', attrName);
        console.log('Remaining selected attributes:', selectedCombinationAttrs);
        
        return;
    }
    
    // Remove active from siblings
    variantSection.querySelectorAll('.combination-attr-box').forEach(box => {
        box.classList.remove('active');
    });
    
    // Add active to selected
    element.classList.add('active');
    
    // Store selection
    selectedCombinationAttrs[attrName] = {
        valueId: valueId,
        valueName: valueName
    };
    
    // Hide error message
    const errorElement = variantSection.querySelector('.variant-error-message');
    if (errorElement) {
        errorElement.style.display = 'none';
    }
    
    console.log('Selected attributes:', selectedCombinationAttrs);
    
    // Check if all attributes are selected
    const requiredAttributes = Object.keys(window.productAttributes || {});
    const selectedAttributes = Object.keys(selectedCombinationAttrs);
    
    if (requiredAttributes.length === selectedAttributes.length) {
        // Find matching combination
        findMatchingCombination();
    }
}

function findMatchingCombination() {
    if (!window.productCombinations) {
        console.error('No combinations data available');
        return;
    }
    
    // Create a string representation of selected attributes
    const selectedStr = Object.keys(selectedCombinationAttrs)
        .sort()
        .map(attr => `${attr}:${selectedCombinationAttrs[attr].valueName}`)
        .join('|');
    
    console.log('Looking for combination:', selectedStr);
    
    // Find matching combination
    let matchedCombo = null;
    for (let combo of window.productCombinations) {
        // Parse combination_string format: "Color:Red|Size:Large|Design:Plain"
        const comboAttrs = parseCombinationString(combo.combination_string);
        
        // Check if all selected attributes match
        let matches = true;
        for (let attr in selectedCombinationAttrs) {
            if (comboAttrs[attr] !== selectedCombinationAttrs[attr].valueName) {
                matches = false;
                break;
            }
        }
        
        if (matches) {
            matchedCombo = combo;
            break;
        }
    }
    
    if (matchedCombo) {
        console.log('✅ Found matching combination:', matchedCombo);
        
        // Update hidden fields
        document.getElementById('selectedCombinationId').value = matchedCombo.id;
        document.getElementById('selectedCombinationPrice').value = matchedCombo.price;
        document.getElementById('selectedCombinationStock').value = matchedCombo.stock_quantity;
        
        // Update displayed price
        updatePrice(parseFloat(matchedCombo.price));
        
        if (matchedCombo.original_price && matchedCombo.original_price > matchedCombo.price) {
            updateOriginalPrice(matchedCombo.original_price);
        } else {
            // Hide original price if not on sale
            const originalPriceEl = document.getElementById('displayOriginalPrice');
            if (originalPriceEl) originalPriceEl.style.display = 'none';
        }
        
        // Update product image if combination has an image (skip during auto-selection)
        if (matchedCombo.image_path && !isAutoSelecting) {
            const imagePath = '<?php echo PRODUCT_IMAGES_DIR; ?>' + matchedCombo.image_path;
            
            // Find the matching thumbnail and trigger proper image update
            const thumbnails = document.querySelectorAll('.thumbnail-item');
            let matchingThumbIndex = -1;
            
            thumbnails.forEach((thumb, idx) => {
                if (thumb.src.includes(matchedCombo.image_path)) {
                    matchingThumbIndex = idx;
                }
            });
            
            if (matchingThumbIndex !== -1) {
                // Use the existing changeMainImage function for consistency
                changeMainImage(thumbnails[matchingThumbIndex], imagePath, false);
            } else {
                // Fallback: directly update if no matching thumbnail found
                const mainImages = document.querySelectorAll('.main-product-image');
                mainImages.forEach(img => {
                    img.src = imagePath;
                });
                
                // Update active thumbnail to none since this is a variant-specific image
                thumbnails.forEach(thumb => {
                    thumb.classList.remove('active');
                });
            }
        }
        
        // Update stock display and buttons based on stock quantity
        updateStockDisplay(matchedCombo.stock_quantity);
        
        // Check if this variant is in cart
        checkVariantInCart();
        
    } else {
        console.warn('⚠️ No matching combination found for:', selectedStr);
        // Reset combination
        document.getElementById('selectedCombinationId').value = '';
        document.getElementById('selectedCombinationPrice').value = '';
        document.getElementById('selectedCombinationStock').value = '';
        
        // Reset button to default
        const addToCartBtn = document.querySelector('.btn-add-cart');
        if (addToCartBtn) {
            addToCartBtn.innerHTML = '<i class="fas fa-shopping-cart"></i> Add to Cart';
            addToCartBtn.style.background = '#7ed957';
            addToCartBtn.style.color = '#1e3a8a';
            addToCartBtn.classList.remove('in-cart');
        }
        
        // Reset stock display to product default
        const productStatus = '<?php echo $product["status"]; ?>';
        if (productStatus === 'Out of Stock') {
            updateStockDisplay(0);
        } else {
            updateStockDisplay(<?php echo $product["stock_count"] ?? 1; ?>);
        }
    }
}

function parseCombinationString(combinationStr) {
    const result = {};
    if (!combinationStr) return result;
    
    const pairs = combinationStr.split('|');
    for (let pair of pairs) {
        const [attr, value] = pair.split(':');
        if (attr && value) {
            result[attr.trim()] = value.trim();
        }
    }
    return result;
}

// Select color variant (legacy function for backward compatibility)
function selectColorVariant(element) {
    // Remove active class from all color variants
    document.querySelectorAll('.color-variant-circle').forEach(item => {
        item.classList.remove('active');
    });
    
    // Add active class to selected
    element.classList.add('active');
    
    // Store selected variant
    selectedVariants['color'] = element.dataset.variantId;
    
    // Update price if variant has different price
    if (element.dataset.variantPrice && element.dataset.variantPrice !== '') {
        updatePrice(parseFloat(element.dataset.variantPrice));
    } else {
        // Reset to base price if no variant price
        updatePrice(parseFloat(basePrice));
    }
    
    // Update original price display
    updateOriginalPrice(element.dataset.variantOriginalPrice);
}

// Select size variant
function selectSizeVariant(button) {
    // Remove active class from all size variants
    document.querySelectorAll('.size-variant-btn').forEach(item => {
        item.classList.remove('active');
    });
    
    // Add active class to selected
    button.classList.add('active');
    
    // Store selected variant
    selectedVariants['size'] = button.dataset.variantId;
    
    // Update price if variant has different price
    if (button.dataset.variantPrice && button.dataset.variantPrice !== '') {
        updatePrice(parseFloat(button.dataset.variantPrice));
    } else {
        // Reset to base price if no variant price
        updatePrice(parseFloat(basePrice));
    }
    
    // Update original price display
    updateOriginalPrice(button.dataset.variantOriginalPrice);
}

// Update price display
function updatePrice(price) {
    const displayPriceElement = document.getElementById('displayPrice');
    if (displayPriceElement) {
        displayPriceElement.textContent = 'Rs ' + price.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }
}

// Update original price display
function updateOriginalPrice(originalPrice) {
    const displayOriginalPriceElement = document.getElementById('displayOriginalPrice');
    const displayPriceElement = document.getElementById('displayPrice');
    const priceSection = document.querySelector('.product-price-section');

    // Debug logging
    if (new URLSearchParams(window.location.search).has('debug')) {
        console.log('updateOriginalPrice called with:', originalPrice);
        console.log('displayOriginalPriceElement:', displayOriginalPriceElement);
        console.log('displayPriceElement:', displayPriceElement);
        console.log('priceSection:', priceSection);
        if (priceSection) {
            console.log('priceSection data attributes:', {
                mainOriginalPrice: priceSection.dataset.mainOriginalPrice,
                mainDiscountedPrice: priceSection.dataset.mainDiscountedPrice,
                hasDiscount: priceSection.dataset.hasDiscount
            });
        }
    }

    if (displayOriginalPriceElement) {
        if (originalPrice && parseFloat(originalPrice) > 0) {
            // Show variant original price with strikethrough
            displayOriginalPriceElement.textContent = 'Rs ' + parseFloat(originalPrice).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
            displayOriginalPriceElement.style.display = 'inline';
            displayOriginalPriceElement.style.textDecoration = 'line-through';
            displayOriginalPriceElement.style.color = '#94a3b8';

            // Make current price stand out more when there's an original price
            if (displayPriceElement) {
                displayPriceElement.style.fontSize = '2rem';
                displayPriceElement.style.fontWeight = '700';
                displayPriceElement.style.color = '#1e3a8a';
            }

            if (new URLSearchParams(window.location.search).has('debug')) {
                console.log('Showing variant original price:', displayOriginalPriceElement.textContent);
            }
        } else {
            // Check if main product has discount
            const mainHasDiscount = priceSection && priceSection.dataset.hasDiscount === 'true';
            const mainOriginalPrice = priceSection ? parseFloat(priceSection.dataset.mainOriginalPrice) : 0;

            if (mainHasDiscount && mainOriginalPrice > 0) {
                // Show main product original price
                displayOriginalPriceElement.textContent = 'Rs ' + mainOriginalPrice.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                displayOriginalPriceElement.style.display = 'inline';
                displayOriginalPriceElement.style.textDecoration = 'line-through';
                displayOriginalPriceElement.style.color = '#94a3b8';

                // Reset current price styling
                if (displayPriceElement) {
                    displayPriceElement.style.fontSize = '2rem';
                    displayPriceElement.style.fontWeight = '700';
                    displayPriceElement.style.color = '#1e3a8a';
                }

                if (new URLSearchParams(window.location.search).has('debug')) {
                    console.log('Showing main product original price:', displayOriginalPriceElement.textContent);
                }
            } else {
                // Hide original price if no discount
                displayOriginalPriceElement.style.display = 'none';

                // Reset current price styling
                if (displayPriceElement) {
                    displayPriceElement.style.fontSize = '1.8rem';
                    displayPriceElement.style.fontWeight = '700';
                    displayPriceElement.style.color = '#1e3a8a';
                }

                if (new URLSearchParams(window.location.search).has('debug')) {
                    console.log('Hiding original price - no discount available');
                }
            }
        }
    }
}

// Quantity functions
function decreaseQuantity() {
    const input = document.getElementById('productQuantity');
    const currentValue = parseInt(input.value);
    if (currentValue > 1) {
        input.value = currentValue - 1;
    }
}

function increaseQuantity() {
    const input = document.getElementById('productQuantity');
    const currentValue = parseInt(input.value);
    input.value = currentValue + 1;
}

// Add to cart
// Validate that all variant types have a selection
function validateVariantSelection() {
    // Get all variant sections
    const variantSections = document.querySelectorAll('.variant-section');
    
    if (variantSections.length === 0) {
        // No variants required for this product
        return true;
    }
    
    let allValid = true;
    const missingTypes = [];
    
    // Check each variant type
    variantSections.forEach(section => {
        const variantType = section.getAttribute('data-variant-type');
        const errorElement = section.querySelector('.variant-error-message');
        const hasSelection = section.querySelector('.variant-box.active');
        
        if (!hasSelection) {
            allValid = false;
            const typeName = section.querySelector('.variant-label').textContent.replace(':', '');
            missingTypes.push(typeName);
            
            // Show error message below variant type
            if (errorElement) {
                errorElement.style.display = 'block';
            }
            
            // Add error styling to variant section
            section.style.borderLeft = '3px solid #ef4444';
            section.style.paddingLeft = '10px';
            section.style.marginLeft = '-10px';
            
            // Scroll to first missing variant
            if (missingTypes.length === 1) {
                section.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else {
            // Hide error message if selection exists
            if (errorElement) {
                errorElement.style.display = 'none';
            }
            section.style.borderLeft = 'none';
            section.style.paddingLeft = '0';
            section.style.marginLeft = '0';
        }
    });
    
    if (!allValid) {
        showNotification(`Please select: ${missingTypes.join(', ')}`, 'error');
        return false;
    }
    
    return true;
}

function addToCartProduct() {
    // Validate all variant selections
    if (!validateVariantSelection()) {
        return;
    }
    
    const quantity = parseInt(document.getElementById('productQuantity').value) || 1;
    
    // Prepare data based on variant type
    let postData = {
        product_id: <?php echo $product_id; ?>,
        quantity: quantity
    };
    
    // Check if it's combination variants
    const combinationId = document.getElementById('selectedCombinationId');
    if (combinationId && combinationId.value) {
        // NEW: Combination variant
        postData.variant_combination_id = combinationId.value;
        console.log('Adding combination to cart:', combinationId.value);
    } else {
        // OLD: Simple variants (backward compatible)
        postData.variant_selections = JSON.stringify(selectedVariants);
    }
    
    // Send to cart
    $.post('ajax/add_to_cart.php', postData, function(response) {
        if (response.success) {
            updateCartCount();
            
            // Show appropriate notification
            showNotification('Added to Cart', 'success');
            
            // Change button to "Added to Cart" state
            const addToCartBtn = document.querySelector('.btn-add-cart');
            if (addToCartBtn) {
                addToCartBtn.innerHTML = '<i class="fas fa-check-circle"></i> Added to Cart';
                addToCartBtn.style.background = '#059669';
                addToCartBtn.style.color = 'white';
                addToCartBtn.classList.add('in-cart');
            }
        } else {
            showNotification(response.message || 'Error adding to cart', 'error');
        }
    }, 'json').fail(function() {
        showNotification('Error adding to cart', 'error');
    });
}

// Buy now - Direct checkout without adding to cart
function buyNowProduct() {
    // Validate all variant selections
    if (!validateVariantSelection()) {
        return;
    }
    
    const quantity = parseInt(document.getElementById('productQuantity').value) || 1;
    
    // Prepare data based on variant type
    let postData = {
        product_id: <?php echo $product_id; ?>,
        quantity: quantity
    };
    
    // Check if it's combination variants
    const combinationId = document.getElementById('selectedCombinationId');
    if (combinationId && combinationId.value) {
        // NEW: Combination variant
        postData.variant_combination_id = combinationId.value;
    } else {
        // OLD: Simple variants (backward compatible)
        postData.variant_selections = JSON.stringify(selectedVariants);
    }
    
    // Store buy-now item in session and redirect to checkout
    $.post('ajax/buy_now.php', postData, function(response) {
        if (response.success) {
            window.location.href = 'checkout.php?buy_now=1';
        } else {
            showNotification(response.message || 'Error processing buy now', 'error');
        }
    }, 'json').fail(function() {
        showNotification('Error processing request', 'error');
    });
}

// Check if current product/variant combination is in cart
function checkVariantInCart() {
    const currentProductId = <?php echo $product_id; ?>;
    const combinationId = document.getElementById('selectedCombinationId');
    
    // Prepare variant data
    let variantData = {
        product_id: currentProductId
    };
    
    // Check if it's combination variants
    if (combinationId && combinationId.value) {
        variantData.combination_id = combinationId.value;
    } else {
        // Simple variants
        variantData.variant_selections = JSON.stringify(selectedVariants);
    }
    
    $.ajax({
        url: 'ajax/check_cart_item.php',
        method: 'POST',
        data: variantData,
        dataType: 'json',
        success: function(response) {
            const addToCartBtn = document.querySelector('.btn-add-cart');
            if (addToCartBtn) {
                if (response.in_cart) {
                    addToCartBtn.innerHTML = '<i class="fas fa-check-circle"></i> Added to Cart';
                    addToCartBtn.style.background = '#059669';
                    addToCartBtn.style.color = 'white';
                    addToCartBtn.classList.add('in-cart');
                } else {
                    addToCartBtn.innerHTML = '<i class="fas fa-shopping-cart"></i> Add to Cart';
                    addToCartBtn.style.background = '#7ed957';
                    addToCartBtn.style.color = '#1e3a8a';
                    addToCartBtn.classList.remove('in-cart');
                }
            }
        },
        error: function() {
            console.log('Could not check cart status');
        }
    });
}

// Show notification function
function showNotification(message, type) {
    // Remove any existing notifications
    const existingNotif = document.querySelector('.variant-notification');
    if (existingNotif) {
        existingNotif.remove();
    }
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = 'variant-notification';
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'error' ? '#fee' : '#efe'};
        color: ${type === 'error' ? '#c33' : '#3c3'};
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 14px;
        animation: slideIn 0.3s ease;
    `;
    
    notification.innerHTML = `
        <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}" style="font-size: 20px;"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Add animation styles
if (!document.getElementById('notification-styles')) {
    const style = document.createElement('style');
    style.id = 'notification-styles';
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        @keyframes slideOut {
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

// Update stock display and buttons based on stock quantity
function updateStockDisplay(stockQuantity) {
    console.log('🔄 updateStockDisplay called with stock:', stockQuantity);
    const actionButtons = document.querySelector('.action-buttons');
    const statusBadge = document.querySelector('.status-badge');
    
    console.log('Action buttons found:', actionButtons ? 'YES' : 'NO');
    console.log('Status badge found:', statusBadge ? 'YES' : 'NO');
    
    if (!actionButtons) {
        console.log('❌ No action buttons found');
        return;
    }
    
    // Check if stock is zero or less
    if (stockQuantity <= 0) {
        console.log('📛 Stock is 0 - showing wishlist button');
        // Hide cart and buy buttons
        const cartBtn = actionButtons.querySelector('.btn-add-cart');
        const buyBtn = actionButtons.querySelector('.btn-buy-now');
        
        if (cartBtn) cartBtn.style.display = 'none';
        if (buyBtn) buyBtn.style.display = 'none';
        
        // Show or create wishlist button
        let wishlistBtn = actionButtons.querySelector('.btn-add-wishlist');
        if (!wishlistBtn) {
            wishlistBtn = document.createElement('button');
            wishlistBtn.className = 'btn-add-wishlist <?php echo $is_in_wishlist ? "in-wishlist" : ""; ?>';
            wishlistBtn.id = 'wishlistBtn';
            wishlistBtn.onclick = function() { toggleWishlistButton(<?php echo $product_id; ?>); };
            wishlistBtn.style.cssText = '<?php echo $is_in_wishlist ? "background: #ef4444; color: white; border-color: #ef4444;" : ""; ?>';
            wishlistBtn.innerHTML = '<i class="<?php echo $is_in_wishlist ? "fas" : "far"; ?> fa-heart me-2"></i><?php echo $is_in_wishlist ? "Remove from Wishlist" : "Add to Wishlist"; ?>';
            actionButtons.appendChild(wishlistBtn);
        }
        wishlistBtn.style.display = 'block';
        
        // Update status badge
        if (statusBadge) {
            console.log('🔴 Updating badge to: Out of Stock');
            statusBadge.textContent = 'Out of Stock';
            statusBadge.className = 'status-badge status-out-stock';
        } else {
            console.log('⚠️ Status badge element not found!');
        }
    } else {
        console.log('✅ Stock available - showing cart/buy buttons');
        // Show cart and buy buttons
        const cartBtn = actionButtons.querySelector('.btn-add-cart');
        const buyBtn = actionButtons.querySelector('.btn-buy-now');
        const wishlistBtn = actionButtons.querySelector('.btn-add-wishlist');
        
        if (cartBtn) cartBtn.style.display = 'inline-block';
        if (buyBtn) buyBtn.style.display = 'inline-block';
        if (wishlistBtn) wishlistBtn.style.display = 'none';
        
        // Update status badge
        if (statusBadge) {
            if (stockQuantity <= 5) {
                console.log('🟡 Updating badge to: Limited Stock');
                statusBadge.textContent = 'Limited Stock';
                statusBadge.className = 'status-badge status-limited';
            } else {
                console.log('🟢 Updating badge to: In Stock');
                statusBadge.textContent = 'In Stock';
                statusBadge.className = 'status-badge status-in-stock';
            }
        } else {
            console.log('⚠️ Status badge element not found!');
        }
    }
}

// Scroll to reviews section
function scrollToReviews() {
    const reviewsSection = document.getElementById('reviewsSection');
    
    // Show reviews section if hidden
    reviewsSection.style.display = 'block';
    
    // Scroll to the reviews section smoothly
    reviewsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Toggle Reviews Section
function toggleReviewsSection() {
    const reviewsSection = document.getElementById('reviewsSection');
    const productContent = document.getElementById('productContent');
    
    if (reviewsSection.style.display === 'none') {
        reviewsSection.style.display = 'block';
        productContent.style.display = 'none';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        reviewsSection.style.display = 'none';
        productContent.style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// Open Review Modal
function openReviewModal() {
    document.getElementById('reviewModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close Review Modal
function closeReviewModal() {
    document.getElementById('reviewModalOverlay').classList.remove('active');
    document.body.style.overflow = 'auto';
    document.getElementById('reviewForm').reset();
    document.getElementById('ratingValue').value = '';
    document.getElementById('selectedRating').innerHTML = '<i class="fas fa-star" style="color: #cbd5e1;"></i><span>Select Rating</span>';
}

// Toggle Rating Dropdown
function toggleRatingDropdown() {
    const dropdown = document.getElementById('ratingDropdown');
    const selectBox = document.querySelector('.custom-select-rating');
    
    dropdown.classList.toggle('active');
    selectBox.classList.toggle('open');
}

// Select Rating
function selectRating(rating) {
    const labels = ['Terrible', 'Poor', 'Average', 'Good', 'Excellent'];
    const selectedRating = document.getElementById('selectedRating');
    const ratingValue = document.getElementById('ratingValue');
    
    let starsHTML = '';
    for (let i = 1; i <= 5; i++) {
        if (i <= rating) {
            starsHTML += '<i class="fas fa-star"></i>';
        } else {
            starsHTML += '<i class="fas fa-star" style="color: #e2e8f0;"></i>';
        }
    }
    
    selectedRating.innerHTML = starsHTML + '<span>' + labels[rating - 1] + '</span>';
    ratingValue.value = rating;
    
    toggleRatingDropdown();
}

// Submit Review
function submitReview(event) {
    event.preventDefault();
    
    const name = document.getElementById('reviewerName').value;
    const rating = document.getElementById('ratingValue').value;
    const review = document.getElementById('reviewText').value;
    
    if (!rating) {
        showNotification('Please select a rating', 'error');
        return;
    }
    
    if (!name || !review) {
        showNotification('Please fill all fields', 'error');
        return;
    }
    
    event.preventDefault(); // Prevent form submission
    
    // Show loading state
    const submitBtn = event.target.querySelector('.btn-submit-review');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
    submitBtn.disabled = true;
    
    // Send AJAX request to submit review
    $.ajax({
        url: 'ajax/submit_review.php',
        method: 'POST',
        data: {
            product_id: <?php echo $product_id; ?>,
            user_name: name,
            rating: rating,
            review_text: review
        },
        dataType: 'json',
        timeout: 5000, // Reduce timeout to 5 seconds
        success: function(response) {
            if (response.success) {
                closeReviewModal();
                showNotification('Review submitted successfully! Thank you for your feedback.', 'success');
                
                // Add the new review to the reviews section immediately
                if (response.review) {
                    addReviewToSection(response.review);
                }
                
                // Reset form
                document.getElementById('reviewForm').reset();
                document.getElementById('ratingValue').value = '';
                document.getElementById('selectedRating').innerHTML = '<i class="fas fa-star" style="color: #cbd5e1;"></i><span>Select Rating</span>';
                
                // Update review count
                const reviewCountElement = document.querySelector('.rating-text');
                if (reviewCountElement) {
                    const currentCount = parseInt(reviewCountElement.textContent.match(/\d+/)[0]) || 0;
                    reviewCountElement.textContent = `(${currentCount + 1} reviews)`;
                }
                
                // Update product review count
                const productReviewCountElement = document.querySelector('.reviews-title');
                if (productReviewCountElement) {
                    const match = productReviewCountElement.textContent.match(/\((\d+)\)/);
                    if (match) {
                        const currentCount = parseInt(match[1]) || 0;
                        productReviewCountElement.innerHTML = productReviewCountElement.innerHTML.replace(`(${currentCount})`, `(${currentCount + 1})`);
                    }
                }
            } else {
                showNotification(response.message || 'Error submitting review', 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            showNotification('Error submitting review. Please try again.', 'error');
        },
        complete: function() {
            // Always reset the button state
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    });
}

// Function to dynamically add a review to the reviews section
function addReviewToSection(review) {
    const reviewsContainer = document.querySelector('.reviews-container');
    if (!reviewsContainer) return;
    
    // Remove "No Reviews" message if it exists
    const noReviewsElement = reviewsContainer.querySelector('.no-reviews');
    if (noReviewsElement) {
        noReviewsElement.remove();
    }
    
    // Create review element
    const reviewDate = new Date(review.created_at).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    const reviewElement = document.createElement('div');
    reviewElement.className = 'review-card';
    reviewElement.innerHTML = `
        <div class="review-header">
            <div class="reviewer-info">
                <div class="reviewer-avatar">
                    ${review.user_name.charAt(0).toUpperCase()}
                </div>
                <div>
                    <div class="reviewer-name">${review.user_name}</div>
                    <div class="review-date">${reviewDate}</div>
                </div>
            </div>
            <div class="review-rating">
                ${generateStarRating(review.rating)}
            </div>
        </div>
        <div class="review-text">
            ${review.review_text.replace(/\n/g, '<br>')}
        </div>
    `;
    
    // Insert the new review at the beginning of the reviews container
    const firstChild = reviewsContainer.firstChild;
    const addButtonContainer = reviewsContainer.querySelector('.add-review-button-container');
    
    if (addButtonContainer) {
        reviewsContainer.insertBefore(reviewElement, addButtonContainer);
    } else {
        reviewsContainer.insertBefore(reviewElement, firstChild);
    }
}

// Helper function to generate star rating HTML
function generateStarRating(rating) {
    let starsHTML = '';
    for (let i = 1; i <= 5; i++) {
        if (i <= rating) {
            starsHTML += '<i class="fas fa-star star-filled"></i>';
        } else {
            starsHTML += '<i class="far fa-star star-empty"></i>';
        }
    }
    return starsHTML;
}

// Initialize original price display on page load
document.addEventListener('DOMContentLoaded', function() {
    const priceSection = document.querySelector('.product-price-section');
    const displayOriginalPriceElement = document.getElementById('displayOriginalPrice');

    if (priceSection && displayOriginalPriceElement) {
        const mainHasDiscount = priceSection.dataset.hasDiscount === 'true';
        const mainOriginalPrice = parseFloat(priceSection.dataset.mainOriginalPrice);

        if (mainHasDiscount && mainOriginalPrice > 0) {
            displayOriginalPriceElement.style.display = 'inline';
            displayOriginalPriceElement.style.textDecoration = 'line-through';
            displayOriginalPriceElement.style.color = '#94a3b8';

            const displayPriceElement = document.getElementById('displayPrice');
            if (displayPriceElement) {
                displayPriceElement.style.fontSize = '2rem';
                displayPriceElement.style.fontWeight = '700';
                displayPriceElement.style.color = '#1e3a8a';
            }
        }
    }
    
    // Initialize image swipe functionality
    initImageSwipe();
    
    // Check if product/variant is already in cart (initial check)
    checkVariantInCart();
    
    // Initialize stock display based on product default stock
    const productStatus = '<?php echo $product["status"]; ?>';
    const productStock = <?php echo $product["stock_count"] ?? 1; ?>;
    
    // Check if there are any active/selected variants (simple variants)
    const activeVariant = document.querySelector('.variant-box.active');
    
    // Check if there are any active combination attributes
    const activeCombinationAttrs = document.querySelectorAll('.combination-attr-box.active');
    
    console.log('🚀 Page Load - Active simple variant:', activeVariant ? 'YES' : 'NO');
    console.log('🚀 Page Load - Active combination attrs:', activeCombinationAttrs.length);
    
    if (activeVariant && activeVariant.dataset.variantStock !== undefined) {
        // If a simple variant is already selected, use its stock
        const variantStock = parseInt(activeVariant.dataset.variantStock) || 0;
        console.log('🚀 Using simple variant stock:', variantStock);
        updateStockDisplay(variantStock);
    } else if (activeCombinationAttrs.length > 0) {
        // If combination attributes are selected, the combination logic will handle it
        console.log('🚀 Combination attributes detected, skipping initial stock update');
    } else if (productStatus === 'Out of Stock' || productStock <= 0) {
        // Otherwise use product default stock
        console.log('🚀 Using product default stock:', productStock);
        updateStockDisplay(0);
    }
    
    // ============================================================================
    // GLOBAL THUMBNAIL CLICK HANDLER - Enhanced jQuery Solution
    // ============================================================================
    // This ensures thumbnails ALWAYS update the main image correctly,
    // even after variant combinations are selected or images are changed dynamically
    // ============================================================================
    
    $(document).on('click', '.thumbnail-item', function(e) {
        const $clickedThumb = $(this);
        const thumbIndex = $clickedThumb.data('index');
        const isPrimary = $clickedThumb.hasClass('primary') || thumbIndex === 0;
        
        console.log('🎯 Global thumbnail handler triggered for index:', thumbIndex);
        
        // Get the image URL from the thumbnail
        let imageUrl = $clickedThumb.attr('src');
        
        // Try to construct the full-size image URL from thumbnail
        // Handle various thumbnail naming conventions
        if (imageUrl) {
            imageUrl = imageUrl
                .replace(/-thumb\.(jpg|jpeg|png|gif|webp)$/i, '.$1')
                .replace(/_thumb\.(jpg|jpeg|png|gif|webp)$/i, '.$1')
                .replace(/\.thumb\.(jpg|jpeg|png|gif|webp)$/i, '.$1')
                .replace(/\-small\.(jpg|jpeg|png|gif|webp)$/i, '.$1')
                .replace(/\_small\.(jpg|jpeg|png|gif|webp)$/i, '.$1');
        }
        
        // Add cache buster for primary image to force reload
        if (isPrimary) {
            const separator = imageUrl.includes('?') ? '&' : '?';
            imageUrl = imageUrl.split('?')[0] + '?cb=' + new Date().getTime();
        }
        
        console.log('📸 Loading image URL:', imageUrl);
        
        // Update ALL main images (handles slider and non-slider layouts)
        $('.main-product-image').each(function(index) {
            if (thumbIndex === undefined || index === thumbIndex) {
                const $img = $(this);
                
                // Force image reload with fade effect
                $img.fadeOut(150, function() {
                    $img.attr('src', imageUrl);
                    $img.on('load', function() {
                        $img.fadeIn(150);
                        console.log('✅ Main image updated successfully at index:', index);
                    }).on('error', function() {
                        console.error('❌ Failed to load image:', imageUrl);
                        // Fallback to thumbnail src if full image fails
                        $img.attr('src', $clickedThumb.attr('src')).fadeIn(150);
                    });
                });
            }
        });
        
        // Also update standalone main image by ID (if exists)
        const $standaloneImg = $('#mainProductImage');
        if ($standaloneImg.length) {
            $standaloneImg.fadeOut(150, function() {
                $standaloneImg.attr('src', imageUrl);
                $standaloneImg.on('load', function() {
                    $standaloneImg.fadeIn(150);
                });
            });
        }
        
        // Update active state on thumbnails
        $('.thumbnail-item').removeClass('active');
        $clickedThumb.addClass('active');
        
        // Update image counter
        if (thumbIndex !== undefined) {
            $('#currentImageIndex').text(parseInt(thumbIndex) + 1);
        }
        
        // Update slider position if slider exists
        const $slider = $('#imageSlider');
        const $sliderWrapper = $('#imageSliderWrapper');
        if ($slider.length && $sliderWrapper.length && thumbIndex !== undefined) {
            const sliderWidth = $sliderWrapper.width();
            $slider.removeClass('dragging').css({
                'transform': 'translateX(' + (-thumbIndex * sliderWidth) + 'px)',
                'transition': 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)'
            });
        }
        
        // Handle plugin-based sliders (Slick, Owl Carousel, Swiper, etc.)
        if (typeof $.fn.slick !== 'undefined' && $slider.hasClass('slick-initialized')) {
            $slider.slick('slickGoTo', thumbIndex);
        }
        if (typeof $.fn.owlCarousel !== 'undefined' && $slider.hasClass('owl-carousel')) {
            $slider.trigger('to.owl.carousel', [thumbIndex, 300]);
        }
        if (window.productImageSwiper && typeof window.productImageSwiper.slideTo === 'function') {
            window.productImageSwiper.slideTo(thumbIndex);
        }
        
        // Scroll thumbnail into view (for mobile)
        $clickedThumb[0].scrollIntoView({ 
            behavior: 'smooth', 
            block: 'nearest', 
            inline: 'center' 
        });
    });
    
    // ============================================================================
    // VARIANT CHANGE IMAGE UPDATE ENHANCER
    // ============================================================================
    // Observe and intercept any image changes from variant selection
    // ============================================================================
    
    // Create a MutationObserver to watch for image source changes
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'src') {
                    const $changedImg = $(mutation.target);
                    if ($changedImg.hasClass('main-product-image')) {
                        const newSrc = $changedImg.attr('src');
                        console.log('🔄 Image source changed via variant selection:', newSrc);
                        
                        // Sync thumbnail active state
                        $('.thumbnail-item').each(function() {
                            const $thumb = $(this);
                            const thumbSrc = $thumb.attr('src');
                            
                            if (newSrc.includes(thumbSrc) || thumbSrc.includes(newSrc)) {
                                $thumb.addClass('active');
                            } else {
                                $thumb.removeClass('active');
                            }
                        });
                    }
                }
            });
        });
        
        // Observe all main images for src attribute changes
        $('.main-product-image').each(function() {
            observer.observe(this, { 
                attributes: true, 
                attributeFilter: ['src'] 
            });
        });
    }
    
    console.log('✅ Global thumbnail handler initialized successfully');
});

// ============================================================================
// COPY PRODUCT DETAILS FUNCTION FOR AFFILIATES
// ============================================================================
function copyProductDetails() {
    // Get product details
    const productName = <?php echo json_encode($product['name']); ?>;
    const productDescription = <?php echo json_encode($product['description'] ?? ''); ?>;
    const originalPrice = <?php echo $product['original_price']; ?>;
    const discountedPrice = <?php echo $product['discounted_price'] ?: $product['original_price']; ?>;
    const hasDiscount = <?php echo ($product['discounted_price'] && $product['discounted_price'] < $product['original_price']) ? 'true' : 'false'; ?>;

    // Get product features
    const productFeatures = [
        <?php
        $features_arr = [];
        foreach ($product_features as $feature) {
            $features_arr[] = json_encode([
                'name' => $feature['feature_name'],
                'value' => $feature['feature_description']
            ]);
        }
        echo implode(', ', $features_arr);
        ?>
    ];

    // Build message in WhatsApp-friendly format
    let message = `*_${productName}_*\n`;
    message += `━━━━━━━━━━━━━━━━━━\n`;

    // Price Information
    message += `*_\`Price\`_*\n`;
    if (hasDiscount) {
        message += `   _Original Price_ ➜ _~Rs ${originalPrice.toFixed(2)}~_\n`;
        message += `*_Sale Price_* ➜ _*Rs ${discountedPrice.toFixed(2)}*_\n`;
    } else {
        message += `   _*Rs ${discountedPrice.toFixed(2)}*_\n`;
    }
    message += `━━━━━━━━━━━━━━━━━━\n`;

    // Description
    if (productDescription && productDescription.trim() !== '') {
        message += `_*\`Description\`*_\n`;
        message += `_${productDescription}_\n`;
        message += `━━━━━━━━━━━━━━━━━━\n`;
    }

    // Features
    if (productFeatures.length > 0) {
        message += ` *_\`Key Features:\`_*\n`;
        productFeatures.forEach(feature => {
            message += `• _*${feature.name}* ➜ ${feature.value}_\n`;
        });
        message += `━━━━━━━━━━━━━━━━━━\n`;
    }

    message += `*_\`Contact me to order!\`_*`;

    // Copy to clipboard
    navigator.clipboard.writeText(message).then(() => {
        showNotification('Product details copied to clipboard!', 'success');
        console.log('📋 Product details copied:', productName);
    }).catch(err => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = message;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showNotification('Product details copied to clipboard!', 'success');
            console.log('📋 Product details copied:', productName);
        } catch (err) {
            showNotification('Failed to copy. Please try again.', 'error');
            console.error('Copy failed:', err);
        }
        document.body.removeChild(textArea);
    });
}

// ============================================================================
// DOWNLOAD ALL IMAGES FUNCTION
// ============================================================================
async function downloadAllImages() {
    const productName = <?php echo json_encode($product['name']); ?>;
    
    // Get all product images
    const productImages = [
        <?php 
        $image_list = [];
        foreach ($all_images as $index => $img) {
            $image_list[] = json_encode([
                'url' => PRODUCT_IMAGES_DIR . $img['image_path'],
                'filename' => basename($img['image_path']),
                'index' => $index + 1
            ]);
        }
        echo implode(', ', $image_list);
        ?>
    ];
    
    if (productImages.length === 0) {
        alert('No images available to download');
        return;
    }
    
    // Show notification
    showNotification('Downloading ' + productImages.length + ' image(s)...', 'info');
    
    // Create sanitized product name for filenames
    const sanitizedProductName = productName.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    
    // Download all images
    for (let i = 0; i < productImages.length; i++) {
        const img = productImages[i];
        
        try {
            // Fetch the image as blob
            const response = await fetch(img.url);
            const blob = await response.blob();
            
            // Create blob URL
            const blobUrl = window.URL.createObjectURL(blob);
            
            // Get file extension
            const extension = img.filename.split('.').pop();
            
            // Create download link
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = `${sanitizedProductName}_${img.index}.${extension}`;
            
            // Trigger download
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Clean up blob URL
            window.URL.revokeObjectURL(blobUrl);
            
            // Small delay between downloads
            if (i < productImages.length - 1) {
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        } catch (error) {
            console.error('Error downloading image:', img.filename, error);
        }
    }
    
    // Show success message
    setTimeout(() => {
        showNotification('All ' + productImages.length + ' images downloaded!', 'success');
    }, 500);
    
    console.log('📥 Downloaded ' + productImages.length + ' images for product:', productName);
}
</script>

<?php
require_once 'includes/footer.php';
?>
