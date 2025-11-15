<?php
// Start session for normal page loads
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../config/database.php';

// Initialize database connection for non-AJAX requests
$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Get filters
$category_filter = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Get categories for filter
$stmt = $db->prepare("SELECT * FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build products query
$where_conditions = [];
$params = [];

$base_query = "
    SELECT p.*, c.name as category_name, pi.image_path, p.delivery_charges,
           (SELECT COUNT(*) FROM reviews WHERE product_id = p.id) as review_count,
           (SELECT AVG(rating) FROM reviews WHERE product_id = p.id) as avg_rating
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
    WHERE 1=1
";

if ($category_filter) {
    $where_conditions[] = "p.category_id = ?";
    $params[] = $category_filter;
}

if ($status_filter) {
    $where_conditions[] = "p.status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $where_conditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($where_conditions)) {
    $base_query .= " AND " . implode(" AND ", $where_conditions);
}

$base_query .= " ORDER BY p.id DESC";

$stmt = $db->prepare($base_query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats for page header
$stmt = $db->prepare("SELECT COUNT(*) FROM products");
$stmt->execute();
$total_products = $stmt->fetchColumn();

// Check if views column exists, if not set to 0
try {
    $stmt = $db->prepare("SELECT SUM(views) FROM products");
    $stmt->execute();
    $total_views = $stmt->fetchColumn() ?: 0;
} catch (PDOException $e) {
    // Column doesn't exist, set to 0
    $total_views = 0;
}

$stmt = $db->prepare("SELECT SUM(stock_count) FROM products");
$stmt->execute();
$total_stock = $stmt->fetchColumn() ?: 0;

$stmt = $db->prepare("SELECT SUM(sales_count) FROM products");
$stmt->execute();
$total_sales = $stmt->fetchColumn() ?: 0;

$page_title = "Products Management";
require_once 'includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Modern Gradient Stats Section -->
<div class="row mb-3">
    <div class="col-12">
        <div class="stats-container-modern">
            <div class="stat-box-modern gradient-blue">
                <div class="stat-icon-modern">
                    <i class="fas fa-box"></i>
                </div>
                <div class="stat-content-modern">
                    <h3 class="stat-number" id="totalProductsStat"><?php echo number_format($total_products); ?></h3>
                    <p class="stat-label">Total Products</p>
                </div>
            </div>

            <div class="stat-box-modern gradient-purple">
                <div class="stat-icon-modern">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-content-modern">
                    <h3 class="stat-number"><?php echo number_format($total_views); ?></h3>
                    <p class="stat-label">Total Views</p>
                </div>
            </div>

            <div class="stat-box-modern gradient-green">
                <div class="stat-icon-modern">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stat-content-modern">
                    <h3 class="stat-number"><?php echo number_format($total_stock); ?></h3>
                    <p class="stat-label">Total Stock</p>
                </div>
            </div>

            <div class="stat-box-modern gradient-orange">
                <div class="stat-icon-modern">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-content-modern">
                    <h3 class="stat-number"><?php echo number_format($total_sales); ?></h3>
                    <p class="stat-label">Total Sales</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modern Search Section -->
<div class="row mb-3">
    <div class="col-12">
        <div class="search-card">
            <div class="search-header">
                <div class="search-title">
                    <i class="fas fa-search me-2"></i>Find Products
                </div>
                <div class="search-actions">
                    <a href="products.php" class="btn btn-clear-modern">
                        <i class="fas fa-redo me-2"></i>Clear Search
                    </a>
                    <a href="bulk-import-products.php" class="btn btn-warning me-2">
                        <i class="fas fa-file-import me-2"></i>Bulk Import Products
                    </a>
                    <a href="add-product.php" class="btn-export-modern">
                        <i class="fas fa-plus me-2"></i>Add New Product
                    </a>
                </div>
            </div>

            <form method="GET" id="searchForm" class="search-form">
                <div class="search-input-group">
                    <div class="search-input-modern">
                        <div class="search-input-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text"
                                   id="searchInput"
                                   name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search by name or description..."
                                   class="search-field">
                            <button type="submit" class="search-btn-modern">
                                <i class="fas fa-search"></i>
                                <span>Search</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modern Filters Section -->
<div class="row mb-3 filters-row">
    <div class="col-12">
        <div class="filters-card">
            <div class="filters-header">
                <div class="filters-title">
                    <i class="fas fa-filter me-2"></i>Filter Products
                </div>
            </div>

            <form method="GET" id="filterForm" class="filters-form">
                <div class="filters-grid">
                    <!-- Status Filter -->
                    <div class="filter-group">
                        <label class="filter-label">Product Status</label>
                        <div class="custom-dropdown-modern" id="statusDropdown">
                            <div class="dropdown-selected-modern" onclick="toggleModernDropdown('statusDropdown')">
                                <span id="selectedStatus">
                                    <?php echo $status_filter ? htmlspecialchars($status_filter) : 'All Statuses'; ?>
                                </span>
                                <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                            </div>
                            <div class="dropdown-options-modern">
                                <div class="dropdown-option-modern <?php echo !$status_filter ? 'selected' : ''; ?>"
                                     onclick="selectStatus('', 'All Statuses')">
                                    <i class="fas fa-list me-2"></i>All Statuses
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'In Stock' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('In Stock', 'In Stock')">
                                    <span class="status-dot status-in-stock"></span>In Stock
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'Out of Stock' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('Out of Stock', 'Out of Stock')">
                                    <span class="status-dot status-out-of-stock"></span>Out of Stock
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'Limited' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('Limited', 'Limited')">
                                    <span class="status-dot status-limited"></span>Limited
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($status_filter); ?>">
                    </div>

                    <!-- Category Filter -->
                    <div class="filter-group">
                        <label class="filter-label">Category</label>
                        <div class="custom-dropdown-modern" id="categoryDropdown">
                            <div class="dropdown-selected-modern" onclick="toggleModernDropdown('categoryDropdown')">
                                <span id="selectedCategory">
                                    <?php
                                    if ($category_filter) {
                                        $cat_name = '';
                                        foreach ($categories as $cat) {
                                            if ($cat['id'] == $category_filter) {
                                                $cat_name = $cat['name'];
                                                break;
                                            }
                                        }
                                        echo htmlspecialchars($cat_name);
                                    } else {
                                        echo 'All Categories';
                                    }
                                    ?>
                                </span>
                                <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                            </div>
                            <div class="dropdown-options-modern">
                                <div class="dropdown-option-modern <?php echo !$category_filter ? 'selected' : ''; ?>"
                                     onclick="selectCategory('', 'All Categories')">
                                    <i class="fas fa-th-large me-2"></i>All Categories
                                </div>
                                <?php foreach ($categories as $category): ?>
                                    <div class="dropdown-option-modern <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>"
                                         onclick="selectCategory('<?php echo htmlspecialchars($category['id']); ?>', '<?php echo htmlspecialchars($category['name']); ?>')">
                                        <i class="fas fa-tag me-2"></i><?php echo htmlspecialchars($category['name']); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="hidden" name="category" id="categoryInput" value="<?php echo htmlspecialchars($category_filter); ?>">
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mobile Action Bar (Hidden on Desktop) -->
<div class="mobile-action-bar">
    <button class="mobile-action-btn gradient-purple" onclick="window.location.href='bulk-import-products.php'" title="Bulk Import">
        <div class="mobile-btn-icon">
            <span class="icon-letter">B</span>
        </div>
        <span class="mobile-btn-label">Bulk Import</span>
    </button>
    
    <button class="mobile-action-btn gradient-blue" onclick="window.location.href='add-product.php'" title="Add Product">
        <div class="mobile-btn-icon">
            <span class="icon-letter">A</span>
        </div>
        <span class="mobile-btn-label">Add Product</span>
    </button>
    
    <button class="mobile-action-btn gradient-green" id="mobileSelectBtn" onclick="toggleSelectAllProducts()" title="Select All">
        <div class="mobile-btn-icon">
            <span class="icon-letter">S</span>
        </div>
        <span class="mobile-btn-label">Select All</span>
    </button>
    
    <button class="mobile-action-btn gradient-red" id="mobileDeleteBtn" onclick="deleteSelectedMobile()" disabled title="Delete Selected">
        <div class="mobile-btn-icon">
            <span class="icon-letter">D</span>
        </div>
        <span class="mobile-btn-label">Delete</span>
    </button>
</div>

<!-- Mobile Filter Buttons (Mini) -->
<div class="mobile-filter-section">
    <div class="mobile-filter-buttons">
        <button class="mobile-filter-btn" onclick="toggleModernDropdown('statusDropdown')">
            <i class="fas fa-box"></i>
            <span>Status</span>
            <i class="fas fa-chevron-down"></i>
        </button>
        <button class="mobile-filter-btn" onclick="toggleModernDropdown('categoryDropdown')">
            <i class="fas fa-tag"></i>
            <span>Category</span>
            <i class="fas fa-chevron-down"></i>
        </button>
    </div>
    
    <!-- Mobile Dropdowns Container -->
    <div class="mobile-dropdowns-container">
        <div class="custom-dropdown-modern" id="statusDropdown" style="display: none;">
            <div class="dropdown-options-modern" style="position: relative; top: 0; border-radius: 12px; border-top: 2px solid var(--primary-color);">
                <div class="dropdown-option-modern <?php echo !$status_filter ? 'selected' : ''; ?>"
                     onclick="selectStatus('', 'All Statuses')">
                    <i class="fas fa-list me-2"></i>All Statuses
                </div>
                <div class="dropdown-option-modern <?php echo $status_filter === 'In Stock' ? 'selected' : ''; ?>"
                     onclick="selectStatus('In Stock', 'In Stock')">
                    <span class="status-dot status-in-stock"></span>In Stock
                </div>
                <div class="dropdown-option-modern <?php echo $status_filter === 'Out of Stock' ? 'selected' : ''; ?>"
                     onclick="selectStatus('Out of Stock', 'Out of Stock')">
                    <span class="status-dot status-out-of-stock"></span>Out of Stock
                </div>
                <div class="dropdown-option-modern <?php echo $status_filter === 'Limited' ? 'selected' : ''; ?>"
                     onclick="selectStatus('Limited', 'Limited')">
                    <span class="status-dot status-limited"></span>Limited
                </div>
            </div>
        </div>
        
        <div class="custom-dropdown-modern" id="categoryDropdown" style="display: none;">
            <div class="dropdown-options-modern" style="position: relative; top: 0; border-radius: 12px; border-top: 2px solid var(--primary-color);">
                <div class="dropdown-option-modern <?php echo !$category_filter ? 'selected' : ''; ?>"
                     onclick="selectCategory('', 'All Categories')">
                    <i class="fas fa-th-large me-2"></i>All Categories
                </div>
                <?php foreach ($categories as $category): ?>
                    <div class="dropdown-option-modern <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>"
                         onclick="selectCategory('<?php echo htmlspecialchars($category['id']); ?>', '<?php echo htmlspecialchars($category['name']); ?>')">
                        <i class="fas fa-tag me-2"></i><?php echo htmlspecialchars($category['name']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Products List with Bulk Actions -->
<div class="row products-row">
    <div class="col-12">
        <div class="products-list-card-modern">
            <div class="list-header-modern">
                <div class="list-header-left">
                    <div class="bulk-actions-modern">
                        <label class="checkbox-modern">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)">
                            <span class="checkmark-modern"></span>
                            <span class="checkbox-label">Select All</span>
                        </label>

                        <!-- Changed: modern persistent delete button (disabled when no selection) -->
                        <button class="btn-delete-selected-modern" id="deleteSelectedBtn" onclick="openDeleteSelectedModal()" disabled aria-disabled="true" title="Select products to enable">
                            <i class="fas fa-trash me-2"></i>
                            <span class="btn-delete-text">Delete Selected Products</span>
                            <span class="selected-count-badge" id="selectedCount">0</span>
                        </button>
                    </div>
                </div>
                <div class="list-header-right">
                    <div class="list-title-modern">
                        <i class="fas fa-list me-2"></i>
                        Products List <span class="product-count-badge" id="productCountBadge">(<?php echo count($products); ?>)</span>
                    </div>
                </div>
            </div>

            <?php if (!empty($products)): ?>
                <div class="products-list-modern" id="productsList">
                    <?php foreach ($products as $product): ?>
                        <div class="product-item-modern" data-product-id="<?php echo $product['id']; ?>" id="product-<?php echo $product['id']; ?>">
                            <div class="product-checkbox-section">
                                <label class="checkbox-modern">
                                    <input type="checkbox" class="product-checkbox" value="<?php echo $product['id']; ?>" onchange="updateBulkActions(); updateMobileBulkActions();">
                                    <span class="checkmark-modern"></span>
                                </label>
                            </div>

                            <div class="product-image-section-list">
                                <img src="<?php echo $product['image_path'] ? '../' . PRODUCT_IMAGES_DIR . $product['image_path'] : '../assets/images/no-image.jpg'; ?>"
                                     class="product-thumbnail-modern"
                                     alt="<?php echo htmlspecialchars($product['name']); ?>">
                                <span class="guaranteed-badge-modern">
                                    <i class="fas fa-check-circle"></i> Guaranteed
                                </span>
                            </div>

                            <div class="product-details-modern">
                                <h6 class="product-name-modern"><?php echo htmlspecialchars($product['name']); ?></h6>
                                <div class="product-meta-modern">
                                    <span class="product-id-modern">
                                        <i class="fas fa-fingerprint me-1"></i>ID: <?php echo $product['id']; ?>
                                    </span>
                                    <span class="product-category-modern">
                                        <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($product['category_name']); ?>
                                    </span>
                                    <span class="product-stock-modern">
                                        <i class="fas fa-boxes me-1"></i>Stock: <?php echo $product['stock_count']; ?>
                                    </span>
                                </div>
                            </div>

                            <div class="product-price-section-list">
                                <div class="price-modern">
                                    <?php if ($product['discounted_price'] && $product['discounted_price'] < $product['original_price']): ?>
                                        <span class="discounted-price-modern"><?php echo formatPrice($product['discounted_price']); ?></span>
                                        <span class="original-price-modern"><?php echo formatPrice($product['original_price']); ?></span>
                                    <?php else: ?>
                                        <span class="discounted-price-modern"><?php echo formatPrice($product['original_price']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="product-actions-modern">
                                <button class="btn-action-modern btn-view-modern" onclick="viewProduct(<?php echo $product['id']; ?>)" title="View Product">
                                    <i class="fas fa-eye"></i>
                                    <span>View</span>
                                </button>
                                <button class="btn-action-modern btn-edit-modern" onclick="editProduct(<?php echo $product['id']; ?>)" title="Edit Product">
                                    <i class="fas fa-edit"></i>
                                    <span>Edit</span>
                                </button>
                                <button class="btn-action-modern btn-delete-modern" onclick="deleteSingleProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>')" title="Delete Product">
                                    <i class="fas fa-trash-alt"></i>
                                    <span>Delete</span>
                                </button>
                            </div>
                            
                            <!-- Mobile Vertical Action Icons -->
                            <div class="product-actions-mobile-vertical">
                                <button class="mobile-icon-btn mobile-icon-view" onclick="viewProduct(<?php echo $product['id']; ?>)" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="mobile-icon-btn mobile-icon-edit" onclick="editProduct(<?php echo $product['id']; ?>)" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="mobile-icon-btn mobile-icon-delete" onclick="deleteSingleProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>')" title="Delete">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-modern">
                    <div class="empty-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <h4>No products found</h4>
                    <p class="text-muted">No products match your current filters.</p>
                    <a href="add-product.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add Your First Product
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Confirmation Modal for bulk delete -->
<div id="deleteConfirmModal" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h4><i class="fas fa-exclamation-triangle me-2" style="color: #f59e0b;"></i>Confirm Deletion</h4>
        </div>
        <div class="modal-body">
            <p id="deleteModalMessage">Are you sure you want to delete the selected products? This action cannot be undone.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-modal-cancel" id="cancelDeleteBtn">
                <i class="fas fa-times me-2"></i>Cancel
            </button>
            <button class="btn-modal-confirm" id="confirmDeleteBtn">
                <i class="fas fa-trash me-2"></i>Confirm Delete
            </button>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>

<script>
// Modern Dropdown Functions
function toggleModernDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    
    // Check if we're on mobile
    const isMobile = window.innerWidth <= 768;
    
    if (isMobile) {
        // Mobile: toggle display of dropdown container in mobile-dropdowns-container
        const mobileDropdown = document.querySelector('.mobile-dropdowns-container #' + dropdownId);
        if (mobileDropdown) {
            const isVisible = mobileDropdown.style.display === 'block';
            
            // Close all other dropdowns
            document.querySelectorAll('.mobile-dropdowns-container .custom-dropdown-modern').forEach(dd => {
                dd.style.display = 'none';
            });
            
            // Toggle current dropdown
            mobileDropdown.style.display = isVisible ? 'none' : 'block';
        }
    } else {
        // Desktop: use active class
        const isActive = dropdown.classList.contains('active');

        // Close all other dropdowns
        document.querySelectorAll('.custom-dropdown-modern').forEach(dd => {
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
}

// Select status
function selectStatus(value, text) {
    const statusInput = document.getElementById('statusInput');
    const statusDropdown = document.getElementById('statusDropdown');
    
    if (statusInput) statusInput.value = value;
    
    // Close dropdown (both desktop and mobile)
    if (statusDropdown) {
        statusDropdown.classList.remove('active');
    }
    
    // Close mobile dropdown
    const mobileStatusDropdown = document.querySelector('.mobile-dropdowns-container #statusDropdown');
    if (mobileStatusDropdown) {
        mobileStatusDropdown.style.display = 'none';
    }

    // Update selected state
    document.querySelectorAll('#statusDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    event.target.classList.add('selected');

    // Submit form
    document.getElementById('filterForm').submit();
}

// Select category
function selectCategory(value, text) {
    const categoryInput = document.getElementById('categoryInput');
    const categoryDropdown = document.getElementById('categoryDropdown');
    
    if (categoryInput) categoryInput.value = value;
    
    // Close dropdown (both desktop and mobile)
    if (categoryDropdown) {
        categoryDropdown.classList.remove('active');
    }
    
    // Close mobile dropdown
    const mobileCategoryDropdown = document.querySelector('.mobile-dropdowns-container #categoryDropdown');
    if (mobileCategoryDropdown) {
        mobileCategoryDropdown.style.display = 'none';
    }

    // Update selected state
    document.querySelectorAll('#categoryDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    event.target.classList.add('selected');

    // Submit form
    document.getElementById('filterForm').submit();
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    const isDropdown = event.target.closest('.custom-dropdown-modern');
    const isFilterBtn = event.target.closest('.mobile-filter-btn');
    
    if (!isDropdown && !isFilterBtn) {
        // Desktop: remove active class
        document.querySelectorAll('.custom-dropdown-modern').forEach(dd => {
            dd.classList.remove('active');
        });
        
        // Mobile: hide dropdowns
        if (window.innerWidth <= 768) {
            document.querySelectorAll('.mobile-dropdowns-container .custom-dropdown-modern').forEach(dd => {
                dd.style.display = 'none';
            });
        }
    }
});

// Product actions
function viewProduct(productId) {
    window.open('../product.php?id=' + productId, '_blank');
}

function editProduct(productId) {
    window.location.href = 'edit-product.php?id=' + productId;
}

// Select All / Deselect All functionality for products
function toggleSelectAllProducts() {
    const checkboxes = document.querySelectorAll('.product-checkbox');
    const selectBtn = document.getElementById('mobileSelectBtn');
    
    // Check if all are currently selected
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    if (allChecked) {
        // Deselect all
        checkboxes.forEach(cb => cb.checked = false);
        selectBtn.classList.remove('active');
        selectBtn.querySelector('.mobile-btn-label').textContent = 'Select All';
    } else {
        // Select all
        checkboxes.forEach(cb => cb.checked = true);
        selectBtn.classList.add('active');
        selectBtn.querySelector('.mobile-btn-label').textContent = 'Deselect All';
    }
    
    updateMobileBulkActions();
    updateBulkActions();
}

function updateMobileBulkActions() {
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    const deleteBtn = document.getElementById('mobileDeleteBtn');
    
    if (checkedBoxes.length > 0) {
        deleteBtn.disabled = false;
        deleteBtn.querySelector('.mobile-btn-label').textContent = `Delete (${checkedBoxes.length})`;
    } else {
        deleteBtn.disabled = true;
        deleteBtn.querySelector('.mobile-btn-label').textContent = 'Delete';
    }
}

function deleteSelectedMobile() {
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    if (checkedBoxes.length === 0) return;
    
    const productIds = Array.from(checkedBoxes).map(cb => cb.value);
    openDeleteSelectedModal();
}

// Bulk Selection Functions
function toggleSelectAll(checkbox) {
    const productCheckboxes = document.querySelectorAll('.product-checkbox');
    productCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    
    // Update mobile delete button
    updateMobileBulkActions();
    updateBulkActions();
}

function updateBulkActions() {
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    const selectedCount = document.getElementById('selectedCount');

    selectedCount.textContent = checkedBoxes.length;

    if (checkedBoxes.length > 0) {
        deleteBtn.removeAttribute('disabled');
        deleteBtn.setAttribute('aria-disabled', 'false');
        deleteBtn.classList.add('active');
        deleteBtn.title = 'Delete selected products';
    } else {
        deleteBtn.setAttribute('disabled', 'disabled');
        deleteBtn.setAttribute('aria-disabled', 'true');
        deleteBtn.classList.remove('active');
        deleteBtn.title = 'Select products to enable';
    }

    const allCheckboxes = document.querySelectorAll('.product-checkbox');
    selectAllCheckbox.checked = allCheckboxes.length > 0 && checkedBoxes.length === allCheckboxes.length;
}

// Open confirmation modal for bulk delete
function openDeleteSelectedModal() {
    const selectedCount = parseInt(document.getElementById('selectedCount').textContent || '0', 10);
    if (selectedCount === 0) {
        // safety - shouldn't be clickable when disabled, but guard anyway
        return;
    }

    // Update modal message for bulk delete
    const modalMessage = document.getElementById('deleteModalMessage');
    modalMessage.innerHTML = `Are you sure you want to delete <strong>${selectedCount} product${selectedCount > 1 ? 's' : ''}</strong>?<br><span style="color: #dc2626; font-size: 0.9rem;">This action cannot be undone.</span>`;

    const modal = document.getElementById('deleteConfirmModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');

    // attach handlers
    const cancelBtn = document.getElementById('cancelDeleteBtn');
    const confirmBtn = document.getElementById('confirmDeleteBtn');

    // Remove any existing handlers by cloning
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    const newCancelBtn = cancelBtn.cloneNode(true);
    cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

    // ensure no duplicate listeners
    newCancelBtn.onclick = closeDeleteModal;
    newConfirmBtn.onclick = deleteSelectedConfirmed;
}

// Close modal
function closeDeleteModal() {
    const modal = document.getElementById('deleteConfirmModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    
    // Reset confirm button state
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-trash me-2"></i>Confirm Delete';
    }
}

// Close modal when clicking on backdrop
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('deleteConfirmModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeDeleteModal();
            }
        });
    }
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('deleteConfirmModal');
            if (modal && modal.style.display === 'flex') {
                closeDeleteModal();
            }
        }
    });
    
    // Initialize bulk actions state on page load
    updateBulkActions();
    updateMobileBulkActions();
});

