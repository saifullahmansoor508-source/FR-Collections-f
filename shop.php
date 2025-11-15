<?php

$page_title = "Shop";
$bodyClass = "no-gap shop-page";
require_once 'includes/header.php';

// Get filters from URL
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';
$sort = isset($_GET['sort']) ? sanitizeInput($_GET['sort']) : 'latest';

// Get categories from database
$categories = ['All Categories'];
try {
    $stmt = $db->prepare("SELECT name FROM categories ORDER BY name ASC");
    $stmt->execute();
    $db_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $categories = array_merge($categories, $db_categories);
} catch (Exception $e) {
    // If error, fall back to empty array with just "All Categories"
    error_log("Error fetching categories: " . $e->getMessage());
}

// Get all products with real data integration
try {
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

    if ($search) {
        $base_query .= " AND (p.name LIKE ? OR p.description LIKE ? OR p.short_description LIKE ? OR p.keywords LIKE ? OR c.name LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    if ($category_filter && $category_filter !== 'All Categories') {
        $base_query .= " AND c.name = ?";
        $params[] = $category_filter;
    }

    // Add sorting
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

    $stmt = $db->prepare($base_query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
} catch (Exception $e) {
    $products = [];
}

// Get user's order count
$user_orders = 0;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT id) FROM orders WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_orders = $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error fetching user orders: " . $e->getMessage());
    }
}
?>
<!-- Animated Stats Section -->
<section class="stats-section">
    <div class="container">
        <div class="row g-4">
            <!-- Happy Customers -->
            <div class="col-lg-3 col-md-6 col-6">
                <div class="stat-card">
                    <div class="stat-icon happy-customers">
                        <i class="fas fa-smile"></i>
                    </div>
                    <div class="stat-number" data-target="9785" data-animation="increase">0</div>
                    <div class="stat-label">Happy Customers</div>
                </div>
            </div>
            
            <!-- Reviews -->
            <div class="col-lg-3 col-md-6 col-6">
                <div class="stat-card">
                    <div class="stat-icon reviews">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-number" data-target="684" data-animation="increase">0</div>
                    <div class="stat-label">Reviews</div>
                </div>
            </div>
            
            <!-- Your Cards -->
            <div class="col-lg-3 col-md-6 col-6">
                <a href="your-cards.php" class="stat-card-link">
                    <div class="stat-card cards-card clickable-card">
                        <div class="stat-icon cards">
                            <div class="card-stack">
                                <div class="card card-1"></div>
                                <div class="card card-2"></div>
                                <div class="card card-3"></div>
                            </div>
                        </div>
                        <div class="cards-shimmer"></div>
                        <div class="stat-number cards-number" data-target="<?php 
                            // Get user's total collected cards
                            if (isset($_SESSION['user_id'])) {
                                $stmt = $db->prepare("SELECT COUNT(*) as count FROM user_card_collections WHERE user_id = ? AND is_collected = TRUE");
                                $stmt->execute([$_SESSION['user_id']]);
                                $user_cards = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                                echo $user_cards;
                            } else {
                                echo '0';
                            }
                        ?>" data-animation="increase">0</div>
                        <div class="stat-label">Your Cards</div>
                    </div>
                </a>
            </div>
            
            <!-- Your Orders -->
            <div class="col-lg-3 col-md-6 col-6">
                <div class="stat-card orders-card">
                    <div class="stat-icon orders">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="orders-dots"></div>
                    <div class="stat-number orders-number" data-target="<?php echo $user_orders; ?>" data-animation="decrease">999</div>
                    <div class="stat-label">Your Orders</div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- Search and Filter Section -->
