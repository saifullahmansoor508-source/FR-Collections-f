<?php
$page_title = "Home";
require_once 'includes/header.php';

// Check for authentication success messages
$auth_message = '';
if (isset($_SESSION['auth_success'])) {
    $auth_message = $_SESSION['auth_success'];
    unset($_SESSION['auth_success']);
}

// Get slider images (check if tables exist)
$slider_images = [];
$categories = [];
$featured_products = [];

try {
    $stmt = $db->prepare("SELECT * FROM slider_images WHERE is_active = 1 ORDER BY sort_order ASC");
    $stmt->execute();
    $slider_images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get categories (limit to 5 for display)
    $stmt = $db->prepare("SELECT * FROM categories ORDER BY name ASC LIMIT 5");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get featured products (3 per row, 9 total)
    $stmt = $db->prepare("
        SELECT p.*, c.name as category_name,
               (SELECT COUNT(*) FROM reviews WHERE product_id = p.id) as review_count,
               (SELECT AVG(rating) FROM reviews WHERE product_id = p.id) as avg_rating,
               COALESCE(p.home_page_image, (SELECT image_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1)) as image_path
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.display_location IN ('Homepage', 'Both')
        ORDER BY p.sales_count DESC, p.created_at DESC
        LIMIT 9
    ");
    $stmt->execute();
    $featured_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's favorite product IDs if logged in
    $favorite_product_ids = [];
    $cart_product_ids = [];
    if (isset($_SESSION['user_id'])) {
        $stmt = $db->prepare("SELECT product_id FROM favorites WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $favorite_product_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get cart product IDs
        $stmt = $db->prepare("SELECT DISTINCT product_id FROM cart WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $cart_product_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    // Tables don't exist yet (during installation)
    $slider_images = [];
    $categories = [];
    $featured_products = [];
}
?>

<!-- Success Message Toast -->
<?php if ($auth_message): ?>
<style>
  
/* ===== ANIMATED REVIEWS SECTION ===== */
.reviews-section {
    padding: 60px 0;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    overflow: hidden;
    position: relative;
}

.reviews-scroll-container {
    width: 100%;
    overflow: hidden;
    position: relative;
}

.reviews-scroll {
    display: flex;
    gap: 25px;
    width: max-content;
    will-change: transform;
}

.reviews-scroll-left {
    animation: scroll-reviews-left 40s linear infinite;
}

@keyframes scroll-reviews-left {
    0% {
        transform: translateX(0);
    }
    100% {
        transform: translateX(-50%);
    }
}

.review-card {
    width: 380px;
    background: white;
    border-radius: 20px;
    padding: 25px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 2px solid transparent;
    background-clip: padding-box;
}

.review-card::before {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    background: linear-gradient(
        45deg,
        #3b82f6,
        #8b5cf6,
        #ec4899,
        #f59e0b,
        #10b981,
        #3b82f6
    );
    border-radius: 20px;
    z-index: -1;
    animation: rotateBorder 4s linear infinite;
    background-size: 300% 300%;
}

@keyframes rotateBorder {
    0% {
        background-position: 0% 50%;
    }
    50% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0% 50%;
    }
}

.review-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15);
}

.review-dots {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 60px;
    height: 60px;
    pointer-events: none;
}

.review-dots::before,
.review-dots::after {
    content: '';
    position: absolute;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: linear-gradient(135deg, #90ee90, #4caf50);
    box-shadow: 0 0 15px rgba(76, 175, 80, 0.6);
    animation: floatDot 3s ease-in-out infinite;
}

.review-dots::before {
    top: 0;
    right: 0;
    animation-delay: 0s;
}

.review-dots::after {
    top: 20px;
    right: 15px;
    animation-delay: 1.5s;
}

@keyframes floatDot {
    0%, 100% {
        transform: translateY(0) scale(1);
        opacity: 0.8;
    }
    50% {
        transform: translateY(-10px) scale(1.2);
        opacity: 1;
    }
}

.review-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
}

.review-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    flex-shrink: 0;
}