// Perform AJAX delete after confirmation
async function deleteSelectedConfirmed() {
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    const productIds = Array.from(checkedBoxes).map(cb => cb.value);

    if (productIds.length === 0) {
        closeDeleteModal();
        return;
    }
    
    console.log('🗑️ Starting bulk delete for products:', productIds);
    
    // Disable confirm button during processing
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Deleting...';

    try {
        const formData = new FormData();
        formData.append('product_ids', JSON.stringify(productIds));

        console.log('📤 Sending bulk delete request to: ajax/delete_product.php');
        console.log('📦 Payload:', JSON.stringify(productIds));

        const response = await fetch('ajax/delete_product.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        console.log('📥 Response status:', response.status, response.statusText);

        // Check if response is ok
        if (!response.ok) {
            console.error('HTTP Error:', response.status, response.statusText);
            throw new Error(`Server returned ${response.status}: ${response.statusText}`);
        }
        
        // Get response text first to debug
        const responseText = await response.text();
        console.log('✓ Server response received:', responseText);
        
        // Try to parse as JSON
        let result;
        try {
            result = JSON.parse(responseText);
            console.log('✓ Parsed JSON:', result);
        } catch (parseError) {
            console.error('✗ Failed to parse JSON. Raw response:', responseText);
            console.error('✗ Parse error:', parseError);
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = originalText;
            closeDeleteModal();
            showToast('error', '⚠️ Server returned invalid response. Check browser console for details.');
            return;
        }

        // Check for success
        if (result.success === true) {
            console.log('✓ Bulk delete operation successful');
            // Remove products from DOM with animation
            productIds.forEach(id => {
                const productCard = document.getElementById('product-' + id);
                if (productCard) {
                    productCard.style.transition = 'all 0.3s ease';
                    productCard.style.opacity = '0';
                    productCard.style.transform = 'translateX(-20px)';
                    setTimeout(() => {
                        productCard.remove();
                        updateProductCount();
                    }, 300);
                }
            });

            // Reset bulk actions
            document.getElementById('selectAllCheckbox').checked = false;
            
            // Uncheck all individual product checkboxes that might still exist
            document.querySelectorAll('.product-checkbox').forEach(cb => {
                cb.checked = false;
            });
            
            updateBulkActions();
            updateMobileBulkActions();

            closeDeleteModal();

            // Show success message
            // const count = productIds.length;
            // showToast('success', `✅ ${count} product${count > 1 ? 's' : ''} deleted successfully!`);
        } else {
            // Re-enable button on error
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = originalText;
            closeDeleteModal();
            showToast('error', result.message || 'Error deleting products.');
        }
    } catch (err) {
        console.error('Delete error:', err);
        // Re-enable button on error
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = originalText;
        closeDeleteModal();
        showToast('error', 'An error occurred while deleting products. Check console for details.');
    }
}
// Delete single product - Using modal for confirmation
function deleteSingleProduct(productId, productName) {
    // Store the product details globally for the modal
    window.pendingSingleDelete = { productId, productName };
    
    // Update modal message for single product
    const modalMessage = document.getElementById('deleteModalMessage');
    modalMessage.innerHTML = `Are you sure you want to delete <strong>"${productName}"</strong>?<br><span style="color: #dc2626; font-size: 0.9rem;">This action cannot be undone.</span>`;
    
    // Show modal
    const modal = document.getElementById('deleteConfirmModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    
    // Set up single delete handler
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    const cancelBtn = document.getElementById('cancelDeleteBtn');
    
    // Remove any existing handlers
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    const newCancelBtn = cancelBtn.cloneNode(true);
    cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
    
    // Add new handlers
    newCancelBtn.onclick = closeDeleteModal;
    newConfirmBtn.onclick = () => performSingleDelete(productId, productName);
}

// Perform single product deletion via AJAX
async function performSingleDelete(productId, productName) {
    console.log('🗑️ Starting delete for product:', productId, productName);
    
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Deleting...';
    
    try {
        const formData = new FormData();
        formData.append('product_ids', JSON.stringify([productId]));
        
        console.log('📤 Sending request to: ajax/delete_product.php');
        console.log('📦 Payload:', JSON.stringify([productId]));
        
        const response = await fetch('ajax/delete_product.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        console.log('📥 Response status:', response.status, response.statusText);
        
        // Check if response is ok
        if (!response.ok) {
            console.error('HTTP Error:', response.status, response.statusText);
            throw new Error(`Server returned ${response.status}: ${response.statusText}`);
        }
        
        // Get response text first to debug
        const responseText = await response.text();
        console.log('✓ Server response received:', responseText);
        
        // Try to parse as JSON
        let result;
        try {
            result = JSON.parse(responseText);
            console.log('✓ Parsed JSON:', result);
        } catch (parseError) {
            console.error('✗ Failed to parse JSON. Raw response:', responseText);
            console.error('✗ Parse error:', parseError);
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = originalText;
            closeDeleteModal();
            showToast('error', '⚠️ Server returned invalid response. Check browser console for details.');
            return; // Don't throw, just return
        }
        
        // Check for success
        if (result.success === true) {
            console.log('✓ Delete operation successful');
            // Close modal first
            closeDeleteModal();
            
            // Remove product from DOM with animation
            const productCard = document.getElementById('product-' + productId);
            if (productCard) {
                productCard.style.transition = 'all 0.3s ease';
                productCard.style.opacity = '0';
                productCard.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    productCard.remove();
                    updateProductCount();
                }, 300);
            }
            
            // Show success message
            // showToast('success', '✅ Product deleted successfully!');
        } else {
            // Re-enable button on error
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = originalText;
            closeDeleteModal();
            showToast('error', result.message || 'Failed to delete product');
        }
    } catch (error) {
        console.error('Delete error:', error);
        // Re-enable button on error
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = originalText;
        closeDeleteModal();
        showToast('error', 'An error occurred while deleting the product. Check console for details.');
    }
}

// Update product count after deletion
function updateProductCount() {
    const productsList = document.querySelectorAll('.product-item-modern');
    const count = productsList.length;
    
    const badge = document.getElementById('productCountBadge');
    if (badge) {
        badge.textContent = `(${count})`;
    }
    
    const totalStat = document.getElementById('totalProductsStat');
    if (totalStat) {
        totalStat.textContent = count.toLocaleString();
    }
    
    // Show empty state if no products
    if (count === 0) {
        document.getElementById('productsList').innerHTML = `
            <div class="empty-state-modern">
                <div class="empty-icon">
                    <i class="fas fa-box"></i>
                </div>
                <h4>No products found</h4>
                <p class="text-muted">No products match your current filters.</p>
                <a href="add-product.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add Your First Product
                </a>
            </div>
        `;
    }
}

// Toast notification function with improved styling and close button
function showToast(type, message, duration = 3000) {
    // Create toast container if it doesn't exist
    let toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    
    // Create toast content
    const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
    toast.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span style="flex: 1;">${message}</span>
        <button class="toast-close" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Add to container
    toastContainer.appendChild(toast);
    
    // Show toast with animation
    setTimeout(() => {
        toast.classList.add('show');
    }, 100);
    
    // Close button functionality
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => {
        removeToast(toast);
    });
    
    // Auto-dismiss after duration
    setTimeout(() => {
        removeToast(toast);
    }, duration);
}