<section class="shop-filters py-4">
    <div class="container">
        <div class="filters-row">
            <!-- Search Bar -->
            <div class="search-wrapper">
                <input type="text" 
                       id="searchInput"
                       class="search-input-modern" 
                       placeholder="Search products..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       oninput="performSearch()">
                <button type="button" class="search-btn-modern">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            
            <!-- Category Filter -->
            <div class="filter-dropdown-wrapper">
                <div class="custom-dropdown" id="categoryDropdown">
                    <div class="dropdown-selected" onclick="toggleDropdown('categoryDropdown')">
                        <span id="selectedCategory">All Categories</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </div>
                    <div class="dropdown-options">
                        <?php foreach ($categories as $category): ?>
                            <div class="dropdown-option <?php echo ($category_filter === $category) ? 'selected' : ''; ?>" 
                                 onclick="selectCategory('<?php echo htmlspecialchars($category); ?>')">
                                <?php echo htmlspecialchars($category); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Sort Filter -->
            <div class="filter-dropdown-wrapper">
                <div class="custom-dropdown" id="sortDropdown">
                    <div class="dropdown-selected" onclick="toggleDropdown('sortDropdown')">
                        <span id="selectedSort">Latest</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </div>
                    <div class="dropdown-options">
                        <div class="dropdown-option <?php echo ($sort === 'latest') ? 'selected' : ''; ?>" 
                             onclick="selectSort('latest', 'Latest')">Latest</div>
                        <div class="dropdown-option <?php echo ($sort === 'popular') ? 'selected' : ''; ?>" 
                             onclick="selectSort('popular', 'Popular')">Popular</div>
                        <div class="dropdown-option <?php echo ($sort === 'price_low') ? 'selected' : ''; ?>" 
                             onclick="selectSort('price_low', 'Price: Low to High')">Price: Low to High</div>
                        <div class="dropdown-option <?php echo ($sort === 'price_high') ? 'selected' : ''; ?>" 
                             onclick="selectSort('price_high', 'Price: High to Low')">Price: High to Low</div>
                        <div class="dropdown-option <?php echo ($sort === 'newest') ? 'selected' : ''; ?>" 
                             onclick="selectSort('newest', 'Newest First')">Newest First</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- Products Section -->
<section class="shop-products py-5">
    <div class="container">
        <?php if (!empty($products)): ?>
            <div class="row">
                <?php foreach ($products as $product): ?>
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
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-12 text-center py-5">
                    <h3>No products found</h3>
                    <p class="text-muted">Try adjusting your search or filter criteria.</p>
                    <a href="shop.php" class="btn btn-primary">View All Products</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
// Include helper functions from index.php
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
?>

<script>
let searchTimeout;
let isSearching = false;
let currentCategory = '<?php echo htmlspecialchars($category_filter ?: 'All Categories'); ?>';
let currentSort = '<?php echo htmlspecialchars($sort ?: 'latest'); ?>';

// Real-time search functionality with AJAX (no page reload)
function performSearch() {
    clearTimeout(searchTimeout);
    const searchTerm = document.getElementById('searchInput').value;
    
    // Show loading state
    showSearchLoading();
    
    // Debounce: wait 300ms after user stops typing
    searchTimeout = setTimeout(() => {
        updateProductsAjax(searchTerm, currentCategory, currentSort);
    }, 300);
}

// Show loading animation
function showSearchLoading() {
    const productsContainer = document.querySelector('.shop-products .row');
    if (productsContainer) {
        productsContainer.style.opacity = '0.6';
        productsContainer.style.pointerEvents = 'none';
    }
}

// Hide loading animation
function hideSearchLoading() {
    const productsContainer = document.querySelector('.shop-products .row');
    if (productsContainer) {
        productsContainer.style.opacity = '1';
        productsContainer.style.pointerEvents = 'auto';
    }
}

// Update products via AJAX (smooth, no page reload)
function updateProductsAjax(search, category, sort) {
    if (isSearching) return;
    
    isSearching = true;
    
    // Build query parameters
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (category && category !== 'All Categories') params.append('category', category);
    if (sort) params.append('sort', sort);
    
    // Update URL without page reload
    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.pushState({}, '', newUrl);
    
    // Fetch products via AJAX
    fetch('ajax/search_products.php?' + params.toString())
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Update products HTML with smooth fade-in
                const productsContainer = document.querySelector('.shop-products .container .row');
                if (productsContainer) {
                    // Fade out
                    productsContainer.style.opacity = '0';
                    productsContainer.style.transition = 'opacity 0.2s ease-in';
                    
                    // Update after fade out
                    setTimeout(() => {
                        productsContainer.innerHTML = data.html;
                        
                        // Fade in
                        productsContainer.style.opacity = '1';
                        productsContainer.style.transition = 'opacity 0.3s ease-out';
                        
                        // Re-initialize event listeners for favorite buttons
                        reinitializeFavoriteButtons();
                    }, 200);
                }
            } else {
                console.error('Error loading products:', data.error || 'Unknown error');
            }
            isSearching = false;
            hideSearchLoading();
        })
        .catch(error => {
            console.error('Search error:', error);
            isSearching = false;
            hideSearchLoading();
        });
}

