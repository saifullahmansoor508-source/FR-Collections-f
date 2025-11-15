<?php
/**
 * AJAX Search Products Endpoint
 * Handles real-time product search without page reload
 * Searches by: product name, description, keywords, and category
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json');

// Get search parameters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$category = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';
$sort = isset($_GET['sort']) ? sanitizeInput($_GET['sort']) : 'latest';

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Build query
    $where_conditions = [];
    $params = [];
    
    $base_query = "
        SELECT p.*, c.name as category_name,
               (SELECT COUNT(*) FROM reviews WHERE product_id = p.id) as review_count,
               (SELECT AVG(rating) FROM reviews WHERE product_id = p.id) as avg_rating,
               COALESCE(p.shop_page_image, (SELECT image_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1)) as image_path
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.display_location IN ('Shop Page', 'Both')";
    
    // Search filter - searches in name, description, keywords, and category
    if ($search) {
        $base_query .= " AND (
            p.name LIKE ? 
            OR p.description LIKE ? 
            OR p.keywords LIKE ? 
            OR c.name LIKE ?
        )";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    // Category filter
    if ($category && $category !== 'All Categories') {
        $base_query .= " AND c.name = ?";
        $params[] = $category;
    }
    
    // Sorting
    switch ($sort) {
        case 'price_low':
            $base_query .= " ORDER BY COALESCE(p.discounted_price, p.original_price) ASC";
            break;
        case 'price_high':
            $base_query .= " ORDER BY COALESCE(p.discounted_price, p.original_price) DESC";
            break;
        case 'newest':
            $base_query .= " ORDER BY p.created_at DESC";
            break;
        case 'popular':
            $base_query .= " ORDER BY p.sales_count DESC";
            break;
        default:
            $base_query .= " ORDER BY p.created_at DESC";
    }
    
    // Execute query
    $stmt = $db->prepare($base_query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get favorite product IDs if user is logged in
    $favorite_product_ids = [];
    if (isset($_SESSION['user_id'])) {
        $stmt = $db->prepare("SELECT product_id FROM favorites WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $favorite_product_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Build HTML for products
    $html = '';
    
    if (!empty($products)) {
        foreach ($products as $product) {
            $is_favorite = in_array($product['id'], $favorite_product_ids);
            
            // Helper function for stock badge
            $stock_class = 'in-stock';
            $stock_text = 'In Stock';
            if ($product['status'] === 'Out of Stock') {
                $stock_class = 'out-of-stock';
                $stock_text = 'Out of Stock';
            } elseif ($product['status'] === 'Limited') {
                $stock_class = 'limited';
                $stock_text = 'Limited Stock';
            }
            
            $price = $product['discounted_price'] ?: $product['original_price'];
            $original_price = $product['original_price'];
            $show_original = $product['discounted_price'] && $product['discounted_price'] < $product['original_price'];
            
            $image_path = !empty($product['image_path']) ? 'uploads/products/' . $product['image_path'] : 'assets/images/no-image.jpg';
            
            $html .= '
            <div class="col-lg-4 col-md-6 col-6 mb-4">
                <div class="product-card-modern" onclick="window.location.href=\'product.php?id=' . $product['id'] . '\' " style="cursor: pointer;">
                    <div class="product-image">
                        <img src="' . htmlspecialchars($image_path) . '" alt="' . htmlspecialchars($product['name']) . '">
                        ' . (isset($_SESSION['user_id']) ? '
                        <button class="favorite-btn" data-product-id="' . $product['id'] . '" onclick="event.stopPropagation(); toggleFavorite(' . $product['id'] . ')" title="Add to favorites">
                            <i class="' . ($is_favorite ? 'fas' : 'far') . ' fa-star"></i>
                        </button>
                        ' : '') . '
                    </div>
                    
                    <div class="product-info">
                        <div class="product-meta-row">
                            <span class="stock-text ' . $stock_class . '">
                                ' . $stock_text . '
                            </span>
                            <span class="sold-stat">' . intval($product['sales_count']) . ' Sold</span>
                        </div>
                        
                        <h6 class="product-title">' . htmlspecialchars($product['name']) . '</h6>
                        
                        <div class="product-price">
                            <span class="current-price">Rs.' . number_format($price, 0) . '</span>
                            ' . ($show_original ? '<span class="original-price">Rs.' . number_format($original_price, 0) . '</span>' : '') . '
                        </div>
                        
                        <div class="product-actions">
                            <button class="btn btn-cart" onclick="event.stopPropagation(); addToCart(' . $product['id'] . ', null, 1, $(this))">
                                Cart
                            </button>
                            <a href="product.php?id=' . $product['id'] . '" class="btn btn-buy" onclick="event.stopPropagation();">
                                Buy
                            </a>
                        </div>
                    </div>
                </div>
            </div>';
        }
    } else {
        $html .= '
        <div class="col-12 text-center py-5">
            <h3>No products found</h3>
            <p class="text-muted">Try adjusting your search or filter criteria.</p>
            <a href="shop.php" class="btn btn-primary">View All Products</a>
        </div>';
    }
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'count' => count($products),
        'html' => $html,
        'products' => $products
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error fetching products: ' . $e->getMessage()
    ]);
}
?>