// Helper function to remove toast with animation
function removeToast(toast) {
    if (!toast || !toast.parentElement) return;
    
    toast.classList.remove('show');
    setTimeout(() => {
        if (toast.parentElement) {
            toast.remove();
        }
        
        // Remove container if empty
        const toastContainer = document.querySelector('.toast-container');
        if (toastContainer && toastContainer.children.length === 0) {
            toastContainer.remove();
        }
    }, 400);
}

// Initialize tooltips and other components
$(document).ready(function() {
    // Initialize tooltips
    $('[title]').tooltip();

    // Auto-submit search on enter
    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) {
            document.getElementById('searchForm').submit();
        }
    });

    // Add loading animation to refresh button
    $('.btn-refresh').on('click', function() {
        const btn = $(this);
        const originalHtml = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Refreshing...');
        btn.prop('disabled', true);

        setTimeout(function() {
            btn.html(originalHtml);
            btn.prop('disabled', false);
        }, 1000);
    });
});
</script>

<style>
* {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    box-sizing: border-box;
}

/* Prevent any element from causing horizontal scroll */
img, 
table, 
td, 
blockquote, 
code, 
pre, 
textarea, 
input, 
video, 
svg {
    max-width: 100%;
}

/* Page Background */
body {
    background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
}

/* Modern Gradient Stats Section */
.stats-container-modern {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin: 0;
    padding: 0;
    max-width: 100%;
    overflow: visible;
}