// Reinitialize favorite buttons after AJAX update
function reinitializeFavoriteButtons() {
    // Any event listeners added dynamically can be reinitialized here
    // The favorite buttons already have onclick handlers in the HTML
}

// Toggle dropdown with sliding animation
function toggleDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    const isActive = dropdown.classList.contains('active');
    
    // Close all other dropdowns
    document.querySelectorAll('.custom-dropdown').forEach(dd => {
        if (dd.id !== dropdownId) {
            dd.classList.remove('active');
        }
    });
    
    // Toggle current dropdown
    if (isActive) {
        dropdown.classList.remove('active');
    } else {
        dropdown.classList.add('active');
    }
}

// Select category
function selectCategory(category) {
    currentCategory = category;
    document.getElementById('selectedCategory').textContent = category;
    document.getElementById('categoryDropdown').classList.remove('active');
    
    // Update selected state
    document.querySelectorAll('#categoryDropdown .dropdown-option').forEach(option => {
        option.classList.remove('selected');
        if (option.textContent.trim() === category) {
            option.classList.add('selected');
        }
    });
    
    // Trigger search with new category
    const searchTerm = document.getElementById('searchInput').value;
    showSearchLoading();
    updateProductsAjax(searchTerm, currentCategory, currentSort);
}

// Select sort option
function selectSort(sortValue, sortText) {
    currentSort = sortValue;
    document.getElementById('selectedSort').textContent = sortText;
    document.getElementById('sortDropdown').classList.remove('active');
    
    // Update selected state
    document.querySelectorAll('#sortDropdown .dropdown-option').forEach(option => {
        option.classList.remove('selected');
        if (option.onclick.toString().includes(sortValue)) {
            option.classList.add('selected');
        }
    });
    
    // Trigger search with new sort
    const searchTerm = document.getElementById('searchInput').value;
    showSearchLoading();
    updateProductsAjax(searchTerm, currentCategory, currentSort);
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.custom-dropdown')) {
        document.querySelectorAll('.custom-dropdown').forEach(dropdown => {
            dropdown.classList.remove('active');
        });
    }
});

// Initialize selected values on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set initial category text
    const categoryText = currentCategory || 'All Categories';
    document.getElementById('selectedCategory').textContent = categoryText;
    
    // Set initial sort text
    const sortTexts = {
        'latest': 'Latest',
        'popular': 'Popular', 
        'price_low': 'Price: Low to High',
        'price_high': 'Price: High to Low',
        'newest': 'Newest First'
    };
    document.getElementById('selectedSort').textContent = sortTexts[currentSort] || 'Latest';
    
    // Add event listener for Enter key in search input
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performSearch();
            }
        });
    }
    
    // Check cart status for all products on page load
    checkAllProductsInCart();
    
    // Animate stats numbers
    animateStats();
    
    // Create cards shimmer effect
    createCardsShimmer();
});

// Animate stats numbers with increase/decrease effect
function animateStats() {
    const statNumbers = document.querySelectorAll('.stat-number[data-target]');
    
    statNumbers.forEach(stat => {
        const target = parseInt(stat.getAttribute('data-target'));
        const animationType = stat.getAttribute('data-animation');
        
        // Check for NaN or invalid target
        if (isNaN(target)) {
            stat.textContent = '0';
            return;
        }
        
        const duration = 2000; // 2 seconds
        const startTime = performance.now();
        
        if (animationType === 'increase') {
            // Increase animation (0 to target)
            function updateIncrease(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                
                // Easing function for smooth animation
                const easeOutQuad = progress * (2 - progress);
                const current = Math.floor(easeOutQuad * target);
                
                stat.textContent = current.toLocaleString();
                
                if (progress < 1) {
                    requestAnimationFrame(updateIncrease);
                } else {
                    stat.textContent = target.toLocaleString();
                }
            }
            requestAnimationFrame(updateIncrease);
            
        } else if (animationType === 'decrease') {
            // Decrease animation (999+ to target)
            const startValue = 999;
            function updateDecrease(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                
                // Easing function for smooth animation
                const easeOutQuad = progress * (2 - progress);
                const current = Math.floor(startValue - (easeOutQuad * (startValue - target)));
                
                stat.textContent = current.toLocaleString();
                
                if (progress < 1) {
                    requestAnimationFrame(updateDecrease);
                } else {
                    stat.textContent = target.toLocaleString();
                }
            }
            requestAnimationFrame(updateDecrease);
        }
    });
}