.review-info {
    flex: 1;
}

.review-name {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 5px 0;
}

.review-stars {
    display: flex;
    gap: 3px;
}

.review-stars i {
    font-size: 14px;
    color: #cbd5e1;
}

.review-stars i.active {
    color: #fbbf24;
    text-shadow: 0 0 10px rgba(251, 191, 36, 0.5);
}

.review-text {
    font-size: 14px;
    line-height: 1.6;
    color: #475569;
    margin-bottom: 15px;
}

.review-product {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    border-radius: 10px;
    font-size: 13px;
    color: #64748b;
}

.review-product i {
    color: #3b82f6;
}

@media (max-width: 768px) {
    .reviews-section {
        padding: 40px 0;
    }
    
    .review-card {
        width: 300px;
        padding: 20px;
    }
    
    .reviews-scroll {
        gap: 15px;
    }
}

@media (max-width: 768px) {
    .hero-section { 
        height: 55vh;
        min-height: 350px;
        margin-bottom: 0;
    }
    
    .hero-content {
        padding: 30px 20px;
    }
    
    .hero-title { 
        font-size: 1.6rem;
        margin-bottom: 16px;
        line-height: 1.3;
    }
    
    .hero-subtitle { 
        font-size: 0.95rem;
        margin-bottom: 22px;
        line-height: 1.7;
    }
    
    .btn-hero {
        font-size: 0.95rem;
        padding: 12px 28px;
    }
}

@media (max-width: 600px) {
    .hero-section { 
        min-height: 300px;
    }
    
    .hero-title {
        font-size: 1.4rem;
    }
    
    .hero-subtitle {
        font-size: 0.88rem;
    }
}
    .success-toast {
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 10000;
        padding: 15px 30px;
        border-radius: 50px;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
        animation: slideDown 0.3s ease-out, fadeOut 0.3s ease-out 2.7s;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        max-width: 90%;
    }
    
    .success-toast.account-created {
        background: #10b981;
        color: #fff;
    }
    
    .success-toast.login-success {
        background: #10b981;
        color: #fff;
    }
    
    .success-toast i {
        font-size: 1.2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        flex-shrink: 0;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }
    
    @keyframes fadeOut {
        from {
            opacity: 1;
        }
        to {
            opacity: 0;
        }
    }
</style>
<?php if ($auth_message === 'account_created'): ?>
    <div class="success-toast account-created" id="authToast">
        <i class="fas fa-check-circle"></i>
        <span>Account created successfully! Redirecting...</span>
    </div>
<?php elseif ($auth_message === 'login_successful'): ?>
    <div class="success-toast login-success" id="authToast">
        <i class="fas fa-check-circle"></i>
        <span>Login Successful! Redirecting...</span>
    </div>
<?php endif; ?>

<script>
    // Auto remove toast after 3 seconds
    setTimeout(function() {
        var toast = document.getElementById('authToast');
        if (toast) {
            toast.remove();
        }
    }, 3000);