@media (min-width: 1400px) {
    .stats-container-modern {
        grid-template-columns: repeat(4, 1fr);
        gap: 28px;
    }
}

@media (max-width: 1200px) {
    .stats-container-modern {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
}

.stat-box-modern {
    border-radius: 20px;
    padding: 28px 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    min-height: 140px;
}

@media (max-width: 1400px) {
    .stat-box-modern {
        padding: 24px 20px;
        gap: 16px;
        min-height: 130px;
    }
}

@media (max-width: 1200px) {
    .stat-box-modern {
        padding: 20px 18px;
        gap: 14px;
        min-height: 120px;
    }
}

.stat-box-modern::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    filter: blur(30px);
}

.stat-box-modern:hover {
    transform: translateY(-10px);
    box-shadow: 0 18px 45px rgba(0, 0, 0, 0.18);
}

.gradient-blue {
    background: linear-gradient(135deg, #0058a3 0%, #003d73 100%);
}

.gradient-purple {
    background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
}

.gradient-green {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}

.gradient-orange {
    background: linear-gradient(135deg, #ff6b00 0%, #d95800 100%);
}

.stat-icon-modern {
    width: 70px;
    height: 70px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: white;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
    flex-shrink: 0;
}

@media (max-width: 1400px) {
    .stat-icon-modern {
        width: 65px;
        height: 65px;
        font-size: 1.8rem;
    }
}

@media (max-width: 1200px) {
    .stat-icon-modern {
        width: 60px;
        height: 60px;
        font-size: 1.6rem;
    }
}

.stat-content-modern {
    flex: 1;
    position: relative;
    z-index: 1;
}

.stat-number {
    font-size: 2.4rem;
    font-weight: 800;
    color: white;
    margin: 0 0 6px 0;
    line-height: 1;
    text-shadow: 0 3px 12px rgba(0, 0, 0, 0.25);
}

.stat-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 1px;
}

@media (max-width: 1400px) {
    .stat-number {
        font-size: 2.2rem;
    }
    
    .stat-label {
        font-size: 0.85rem;
        letter-spacing: 0.8px;
    }
}

@media (max-width: 1200px) {
    .stat-number {
        font-size: 2rem;
    }
    
    .stat-label {
        font-size: 0.8rem;
        letter-spacing: 0.6px;
    }
}

/* Filters Card */
.filters-card {
    background: white;
    border-radius: 20px;
    padding: 28px 32px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
    position: relative;
    z-index: 1000;
}

/* Ensure filters row has higher z-index */
.filters-row {
    position: relative;
    z-index: 1000;
}

/* Ensure products row has lower z-index */
.products-row {
    position: relative;
    z-index: 1;
}

/* Fallback for browsers that support :has() */
.row:has(.filters-card) {
    position: relative;
    z-index: 100;
}

.row:has(.products-list-card-modern) {
    position: relative;
    z-index: 1;
}

@media (max-width: 1400px) {
    .filters-card {
        padding: 20px;
    }
}

@media (max-width: 1200px) {
    .filters-card {
        padding: 18px;
    }
}

.filters-header {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-bottom: 32px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e9ecef;
    position: relative;
}

.filters-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 3px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border-radius: 2px;
}