// Create shimmer effect for cards
function createCardsShimmer() {
    const shimmerContainer = document.querySelector('.cards-shimmer');
    if (!shimmerContainer) return;
    
    function createShimmer() {
        const shimmer = document.createElement('div');
        shimmer.className = 'shimmer-particle';
        
        // Random size between 2px and 6px
        const size = Math.random() * 4 + 2;
        shimmer.style.width = size + 'px';
        shimmer.style.height = size + 'px';
        
        // Random horizontal position
        shimmer.style.left = Math.random() * 100 + '%';
        
        // Random animation duration between 2s and 4s
        const duration = Math.random() * 2 + 2;
        shimmer.style.animationDuration = duration + 's';
        
        // Random delay
        shimmer.style.animationDelay = Math.random() * 1 + 's';
        
        shimmerContainer.appendChild(shimmer);
        
        // Remove shimmer after animation completes
        setTimeout(() => {
            shimmer.remove();
        }, (duration + 1) * 1000);
    }
    
    // Create initial shimmers
    for (let i = 0; i < 8; i++) {
        setTimeout(() => createShimmer(), i * 300);
    }
    
    // Continuously create new shimmers
    setInterval(() => {
        createShimmer();
    }, 600);
}

// Check if products are in cart and update buttons
function checkAllProductsInCart() {
    <?php if (isset($_SESSION['user_id'])): ?>
    const productCards = document.querySelectorAll('.product-card-modern');
    productCards.forEach(card => {
        const addToCartBtn = card.querySelector('.btn-cart');
        if (addToCartBtn) {
            const onclickAttr = addToCartBtn.getAttribute('onclick');
            if (onclickAttr) {
                const match = onclickAttr.match(/addToCart\((\d+)\)/);
                if (match && match[1]) {
                    const productId = match[1];
                    checkProductInCart(productId, addToCartBtn);
                }
            }
        }
    });
    <?php endif; ?>
}

// Check if a specific product is in cart
function checkProductInCart(productId, button) {
    fetch('ajax/check_cart_item.php?product_id=' + productId)
        .then(response => response.json())
        .then(data => {
            if (data.in_cart) {
                button.textContent = 'In Cart';
                button.classList.add('added-to-cart');
            }
        })
        .catch(error => console.error('Error checking cart:', error));
}

// Update card count dynamically
function updateCardsCount() {
    <?php if (isset($_SESSION['user_id'])): ?>
    fetch('ajax/get_user_cards_count.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const cardCountElement = document.querySelector('.cards-number');
                if (cardCountElement) {
                    const newCount = data.count;
                    cardCountElement.setAttribute('data-target', newCount);
                    
                    // Animate to new count
                    const duration = 1000;
                    const startTime = performance.now();
                    const startValue = parseInt(cardCountElement.textContent) || 0;
                    
                    function updateCount(currentTime) {
                        const elapsed = currentTime - startTime;
                        const progress = Math.min(elapsed / duration, 1);
                        const easeOutQuad = progress * (2 - progress);
                        const current = Math.floor(startValue + (easeOutQuad * (newCount - startValue)));
                        
                        cardCountElement.textContent = current;
                        
                        if (progress < 1) {
                            requestAnimationFrame(updateCount);
                        } else {
                            cardCountElement.textContent = newCount;
                        }
                    }
                    requestAnimationFrame(updateCount);
                }
            }
        })
        .catch(error => console.error('Error updating card count:', error));
    <?php endif; ?>
}

</script>

<?php require_once 'includes/footer.php'; ?>