</script>
<?php endif; ?>
<section class="reviews-section">
    <div class="reviews-scroll-container">
        <div class="reviews-scroll reviews-scroll-left">
            <?php 
            // If no reviews from database, use default reviews
            if (empty($reviews)) {
                $reviews = [
                    ['username' => 'Ayesha Khan', 'rating' => 5, 'review' => 'Absolutely loved it! The quality is top-notch and the stitching is flawless.', 'product_name' => 'Embroidered Lawn Suit'],
['username' => 'Ali Raza', 'rating' => 5, 'review' => 'Received my order on time. The shoes are exactly as shown, very comfortable.', 'product_name' => 'Casual Loafers'],
['username' => 'Hira Ahmed', 'rating' => 5, 'review' => 'The dress is so elegant and fits perfectly. Everyone complimented me!', 'product_name' => 'Formal Maxi Dress'],
['username' => 'Usman Malik', 'rating' => 5, 'review' => 'Excellent product quality! I’m really impressed with the packaging too.', 'product_name' => 'Smart Watch'],
['username' => 'Fatima Noor', 'rating' => 5, 'review' => 'Beautiful jewelry set! It looks even better in real life. Highly recommend.', 'product_name' => 'Pearl Necklace Set'],
['username' => 'Saad Ali', 'rating' => 5, 'review' => 'Fast delivery and premium quality. The fabric feels very soft and rich.', 'product_name' => 'Cotton Kurta'],
['username' => 'Mariam Zafar', 'rating' => 5, 'review' => 'Loved the home decor pieces! They’ve added so much charm to my room.', 'product_name' => 'Table Lamp'],
['username' => 'Bilal Hussain', 'rating' => 5, 'review' => 'Perfect size and finish. The bag looks classy and durable.', 'product_name' => 'Leather Laptop Bag'],
['username' => 'Sana Tariq', 'rating' => 5, 'review' => 'The color and embroidery are exactly like the photos. Super happy!', 'product_name' => 'Chiffon Dress'],
['username' => 'Hamza Qureshi', 'rating' => 5, 'review' => 'Very good experience. Excellent material and great fit.', 'product_name' => 'Denim Jacket'],
['username' => 'Nida Rehman', 'rating' => 5, 'review' => 'I love the design and quality! Perfect for festive wear.', 'product_name' => 'Embroidered Dupatta'],
['username' => 'Ahmad Khan', 'rating' => 5, 'review' => 'Superb product! Looks stylish and is worth every rupee.', 'product_name' => 'Analog Wrist Watch'],
['username' => 'Sara Ali', 'rating' => 5, 'review' => 'Such a beautiful piece! Arrived quickly and well packaged.', 'product_name' => 'Crystal Earrings'],
['username' => 'Zainab Shah', 'rating' => 5, 'review' => 'Loved the scent and presentation. Perfect for gifting!', 'product_name' => 'Luxury Perfume'],
['username' => 'Hassan Raza', 'rating' => 5, 'review' => 'Very satisfied! The sound quality is amazing for the price.', 'product_name' => 'Bluetooth Earbuds'],
['username' => 'Iqra Javed', 'rating' => 5, 'review' => 'The bedsheet set is so soft and looks beautiful in my room.', 'product_name' => 'Cotton Bedsheet Set'],
['username' => 'Shahzaib Ahmed', 'rating' => 5, 'review' => 'Fantastic product quality and professional service!', 'product_name' => 'Sports Shoes'],
['username' => 'Laiba Aslam', 'rating' => 5, 'review' => 'Beautiful color and premium fabric. Exactly what I wanted.', 'product_name' => 'Silk Scarf'],
['username' => 'Nouman Siddiqui', 'rating' => 5, 'review' => 'Amazing craftsmanship! The wallet feels luxurious and sturdy.', 'product_name' => 'Men’s Leather Wallet'],
['username' => 'Komal Irfan', 'rating' => 5, 'review' => 'Very happy with my purchase. Everything was perfect from packaging to delivery.', 'product_name' => 'Handmade Clutch Bag'],
                ];
            }
            
            // Duplicate for infinite scroll
            $reviews_display = array_merge($reviews, $reviews);
            foreach ($reviews_display as $review): 
            ?>
                <div class="review-card">
                    <div class="light-dot-1"></div>
                    <div class="light-dot-2"></div>
                    <div class="light-dot-3"></div>
                    <div class="review-dots"></div>
                    <div class="review-header">
                        <div class="review-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="review-info">
                            <h6 class="review-name"><?php echo htmlspecialchars($review['username'] ?? 'Anonymous'); ?></h6>
                            <div class="review-stars">
                                <?php for ($i = 0; $i < 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i < $review['rating'] ? 'active' : ''; ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                    <p class="review-text"><?php echo htmlspecialchars($review['review'] ?? $review['text'] ?? ''); ?></p>
                    <div class="review-product">
                        <i class="fas fa-shopping-bag"></i>
                        <span><?php echo htmlspecialchars($review['product_name'] ?? 'Product'); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Shop by Category Section -->
<section class="categories-section py-5">
    <div class="container">
        <div class="row">
            <div class="col-12 text-center mb-1">
                <h2 class="section-title">Shop by Category</h2>
            </div>
        </div>
        
        <!-- Desktop Categories -->
        <div class="d-none d-md-block">
            <div class="categories-scroll-container">
                <div class="categories-desktop-scroll">
                    <?php 
                    // All 12 categories
                    $default_categories = [
                        ['name' => 'Dresses', 'icon' => 'fas fa-female'],
                        ['name' => 'Shoes', 'icon' => 'fas fa-shoe-prints'],
                        ['name' => 'Jewellery', 'icon' => 'fas fa-gem'],
                        ['name' => 'Appliances', 'icon' => 'fas fa-blender'],
                        ['name' => 'Electronics', 'icon' => 'fas fa-laptop'],
                        ['name' => 'Stationery', 'icon' => 'fas fa-pen'],
                        ['name' => 'Home and Living', 'icon' => 'fas fa-home'],
                        ['name' => 'Kids Hub', 'icon' => 'fas fa-child'],
                        ['name' => 'Gents Collection', 'icon' => 'fas fa-user-tie'],
                        ['name' => 'Purse and Bags', 'icon' => 'fas fa-shopping-bag'],
                        ['name' => 'Gifts', 'icon' => 'fas fa-gift'],
                        ['name' => 'Apparel', 'icon' => 'fas fa-tshirt'],
                        ['name' => 'Digital Products', 'icon' => 'fas fa-download']
                    ];
                    
                    // Duplicate categories for infinite scroll
                    $desktop_categories = array_merge($default_categories, $default_categories);
                    
                    foreach ($desktop_categories as $category): 
                    ?>
                        <div class="category-card-modern" onclick="window.location.href='shop.php?category=<?php echo urlencode($category['name']); ?>'">
                            <div class="category-icon">
                                <i class="<?php echo isset($category['icon']) ? $category['icon'] : 'fas fa-shopping-bag'; ?>"></i>
                            </div>
                            <h6 class="category-name"><?php echo htmlspecialchars($category['name']); ?></h6>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Mobile Categories - Infinite Scroll -->
        <div class="d-md-none">
            <div class="categories-scroll-container">
                <div class="categories-scroll">
                    <?php 
                    // Duplicate categories for infinite scroll effect
                    $mobile_categories = array_merge($default_categories, $default_categories);
                    foreach ($mobile_categories as $category): 
                    ?>
                        <div class="category-card-mobile" onclick="window.location.href='shop.php?category=<?php echo urlencode($category['name']); ?>'">
                            <div class="category-icon-mobile">
                                <i class="<?php echo isset($category['icon']) ? $category['icon'] : 'fas fa-shopping-bag'; ?>"></i>
                            </div>
                            <h6 class="category-name-mobile"><?php echo htmlspecialchars($category['name']); ?></h6>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Featured Products Section -->
<section class="products-section py-5">
    <div class="container">
        <div class="row">
            <div class="col-12 text-center mb-5">
                <h2 class="section-title">Featured Products</h2>
            </div>
        </div>
        
        <div class="row">
            <?php if (!empty($featured_products)): ?>
                <?php foreach ($featured_products as $product): 
            ?>
                    <div class="col-lg-4 col-md-6 col-6 mb-4">
                        <div class="product-card-modern" onclick="window.location.href='product.php?id=<?php echo $product['id']; ?>'" style="cursor: pointer;">
                            <div class="product-image">
                                <img src="<?php echo !empty($product['image_path']) ? 'uploads/products/' . $product['image_path'] : 'assets/images/no-image.jpg'; ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>">
                                
                                <!-- Favorite Button -->
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <button class="favorite-btn" data-product-id="<?php echo $product['id']; ?>" onclick="event.stopPropagation(); toggleFavorite(<?php echo $product['id']; ?>)" title="Add to favorites">
                                        <i class="<?php echo in_array($product['id'], $favorite_product_ids) ? 'fas' : 'far'; ?> fa-star"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-info">
                                <!-- Stock Status and Sold Stats inline -->
                                <div class="product-meta-row">
                                    <span class="stock-text <?php echo getStockBadgeClass($product['status'], $product['stock_count']); ?>">
                                        <?php echo getStockBadgeText($product['status'], $product['stock_count']); ?>
                                    </span>
                                    <span class="sold-stat"><?php echo $product['sales_count']; ?> Sold</span>
                                </div>
                                
                                <!-- Product Title -->
                                <h6 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h6>
                                
                                <!-- Price -->
                                <div class="product-price">
                                    <span class="current-price">Rs.<?php echo number_format($product['discounted_price'] ?: $product['original_price'], 0); ?></span>
                                    <?php if ($product['discounted_price'] && $product['discounted_price'] < $product['original_price']): ?>
                                        <span class="original-price">Rs.<?php echo number_format($product['original_price'], 0); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="product-actions">
                                    <?php 
                                    $isInCart = in_array($product['id'], $cart_product_ids);
                                    $cartBtnClass = $isInCart ? 'btn btn-in-cart' : 'btn btn-cart';
                                    $cartBtnText = $isInCart ? 'In Cart' : 'Cart';
                                    ?>
                                    <button class="<?php echo $cartBtnClass; ?>" onclick="event.stopPropagation(); <?php echo $isInCart ? 'window.location.href=\'cart.php\'' : 'addToCart(' . $product['id'] . ', null, 1, $(this))'; ?>">
                                        <?php echo $cartBtnText; ?>
                                    </button>
                                    <a href="product.php?id=<?php echo $product['id']; ?>" class="btn btn-buy" onclick="event.stopPropagation();">
                                        Buy
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                <h4>No Featured Products Yet</h4>
                <p class="text-muted">Products will appear here once they are added.</p>
            </div>
        <?php endif; ?>
        </div>
        
    </div>
</section>

<!-- Back to Top Button -->
<button id="backToTop" class="btn btn-primary position-fixed" style="bottom: 100px; right: 20px; display: none; z-index: 999;">
    <i class="fas fa-arrow-up"></i>
</button>


<?php
// Helper functions
function getCategoryIcon($categoryName) {
    $icons = [
        'Dresses' => 'tshirt',
        'Shoes' => 'shoe-prints',
        'Jewellery' => 'gem',
        'Appliances' => 'blender',
        'Electronics' => 'laptop',
        'Stationery' => 'pen',
        'Home & Living' => 'home',
        'Kids Hub' => 'child',
        'Gents Collection' => 'user-tie',
        'Purses & Bags' => 'shopping-bag',
        'Gifts' => 'gift',
        'Apparel' => 'tshirt',
        'Digital Products' => 'download'
    ];
    
    return $icons[$categoryName] ?? 'box';
}

function getStockBadgeClass($status, $stock_count) {
    // Only check status from database, ignore stock_count logic
    if ($status === 'Out of Stock') {
        return 'out-of-stock';
    } elseif ($status === 'Limited') {
        return 'limited';
    } else {
        return 'in-stock';
    }
}

function getStockBadgeText($status, $stock_count) {
    // Only check status from database
    if ($status === 'Out of Stock') {
        return 'Out of Stock';
    } elseif ($status === 'Limited') {
        return 'Limited Stock';
    } else {
        return 'In Stock';
    }
}

function getStatusClass($status, $stock_count) {
    switch ($status) {
        case 'Out of Stock':
            return 'status-out-of-stock';
        case 'Limited':
            return 'status-limited';
        default:
            return 'status-in-stock';
    }
}

function getStatusText($status, $stock_count) {
    // Only use database status
    switch ($status) {
        case 'Out of Stock':
            return 'Out of Stock';
        case 'Limited':
            return 'Limited Stock';
        default:
            return 'In Stock';
    }
}

require_once 'includes/footer.php';
?>