.filters-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
    overflow: visible;
}

.filter-group {
    display: flex;
    flex-direction: column;
    overflow: visible;
    position: relative;
    z-index: 100;
}

.filters-form {
    overflow: visible;
    position: relative;
    z-index: 100;
}

.filter-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    display: block;
}

/* Modern Dropdowns */
.custom-dropdown-modern {
    position: relative;
    width: 100%;
    z-index: 100;
}

.custom-dropdown-modern.active {
    z-index: 1000;
}

.dropdown-selected-modern {
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 14px 18px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.95rem;
    font-weight: 500;
    color: #1f2937;
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.dropdown-selected-modern:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.15);
}

.custom-dropdown-modern.active .dropdown-selected-modern {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
    border-radius: 12px 12px 0 0;
}

.dropdown-arrow-modern {
    font-size: 0.8rem;
    color: #6b7280;
    transition: transform 0.3s ease;
}

.custom-dropdown-modern.active .dropdown-arrow-modern {
    transform: rotate(180deg);
    color: var(--primary-color);
}

.dropdown-options-modern {
    position: absolute;
    top: calc(100% - 2px);
    left: 0;
    right: 0;
    background: white;
    border: 2px solid var(--primary-color);
    border-top: none;
    border-radius: 0 0 12px 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    z-index: 1001;
    max-height: 0;
    overflow: visible;
    opacity: 0;
    transform: translateY(-10px);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.custom-dropdown-modern.active .dropdown-options-modern {
    max-height: 300px;
    opacity: 1;
    transform: translateY(0);
    overflow-y: auto;
    overflow-x: visible;
}

.dropdown-option-modern {
    padding: 12px 18px;
    cursor: pointer;
    font-size: 0.9rem;
    color: #374151;
    transition: all 0.2s ease;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    align-items: center;
}

.dropdown-option-modern:last-child {
    border-bottom: none;
}

.dropdown-option-modern:hover {
    background: #f9fafb;
    padding-left: 24px;
}

.dropdown-option-modern.selected {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    font-weight: 600;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 8px;
    display: inline-block;
}

.status-in-stock { background-color: #10b981; }
.status-out-of-stock { background-color: #ef4444; }
.status-limited { background-color: #f59e0b; }

/* Search Card */
.search-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    margin-bottom: 20px;
    max-width: 100%;
    overflow: hidden;
}

@media (max-width: 1400px) {
    .search-card {
        padding: 20px;
    }
}

@media (max-width: 1200px) {
    .search-card {
        padding: 18px;
    }
}

.search-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap;
    gap: 12px;
}

@media (max-width: 1200px) {
    .search-header {
        margin-bottom: 16px;
        padding-bottom: 12px;
    }
}

.search-title {
    font-size: 1.15rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0;
}

@media (max-width: 1200px) {
    .search-title {
        font-size: 1.05rem;
    }
}

.search-actions {
    display: flex;
    gap: 12px;
}

.btn-clear-modern {
    background: white;
    border: 2px solid #e9ecef;
    color: #6b7280;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-clear-modern:hover {
    background: #f8f9fa;
    border-color: #d1d5db;
    color: #374151;
    text-decoration: none;
}

.search-input-group {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.search-input-modern {
    position: relative;
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 300px;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
    max-width: 100%;
}

.search-icon {
    position: absolute;
    left: 14px;
    color: #9ca3af;
    font-size: 0.9rem;
    z-index: 1;
}

.search-field {
    flex: 1;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px 110px 14px 42px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: white;
    width: 100%;
    max-width: 100%;
}

.search-field:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
}

.search-btn-modern {
    position: absolute;
    right: 6px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border: none;
    border-radius: 8px;
    width: auto;
    min-width: 80px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
    padding: 0 12px;
    font-size: 0.85rem;
    font-weight: 500;
}

.search-btn-modern:hover {
    transform: scale(1.05);
}

.btn-export-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border: none;
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
    white-space: nowrap;
}

@media (max-width: 1400px) {
    .btn-export-modern {
        padding: 9px 16px;
        font-size: 0.85rem;
    }
}

@media (max-width: 1200px) {
    .btn-export-modern {
        padding: 8px 14px;
        font-size: 0.8rem;
    }
}

.btn-export-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
    color: white;
}

/* Products List Card */
.products-list-card-modern {
    background: white;
    border-radius: 24px;
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-top: 30px;
    max-width: 100%;
    width: 100%;
    position: relative;
    z-index: 1;
}

.list-header-modern {
    background: linear-gradient(135deg, #0058a3 0%, #003d73 100%);
    padding: 24px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: none;
    flex-wrap: wrap;
    gap: 16px;
}

@media (max-width: 1400px) {
    .list-header-modern {
        padding: 22px 24px;
    }
}

@media (max-width: 1200px) {
    .list-header-modern {
        padding: 20px 20px;
    }
}

/* Improved bulk actions spacing and layout */
.bulk-actions-modern {
    display: flex;
    align-items: center;
    gap: 14px; /* spacing between select all and delete button */
}

/* Ensure stacked layout on small screens keeps comfortable spacing */
@media (max-width: 768px) {
    .bulk-actions-modern {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        width: 100%;
    }

    /* ...existing responsive overrides... */
}

.checkbox-modern {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
}

.checkbox-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: white;
}

@media (max-width: 1200px) {
    .checkbox-label {
        font-size: 0.85rem;
    }
}

@media (max-width: 992px) {
    .checkbox-label {
        display: none;
    }
}

