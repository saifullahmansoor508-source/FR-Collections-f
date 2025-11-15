<?php
session_start();
header('Content-Type: application/json');

require_once '../../config/config.php';
require_once '../../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$productData = json_decode($input, true);

if (!$productData) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
$requiredFields = ['product_no', 'name', 'category', 'original_price'];
foreach ($requiredFields as $field) {
    if (empty($productData[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $db->beginTransaction();
    
    // Find or create category
    $categoryId = getCategoryId($db, $productData['category']);
    
    // Insert product
    $stmt = $db->prepare("
        INSERT INTO products (
            name, 
            description, 
            short_description,
            category_id, 
            original_price, 
            discounted_price, 
            commission,
            delivery_charges,
            stock_count,
            units_sold,
            views,
            primary_image,
            status, 
            display_location,
            keywords,
            created_at,
            updated_at
        ) VALUES (
            :name, 
            :description, 
            :short_description,
            :category_id, 
            :original_price, 
            :discounted_price,
            :commission,
            :delivery_charges,
            :stock_count,
            :units_sold,
            :views,
            :primary_image,
            :status, 
            :display_location,
            :keywords,
            NOW(),
            NOW()
        )
    ");
    
    $stmt->execute([
        ':name' => $productData['name'],
        ':description' => $productData['description'] ?? '',
        ':short_description' => $productData['short_description'] ?? substr($productData['description'] ?? '', 0, 200),
        ':category_id' => $categoryId,
        ':original_price' => $productData['original_price'],
        ':discounted_price' => $productData['discounted_price'] ?? null,
        ':commission' => $productData['commission'] ?? 0,
        ':delivery_charges' => $productData['delivery_charges'] ?? 0,
        ':stock_count' => $productData['stock'] ?? $productData['stock_count'] ?? 0,
        ':units_sold' => $productData['sold'] ?? $productData['units_sold'] ?? 0,
        ':views' => $productData['views'] ?? 0,
        ':primary_image' => $productData['primary_image'] ?? null,
        ':status' => normalizeStatus($productData['status'] ?? 'In Stock'),
        ':display_location' => normalizeDisplayLocation($productData['display_location'] ?? 'Shop Page'),
        ':keywords' => $productData['keywords'] ?? ''
    ]);
    
    $productId = $db->lastInsertId();
    
    // Insert features
    if (!empty($productData['features']) && is_array($productData['features'])) {
        $featureStmt = $db->prepare("
            INSERT INTO product_features (product_id, feature_name, feature_description)
            VALUES (:product_id, :feature_name, :feature_description)
        ");
        
        foreach ($productData['features'] as $feature) {
            if (!empty($feature['name'])) {
                $featureStmt->execute([
                    ':product_id' => $productId,
                    ':feature_name' => $feature['name'],
                    ':feature_description' => $feature['description'] ?? ''
                ]);
            }
        }
    }
    
    // Insert variants
    if (!empty($productData['variants']) && is_array($productData['variants'])) {
        $variantStmt = $db->prepare("
            INSERT INTO product_variants (
                product_id, 
                variant_type, 
                variant_name, 
                sale_price, 
                original_price,
                image_url
            ) VALUES (
                :product_id, 
                :variant_type, 
                :variant_name, 
                :sale_price, 
                :original_price,
                :image_url
            )
        ");
        
        foreach ($productData['variants'] as $index => $variant) {
            if (!empty($variant['name'])) {
                // Use variant image if available
                $variantImage = null;
                if (isset($productData['variant_images'][$index])) {
                    $variantImage = $productData['variant_images'][$index];
                } elseif (!empty($variant['image_url'])) {
                    $variantImage = $variant['image_url'];
                }
                
                $variantStmt->execute([
                    ':product_id' => $productId,
                    ':variant_type' => $variant['type'] ?? 'Option',
                    ':variant_name' => $variant['name'],
                    ':sale_price' => $variant['sale_price'] ?? $productData['discounted_price'] ?? $productData['original_price'],
                    ':original_price' => $variant['original_price'] ?? $productData['original_price'],
                    ':image_url' => $variantImage
                ]);
            }
        }
    }
    
    // Insert reviews
    if (!empty($productData['reviews']) && is_array($productData['reviews'])) {
        $reviewStmt = $db->prepare("
            INSERT INTO product_reviews (
                product_id, 
                reviewer_name, 
                rating, 
                review_text,
                status,
                created_at
            ) VALUES (
                :product_id, 
                :reviewer_name, 
                :rating, 
                :review_text,
                'approved',
                NOW()
            )
        ");
        
        foreach ($productData['reviews'] as $review) {
            if (!empty($review['reviewer_name']) && !empty($review['rating'])) {
                $reviewStmt->execute([
                    ':product_id' => $productId,
                    ':reviewer_name' => $review['reviewer_name'],
                    ':rating' => max(1, min(5, intval($review['rating']))),
                    ':review_text' => $review['review_text'] ?? ''
                ]);
            }
        }
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'product_id' => $productId,
        'message' => 'Product saved successfully'
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

// Helper Functions

function getCategoryId($db, $categoryName) {
    // First, try to find existing category
    $stmt = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $stmt->execute([':name' => trim($categoryName)]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        return $result['id'];
    }
    
    // If not found, create new category
    $stmt = $db->prepare("INSERT INTO categories (name, created_at) VALUES (:name, NOW())");
    $stmt->execute([':name' => trim($categoryName)]);
    
    return $db->lastInsertId();
}

function normalizeStatus($status) {
    $status = trim($status);
    
    if (preg_match('/out.*stock/i', $status)) {
        return 'Out of Stock';
    } elseif (preg_match('/limited/i', $status)) {
        return 'Limited';
    } else {
        return 'In Stock';
    }
}

function normalizeDisplayLocation($location) {
    $location = trim($location);
    
    if (preg_match('/home.*page/i', $location) && !preg_match('/shop/i', $location)) {
        return 'Homepage';
    } elseif (preg_match('/both/i', $location) || (preg_match('/home/i', $location) && preg_match('/shop/i', $location))) {
        return 'Both';
    } else {
        return 'Shop Page';
    }
}
?>