.list-title-modern {
    font-size: 1.2rem;
    font-weight: 700;
    color: white;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

@media (max-width: 1400px) {
    .list-title-modern {
        font-size: 1.15rem;
    }
}

@media (max-width: 1200px) {
    .list-title-modern {
        font-size: 1.1rem;
    }
}

.product-count-badge {
    background: rgba(255, 255, 255, 0.25);
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 700;
    margin-left: 8px;
}

@media (max-width: 1200px) {
    .product-count-badge {
        font-size: 0.8rem;
        padding: 3px 10px;
    }
}

/* Products List */
.products-list-modern {
    padding: 0;
    background: #fafbfc;
}

.product-item-modern {
    display: flex;
    align-items: center;
    padding: 20px 28px;
    border-bottom: 1px solid #e9ecef;
    transition: all 0.3s ease;
    background: white;
}

@media (max-width: 1400px) {
    .product-item-modern {
        padding: 18px 24px;
    }
}

@media (max-width: 1200px) {
    .product-item-modern {
        padding: 16px 20px;
    }
}

.product-item-modern:hover {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    transform: translateX(6px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
}

.product-item-modern:last-child {
    border-bottom: none;
}

.product-checkbox-section {
    margin-right: 24px;
}

.product-image-section-list {
    position: relative;
    margin-right: 28px;
}

.product-thumbnail-modern {
    width: 80px;
    height: 80px;
    border-radius: 14px;
    object-fit: cover;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
    border: 2px solid #f1f5f9;
}

@media (max-width: 1400px) {
    .product-thumbnail-modern {
        width: 75px;
        height: 75px;
    }
}

@media (max-width: 1200px) {
    .product-thumbnail-modern {
        width: 70px;
        height: 70px;
    }
}

.guaranteed-badge-modern {
    position: absolute;
    top: -8px;
    right: -8px;
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: white;
    padding: 5px 10px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.5);
}

.product-details-modern {
    flex: 1;
    min-width: 0;
    padding-right: 20px;
}

.product-name-modern {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1a202c;
    margin: 0 0 10px 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

.product-meta-modern {
    display: flex;
    align-items: center;
    gap: 20px;
    font-size: 0.9rem;
    color: #64748b;
}

.product-id-modern,
.product-category-modern,
.product-stock-modern {
    display: flex;
    align-items: center;
    font-weight: 600;
}

.product-price-section-list {
    margin: 0 40px;
}

.price-modern {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
}

.discounted-price-modern {
    font-size: 1.4rem;
    font-weight: 800;
    background: linear-gradient(135deg, #0058a3 0%, #7c3aed 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

@media (max-width: 1400px) {
    .discounted-price-modern {
        font-size: 1.3rem;
    }
}

@media (max-width: 1200px) {
    .discounted-price-modern {
        font-size: 1.2rem;
    }
}

.original-price-modern {
    font-size: 0.95rem;
    color: #94a3b8;
    text-decoration: line-through;
    font-weight: 500;
}

.product-actions-modern {
    display: flex;
    gap: 12px;
}

.btn-action-modern {
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-width: 90px;
    justify-content: center;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

@media (max-width: 1400px) {
    .btn-action-modern {
        padding: 9px 14px;
        font-size: 0.8rem;
        min-width: 85px;
    }
}

@media (max-width: 1200px) {
    .btn-action-modern {
        padding: 8px 12px;
        font-size: 0.75rem;
        min-width: 80px;
        gap: 5px;
    }
}

.btn-view-modern {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    box-shadow: 0 6px 18px rgba(59, 130, 246, 0.35);
}

.btn-view-modern:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(59, 130, 246, 0.45);
}

.btn-edit-modern {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: white;
    box-shadow: 0 6px 18px rgba(5, 150, 105, 0.35);
}

.btn-edit-modern:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(5, 150, 105, 0.45);
}

.btn-delete-modern {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    box-shadow: 0 6px 18px rgba(239, 68, 68, 0.35);
}

.btn-delete-modern:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(239, 68, 68, 0.45);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 100px 40px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
}

.empty-icon {
    font-size: 5rem;
    color: #cbd5e0;
    margin-bottom: 28px;
}

.empty-state-modern h4 {
    color: #2d3748;
    margin-bottom: 12px;
    font-weight: 700;
    font-size: 1.5rem;
}

.empty-state-modern p {
    color: #718096;
    margin-bottom: 32px;
    max-width: 450px;
    margin-left: auto;
    margin-right: auto;
    font-size: 1.05rem;
}

/* Toast Notifications */
.toast-notification {
    position: fixed;
    top: 24px;
    right: 24px;
    background: white;
    padding: 18px 28px 18px 24px;
    border-radius: 14px;
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.18);
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 1rem;
    font-weight: 600;
    z-index: 10000;
    opacity: 0;
    transform: translateX(400px);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 300px;
    max-width: 500px;
    pointer-events: none;
}

.toast-notification.show {
    opacity: 1;
    transform: translateX(0);
    pointer-events: auto;
}

.toast-success {
    border-left: 5px solid #059669;
    color: #047857;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
}

.toast-success i {
    color: #059669;
    font-size: 1.2rem;
}

.toast-error {
    border-left: 5px solid #ef4444;
    color: #dc2626;
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
}

.toast-error i {
    color: #ef4444;
    font-size: 1.2rem;
}

.toast-notification .toast-close {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 1.2rem;
    color: #9ca3af;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.toast-notification .toast-close:hover {
    background: rgba(0, 0, 0, 0.1);
    color: #374151;
}

/* Toast container for stacking multiple toasts */
.toast-container {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 12px;
    pointer-events: none;
}

.toast-container .toast-notification {
    position: relative;
    top: auto;
    right: auto;
}

/* New styles for Delete Selected button */
.btn-delete-selected-modern {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #7c3aed 0%, #0058a3 100%);
    color: white;
    padding: 10px 18px;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(0, 88, 163, 0.18);
    transition: all 0.18s ease;
    min-height: 42px;
    font-size: 0.9rem;
    white-space: nowrap;
}

@media (max-width: 1400px) {
    .btn-delete-selected-modern {
        padding: 9px 16px;
        font-size: 0.85rem;
        gap: 6px;
    }
}

@media (max-width: 1200px) {
    .btn-delete-selected-modern {
        padding: 8px 14px;
        font-size: 0.8rem;
    }
    
    .btn-delete-text {
        display: none;
    }
    
    .btn-delete-selected-modern .fas {
        margin-right: 0;
    }
}

.btn-delete-selected-modern .selected-count-badge {
    background: rgba(255,255,255,0.12);
    color: rgba(255,255,255,0.95);
    padding: 4px 8px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.85rem;
    margin-left: 6px;
}

.btn-delete-selected-modern:hover {
    filter: brightness(1.04);
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(0, 88, 163, 0.2);
}

/* Disabled state */
.btn-delete-selected-modern[disabled],
.btn-delete-selected-modern[aria-disabled="true"] {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
    filter: none;
}

/* Active state (enabled) */
.btn-delete-selected-modern.active {
    outline: 0;
}

/* Modal styles */
.modal-overlay {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.6);
    z-index: 11000;
    padding: 24px;
}

.modal-card {
    background: #fff;
    border-radius: 12px;
    max-width: 520px;
    width: 100%;
    box-shadow: 0 20px 50px rgba(15,23,42,0.4);
    overflow: hidden;
    animation: modalIn 220ms ease;
}

@keyframes modalIn {
    from { transform: translateY(6px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.modal-header {
    padding: 24px 28px;
    border-bottom: 2px solid #f1f5f9;
    background: linear-gradient(135deg, #fafbfc 0%, #f8f9fa 100%);
}

.modal-header h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #111827;
    display: flex;
    align-items: center;
}

.modal-body {
    padding: 28px 28px;
    color: #4b5563;
    font-size: 1rem;
    line-height: 1.6;
}

.modal-body strong {
    color: #111827;
    font-weight: 700;
}

.modal-actions {
    padding: 20px 28px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    border-top: 2px solid #f1f5f9;
    background: #fafbfc;
}

/* Modal buttons */
.btn-modal-cancel {
    background: white;
    border: 2px solid #e5e7eb;
    color: #374151;
    padding: 12px 24px;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-modal-cancel:hover {
    background: #f9fafb;
    border-color: #d1d5db;
    transform: translateY(-1px);
}

.btn-modal-confirm {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    padding: 12px 24px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    font-weight: 700;
    font-size: 0.95rem;
    box-shadow: 0 8px 22px rgba(220, 38, 38, 0.25);
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-modal-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(220, 38, 38, 0.35);
}

.btn-modal-confirm:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Close modal on backdrop click */
.modal-overlay {
    cursor: pointer;
}

.modal-card {
    cursor: default;
}

/* Base responsive container */
.container-fluid {
    max-width: 100%;
    overflow-x: hidden;
}

/* Prevent horizontal scroll globally */
html, body {
    overflow-x: hidden;
    max-width: 100vw;
    position: relative;
}

* {
    box-sizing: border-box;
}

.content-wrapper,
.row,
.col-12,
.col-md-6,
.col-lg-4 {
    max-width: 100%;
    overflow-x: hidden;
}

.row {
    margin-left: 0;
    margin-right: 0;
}

.col-12 {
    padding-left: 15px;
    padding-right: 15px;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .stats-container-modern {
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
    }
    
    .search-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .search-actions {
        width: 100%;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .btn-clear-modern,
    .btn-export-modern,
    .btn-warning {
        flex: 1 1 calc(50% - 5px);
        min-width: 120px;
        text-align: center;
        justify-content: center;
        display: inline-flex;
        padding: 9px 14px;
        font-size: 0.85rem;
    }
    
    /* Make the bulk import button full width on medium screens */
    @media (max-width: 1000px) {
        .btn-warning {
            flex: 1 1 100%;
        }
    }
}

@media (max-width: 992px) {
    .product-item-modern {
        flex-wrap: wrap;
        padding: 20px 16px;
        gap: 12px;
    }
    
    .product-checkbox-section {
        margin-right: 16px;
    }
    
    .product-image-section-list {
        margin-right: 16px;
    }

    .product-details-modern {
        flex: 1;
        min-width: 200px;
        padding-right: 12px;
    }

    .product-price-section-list {
        margin: 0;
        order: 4;
        width: 100%;
        margin-top: 12px;
        padding-left: 106px;
    }
    
    .price-modern {
        align-items: flex-start;
    }

    .product-actions-modern {
        order: 5;
        width: 100%;
        margin-top: 12px;
        padding-left: 106px;
        flex-wrap: wrap;
    }

    .btn-action-modern {
        flex: 1;
        min-width: 90px;
    }
    
    .filters-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .product-checkbox-section {
        margin-right: 12px;
    }
    
    .product-image-section-list {
        margin-right: 12px;
    }
}

@media (max-width: 768px) {
    .stats-container-modern {
        grid-template-columns: 1fr;
        gap: 20px;
        padding: 0;
    }

    .stat-box-modern {
        padding: 24px 20px;
    }
    
    .search-card,
    .filters-card,
    .products-list-card-modern {
        border-radius: 12px;
        padding: 20px 16px;
    }
    
    .search-header {
        padding-bottom: 12px;
    }
    
    .search-input-modern {
        min-width: 100%;
    }
    
    .search-field {
        padding: 12px 90px 12px 38px;
        font-size: 0.9rem;
    }
    
    .search-btn-modern {
        min-width: 70px;
        height: 32px;
        font-size: 0.8rem;
    }
    
    .search-icon {
        left: 12px;
    }

    .list-header-modern {
        flex-direction: column;
        gap: 16px;
        align-items: stretch;
        padding: 20px 16px;
    }
    
    .list-header-left,
    .list-header-right {
        width: 100%;
    }

    .bulk-actions-modern {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        width: 100%;
    }
    
    .btn-delete-selected-modern {
        width: 100%;
        justify-content: center;
    }
    
    .checkbox-modern {
        width: 100%;
    }
    
    .list-title-modern {
        font-size: 1.1rem;
        justify-content: center;
    }

    .product-item-modern {
        padding: 16px 12px;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .product-checkbox-section {
        position: absolute;
        top: 16px;
        left: 12px;
        margin: 0;
    }
    
    .product-image-section-list {
        margin: 0 0 12px 0;
        width: 100%;
        display: flex;
        justify-content: center;
    }
    
    .product-thumbnail-modern {
        width: 100%;
        max-width: 200px;
        height: 200px;
    }
    
    .guaranteed-badge-modern {
        top: 0;
        right: 0;
    }
    
    .product-details-modern {
        width: 100%;
        padding: 0;
        min-width: 0;
    }
    
    .product-name-modern {
        white-space: normal;
        font-size: 1.05rem;
        margin-bottom: 8px;
        word-wrap: break-word;
        overflow-wrap: break-word;
        hyphens: auto;
    }
    
    .product-meta-modern {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .product-price-section-list {
        width: 100%;
        padding: 0;
        margin: 12px 0;
    }
    
    .price-modern {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }

    .product-actions-modern {
        width: 100%;
        padding: 0;
        margin-top: 12px;
        gap: 8px;
        display: grid;
        grid-template-columns: repeat(3, 1fr);
    }

    .btn-action-modern {
        padding: 10px 8px;
        min-width: 0;
        font-size: 0.85rem;
    }
    
    .btn-action-modern span {
        display: none;
    }
    
    .btn-action-modern i {
        margin: 0;
    }
}

@media (max-width: 576px) {
    /* Stats Section */
    .stats-container-modern {
        grid-template-columns: 1fr;
        gap: 12px;
        margin: 15px 0 20px 0;
    }
    
    .stat-number {
        font-size: 1.8rem;
    }

    .stat-icon-modern {
        width: 55px;
        height: 55px;
        font-size: 1.4rem;
    }
    
    .stat-box-modern {
        padding: 18px 14px;
        gap: 14px;
        min-height: 100px;
    }
    
    .stat-label {
        font-size: 0.75rem;
        letter-spacing: 0.5px;
    }
    
    /* Cards Padding */
    .search-card,
    .filters-card {
        padding: 16px 12px;
        border-radius: 12px;
    }
    
    .search-title,
    .filters-title {
        font-size: 1.1rem;
    }
    
    .search-header {
        margin-bottom: 16px;
        padding-bottom: 12px;
    }
    
    /* Action Buttons Stack */
    .search-actions {
        flex-direction: column;
        width: 100%;
        gap: 8px;
    }
    
    .btn-clear-modern,
    .btn-export-modern,
    .btn-warning {
        width: 100%;
        padding: 10px 14px;
        font-size: 0.85rem;
        flex: 1 1 100%;
    }
    
    .search-title {
        font-size: 1rem;
    }
    
    /* Product Thumbnail */
    .product-thumbnail-modern {
        max-width: 150px;
        height: 150px;
    }

    /* Product Info */
    .product-name-modern {
        font-size: 0.95rem;
        line-height: 1.4;
    }

    .discounted-price-modern {
        font-size: 1.2rem;
    }
    
    .original-price-modern {
        font-size: 0.85rem;
    }
    
    /* Product Actions Grid */
    .product-actions-modern {
        grid-template-columns: 1fr 1fr;
        gap: 6px;
    }
    
    .btn-action-modern {
        padding: 12px 8px;
        font-size: 0.75rem;
    }
    
    .btn-action-modern:last-child {
        grid-column: 1 / -1;
    }
    
    /* Modal Responsive */
    .modal-card {
        margin: 16px;
        max-width: calc(100% - 32px);
    }
    
    .modal-actions {
        flex-direction: column;
    }
    
    .btn-modal-cancel,
    .btn-modal-confirm {
        width: 100%;
    }
    
    /* Dropdown Max Width */
    .dropdown-options-modern {
        max-height: 250px;
    }
    
    /* Products List */
    .products-list-modern {
        padding: 0;
    }
    
    .product-item-modern {
        margin: 0;
        border-radius: 0;
    }
    
    .product-item-modern:first-child {
        border-top-left-radius: 0;
        border-top-right-radius: 0;
    }
}

/* Extra small devices optimization */
@media (max-width: 400px) {
    .stats-container-modern {
        padding: 0;
        gap: 16px;
    }
    
    .stat-box-modern {
        padding: 18px 14px;
    }
    
    .stat-number {
        font-size: 1.8rem;
    }
    
    .stat-icon-modern {
        width: 50px;
        height: 50px;
        font-size: 1.4rem;
    }
    
    .search-card,
    .filters-card,
    .products-list-card-modern {
        border-radius: 10px;
        padding: 14px 10px;
    }
    
    .product-thumbnail-modern {
        max-width: 120px;
        height: 120px;
    }
    
    .btn-action-modern {
        padding: 10px 6px;
        font-size: 0.7rem;
    }
}

/* ========================================
   MOBILE ACTION BAR - BEAUTIFUL GRADIENT BUTTONS
   ======================================== */
.mobile-action-bar {
    display: none;
    gap: 8px;
    padding: 12px 15px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    margin-bottom: 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.mobile-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 10px 12px;
    border: none;
    border-radius: 12px;
    background: white;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    min-width: 70px;
    flex-shrink: 0;
}

.mobile-action-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.3) 50%, transparent 70%);
    animation: mobileShine 3s infinite;
    z-index: 0;
}

@keyframes mobileShine {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

.mobile-btn-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 1;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
}

.icon-letter {
    font-size: 1.3rem;
    font-weight: 800;
    color: white;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.mobile-btn-label {
    font-size: 0.7rem;
    font-weight: 600;
    color: #374151;
    position: relative;
    z-index: 1;
    white-space: nowrap;
}

/* Gradient backgrounds for icons */
.gradient-purple .mobile-btn-icon {
    background: linear-gradient(135deg, #8B5CF6 0%, #6366F1 100%);
}

.gradient-blue .mobile-btn-icon {
    background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
}

.gradient-green .mobile-btn-icon {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
}

.gradient-red .mobile-btn-icon {
    background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
}

/* Hover/Active effects */
.mobile-action-btn:active {
    transform: scale(0.95);
}

.mobile-action-btn:active .mobile-btn-icon {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
}

.mobile-action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.mobile-action-btn:disabled:active {
    transform: none;
}

.mobile-action-btn:disabled .mobile-btn-icon {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

/* Selection mode active state */
.mobile-action-btn.active {
    background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%);
}

.mobile-action-btn.active .mobile-btn-label {
    color: var(--primary-color);
    font-weight: 700;
}

/* Checkbox styles */
.product-checkbox-section {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.checkbox-modern {
    position: relative;
    display: flex;
    align-items: center;
    cursor: pointer;
    user-select: none;
}

.checkbox-modern input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    height: 0;
    width: 0;
}

.checkmark-modern {
    height: 22px;
    width: 22px;
    background-color: #fff;
    border: 2px solid #d1d5db;
    border-radius: 6px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.checkbox-modern:hover .checkmark-modern {
    border-color: var(--primary-color);
}

.checkbox-modern input:checked ~ .checkmark-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border-color: transparent;
}

.checkmark-modern:after {
    content: "";
    display: none;
}

.checkbox-modern input:checked ~ .checkmark-modern:after {
    display: block;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

/* ========================================
   MOBILE FILTER BUTTONS (MINI)
   ======================================== */
.mobile-filter-section {
    display: none;
    padding: 12px 15px;
    background: #f8f9fa;
}

.mobile-filter-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 12px;
}

.mobile-filter-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 14px;
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #374151;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}

.mobile-filter-btn:hover,
.mobile-filter-btn:active {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border-color: var(--primary-color);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.2);
}

.mobile-filter-btn i:first-child {
    font-size: 0.9rem;
}

.mobile-filter-btn i:last-child {
    font-size: 0.7rem;
    margin-left: auto;
}

.mobile-dropdowns-container {
    position: relative;
}

.mobile-dropdowns-container .custom-dropdown-modern {
    margin-bottom: 12px;
}

.mobile-dropdowns-container .dropdown-options-modern {
    max-height: 250px !important;
    overflow-y: auto;
    display: block !important;
    opacity: 1 !important;
    transform: translateY(0) !important;
}

/* ========================================
   MOBILE VERTICAL ACTION ICONS - BEAUTIFUL GRADIENT GLOSSY
   ======================================== */
.product-actions-mobile-vertical {
    display: none;
}

.mobile-icon-btn {
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    font-size: 0.75rem;
}

/* Shine animation overlay */
.mobile-icon-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
        45deg,
        transparent 30%,
        rgba(255, 255, 255, 0.3) 50%,
        transparent 70%
    );
    transform: rotate(45deg);
    animation: shine 3s infinite;
}

@keyframes shine {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

/* Pulse animation */
@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.mobile-icon-btn:active {
    transform: scale(0.95);
}

/* View button - Blue gradient */
.mobile-icon-view {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.mobile-icon-view:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    animation: pulse 0.6s ease-in-out;
}

/* Edit button - Green gradient */
.mobile-icon-edit {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
}

.mobile-icon-edit:hover {
    background: linear-gradient(135deg, #38ef7d 0%, #11998e 100%);
    box-shadow: 0 6px 20px rgba(56, 239, 125, 0.4);
    animation: pulse 0.6s ease-in-out;
}

/* Delete button - Red gradient */
.mobile-icon-delete {
    background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%);
    color: white;
}

.mobile-icon-delete:hover {
    background: linear-gradient(135deg, #ff6a00 0%, #ee0979 100%);
    box-shadow: 0 6px 20px rgba(238, 9, 121, 0.4);
    animation: pulse 0.6s ease-in-out;
}

/* Glossy effect */
.mobile-icon-btn::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(
        to bottom,
        rgba(255, 255, 255, 0.3) 0%,
        rgba(255, 255, 255, 0) 100%
    );
    border-radius: 10px 10px 0 0;
    pointer-events: none;
}

.mobile-icon-btn i {
    position: relative;
    z-index: 1;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2));
}

/* ========================================
   MOBILE RESPONSIVE - MATCH USERS/AFFILIATES/CATEGORIES
   ======================================== */
@media (max-width: 768px) {
    /* Reduce row margins on mobile */
    .row.mb-3, .row.mb-4, .row.mb-5 {
        margin-bottom: 12px !important;
    }
    
    /* Show mobile action bar */
    .mobile-action-bar {
        display: flex !important;
    }
    
    /* Show mobile filter section */
    .mobile-filter-section {
        display: block !important;
    }
    
    /* Hide search and filters on mobile */
    .search-card,
    .filters-card {
        display: none !important;
    }
    
    /* Hide bulk actions header on mobile */
    .list-header-modern {
        display: none !important;
    }
    
    /* Adjust stats to 4 cards in 2 rows */
    .stats-container-modern {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 10px !important;
    }
    
    .stat-box-modern {
        min-height: 100px !important;
        padding: 12px !important;
    }
    
    .stat-number {
        font-size: 1.3rem !important;
    }
    
    .stat-label {
        font-size: 0.7rem !important;
    }
    
    /* Transform product list to mobile card layout */
    .products-list-modern {
        padding: 12px 15px !important;
        background: #f8f9fa !important;
    }
    
    .product-item-modern {
        display: flex !important;
        flex-direction: row !important;
        align-items: flex-start !important;
        padding: 12px !important;
        gap: 12px !important;
        border-radius: 12px !important;
        margin-bottom: 12px !important;
        background: white !important;
        border: 2px solid #e9ecef !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05) !important;
        position: relative !important;
        overflow: visible !important;
    }
    
    .product-item-modern::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 3px;
        background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
        transform: scaleY(0);
        transition: transform 0.3s ease;
    }
    
    .product-item-modern:active::before {
        transform: scaleY(1);
    }
    
    /* Show checkbox on mobile */
    .product-checkbox-section {
        display: flex !important;
        align-items: center;
        justify-content: center;
        position: relative !important;
        z-index: 10 !important;
        margin-right: 8px !important;
    }
    
    .checkbox-modern {
        position: relative !important;
        z-index: 10 !important;
    }
    
    /* Product image */
    .product-image-section-list {
        width: 60px !important;
        height: 60px !important;
        flex-shrink: 0 !important;
        position: relative !important;
    }
    
    .product-thumbnail-modern {
        width: 60px !important;
        height: 60px !important;
        border-radius: 10px !important;
        object-fit: cover !important;
    }
    
    .guaranteed-badge-modern {
        display: none !important;
    }
    
    /* Product details */
    .product-details-modern {
        flex: 1 !important;
        min-width: 0 !important;
    }
    
    .product-name-modern {
        font-size: 0.95rem !important;
        font-weight: 600 !important;
        margin-bottom: 6px !important;
        line-height: 1.3 !important;
        display: -webkit-box !important;
        -webkit-line-clamp: 2 !important;
        -webkit-box-orient: vertical !important;
        overflow: hidden !important;
    }
    
    .product-meta-modern {
        display: flex !important;
        flex-direction: column !important;
        gap: 4px !important;
        font-size: 0.75rem !important;
    }
    
    .product-meta-modern span {
        display: block !important;
    }
    
    /* Hide price section, show in meta */
    .product-price-section-list {
        display: none !important;
    }
    
    /* Add price to meta */
    .product-meta-modern::after {
        content: attr(data-price);
        display: block;
        font-weight: 700;
        color: var(--primary-color);
        font-size: 0.9rem;
        margin-top: 4px;
    }
    
    /* Hide desktop actions */
    .product-actions-modern {
        display: none !important;
    }
    
    /* Show mobile vertical action icons */
    .product-actions-mobile-vertical {
        display: flex !important;
        flex-direction: column !important;
        gap: 6px !important;
        position: absolute !important;
        right: 10px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
    }
}

@media (max-width: 576px) {
    .stat-box-modern {
        min-height: 90px !important;
        padding: 10px !important;
    }
    
    .stat-number {
        font-size: 1.1rem !important;
    }
    
    .stat-label {
        font-size: 0.65rem !important;
    }
    
    .product-item-modern {
        padding: 10px !important;
        gap: 10px !important;
    }
    
    .product-image-section-list {
        width: 50px !important;
        height: 50px !important;
    }
    
    .product-thumbnail-modern {
        width: 50px !important;
        height: 50px !important;
    }
    
    .product-name-modern {
        font-size: 0.9rem !important;
    }
    
    .product-meta-modern {
        font-size: 0.7rem !important;
    }
}
</style>
