<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Get categories
$stmt = $db->prepare("SELECT * FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = sanitizeInput($_POST['name']);
    $category_id = intval($_POST['category_id']);
    $original_price = floatval($_POST['original_price']);
    $discounted_price = !empty($_POST['discounted_price']) ? floatval($_POST['discounted_price']) : null;
    $commission_rate = !empty($_POST['commission_rate']) ? floatval($_POST['commission_rate']) : 0.00;
    $delivery_charges = !empty($_POST['delivery_charges']) ? floatval($_POST['delivery_charges']) : 0.00;
    $description = sanitizeInput($_POST['description']);
    $keywords = sanitizeInput($_POST['keywords']);
    $status = sanitizeInput($_POST['status']);
    $stock_count = intval($_POST['stock_count']);
    $display_location = sanitizeInput($_POST['display_location']);
    $sales_count = intval($_POST['sales_count'] ?? 0);
    $home_page_image = '';
    $shop_page_image = '';
    
    if (empty($name) || $category_id <= 0 || $original_price <= 0) {
        $error = "Please fill all required fields.";
    } else {
        try {
            $db->beginTransaction();
            
            $uploaded_image_files = [];
            
            if (!empty($_FILES['images']['name'][0])) {
                $upload_dir = '../' . PRODUCT_IMAGES_DIR;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                foreach ($_FILES['images']['name'] as $key => $filename) {
                    if ($_FILES['images']['error'][$key] == 0) {
                        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        
                        if (in_array($file_ext, $allowed_types) && $_FILES['images']['size'][$key] <= 5242880) {
                            $new_filename = uniqid() . '.' . $file_ext;
                            $upload_path = $upload_dir . $new_filename;
                            
                            if (move_uploaded_file($_FILES['images']['tmp_name'][$key], $upload_path)) {
                                $uploaded_image_files[] = $new_filename;
                            }
                        }
                    }
                }
            }
            
            $home_page_image_index = isset($_POST['home_page_image_index']) && $_POST['home_page_image_index'] !== '' ? intval($_POST['home_page_image_index']) : null;
            $shop_page_image_index = isset($_POST['shop_page_image_index']) && $_POST['shop_page_image_index'] !== '' ? intval($_POST['shop_page_image_index']) : null;
            
            if ($home_page_image_index !== null && isset($uploaded_image_files[$home_page_image_index])) {
                $home_page_image = $uploaded_image_files[$home_page_image_index];
            }
            
            if ($shop_page_image_index !== null && isset($uploaded_image_files[$shop_page_image_index])) {
                $shop_page_image = $uploaded_image_files[$shop_page_image_index];
            }
            
            $stmt = $db->prepare("
                INSERT INTO products (name, category_id, original_price, discounted_price, commission_rate, delivery_charges, 
                                    description, keywords, status, stock_count, sales_count, display_location,
                                    home_page_image, shop_page_image, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $name, $category_id, $original_price, $discounted_price, $commission_rate, $delivery_charges,
                $description, $keywords, $status, $stock_count, $sales_count, $display_location,
                $home_page_image, $shop_page_image
            ]);
            
            $product_id = $db->lastInsertId();
            
            if (!empty($uploaded_image_files)) {
                $is_primary = true; 
                foreach ($uploaded_image_files as $image_filename) {
                    $stmt = $db->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, ?)");
                    $stmt->execute([$product_id, $image_filename, $is_primary]);
                    $is_primary = false;
                }
            }
            
            if (!empty($_POST['feature_names'])) {
                foreach ($_POST['feature_names'] as $key => $feature_name) {
                    $feature_description = $_POST['feature_descriptions'][$key] ?? '';
                    if (!empty($feature_name)) {
                        $stmt = $db->prepare("INSERT INTO product_features (product_id, feature_name, feature_description) VALUES (?, ?, ?)");
                        $stmt->execute([$product_id, sanitizeInput($feature_name), sanitizeInput($feature_description)]);
                    }
                }
            }
            
            // Handle variant mode
            $variant_mode = isset($_POST['variant_mode']) ? sanitizeInput($_POST['variant_mode']) : 'simple';
            
            if ($variant_mode === 'simple') {
                // Simple variant processing (existing logic)
                $variant_count = intval($_POST['variant_count'] ?? 0);
                if ($variant_count > 0) {
                    $variant_type = sanitizeInput($_POST['variant_type']);
                    
                    for ($i = 1; $i <= $variant_count; $i++) {
                        $variant_name = sanitizeInput($_POST["variant_name_$i"] ?? '');
                        $variant_price = !empty($_POST["variant_price_$i"]) ? floatval($_POST["variant_price_$i"]) : null;
                        $variant_original_price = !empty($_POST["variant_original_price_$i"]) ? floatval($_POST["variant_original_price_$i"]) : null;
                        $variant_stock_count = !empty($_POST["variant_stock_count_$i"]) ? intval($_POST["variant_stock_count_$i"]) : 1000;
                        $variant_image = '';
                        
                        $variant_image_index = isset($_POST["variant_image_index_$i"]) && $_POST["variant_image_index_$i"] !== '' ? intval($_POST["variant_image_index_$i"]) : null;
                        
                        if ($variant_image_index !== null && isset($uploaded_image_files[$variant_image_index])) {
                            $variant_image = $uploaded_image_files[$variant_image_index];
                        }
                        
                        if (!empty($variant_name)) {
                            $stmt = $db->prepare("INSERT INTO product_variants (product_id, variant_type, variant_name, variant_price, variant_original_price, variant_image, stock_count) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$product_id, $variant_type, $variant_name, $variant_price, $variant_original_price, $variant_image, $variant_stock_count]);
                        }
                    }
                }
            } elseif ($variant_mode === 'combination' && !empty($_POST['combinations'])) {
                // Combination variant processing (new logic)
                require_once '../config/variant_helpers.php';
                
                $combinations = $_POST['combinations'];
                $attributeMap = []; // Track attribute_id => [value => value_id]
                
                // First, collect all unique attributes and their values
                foreach ($combinations as $combination) {
                    foreach ($combination as $key => $value) {
                        if ($key !== 'price' && $key !== 'stock' && $key !== 'sku') {
                            $attributeName = $key;
                            $attributeValue = sanitizeInput($value);
                            
                            if (!isset($attributeMap[$attributeName])) {
                                // Create attribute if not exists
                                $stmt = $db->prepare("INSERT INTO variant_attributes (product_id, attribute_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                                $stmt->execute([$product_id, $attributeName]);
                                $attributeId = $db->lastInsertId();
                                $attributeMap[$attributeName] = ['id' => $attributeId, 'values' => []];
                            }
                            
                            if (!isset($attributeMap[$attributeName]['values'][$attributeValue])) {
                                // Create attribute value if not exists
                                $stmt = $db->prepare("INSERT INTO variant_attribute_values (attribute_id, value_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                                $stmt->execute([$attributeMap[$attributeName]['id'], $attributeValue]);
                                $valueId = $db->lastInsertId();
                                $attributeMap[$attributeName]['values'][$attributeValue] = $valueId;
                            }
                        }
                    }
                }
                
                // Now create combinations
                foreach ($combinations as $index => $combination) {
                    $price = floatval($combination['price'] ?? 0);
                    $stock = intval($combination['stock'] ?? 0);
                    $sku = !empty($combination['sku']) ? sanitizeInput($combination['sku']) : generateVariantSKU($db, $product_id, $combination);
                    
                    // Handle combination image
                    $combo_image = '';
                    $combo_image_index = isset($combination['image_index']) && $combination['image_index'] !== '' ? intval($combination['image_index']) : null;
                    if ($combo_image_index !== null && isset($uploaded_image_files[$combo_image_index])) {
                        $combo_image = $uploaded_image_files[$combo_image_index];
                    }
                    
                    // Insert combination
                    $stmt = $db->prepare("INSERT INTO product_variant_combinations (product_id, sku, price, stock_quantity, image_path, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$product_id, $sku, $price, $stock, $combo_image]);
                    $combinationId = $db->lastInsertId();
                    
                    // Map attributes to this combination
                    foreach ($combination as $key => $value) {
                        if ($key !== 'price' && $key !== 'stock' && $key !== 'sku') {
                            $attributeName = $key;
                            $attributeValue = sanitizeInput($value);
                            $valueId = $attributeMap[$attributeName]['values'][$attributeValue];
                            
                            $stmt = $db->prepare("INSERT INTO combination_attribute_map (combination_id, attribute_value_id) VALUES (?, ?)");
                            $stmt->execute([$combinationId, $valueId]);
                        }
                    }
                }
            }
            
            if (!empty($_FILES['reviews_file']['name'])) {
                $file = $_FILES['reviews_file'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (in_array($file_ext, ['csv']) && $file['error'] == 0) {
                    $file_path = $file['tmp_name'];
                    
                    if (($handle = fopen($file_path, 'r')) !== FALSE) {
                        $header = fgetcsv($handle);
                        
                        while (($data = fgetcsv($handle)) !== FALSE) {
                            if (count($data) >= 3) {
                                $user_name = sanitizeInput($data[0]);
                                $rating = intval($data[1]);
                                $review_text = sanitizeInput($data[2]);
                                
                                if (!empty($user_name) && $rating >= 1 && $rating <= 5 && !empty($review_text)) {
                                    $stmt = $db->prepare("INSERT INTO reviews (product_id, user_name, rating, review_text, is_approved) VALUES (?, ?, ?, ?, 1)");
                                    $stmt->execute([$product_id, $user_name, $rating, $review_text]);
                                }
                            }
                        }
                        fclose($handle);
                    }
                }
            }
            
            $db->commit();
            
            header("Location: edit-product.php?id=$product_id&success=1");
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error adding product: " . $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $success = "Product added successfully!";
}

$page_title = "Add Product";
require_once 'includes/header.php';
?>

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

<!-- Page Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="page-header-card">
            <div class="page-header-content">
                <div class="page-header-icon">
                    <i class="fas fa-plus-square"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Add New Product</h1>
                    <p class="page-subtitle">Fill in the details to create a new product</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Import Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="import-card">
            <div class="import-header">
                <div class="import-icon">
                    <i class="fas fa-file-import"></i>
                </div>
                <div class="import-title">
                    <h6 class="mb-0">Quick Import from File</h6>
                    <small class="text-muted">Auto-fill product data from Excel or CSV files</small>
                </div>
            </div>
            <div class="import-buttons">
                <button type="button" class="btn btn-import" onclick="openSmartImport('product')">
                    <i class="fas fa-box me-2"></i>Import Product Data
                </button>
                <button type="button" class="btn btn-import" onclick="openSmartImport('variants')">
                    <i class="fas fa-palette me-2"></i>Import Variants
                </button>
                <button type="button" class="btn btn-import" onclick="openSmartImport('reviews')">
                    <i class="fas fa-star me-2"></i>Import Reviews
                </button>
                <button type="button" class="btn btn-import" onclick="openSmartImport('features')">
                    <i class="fas fa-list-ul me-2"></i>Import Features
                </button>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="form-card">
            <div class="form-header">
                <div class="form-title">
                    <i class="fas fa-plus-square me-2"></i>Product Information
                </div>
                <div class="form-actions">
                    <a href="products.php" class="btn btn-outline-modern">
                        <i class="fas fa-arrow-left me-2"></i>Back to Products
                    </a>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="productForm">
                <!-- Basic Information Section -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div class="section-title">
                            <h6 class="mb-0">Basic Information</h6>
                            <small class="text-muted">Enter the core details of your product</small>
                        </div>
                    </div>

                    <div class="section-content">
                        <div class="form-grid">
                            <div class="form-group-modern">
                                <label for="name" class="form-label-modern">
                                    <i class="fas fa-tag me-1"></i>Product Name <span class="required">*</span>
                                </label>
                                <div class="input-wrapper-modern">
                                    <input type="text" class="form-control-modern" id="name" name="name" 
                                           placeholder="Enter product name" value="" required>
                                    <div class="input-icon">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group-modern">
                                <label for="category_id" class="form-label-modern">
                                    <i class="fas fa-folder me-1"></i>Product Category <span class="required">*</span>
                                </label>
                                <div class="custom-dropdown-modern" id="categoryDropdown">
                                    <div class="dropdown-selected-modern" onclick="toggleModernDropdown('categoryDropdown')">
                                        <span id="selectedCategory">Choose a category...</span>
                                        <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                                    </div>
                                    <div class="dropdown-options-modern">
                                        <?php foreach ($categories as $category): ?>
                                            <div class="dropdown-option-modern" onclick="selectCategory('<?php echo htmlspecialchars($category['id']); ?>', '<?php echo htmlspecialchars($category['name']); ?>')">
                                                <i class="fas fa-tag me-2"></i><?php echo htmlspecialchars($category['name']); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="category_id" id="categoryInput" value="" required>
                            </div>

                            <div class="form-group-modern">
                                <label for="original_price" class="form-label-modern">
                                    <i class="fas fa-rupee-sign me-1"></i>Original Price (PKR) <span class="required">*</span>
                                </label>
                                <div class="input-wrapper-modern">
                                    <input type="number" class="form-control-modern" id="original_price" name="original_price" 
                                           min="0" step="0.01" placeholder="0.00" value="" required>
                                    <div class="input-icon">
                                        <i class="fas fa-dollar-sign"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group-modern">
                                <label for="discounted_price" class="form-label-modern">
                                    <i class="fas fa-tags me-1"></i>Discounted Price (PKR)
                                </label>
                                <div class="input-wrapper-modern">
                                    <input type="number" class="form-control-modern" id="discounted_price" name="discounted_price" 
                                           min="0" step="0.01" placeholder="0.00" value="">
                                    <div class="input-icon">
                                        <i class="fas fa-percent"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group-modern">
                                <label for="stock_count" class="form-label-modern">
                                    <i class="fas fa-boxes me-1"></i>Stock Count
                                </label>
                                <div class="input-wrapper-modern">
                                    <input type="number" class="form-control-modern" id="stock_count" name="stock_count" 
                                           min="0" value="" placeholder="0">
                                    <div class="input-icon">
                                        <i class="fas fa-cube"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group-modern">
                                <label for="sales_count" class="form-label-modern">
                                    <i class="fas fa-shopping-cart me-1"></i>Products Sold
                                </label>
                                <div class="input-wrapper-modern">
                                    <input type="number" class="form-control-modern" id="sales_count" name="sales_count" 
                                           min="0" value="" placeholder="0">
                                    <div class="input-icon">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                </div>
                                <small class="form-text-modern">Number of products sold (shown on product card)</small>
                            </div>

                            <div class="form-group-modern">
                                <label for="commission_rate" class="form-label-modern">
                                    <i class="fas fa-handshake me-1"></i>Affiliate Commission (PKR) <span class="text-muted">(Fixed Amount)</span>
                                </label>
                                <div class="input-wrapper-modern">
                                    <input type="number" class="form-control-modern" id="commission_rate" name="commission_rate" 
                                           min="0" step="0.01" value="" placeholder="0.00">
                                    <div class="input-icon">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                </div>
                                <small class="form-text-modern">Fixed commission amount in PKR that affiliates will earn per sale of this product</small>
                            </div>

                            <div class="form-group-modern">
                                <label for="delivery_charges" class="form-label-modern">
                                    <i class="fas fa-truck me-1"></i>Delivery Charges (PKR)
                                </label>
                                <div class="input-wrapper-modern">
                                    <input type="number" class="form-control-modern" id="delivery_charges" name="delivery_charges"
                                           min="0" step="0.01" value="" placeholder="0.00">
                                    <div class="input-icon">
                                        <i class="fas fa-shipping-fast"></i>
                                    </div>
                                </div>
                                <small class="form-text-modern">Delivery cost per unit. Leave 0 to use default site delivery charges.</small>
                            </div>

                            <div class="form-group-modern">
                                <label for="status" class="form-label-modern">
                                    <i class="fas fa-info-circle me-1"></i>Product Status
                                </label>
                                <div class="custom-dropdown-modern" id="statusDropdown">
                                    <div class="dropdown-selected-modern" onclick="toggleModernDropdown('statusDropdown')">
                                        <span id="selectedStatus">In Stock</span>
                                        <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                                    </div>
                                    <div class="dropdown-options-modern">
                                        <div class="dropdown-option-modern selected" onclick="selectStatus('In Stock')">
                                            <span class="status-dot status-in-stock"></span>In Stock
                                        </div>
                                        <div class="dropdown-option-modern" onclick="selectStatus('Out of Stock')">
                                            <span class="status-dot status-out-of-stock"></span>Out of Stock
                                        </div>
                                        <div class="dropdown-option-modern" onclick="selectStatus('Limited')">
                                            <span class="status-dot status-limited"></span>Limited Stock
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="status" id="statusInput" value="In Stock">
                            </div>

                            <div class="form-group-modern">
                                <label for="display_location" class="form-label-modern">
                                    <i class="fas fa-map-marker-alt me-1"></i>Display Location
                                </label>
                                <div class="custom-dropdown-modern" id="displayDropdown">
                                    <div class="dropdown-selected-modern" onclick="toggleModernDropdown('displayDropdown')">
                                        <span id="selectedDisplay">Both Pages</span>
                                        <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                                    </div>
                                    <div class="dropdown-options-modern">
                                        <div class="dropdown-option-modern" onclick="selectDisplay('Shop Page')">
                                            <i class="fas fa-store me-2"></i>Shop Page Only
                                        </div>
                                        <div class="dropdown-option-modern" onclick="selectDisplay('Homepage')">
                                            <i class="fas fa-home me-2"></i>Homepage Only
                                        </div>
                                        <div class="dropdown-option-modern selected" onclick="selectDisplay('Both')">
                                            <i class="fas fa-expand-arrows-alt me-2"></i>Both Pages
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="display_location" id="displayInput" value="Both">
                            </div>
                        </div>

                        <div class="form-group-modern full-width">
                            <label for="description" class="form-label-modern">
                                <i class="fas fa-align-left me-1"></i>Description
                            </label>
                            <div class="textarea-wrapper-modern">
                                <textarea class="form-control-modern textarea-modern" id="description" name="description" 
                                          rows="4" placeholder="Detailed description of the product..."></textarea>
                                <div class="textarea-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                            </div>
                        </div>

                        <div class="form-group-modern full-width">
                            <label for="keywords" class="form-label-modern">
                                <i class="fas fa-search me-1"></i>Search Keywords
                            </label>
                            <div class="input-wrapper-modern">
                                <input type="text" class="form-control-modern" id="keywords" name="keywords" 
                                       placeholder="Enter keywords separated by commas (e.g., laptop, computer, electronics, gaming)" value="">
                                <div class="input-icon">
                                    <i class="fas fa-hashtag"></i>
                                </div>
                            </div>
                            <small class="form-text-modern">Add keywords to help customers find this product easily. Separate each keyword with a comma.</small>
                        </div>
                    </div>
                </div>

                <!-- Product Images Section -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-images"></i>
                        </div>
                        <div class="section-title">
                            <h6 class="mb-0">Product Images</h6>
                            <small class="text-muted">Upload high-quality images for your product</small>
                        </div>
                    </div>

                    <div class="section-content">
                        <div class="image-upload-section">
                            <div class="file-upload-area" id="mainImageUpload">
                                <div class="upload-content">
                                    <div class="upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <div class="upload-text">
                                        <h6>Upload Product Images</h6>
                                        <p>Drag & drop images here or click to browse</p>
                                        <small class="text-muted">Supported formats: JPG, PNG, GIF, WebP (Max 5MB each)</small>
                                    </div>
                                    <input type="file" id="images" name="images[]" multiple accept="image/*" style="display: none;">
                                    <button type="button" class="btn btn-primary-modern" onclick="document.getElementById('images').click()">
                                        <i class="fas fa-plus me-2"></i>Choose Images
                                    </button>
                                </div>
                            </div>

                            <div id="imagePreview" class="image-preview-grid">
                                <!-- New image previews will be added here -->
                            </div>
                        </div>

                        <div class="special-images-section">
                            <h6 class="section-subtitle">
                                <i class="fas fa-star me-2"></i>Display Image Selection
                            </h6>
                            <p class="text-muted mb-3">
                                <i class="fas fa-info-circle me-1"></i>
                                Select which images to display on different pages. Images will appear here after you upload them.
                            </p>

                            <div class="special-images-grid">
                                <div class="image-selector-group">
                                    <label class="form-label-modern">
                                        <i class="fas fa-home me-1"></i>Home Page Display Image
                                    </label>
                                    <select class="form-control-modern" id="homePageImageSelect" name="home_page_image_index">
                                        <option value="">Select from uploaded images...</option>
                                    </select>
                                    <div id="homePageImagePreview" class="image-selector-preview mt-2"></div>
                                    <small class="form-text-modern">Image shown on homepage product cards</small>
                                </div>

                                <div class="image-selector-group">
                                    <label class="form-label-modern">
                                        <i class="fas fa-store me-1"></i>Shop Page Display Image
                                    </label>
                                    <select class="form-control-modern" id="shopPageImageSelect" name="shop_page_image_index">
                                        <option value="">Select from uploaded images...</option>
                                    </select>
                                    <div id="shopPageImagePreview" class="image-selector-preview mt-2"></div>
                                    <small class="form-text-modern">Image shown on shop page product cards</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Features Section -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-list-ul"></i>
                        </div>
                        <div class="section-title">
                            <h6 class="mb-0">Product Features</h6>
                            <small class="text-muted">Add key features and specifications</small>
                        </div>
                    </div>

                    <div class="section-content">
                        <div id="featuresContainer" class="features-list">
                            <div class="feature-item-modern">
                                <div class="feature-inputs">
                                    <input type="text" class="form-control-modern feature-name" name="feature_names[]" 
                                           placeholder="Feature name (e.g., Material)">
                                    <input type="text" class="form-control-modern feature-desc" name="feature_descriptions[]" 
                                           placeholder="Feature description (e.g., Premium quality fabric)">
                                </div>
                                <button type="button" class="btn btn-remove-modern" onclick="removeFeature(this)" title="Remove Feature">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>

                        <button type="button" class="btn btn-add-modern" onclick="addFeature()">
                            <i class="fas fa-plus me-2"></i>Add Feature
                        </button>
                    </div>
                </div>

                <!-- Product Variants Section -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-palette"></i>
                        </div>
                        <div class="section-title">
                            <h6 class="mb-0">Product Variants</h6>
                            <small class="text-muted">Add color, design, or custom variants</small>
                        </div>
                    </div>

                    <div class="section-content">
                        <!-- Variant Mode Toggle -->
                        <div class="variant-mode-toggle mb-4">
                            <div class="btn-group w-100" role="group">
                                <button type="button" class="btn btn-variant-mode active" id="simpleVariantBtn" onclick="switchVariantMode('simple')">
                                    <i class="fas fa-list"></i> Simple Variants
                                    <small class="d-block">Single attribute (Color OR Size OR Design)</small>
                                </button>
                                <button type="button" class="btn btn-variant-mode" id="combinationVariantBtn" onclick="switchVariantMode('combination')">
                                    <i class="fas fa-layer-group"></i> Combination Variants
                                    <small class="d-block">Multiple attributes (Color + Size + Design)</small>
                                </button>
                            </div>
                        </div>
                        <input type="hidden" id="variant_mode" name="variant_mode" value="simple">

                        <!-- Simple Variant Section -->
                        <div id="simpleVariantSection" class="variants-config">
                            <div class="variant-controls">
                                <div class="control-group">
                                    <label class="control-label">Number of Variants</label>
                                    <select class="form-control-modern" id="variant_count" name="variant_count" onchange="updateVariants()">
                                        <option value="0" selected>No Variants</option>
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?> Variant<?php echo $i > 1 ? 's' : ''; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <div class="control-group">
                                    <label class="control-label">Variant Type</label>
                                    <div class="variant-type-selector">
                                        <select class="form-control-modern" id="variant_type" name="variant_type" onchange="updateVariantType()">
                                            <?php
                                            // Get existing variant types from database
                                            try {
                                                $stmt = $db->prepare("SELECT DISTINCT type_name FROM variant_types ORDER BY type_name ASC");
                                                $stmt->execute();
                                                $variant_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                                
                                                foreach ($variant_types as $type) {
                                                    $selected = $type['type_name'] === 'Color' ? 'selected' : '';
                                                    echo '<option value="' . htmlspecialchars($type['type_name']) . '" ' . $selected . '>' . htmlspecialchars($type['type_name']) . ' Variants</option>';
                                                }
                                            } catch (Exception $e) {
                                                // Fallback to default options if table doesn't exist yet
                                                echo '<option value="Color" selected>Color Variants</option>';
                                                echo '<option value="Design">Design Variants</option>';
                                                echo '<option value="Size">Size Variants</option>';
                                                echo '<option value="Style">Style Variants</option>';
                                            }
                                            ?>
                                            <option value="custom">+ Add Custom Type</option>
                                        </select>
                                        <div id="customVariantTypeInput" class="custom-variant-input mt-2" style="display: none;">
                                            <div class="input-group">
                                                <input type="text" class="form-control-modern" id="customVariantTypeName" 
                                                       placeholder="Enter custom variant type (e.g., Material, Brand, etc.)" maxlength="100">
                                                <button type="button" class="btn btn-primary-modern" onclick="addCustomVariantType()">
                                                    <i class="fas fa-plus"></i> Add
                                                </button>
                                                <button type="button" class="btn btn-secondary-modern" onclick="cancelCustomVariantType()">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="variantsContainer" class="variants-list">
                            </div>
                        </div>

                        <!-- Combination Variant Section -->
                        <div id="combinationVariantSection" style="display: none;">
                            <!-- Attribute Builder -->
                            <div class="combination-builder">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> 
                                    <strong>Combination Variants:</strong> Add multiple attributes (Color, Size, Design) and their values. 
                                    The system will automatically generate all possible combinations with individual prices and stock.
                                </div>

                                <!-- Attribute Configuration -->
                                <div id="attributesContainer">
                                    <!-- Will be populated by JavaScript -->
                                </div>

                                <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="addAttribute()">
                                    <i class="fas fa-plus"></i> Add Attribute
                                </button>

                                <button type="button" class="btn btn-primary mb-3" onclick="generateCombinations()">
                                    <i class="fas fa-magic"></i> Generate Combinations
                                </button>

                                <!-- Combinations Grid -->
                                <div id="combinationsGrid" class="combinations-grid">
                                    <p class="text-muted text-center">Add attributes and click "Generate Combinations" to create variant combinations.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Section -->
                <div class="form-section">
                    <div class="section-content">
                        <div class="submit-section">
                            <div class="submit-buttons">
                                <button type="submit" class="btn btn-primary-modern btn-lg">
                                    <i class="fas fa-plus-circle me-2"></i>Add Product
                                </button>
                                <button type="button" class="btn btn-secondary-modern btn-lg" onclick="saveDraft()">
                                    <i class="fas fa-save me-2"></i>Save as Draft
                                </button>
                                <a href="products.php" class="btn btn-outline-modern btn-lg">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                            <div class="submit-info">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Make sure all required fields are filled before submitting
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* ========================================
   FORM JAVASCRIPT
   ======================================== */

// Toggle modern dropdown
function toggleModernDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    const allDropdowns = document.querySelectorAll('.custom-dropdown-modern');
    
    allDropdowns.forEach(dd => {
        if (dd.id !== dropdownId) {
            dd.classList.remove('active');
        }
    });
    
    dropdown.classList.toggle('active');
}

// Select category
function selectCategory(id, name) {
    document.getElementById('selectedCategory').textContent = name;
    document.getElementById('categoryInput').value = id;
    document.getElementById('categoryDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#categoryDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    event.target.classList.add('selected');
}

// Select status
function selectStatus(value) {
    document.getElementById('selectedStatus').textContent = value;
    document.getElementById('statusInput').value = value;
    document.getElementById('statusDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#statusDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    event.target.classList.add('selected');
}

// Select display location
function selectDisplay(value) {
    let displayText = '';
    switch(value) {
        case 'Shop Page': displayText = 'Shop Page Only'; break;
        case 'Homepage': displayText = 'Homepage Only'; break;
        case 'Both': displayText = 'Both Pages'; break;
    }
    document.getElementById('selectedDisplay').textContent = displayText;
    document.getElementById('displayInput').value = value;
    document.getElementById('displayDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#displayDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    event.target.classList.add('selected');
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    const isDropdown = event.target.closest('.custom-dropdown-modern');
    if (!isDropdown) {
        document.querySelectorAll('.custom-dropdown-modern').forEach(dd => {
            dd.classList.remove('active');
        });
    }
});

// Store uploaded images data globally
let uploadedImages = [];

// Image preview functionality with dropdown population
document.getElementById('images').addEventListener('change', function(e) {
    const preview = document.getElementById('imagePreview');
    const homeSelect = document.getElementById('homePageImageSelect');
    const shopSelect = document.getElementById('shopPageImageSelect');
    
    preview.innerHTML = '';
    homeSelect.innerHTML = '<option value="">Select from uploaded images...</option>';
    shopSelect.innerHTML = '<option value="">Select from uploaded images...</option>';
    uploadedImages = [];

    for (let i = 0; i < e.target.files.length; i++) {
        const file = e.target.files[i];
        const reader = new FileReader();

        reader.onload = function(event) {
            uploadedImages.push({
                index: i,
                name: file.name,
                data: event.target.result,
                file: file
            });
            
            const imageItem = document.createElement('div');
            imageItem.className = 'image-preview-item';
            imageItem.innerHTML = `
                <img src="${event.target.result}" alt="Preview">
                <div class="image-number">${i + 1}</div>
                <button type="button" class="btn btn-remove-image" onclick="removeImage(this)">
                    <i class="fas fa-times"></i>
                </button>
            `;
            preview.appendChild(imageItem);
            
            const optionHome = document.createElement('option');
            optionHome.value = i;
            optionHome.textContent = `Image ${i + 1} - ${file.name}`;
            optionHome.dataset.src = event.target.result;
            homeSelect.appendChild(optionHome);
            
            const optionShop = document.createElement('option');
            optionShop.value = i;
            optionShop.textContent = `Image ${i + 1} - ${file.name}`;
            optionShop.dataset.src = event.target.result;
            shopSelect.appendChild(optionShop);
            
            populateVariantImageSelectors();
        };

        reader.readAsDataURL(file);
    }
});

// Home page image selection preview
document.getElementById('homePageImageSelect').addEventListener('change', function() {
    const preview = document.getElementById('homePageImagePreview');
    const selectedOption = this.options[this.selectedIndex];
    
    if (selectedOption.dataset.src) {
        preview.innerHTML = `
            <div class="selected-image-preview">
                <img src="${selectedOption.dataset.src}" alt="Home Page Image">
                <span class="preview-label"><i class="fas fa-home me-1"></i>Homepage Display</span>
            </div>
        `;
    } else {
        preview.innerHTML = '';
    }
});

// Shop page image selection preview
document.getElementById('shopPageImageSelect').addEventListener('change', function() {
    const preview = document.getElementById('shopPageImagePreview');
    const selectedOption = this.options[this.selectedIndex];
    
    if (selectedOption.dataset.src) {
        preview.innerHTML = `
            <div class="selected-image-preview">
                <img src="${selectedOption.dataset.src}" alt="Shop Page Image">
                <span class="preview-label"><i class="fas fa-store me-1"></i>Shop Page Display</span>
            </div>
        `;
    } else {
        preview.innerHTML = '';
    }
});

function removeImage(button) {
    button.closest('.image-preview-item').remove();
}

// Features management
function addFeature() {
    const container = document.getElementById('featuresContainer');
    const newFeature = document.createElement('div');
    newFeature.className = 'feature-item-modern';
    newFeature.innerHTML = `
        <div class="feature-inputs">
            <input type="text" class="form-control-modern feature-name" name="feature_names[]" 
                   placeholder="Feature name (e.g., Material)">
            <input type="text" class="form-control-modern feature-desc" name="feature_descriptions[]" 
                   placeholder="Feature description (e.g., Premium quality fabric)">
        </div>
        <button type="button" class="btn btn-remove-modern" onclick="removeFeature(this)" title="Remove Feature">
            <i class="fas fa-times"></i>
        </button>
    `;
    container.appendChild(newFeature);
}

function removeFeature(button) {
    if (document.querySelectorAll('.feature-item-modern').length > 1) {
        button.closest('.feature-item-modern').remove();
    } else {
        // Clear the inputs instead of removing the last one
        const inputs = button.closest('.feature-item-modern').querySelectorAll('input');
        inputs.forEach(input => input.value = '');
    }
}

// Variants management
function updateVariants() {
    const count = parseInt(document.getElementById('variant_count').value);
    const container = document.getElementById('variantsContainer');
    container.innerHTML = '';

    if (count > 0) {
        updateVariantType();
    }
}

function updateVariantType() {
    const type = document.getElementById('variant_type').value;
    const customInput = document.getElementById('customVariantTypeInput');
    
    // Show/hide custom input based on selection
    if (type === 'custom') {
        customInput.style.display = 'block';
        document.getElementById('customVariantTypeName').focus();
        return; // Don't update variants until custom type is added
    } else {
        customInput.style.display = 'none';
    }
    
    const count = parseInt(document.getElementById('variant_count').value);
    const container = document.getElementById('variantsContainer');
    container.innerHTML = '';

    if (count > 0) {
        for (let i = 1; i <= count; i++) {
            const variantItem = document.createElement('div');
            variantItem.className = 'variant-item-modern';

            let placeholderText = getPlaceholderForType(type);

            let html = `
                <div class="variant-header">
                    <span class="variant-number">Variant ${i}</span>
                </div>
                <div class="variant-content">
                    <div class="variant-row" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div class="variant-field">
                            <label class="variant-label">${type} Name</label>
                            <input type="text" class="form-control-modern" name="variant_name_${i}" 
                                   placeholder="e.g., ${placeholderText}" required>
                        </div>
                        <div class="variant-field">
                            <label class="variant-label">Sale Price</label>
                            <input type="number" class="form-control-modern" name="variant_price_${i}" 
                                   placeholder="0.00" min="0" step="0.01">
                        </div>
                        <div class="variant-field">
                            <label class="variant-label">Original Price</label>
                            <input type="number" class="form-control-modern" name="variant_original_price_${i}" 
                                   placeholder="0.00" min="0" step="0.01">
                        </div>
                        <div class="variant-field">
                            <label class="variant-label">
                                <i class="fas fa-boxes me-1"></i>Stock Count
                            </label>
                            <input type="number" class="form-control-modern" name="variant_stock_count_${i}" 
                                   placeholder="1000" min="0" step="1" value="1000">
                        </div>
                    </div>
                    <div class="variant-row">
                        <div class="variant-field">
                            <label class="variant-label">
                                <i class="fas fa-image me-1"></i>Variant Image
                            </label>
                            <select class="form-control-modern" id="variantImageSelect_${i}" name="variant_image_index_${i}" onchange="previewVariantImage(${i})">
                                <option value="">Select from images...</option>
                            </select>
                            <div id="variantImagePreview_${i}" class="variant-image-preview mt-2"></div>
                        </div>
                    </div>
                </div>
            `;
            variantItem.innerHTML = html;
            container.appendChild(variantItem);
        }
        
        // Populate variant image selectors after creating variants
        populateVariantImageSelectors();
    }
}

function populateVariantImageSelectors() {
    const variantCount = parseInt(document.getElementById('variant_count').value);
    
    // Populate simple variant selectors
    for (let i = 1; i <= variantCount; i++) {
        const select = document.getElementById(`variantImageSelect_${i}`);
        if (select) {
            select.innerHTML = '<option value="">Select from images...</option>';
            
            uploadedImages.forEach((img, index) => {
                const option = document.createElement('option');
                option.value = index;
                option.textContent = `Image ${index + 1} - ${img.name}`;
                option.dataset.src = img.data;
                select.appendChild(option);
            });
        }
    }
    
    // Populate combination variant selectors
    const comboGrid = document.getElementById('combinationsGrid');
    if (comboGrid) {
        const comboSelects = comboGrid.querySelectorAll('select[id^="comboImageSelect_"]');
        comboSelects.forEach(select => {
            select.innerHTML = '<option value="">Select from images...</option>';
            
            uploadedImages.forEach((img, index) => {
                const option = document.createElement('option');
                option.value = index;
                option.textContent = `Image ${index + 1} - ${img.name}`;
                option.dataset.src = img.data;
                select.appendChild(option);
            });
        });
    }
}

// Preview selected variant image
function previewVariantImage(variantIndex) {
    const select = document.getElementById(`variantImageSelect_${variantIndex}`);
    const preview = document.getElementById(`variantImagePreview_${variantIndex}`);
    
    if (!select || !preview) return;
    
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.dataset.src) {
        preview.innerHTML = `
            <div class="variant-image-preview-box">
                <img src="${selectedOption.dataset.src}" alt="Variant Image">
                <span class="preview-badge"><i class="fas fa-check-circle"></i> Selected</span>
            </div>
        `;
    } else {
        preview.innerHTML = '';
    }
}

// Preview selected combination variant image
function previewComboImage(comboIndex) {
    const select = document.getElementById(`comboImageSelect_${comboIndex}`);
    const preview = document.getElementById(`comboImagePreview_${comboIndex}`);
    
    if (!select || !preview) return;
    
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.dataset.src) {
        preview.innerHTML = `
            <div class="variant-image-preview-box">
                <img src="${selectedOption.dataset.src}" alt="Combination Image" style="max-width: 80px; border-radius: 4px;">
                <span class="preview-badge"><i class="fas fa-check-circle"></i> Selected</span>
            </div>
        `;
    } else {
        preview.innerHTML = '';
    }
}

// Helper function to get placeholder text for variant types
function getPlaceholderForType(type) {
    const placeholders = {
        'Color': 'Red',
        'Design': 'Classic Design',
        'Size': 'Large',
        'Style': 'Modern Style',
        'Material': 'Cotton',
        'Brand': 'Brand Name'
    };
    return placeholders[type] || 'Custom Value';
}

// Add custom variant type
async function addCustomVariantType() {
    const input = document.getElementById('customVariantTypeName');
    const typeName = input.value.trim();
    
    if (!typeName) {
        alert('Please enter a variant type name');
        return;
    }
    
    if (typeName.length > 100) {
        alert('Variant type name must be 100 characters or less');
        return;
    }
    
    try {
        // Send AJAX request to save the new variant type
        const response = await fetch('ajax/add_variant_type.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ type_name: typeName })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Add the new option to the dropdown
            const select = document.getElementById('variant_type');
            const customOption = select.querySelector('option[value="custom"]');
            
            // Create new option
            const newOption = document.createElement('option');
            newOption.value = typeName;
            newOption.textContent = typeName + ' Variants';
            newOption.selected = true;
            
            // Insert before the "Add Custom Type" option
            select.insertBefore(newOption, customOption);
            
            // Hide custom input and clear it
            document.getElementById('customVariantTypeInput').style.display = 'none';
            input.value = '';
            
            // Update the variants display
            updateVariantType();
            
            // Show success message
            showToast('Custom variant type "' + typeName + '" added successfully!', 'success');
        } else {
            alert('Error: ' + (result.message || 'Failed to add variant type'));
        }
    } catch (error) {
        console.error('Error adding variant type:', error);
        alert('Error adding variant type. Please try again.');
    }
}

// Cancel custom variant type input
function cancelCustomVariantType() {
    document.getElementById('customVariantTypeInput').style.display = 'none';
    document.getElementById('customVariantTypeName').value = '';
    
    // Reset dropdown to first option
    const select = document.getElementById('variant_type');
    select.selectedIndex = 0;
    updateVariantType();
}

// Auto-resize textareas
document.querySelectorAll('textarea').forEach(textarea => {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});

// Form validation
document.getElementById('productForm').addEventListener('submit', function(e) {
    // Basic validation
    const requiredFields = ['name', 'category_id', 'original_price'];
    let isValid = true;

    requiredFields.forEach(field => {
        const element = document.getElementById(field);
        if (!element.value.trim()) {
            element.style.borderColor = '#ef4444';
            isValid = false;
        } else {
            element.style.borderColor = '#e5e7eb';
        }
    });

    if (!isValid) {
        e.preventDefault();
        showAlert('Please fill in all required fields.', 'error', 'Validation Error');
    }
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Set default values for dropdowns
    selectStatus('In Stock');
    selectDisplay('Both');
});

/* ========================================
   COMBINATION VARIANT FUNCTIONS
   ======================================== */

let attributeCounter = 0;
let attributes = [];

// Switch between simple and combination variant modes
function switchVariantMode(mode) {
    document.getElementById('variant_mode').value = mode;
    
    if (mode === 'simple') {
        document.getElementById('simpleVariantBtn').classList.add('active');
        document.getElementById('combinationVariantBtn').classList.remove('active');
        document.getElementById('simpleVariantSection').style.display = 'block';
        document.getElementById('combinationVariantSection').style.display = 'none';
    } else {
        document.getElementById('simpleVariantBtn').classList.remove('active');
        document.getElementById('combinationVariantBtn').classList.add('active');
        document.getElementById('simpleVariantSection').style.display = 'none';
        document.getElementById('combinationVariantSection').style.display = 'block';
        
        // Initialize with default attributes if empty
        if (attributes.length === 0) {
            addAttribute('Color');
            addAttribute('Size');
        }
    }
}

// Add a new attribute
function addAttribute(defaultName = '') {
    attributeCounter++;
    const container = document.getElementById('attributesContainer');
    
    const attributeDiv = document.createElement('div');
    attributeDiv.className = 'attribute-builder-item';
    attributeDiv.id = `attribute_${attributeCounter}`;
    attributeDiv.dataset.id = attributeCounter;
    
    attributeDiv.innerHTML = `
        <div class="attribute-header">
            <input type="text" class="form-control form-control-sm attribute-name" 
                   placeholder="Attribute Name (e.g., Color, Size, Design)" 
                   value="${defaultName}" required>
            <button type="button" class="btn btn-sm btn-danger" onclick="removeAttribute(${attributeCounter})">
                <i class="fas fa-trash"></i>
            </button>
        </div>
        <div class="attribute-values">
            <div class="input-group mb-2">
                <input type="text" class="form-control form-control-sm value-input" 
                       placeholder="Value (e.g., Red, Large, Design A)">
                <button type="button" class="btn btn-sm btn-primary" onclick="addValue(${attributeCounter})">
                    <i class="fas fa-plus"></i> Add
                </button>
            </div>
            <div class="values-list" id="values_${attributeCounter}">
                <!-- Values will be added here -->
            </div>
        </div>
    `;
    
    container.appendChild(attributeDiv);
    
    attributes.push({
        id: attributeCounter,
        name: defaultName,
        values: []
    });
}

// Remove an attribute
function removeAttribute(id) {
    const element = document.getElementById(`attribute_${id}`);
    if (element) {
        element.remove();
    }
    attributes = attributes.filter(attr => attr.id !== id);
    
    // Clear combinations if attributes are removed
    document.getElementById('combinationsGrid').innerHTML = '<p class="text-muted text-center">Add attributes and click "Generate Combinations" to create variant combinations.</p>';
}

// Add a value to an attribute
function addValue(attributeId) {
    const attributeDiv = document.getElementById(`attribute_${attributeId}`);
    const input = attributeDiv.querySelector('.value-input');
    const value = input.value.trim();
    
    if (!value) {
        showAlert('Please enter a value', 'warning');
        return;
    }
    
    const attribute = attributes.find(attr => attr.id === attributeId);
    if (!attribute) return;
    
    // Check for duplicates
    if (attribute.values.includes(value)) {
        showAlert('This value already exists', 'warning', 'Duplicate Value');
        return;
    }
    
    attribute.values.push(value);
    
    const valuesList = document.getElementById(`values_${attributeId}`);
    const valueBadge = document.createElement('span');
    valueBadge.className = 'badge bg-primary me-2 mb-2 value-badge';
    valueBadge.innerHTML = `
        ${value}
        <i class="fas fa-times ms-1" onclick="removeValue(${attributeId}, '${value.replace(/'/g, "\\'")}')"></i>
    `;
    valuesList.appendChild(valueBadge);
    
    input.value = '';
    input.focus();
}

// Remove a value from an attribute
function removeValue(attributeId, value) {
    const attribute = attributes.find(attr => attr.id === attributeId);
    if (!attribute) return;
    
    attribute.values = attribute.values.filter(v => v !== value);
    
    // Re-render values
    const valuesList = document.getElementById(`values_${attributeId}`);
    const badges = valuesList.querySelectorAll('.value-badge');
    badges.forEach(badge => {
        if (badge.textContent.trim().startsWith(value)) {
            badge.remove();
        }
    });
}

// Generate all possible combinations
function generateCombinations() {
    // Update attribute names from inputs
    attributes.forEach(attr => {
        const input = document.getElementById(`attribute_${attr.id}`).querySelector('.attribute-name');
        attr.name = input.value.trim();
    });
    
    // Validate
    const validAttributes = attributes.filter(attr => attr.name && attr.values.length > 0);
    
    if (validAttributes.length === 0) {
        showAlert('Please add at least one attribute with values', 'warning', 'Missing Attributes');
        return;
    }
    
    // Generate Cartesian product
    const combinations = cartesianProduct(validAttributes);
    
    // Render combinations grid
    renderCombinationsGrid(validAttributes, combinations);
}

// Calculate Cartesian product of all attribute values
function cartesianProduct(attributes) {
    if (attributes.length === 0) return [[]];
    if (attributes.length === 1) return attributes[0].values.map(v => [{ name: attributes[0].name, value: v }]);
    
    const result = [];
    const first = attributes[0];
    const rest = cartesianProduct(attributes.slice(1));
    
    first.values.forEach(value => {
        rest.forEach(combo => {
            result.push([{ name: first.name, value: value }, ...combo]);
        });
    });
    
    return result;
}

// Render the combinations grid
function renderCombinationsGrid(attributes, combinations) {
    const grid = document.getElementById('combinationsGrid');
    
    if (combinations.length === 0) {
        grid.innerHTML = '<p class="text-muted text-center">No combinations generated</p>';
        return;
    }
    
    let html = `
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Generated <strong>${combinations.length}</strong> combinations
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>#</th>
    `;
    
    // Add attribute name columns
    attributes.forEach(attr => {
        html += `<th>${attr.name}</th>`;
    });
    
    html += `
                        <th>Price (₹)</th>
                        <th>Stock</th>
                        <th>SKU (Optional)</th>
                        <th>Variant Image</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    // Add combination rows
    combinations.forEach((combo, index) => {
        html += `<tr>`;
        html += `<td>${index + 1}</td>`;
        
        // Add attribute value cells
        combo.forEach(attr => {
            html += `<td><strong>${attr.value}</strong></td>`;
            html += `<input type="hidden" name="combinations[${index}][${attr.name}]" value="${attr.value}">`;
        });
        
        // Add price and stock inputs
        html += `
            <td>
                <input type="number" class="form-control form-control-sm" 
                       name="combinations[${index}][price]" 
                       placeholder="0.00" min="0" step="0.01" required>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm" 
                       name="combinations[${index}][stock]" 
                       placeholder="0" min="0" value="0" required>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm" 
                       name="combinations[${index}][sku]" 
                       placeholder="Auto-generated">
            </td>
            <td>
                <select class="form-control form-control-sm" id="comboImageSelect_${index}" name="combinations[${index}][image_index]" onchange="previewComboImage(${index})">
                    <option value="">Select image...</option>
                </select>
                <div id="comboImagePreview_${index}" class="mt-2"></div>
            </td>
        `;
        html += `</tr>`;
    });
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    grid.innerHTML = html;
}

</script>

<style>
/* Import Card Styles */
.import-card {
    background: white;
    border-radius: 16px;
    padding: 32px;
    border: 2px solid #e5e7eb;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
}

.import-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 2px solid #f3f4f6;
}

.import-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.4rem;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.import-title h6 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 600;
    color: #2d3748;
}

.import-title small {
    color: #718096;
    font-size: 0.85rem;
}

.import-buttons {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.btn-import {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border: 2px solid #e2e8f0;
    color: #334155;
    padding: 14px 20px;
    border-radius: 12px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.btn-import:hover {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border-color: var(--primary-color);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.3);
}

.btn-import i {
    font-size: 1rem;
}

/* Form Card Styles */
.form-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.form-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 24px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
    position: relative;
}

.form-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80px;
    height: 2px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border-radius: 1px;
}

.form-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0;
}

.form-actions {
    display: flex;
    gap: 12px;
}

.btn-outline-modern {
    background: white;
    border: 2px solid #e9ecef;
    color: #6b7280;
    padding: 10px 20px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-outline-modern:hover {
    background: #f8f9fa;
    border-color: #d1d5db;
    color: #374151;
    text-decoration: none;
}

/* Form Sections */
.section-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 24px 32px 16px;
    border-bottom: 1px solid #f3f4f6;
}

.section-icon {
    width: 44px;
    height: 44px;
    min-width: 44px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.1rem;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.25);
    transition: all 0.3s ease;
    cursor: pointer;
    flex-shrink: 0;
    margin-top: 2px;
}

.section-icon:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.35);
}

.section-title {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.section-title h6 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
    line-height: 1.4;
}

.section-title small {
    color: #718096;
    font-size: 0.85rem;
    display: block;
    margin-top: 4px;
}

.section-content {
    padding: 0 32px 32px;
}

/* Form Grid and Fields */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.form-group-modern {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group-modern.full-width {
    grid-column: 1 / -1;
}

.form-label-modern {
    font-size: 0.9rem;
    font-weight: 600;
    color: #374151;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.required {
    color: #ef4444;
    font-size: 0.8rem;
}

.input-wrapper-modern {
    position: relative;
    display: flex;
    align-items: center;
}

.form-control-modern {
    width: 100%;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px 50px 14px 16px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: white;
}

.form-control-modern:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
}

.input-icon {
    position: absolute;
    right: 16px;
    color: #9ca3af;
    font-size: 0.9rem;
    pointer-events: none;
}

.textarea-wrapper-modern {
    position: relative;
}

.textarea-modern {
    min-height: 80px;
    resize: vertical;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px 50px 14px 16px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: white;
    font-family: inherit;
}

.textarea-modern:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
}

.textarea-icon {
    position: absolute;
    right: 16px;
    top: 14px;
    color: #9ca3af;
    font-size: 0.9rem;
    pointer-events: none;
}

.form-text-modern {
    font-size: 0.8rem;
    color: #718096;
    margin-top: 4px;
}

/* Modern Dropdowns */
.custom-dropdown-modern {
    position: relative;
    width: 100%;
}

.dropdown-selected-modern {
    background: white;
    border: 2px solid #e5e7eb;
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
    z-index: 1000;
    max-height: 0;
    overflow: hidden;
    opacity: 0;
    transform: translateY(-10px);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.custom-dropdown-modern.active .dropdown-options-modern {
    max-height: 300px;
    opacity: 1;
    transform: translateY(0);
    overflow-y: auto;
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

/* Image Upload Styles */
.image-upload-section {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.file-upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    padding: 40px;
    text-align: center;
    background: #fafafa;
    transition: all 0.3s ease;
    cursor: pointer;
}

.file-upload-area:hover {
    border-color: var(--primary-color);
    background: #f0f9ff;
}

.upload-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}

.upload-icon {
    font-size: 3rem;
    color: #9ca3af;
}

.upload-text h6 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #374151;
}

.upload-text p {
    margin: 0;
    color: #6b7280;
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
    color: white;
}

.image-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.image-preview-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    background: white;
    border: 2px solid #e5e7eb;
    transition: all 0.3s ease;
}

.image-preview-item:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.2);
}

.image-number {
    position: absolute;
    top: 8px;
    left: 8px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
}

.image-preview-item img {
    width: 100%;
    height: 120px;
    object-fit: cover;
    display: block;
}

.btn-remove-image {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgba(239, 68, 68, 0.9);
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    transition: all 0.3s ease;
}

.btn-remove-image:hover {
    background: rgba(239, 68, 68, 1);
    transform: scale(1.1);
}

.special-images-section {
    margin-top: 24px;
}

.section-subtitle {
    font-size: 1rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.special-images-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.special-image-upload {
    position: relative;
}

.upload-label-modern {
    display: block;
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    background: #fafafa;
    transition: all 0.3s ease;
    cursor: pointer;
}

.upload-label-modern:hover {
    border-color: var(--primary-color);
    background: #f0f9ff;
}

.upload-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.upload-placeholder i {
    font-size: 2rem;
    color: #9ca3af;
}

.upload-placeholder span {
    font-weight: 600;
    color: #374151;
}

.upload-placeholder small {
    color: #6b7280;
    font-size: 0.8rem;
}

.image-preview-single {
    width: 100%;
    height: 120px;
    border-radius: 8px;
    margin-top: 12px;
    display: none;
}

/* Image Selector Styles */
.image-selector-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.image-selector-preview {
    min-height: 60px;
}

.selected-image-preview {
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid var(--primary-color);
    background: white;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.15);
}

.selected-image-preview img {
    width: 100%;
    height: 180px;
    object-fit: cover;
    display: block;
}

.preview-label {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
    color: white;
    padding: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Features Styles */
.features-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.feature-item-modern {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e9ecef;
}

.feature-inputs {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 12px;
}

.feature-name, .feature-desc {
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.feature-name:focus, .feature-desc:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
}

.btn-remove-modern {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.btn-remove-modern:hover {
    background: #dc2626;
    color: white;
    transform: scale(1.1);
}

.btn-add-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
    align-self: flex-start;
}

.btn-add-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
    color: white;
}

/* Variants Styles */
.variants-config {
    margin-bottom: 24px;
}

.variant-controls {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.control-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.control-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #374151;
}

.variants-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.variant-item-modern {
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    overflow: hidden;
}

.variant-header {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    padding: 12px 20px;
    color: white;
}

.variant-number {
    font-weight: 600;
    font-size: 0.9rem;
}

.variant-content {
    padding: 20px;
}

.variant-row {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-wrap: nowrap;
}

.variant-field {
    flex: 1;
    min-width: 0;
}

.variant-field:nth-child(1) {
    flex: 1.5;
}

.variant-field:nth-child(4) {
    flex: 0.8;
}

.variant-field:nth-child(5) {
    flex: 1.5;
}

.variant-field input,
.variant-field select {
    width: 100%;
    min-width: 0;
}

.variant-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.variant-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #374151;
}

/* Info Alert */
.info-alert-modern {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 16px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 20px;
}

.alert-icon {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--primary-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    flex-shrink: 0;
}

.alert-content {
    flex: 1;
}

.alert-content strong {
    color: #1e40af;
}

/* File Upload Single */
.file-upload-single {
    margin-top: 16px;
}

/* Form Actions */
.form-actions-section {
    padding: 32px;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
}

.form-actions-content {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.form-info {
    margin-left: auto;
}

/* Smart Import Modal Styles */
.import-steps {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 20px;
    padding: 0 20px;
}

.step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    flex: 1;
}

.step-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e5e7eb;
    color: #9ca3af;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
    transition: all 0.3s ease;
}

.step-item.active .step-circle {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.step-item.completed .step-circle {
    background: #10b981;
    color: white;
}

.step-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #6b7280;
    text-align: center;
}

.step-item.active .step-label {
    color: var(--primary-color);
}

.step-line {
    flex: 1;
    height: 2px;
    background: #e5e7eb;
    margin: 0 10px;
    margin-bottom: 30px;
}

.step-item.completed ~ .step-line {
    background: #10b981;
}

.upload-option-card {
    border: 2px dashed #d1d5db;
    border-radius: 16px;
    padding: 30px;
    text-align: center;
    background: #fafafa;
    transition: all 0.3s ease;
    height: 100%;
}

.upload-option-card:hover {
    border-color: var(--primary-color);
    background: #f0f9ff;
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0, 88, 163, 0.15);
}

.upload-option-icon {
    font-size: 3rem;
    color: var(--primary-color);
    margin-bottom: 15px;
}

.upload-option-card h6 {
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 10px;
}

.mapping-field {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 15px;
    transition: all 0.3s ease;
}

.mapping-field:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.1);
}

.mapping-field label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.mapping-field .required-badge {
    background: #fee2e2;
    color: #dc2626;
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: 600;
}

#previewTable {
    font-size: 0.9rem;
}

#previewTable th {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    font-weight: 700;
    color: #1f2937;
    white-space: nowrap;
}

#previewTable td {
    padding: 12px;
}

#previewTable td[contenteditable="true"] {
    background: #fffbeb;
    cursor: text;
}

#previewTable td[contenteditable="true"]:hover {
    background: #fef3c7;
    outline: 2px solid #fbbf24;
}

#previewTable .error-cell {
    background: #fee2e2;
    color: #dc2626;
    font-weight: 600;
}

.summary-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
}

.summary-card:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.15);
    transform: translateY(-3px);
}

.summary-icon {
    font-size: 2rem;
    color: var(--primary-color);
    margin-bottom: 10px;
}

.summary-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
}

.summary-label {
    font-size: 0.85rem;
    color: #6b7280;
    margin-top: 5px;
}

.validation-error {
    background: #fee2e2;
    border-left: 4px solid #dc2626;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 10px;
}

.validation-warning {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 10px;
}

/* Variant Image Preview Styles */
.variant-image-preview {
    min-height: 40px;
}

.variant-image-preview-box {
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid var(--primary-color);
    background: white;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.15);
    max-width: 200px;
}

.variant-image-preview-box img {
    width: 100%;
    height: 120px;
    object-fit: cover;
    display: block;
}

.preview-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
}

/* Responsive Design */
@media (max-width: 1024px) {
    .form-grid {
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
    }

    .section-content {
        padding: 0 24px 24px;
    }

    .section-header {
        padding: 20px 24px 12px;
    }

    .form-header {
        padding: 20px 24px;
    }
}

/* Tablet Responsive */
@media (max-width: 1024px) and (min-width: 769px) {
    .variant-row {
        gap: 8px;
    }
    
    .variant-field {
        flex: 1 !important;
    }
}

@media (max-width: 768px) {
    .form-card {
        margin: 0 -15px;
        border-radius: 0;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .special-images-grid {
        grid-template-columns: 1fr;
    }

    .variant-controls {
        grid-template-columns: 1fr;
    }

    .variant-row {
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .variant-field {
        flex: 1 1 calc(50% - 5px) !important;
        min-width: calc(50% - 5px);
    }
    
    .variant-field:first-child,
    .variant-field:last-child {
        flex: 1 1 100% !important;
        min-width: 100%;
    }

    .form-actions-content {
        flex-direction: column;
        gap: 12px;
    }

    .form-info {
        margin-left: 0;
        margin-top: 12px;
    }

    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
}

@media (max-width: 576px) {
    .section-content {
        padding: 0 16px 20px;
    }

    .section-header {
        padding: 16px 16px 8px;
    }

    .form-header {
        padding: 16px 16px;
        flex-direction: column;
        gap: 12px;
        text-align: center;
    }

    .file-upload-area {
        padding: 24px 16px;
    }

    .image-preview-grid {
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 12px;
    }

    .feature-inputs {
        grid-template-columns: 1fr;
    }
}

/* Additional styles for existing images */
.existing-images-section {
    margin-bottom: 24px;
}

.existing-images-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.existing-image-item {
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid #e5e7eb;
    background: white;
    transition: all 0.3s ease;
}

.existing-image-item:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.2);
}

.existing-image-item img {
    width: 100%;
    height: 150px;
    object-fit: cover;
    display: block;
}

.existing-image-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.existing-image-item:hover .existing-image-overlay {
    opacity: 1;
}

.existing-image-overlay .badge {
    position: static;
    margin: 0;
}

.existing-image-overlay .btn {
    font-size: 0.75rem;
    padding: 4px 8px;
}

/* Combination Variant Styles */
.variant-mode-toggle {
    margin-bottom: 24px;
}

.btn-variant-mode {
    flex: 1;
    padding: 16px 20px;
    border: 2px solid #e5e7eb;
    background: white;
    color: #6b7280;
    transition: all 0.3s ease;
    text-align: center;
}

.btn-variant-mode:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
}

.btn-variant-mode.active {
    background: linear-gradient(135deg, var(--primary-color) 0%, #0066cc 100%);
    border-color: var(--primary-color);
    color: white;
}

.btn-variant-mode i {
    font-size: 1.5rem;
    display: block;
    margin-bottom: 8px;
}

.btn-variant-mode small {
    font-size: 0.75rem;
    opacity: 0.9;
}

.combination-builder {
    padding: 20px;
    background: #f9fafb;
    border-radius: 12px;
}

.attribute-builder-item {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
}

.attribute-header {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 12px;
}

.attribute-header .attribute-name {
    flex: 1;
    font-weight: 600;
    border: 2px solid #e5e7eb;
    padding: 8px 12px;
}

.attribute-values {
    margin-top: 12px;
}

.values-list {
    min-height: 40px;
    padding: 8px;
    background: #f9fafb;
    border-radius: 8px;
    margin-top: 8px;
}

.value-badge {
    font-size: 0.875rem;
    padding: 6px 12px;
    cursor: default;
}

.value-badge i {
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.value-badge i:hover {
    opacity: 1;
}

.combinations-grid {
    margin-top: 24px;
}

.combinations-grid table {
    font-size: 0.875rem;
    background: white;
}

.combinations-grid thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.combinations-grid thead th {
    border-color: #764ba2;
    font-weight: 600;
    padding: 12px;
}

.combinations-grid tbody td {
    padding: 8px;
    vertical-align: middle;
}

.combinations-grid tbody tr:hover {
    background: #f0f9ff;
}

.combinations-grid input {
    min-width: 100px;
}
</style>

<!-- Smart Import Modal (4-Step Wizard) -->
<div class="modal fade" id="smartImportModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <!-- Modal Header with Progress Steps -->
            <div class="modal-header border-0 pb-0">
                <div class="w-100">
                    <h5 class="modal-title mb-3">
                        <i class="fas fa-file-import me-2"></i>
                        <span id="importModalTitle">Import Data from Google Sheet or File</span>
                    </h5>
                    <!-- Step Indicator -->
                    <div class="import-steps">
                        <div class="step-item active" data-step="1">
                            <div class="step-circle">1</div>
                            <div class="step-label">Upload Sheet</div>
                        </div>
                        <div class="step-line"></div>
                        <div class="step-item" data-step="2">
                            <div class="step-circle">2</div>
                            <div class="step-label">Map Columns</div>
                        </div>
                        <div class="step-line"></div>
                        <div class="step-item" data-step="3">
                            <div class="step-circle">3</div>
                            <div class="step-label">Preview Data</div>
                        </div>
                        <div class="step-line"></div>
                        <div class="step-item" data-step="4">
                            <div class="step-circle">4</div>
                            <div class="step-label">Confirm Import</div>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" onclick="closeSmartImport()"></button>
            </div>

            <!-- Modal Body with Step Content -->
            <div class="modal-body">
                <!-- Step 1: Upload Sheet -->
                <div class="import-step-content" id="step1Content">
                    <div class="upload-options">
                        <div class="row g-4">
                            <!-- Option 1: Upload File -->
                            <div class="col-md-6">
                                <div class="upload-option-card">
                                    <div class="upload-option-icon">
                                        <i class="fas fa-file-upload"></i>
                                    </div>
                                    <h6>Upload Excel or CSV File</h6>
                                    <p class="text-muted">Upload .xlsx, .xls, or .csv file from your computer</p>
                                    <input type="file" id="smartFileInput" accept=".csv,.xlsx,.xls" class="d-none" onchange="handleFileUpload(event)">
                                    <button class="btn btn-primary" onclick="document.getElementById('smartFileInput').click()">
                                        <i class="fas fa-upload me-2"></i>Choose File
                                    </button>
                                    <div id="fileNameDisplay" class="mt-2 text-success"></div>
                                </div>
                            </div>

                            <!-- Option 2: Google Sheets -->
                            <div class="col-md-6">
                                <div class="upload-option-card">
                                    <div class="upload-option-icon">
                                        <i class="fab fa-google"></i>
                                    </div>
                                    <h6>Import from Google Sheets</h6>
                                    <p class="text-muted">Paste a public Google Sheets link</p>
                                    <input type="text" id="googleSheetUrl" class="form-control mb-2" placeholder="https://docs.google.com/spreadsheets/d/...">
                                    <button class="btn btn-success" onclick="handleGoogleSheetImport()">
                                        <i class="fab fa-google me-2"></i>Fetch from Google Sheets
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Sheet Selection (for multi-sheet files) -->
                        <div id="sheetSelector" class="mt-4" style="display: none;">
                            <label class="form-label fw-bold">Select Sheet:</label>
                            <select id="sheetSelect" class="form-control" onchange="loadSelectedSheet()">
                            </select>
                        </div>

                        <!-- Download Template -->
                        <div class="mt-4 text-center">
                            <button class="btn btn-outline-secondary" onclick="downloadTemplate()">
                                <i class="fas fa-download me-2"></i>Download Template for <span id="templateType">Product</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Map Columns -->
                <div class="import-step-content" id="step2Content" style="display: none;">
                    <div class="mapping-container">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Map your spreadsheet columns to system fields.</strong> 
                            Select the corresponding column for each field below.
                        </div>
                        <div id="columnMappingArea" class="row g-3">
                            <!-- Dynamic mapping fields will be inserted here -->
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-sm btn-outline-primary" onclick="autoMapColumns()">
                                <i class="fas fa-magic me-2"></i>Auto-Map Columns
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="saveMapping()">
                                <i class="fas fa-save me-2"></i>Save Mapping for Later
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Preview Data -->
                <div class="import-step-content" id="step3Content" style="display: none;">
                    <div class="preview-container">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Preview First 10 Rows</h6>
                            <span class="badge bg-info" id="rowCount">0 rows detected</span>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Review the data below. You can edit values directly in the table before importing.
                        </div>
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-bordered table-hover" id="previewTable">
                                <thead class="table-light sticky-top">
                                    <!-- Dynamic headers -->
                                </thead>
                                <tbody>
                                    <!-- Dynamic preview rows -->
                                </tbody>
                            </table>
                        </div>
                        <div id="validationErrors" class="mt-3"></div>
                    </div>
                </div>

                <!-- Step 4: Confirm Import -->
                <div class="import-step-content" id="step4Content" style="display: none;">
                    <div class="confirm-container text-center">
                        <div class="success-icon mb-3">
                            <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                        </div>
                        <h5>Ready to Import!</h5>
                        <p class="text-muted">Click the button below to import <span id="importCount">0</span> rows into your form.</p>
                        <div class="import-summary mt-4">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="summary-card">
                                        <div class="summary-icon"><i class="fas fa-file-alt"></i></div>
                                        <div class="summary-value" id="summaryRows">0</div>
                                        <div class="summary-label">Total Rows</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-card">
                                        <div class="summary-icon"><i class="fas fa-columns"></i></div>
                                        <div class="summary-value" id="summaryColumns">0</div>
                                        <div class="summary-label">Mapped Fields</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-card">
                                        <div class="summary-icon"><i class="fas fa-exclamation-triangle"></i></div>
                                        <div class="summary-value text-warning" id="summaryErrors">0</div>
                                        <div class="summary-label">Warnings</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading Spinner -->
                <div id="loadingSpinner" class="text-center" style="display: none;">
                    <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Processing your data...</p>
                </div>
            </div>

            <!-- Modal Footer with Navigation -->
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSmartImport()">Cancel</button>
                <button type="button" class="btn btn-outline-primary" id="prevBtn" onclick="previousStep()" style="display: none;">
                    <i class="fas fa-arrow-left me-2"></i>Previous
                </button>
                <button type="button" class="btn btn-primary" id="nextBtn" onclick="nextStep()" disabled>
                    Next<i class="fas fa-arrow-right ms-2"></i>
                </button>
                <button type="button" class="btn btn-success" id="importBtn" onclick="finalizeImport()" style="display: none;">
                    <i class="fas fa-check me-2"></i>Import Now
                </button>
            </div>
        </div>
    </div>
</div>

<script>
/* ========================================
   SMART IMPORT SYSTEM - CONFIGURATION
   ======================================== */

// Global variables
let currentModule = '';
let currentStep = 1;
let importedData = [];
let detectedColumns = [];
let columnMapping = {};
let workbook = null;
let selectedSheetName = '';

// Module field configurations
const moduleFields = {
    product: [
        { key: 'name', label: 'Product Name', required: true, type: 'text' },
        { key: 'category', label: 'Category', required: false, type: 'text' },
        { key: 'original_price', label: 'Original Price', required: true, type: 'number' },
        { key: 'discounted_price', label: 'Discounted Price', required: false, type: 'number' },
        { key: 'commission', label: 'Commission (PKR)', required: false, type: 'number' },
        { key: 'delivery_charges', label: 'Delivery Charges', required: false, type: 'number' },
        { key: 'stock_count', label: 'Stock Count', required: false, type: 'number' },
        { key: 'sales_count', label: 'Products Sold', required: false, type: 'number' },
        { key: 'status', label: 'Product Status', required: false, type: 'text' },
        { key: 'display_location', label: 'Display Location', required: false, type: 'text' },
        { key: 'description', label: 'Description', required: false, type: 'text' },
        { key: 'keywords', label: 'Search Keywords', required: false, type: 'text' }
    ],
    variants: [
        { key: 'variant_type', label: 'Variant Type (Color/Design)', required: true, type: 'text' },
        { key: 'variant_name', label: 'Variant Name', required: true, type: 'text' },
        { key: 'sale_price', label: 'Sale Price', required: false, type: 'number' },
        { key: 'original_price', label: 'Original Price', required: false, type: 'number' },
        { key: 'stock_count', label: 'Stock Count', required: false, type: 'number' },
        { key: 'image_url', label: 'Image URL', required: false, type: 'text' }
    ],
    reviews: [
        { key: 'reviewer_name', label: 'Reviewer Name', required: true, type: 'text' },
        { key: 'rating', label: 'Rating (1-5)', required: true, type: 'number' },
        { key: 'review_text', label: 'Review Text', required: true, type: 'text' }
    ],
    features: [
        { key: 'feature_name', label: 'Feature Name', required: true, type: 'text' },
        { key: 'feature_description', label: 'Feature Description', required: true, type: 'text' }
    ]
};

/* ========================================
   STEP 1: OPEN MODAL & UPLOAD
   ======================================== */

function openSmartImport(module) {
    currentModule = module;
    currentStep = 1;
    importedData = [];
    detectedColumns = [];
    columnMapping = {};
    
    // Update modal title
    const titles = {
        product: 'Import Product Data',
        variants: 'Import Variants',
        reviews: 'Import Reviews',
        features: 'Import Features'
    };
    document.getElementById('importModalTitle').textContent = titles[module];
    document.getElementById('templateType').textContent = module.charAt(0).toUpperCase() + module.slice(1);
    
    // Reset form
    document.getElementById('smartFileInput').value = '';
    document.getElementById('googleSheetUrl').value = '';
    document.getElementById('fileNameDisplay').innerHTML = '';
    document.getElementById('sheetSelector').style.display = 'none';
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('smartImportModal'));
    modal.show();
    
    // Reset steps
    resetSteps();
    document.getElementById('nextBtn').disabled = true;
}

function closeSmartImport() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('smartImportModal'));
    if (modal) modal.hide();
}

function resetSteps() {
    // Reset step indicators
    document.querySelectorAll('.step-item').forEach((item, index) => {
        item.classList.remove('active', 'completed');
        if (index === 0) item.classList.add('active');
    });
    
    // Show only step 1 content
    document.querySelectorAll('.import-step-content').forEach(content => {
        content.style.display = 'none';
    });
    document.getElementById('step1Content').style.display = 'block';
    
    // Reset buttons
    document.getElementById('prevBtn').style.display = 'none';
    document.getElementById('nextBtn').style.display = 'inline-block';
    document.getElementById('nextBtn').disabled = true;
    document.getElementById('importBtn').style.display = 'none';
}

/* ========================================
   FILE UPLOAD HANDLING
   ======================================== */

function handleFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    showLoading(true);
    document.getElementById('fileNameDisplay').innerHTML = `<i class="fas fa-check-circle"></i> ${file.name}`;
    
    const reader = new FileReader();
    
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            workbook = XLSX.read(data, { type: 'array' });
            
            // Check if multi-sheet
            if (workbook.SheetNames.length > 1) {
                showSheetSelector();
            } else {
                selectedSheetName = workbook.SheetNames[0];
                processSheet();
            }
            
            showLoading(false);
            document.getElementById('nextBtn').disabled = false;
        } catch (error) {
            showLoading(false);
            alert('Error reading file: ' + error.message);
        }
    };
    
    reader.readAsArrayBuffer(file);
}

function showSheetSelector() {
    const selector = document.getElementById('sheetSelector');
    const select = document.getElementById('sheetSelect');
    
    select.innerHTML = '';
    workbook.SheetNames.forEach(name => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        select.appendChild(option);
    });
    
    selector.style.display = 'block';
    selectedSheetName = workbook.SheetNames[0];
    processSheet();
}

function loadSelectedSheet() {
    selectedSheetName = document.getElementById('sheetSelect').value;
    processSheet();
}

function processSheet() {
    const worksheet = workbook.Sheets[selectedSheetName];
    const jsonData = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
    
    if (jsonData.length === 0) {
        alert('Sheet is empty!');
        return;
    }
    
    // First row as headers
    detectedColumns = jsonData[0].map(col => col ? col.toString().trim() : '');
    
    // Rest as data
    importedData = jsonData.slice(1).filter(row => row.some(cell => cell !== null && cell !== ''));
    
    console.log('Detected columns:', detectedColumns);
    console.log('Imported rows:', importedData.length);
}

/* ========================================
   GOOGLE SHEETS INTEGRATION
   ======================================== */

function handleGoogleSheetImport() {
    const url = document.getElementById('googleSheetUrl').value.trim();
    
    if (!url) {
        alert('Please enter a Google Sheets URL');
        return;
    }
    
    // Extract sheet ID from URL
    const match = url.match(/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/);
    if (!match) {
        alert('Invalid Google Sheets URL');
        return;
    }
    
    const sheetId = match[1];
    const csvUrl = `https://docs.google.com/spreadsheets/d/${sheetId}/export?format=csv`;
    
    showLoading(true);
    
    fetch(csvUrl)
        .then(response => {
            if (!response.ok) throw new Error('Failed to fetch Google Sheet. Make sure it is publicly accessible.');
            return response.text();
        })
        .then(csvText => {
            // Parse CSV
            const rows = csvText.split('\n').map(row => {
                // Simple CSV parsing (handles quoted fields)
                const result = [];
                let current = '';
                let inQuotes = false;
                
                for (let i = 0; i < row.length; i++) {
                    const char = row[i];
                    if (char === '"') {
                        inQuotes = !inQuotes;
                    } else if (char === ',' && !inQuotes) {
                        result.push(current.trim());
                        current = '';
                    } else {
                        current += char;
                    }
                }
                result.push(current.trim());
                return result;
            });
            
            detectedColumns = rows[0];
            importedData = rows.slice(1).filter(row => row.some(cell => cell !== ''));
            
            showLoading(false);
            document.getElementById('nextBtn').disabled = false;
            document.getElementById('fileNameDisplay').innerHTML = '<i class="fas fa-check-circle"></i> Google Sheet loaded successfully';
        })
        .catch(error => {
            showLoading(false);
            alert(error.message);
        });
}

/* ========================================
   STEP NAVIGATION
   ======================================== */

function nextStep() {
    if (currentStep === 1) {
        // Move to mapping
        currentStep = 2;
        showStep(2);
        buildMappingInterface();
    } else if (currentStep === 2) {
        // Move to preview
        if (!validateMapping()) {
            alert('Please map all required fields');
            return;
        }
        currentStep = 3;
        showStep(3);
        buildPreview();
    } else if (currentStep === 3) {
        // Move to confirm
        currentStep = 4;
        showStep(4);
        buildSummary();
    }
}

function previousStep() {
    if (currentStep > 1) {
        currentStep--;
        showStep(currentStep);
    }
}

function showStep(step) {
    // Update step indicators
    document.querySelectorAll('.step-item').forEach((item, index) => {
        item.classList.remove('active');
        if (index < step - 1) {
            item.classList.add('completed');
        } else if (index === step - 1) {
            item.classList.add('active');
        }
    });
    
    // Show/hide content
    document.querySelectorAll('.import-step-content').forEach(content => {
        content.style.display = 'none';
    });
    document.getElementById(`step${step}Content`).style.display = 'block';
    
    // Update buttons
    document.getElementById('prevBtn').style.display = step > 1 ? 'inline-block' : 'none';
    document.getElementById('nextBtn').style.display = step < 4 ? 'inline-block' : 'none';
    document.getElementById('importBtn').style.display = step === 4 ? 'inline-block' : 'none';
    
    if (step === 2 || step === 3) {
        document.getElementById('nextBtn').disabled = false;
    }
}

function showLoading(show) {
    document.getElementById('loadingSpinner').style.display = show ? 'block' : 'none';
    document.querySelectorAll('.import-step-content').forEach(content => {
        content.style.display = show ? 'none' : content.style.display;
    });
}

/* ========================================
   STEP 2: COLUMN MAPPING
   ======================================== */

function buildMappingInterface() {
    const container = document.getElementById('columnMappingArea');
    container.innerHTML = '';
    
    const fields = moduleFields[currentModule];
    
    // Try to load saved mapping from localStorage
    const savedMapping = localStorage.getItem(`mapping_${currentModule}`);
    if (savedMapping) {
        columnMapping = JSON.parse(savedMapping);
    } else {
        columnMapping = {};
    }
    
    fields.forEach(field => {
        const col = document.createElement('div');
        col.className = 'col-md-6';
        col.innerHTML = `
            <div class="mapping-field">
                <label>
                    ${field.label}
                    ${field.required ? '<span class="required-badge">REQUIRED</span>' : ''}
                </label>
                <select class="form-control" id="map_${field.key}" onchange="updateMapping('${field.key}', this.value)">
                    <option value="">-- Select Column --</option>
                    ${detectedColumns.map((col, idx) => 
                        `<option value="${idx}" ${columnMapping[field.key] == idx ? 'selected' : ''}>${col || 'Column ' + (idx + 1)}</option>`
                    ).join('')}
                </select>
            </div>
        `;
        container.appendChild(col);
    });
}

function updateMapping(fieldKey, columnIndex) {
    if (columnIndex === '') {
        delete columnMapping[fieldKey];
    } else {
        columnMapping[fieldKey] = parseInt(columnIndex);
    }
}

function autoMapColumns() {
    const fields = moduleFields[currentModule];
    
    fields.forEach(field => {
        // Try to find matching column by name similarity
        const fieldLabel = field.label.toLowerCase();
        const matchIndex = detectedColumns.findIndex(col => {
            const colName = col.toLowerCase();
            return colName.includes(fieldLabel.split(' ')[0]) || 
                   fieldLabel.includes(colName.split(' ')[0]);
        });
        
        if (matchIndex !== -1) {
            columnMapping[field.key] = matchIndex;
            document.getElementById(`map_${field.key}`).value = matchIndex;
        }
    });
    
    showToast('Auto-mapping completed! Please review the mappings.');
}

function saveMapping() {
    localStorage.setItem(`mapping_${currentModule}`, JSON.stringify(columnMapping));
    showToast('Mapping saved successfully!');
}

function validateMapping() {
    const fields = moduleFields[currentModule];
    const requiredFields = fields.filter(f => f.required);
    
    for (let field of requiredFields) {
        if (!columnMapping[field.key] && columnMapping[field.key] !== 0) {
            return false;
        }
    }
    return true;
}

/* ========================================
   STEP 3: PREVIEW DATA
   ======================================== */

function buildPreview() {
    const table = document.getElementById('previewTable');
    const fields = moduleFields[currentModule];
    
    // Build header
    const thead = table.querySelector('thead');
    thead.innerHTML = '<tr>' + 
        fields.map(f => `<th>${f.label}</th>`).join('') + 
        '</tr>';
    
    // Build rows (first 10)
    const tbody = table.querySelector('tbody');
    tbody.innerHTML = '';
    
    const previewRows = importedData.slice(0, 10);
    let errorCount = 0;
    
    previewRows.forEach((row, rowIndex) => {
        const tr = document.createElement('tr');
        
        fields.forEach(field => {
            const td = document.createElement('td');
            const colIndex = columnMapping[field.key];
            const value = colIndex !== undefined ? (row[colIndex] || '') : '';
            
            td.textContent = value;
            td.contentEditable = 'true';
            td.dataset.row = rowIndex;
            td.dataset.field = field.key;
            
            // Validation
            if (field.required && !value) {
                td.classList.add('error-cell');
                td.title = 'Required field is empty';
                errorCount++;
            } else if (field.type === 'number' && value && isNaN(value)) {
                td.classList.add('error-cell');
                td.title = 'Must be a number';
                errorCount++;
            }
            
            // Update data on edit
            td.addEventListener('blur', function() {
                const rowIdx = parseInt(this.dataset.row);
                const fieldKey = this.dataset.field;
                const colIdx = columnMapping[fieldKey];
                if (colIdx !== undefined) {
                    importedData[rowIdx][colIdx] = this.textContent;
                }
                // Re-validate
                this.classList.remove('error-cell');
                if (field.required && !this.textContent) {
                    this.classList.add('error-cell');
                } else if (field.type === 'number' && this.textContent && isNaN(this.textContent)) {
                    this.classList.add('error-cell');
                }
            });
            
            tr.appendChild(td);
        });
        
        tbody.appendChild(tr);
    });
    
    // Update row count
    document.getElementById('rowCount').textContent = `${importedData.length} rows detected`;
    
    // Show validation errors
    const errorsDiv = document.getElementById('validationErrors');
    if (errorCount > 0) {
        errorsDiv.innerHTML = `
            <div class="validation-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>${errorCount} validation warnings found.</strong> 
                Please fix highlighted cells before importing.
            </div>
        `;
    } else {
        errorsDiv.innerHTML = `
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                All data validated successfully!
            </div>
        `;
    }
}

/* ========================================
   STEP 4: SUMMARY & IMPORT
   ======================================== */

function buildSummary() {
    document.getElementById('importCount').textContent = importedData.length;
    document.getElementById('summaryRows').textContent = importedData.length;
    document.getElementById('summaryColumns').textContent = Object.keys(columnMapping).length;
    
    // Count errors
    let errors = 0;
    const fields = moduleFields[currentModule];
    importedData.forEach(row => {
        fields.forEach(field => {
            const colIndex = columnMapping[field.key];
            const value = colIndex !== undefined ? row[colIndex] : '';
            if (field.required && !value) errors++;
            if (field.type === 'number' && value && isNaN(value)) errors++;
        });
    });
    
    document.getElementById('summaryErrors').textContent = errors;
}

function finalizeImport() {
    showLoading(true);
    
    setTimeout(() => {
        try {
            if (currentModule === 'product') {
                importProductData();
            } else if (currentModule === 'variants') {
                importVariantsData();
            } else if (currentModule === 'reviews') {
                importReviewsData();
            } else if (currentModule === 'features') {
                importFeaturesData();
            }
            
            showLoading(false);
            closeSmartImport();
            showToast(`Successfully imported ${importedData.length} ${currentModule}!`, 'success');
        } catch (error) {
            showLoading(false);
            alert('Error during import: ' + error.message);
        }
    }, 500);
}

/* ========================================
   MODULE-SPECIFIC IMPORT FUNCTIONS
   ======================================== */

function importProductData() {
    // Import only first row for product data
    const row = importedData[0];
    if (!row) return;
    
    const getValue = (key) => {
        const colIndex = columnMapping[key];
        return colIndex !== undefined ? (row[colIndex] || '') : '';
    };
    
    try {
        // Fill form fields
        const nameField = document.getElementById('name');
        if (nameField) nameField.value = getValue('name');
        
        const originalPriceField = document.getElementById('original_price');
        if (originalPriceField) originalPriceField.value = getValue('original_price');
        
        const discountedPriceField = document.getElementById('discounted_price');
        if (discountedPriceField) discountedPriceField.value = getValue('discounted_price');
        
        const commissionField = document.getElementById('commission_rate');
        if (commissionField) commissionField.value = getValue('commission') || '0';
        
        const deliveryField = document.getElementById('delivery_charges');
        if (deliveryField) deliveryField.value = getValue('delivery_charges') || '0';
        
        const stockField = document.getElementById('stock_count');
        if (stockField) stockField.value = getValue('stock_count') || '0';
        
        const salesField = document.getElementById('sales_count');
        if (salesField) salesField.value = getValue('sales_count') || '0';
        
        const shortDescField = document.getElementById('short_description');
        if (shortDescField) shortDescField.value = getValue('short_description');
        
        const descField = document.getElementById('description');
        if (descField) descField.value = getValue('description');
        
        const keywordsField = document.getElementById('keywords');
        if (keywordsField) keywordsField.value = getValue('keywords');
        
        // Handle status dropdown - find and click the matching status button
        const status = getValue('status');
        if (status) {
            const statusButtons = document.querySelectorAll('.status-option-modern');
            statusButtons.forEach(btn => {
                if (btn.textContent.trim().toLowerCase() === status.toLowerCase()) {
                    btn.click();
                }
            });
        }
        
        // Handle display location - find and click the matching display button
        const displayLoc = getValue('display_location');
        if (displayLoc) {
            const displayButtons = document.querySelectorAll('.display-option-modern');
            displayButtons.forEach(btn => {
                if (btn.textContent.trim().toLowerCase().includes(displayLoc.toLowerCase())) {
                    btn.click();
                }
            });
        }
    } catch (error) {
        console.error('Error importing product data:', error);
        throw new Error('Failed to import product data: ' + error.message);
    }
}

function importVariantsData() {
    const variantCount = Math.min(importedData.length, 10);
    document.getElementById('variant_count').value = variantCount;
    
    // Get variant type from first row
    const firstRow = importedData[0];
    const variantType = columnMapping['variant_type'] !== undefined ? 
        (firstRow[columnMapping['variant_type']] || 'Color') : 'Color';
    document.getElementById('variant_type').value = variantType;
    
    // Generate variant forms
    updateVariants();
    
    // Fill variant data
    setTimeout(() => {
        importedData.forEach((row, index) => {
            if (index >= 10) return;
            
            const i = index + 1;
            const getValue = (key) => {
                const colIndex = columnMapping[key];
                return colIndex !== undefined ? (row[colIndex] || '') : '';
            };
            
            const nameInput = document.querySelector(`input[name="variant_name_${i}"]`);
            const priceInput = document.querySelector(`input[name="variant_price_${i}"]`);
            const origPriceInput = document.querySelector(`input[name="variant_original_price_${i}"]`);
            const stockInput = document.querySelector(`input[name="variant_stock_count_${i}"]`);
            
            if (nameInput) nameInput.value = getValue('variant_name');
            if (priceInput) priceInput.value = getValue('sale_price');
            if (origPriceInput) origPriceInput.value = getValue('original_price');
            if (stockInput) stockInput.value = getValue('stock_count') || '1000';
        });
    }, 500);
}

function importReviewsData() {
    // Reviews are stored for later submission with the product
    // For now, just show a message
    console.log('Reviews data imported:', importedData.length, 'reviews');
}

function importFeaturesData() {
    // Clear existing features
    const container = document.getElementById('featuresContainer');
    container.innerHTML = '';
    
    // Add features from imported data
    importedData.forEach((row, index) => {
        const getValue = (key) => {
            const colIndex = columnMapping[key];
            return colIndex !== undefined ? (row[colIndex] || '') : '';
        };
        
        const featureName = getValue('feature_name');
        const featureDesc = getValue('feature_description');
        
        if (featureName) {
            const featureItem = document.createElement('div');
            featureItem.className = 'feature-item-modern';
            featureItem.innerHTML = `
                <div class="feature-inputs">
                    <input type="text" class="form-control-modern feature-name" name="feature_names[]" 
                           value="${escapeHtml(featureName)}" placeholder="Feature name">
                    <input type="text" class="form-control-modern feature-desc" name="feature_descriptions[]" 
                           value="${escapeHtml(featureDesc)}" placeholder="Feature description">
                </div>
                <button type="button" class="btn btn-remove-modern" onclick="removeFeature(this)" title="Remove Feature">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(featureItem);
        }
    });
    
    // Show success message
    console.log(`Imported ${importedData.length} features successfully`);
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/* ========================================
   TEMPLATE DOWNLOAD
   ======================================== */

function downloadTemplate() {
    const templates = {
        product: 'Product Name,Category,Original Price,Discounted Price,Commission (PKR),Delivery Charges,Stock,Sold,Status,Display Location,Description,Keywords\nSample Product,Electronics,5000,4500,500,150,100,25,In Stock,Shop Page,"Product description here","laptop,computer"',
        variants: 'Variant Type,Variant Name,Sale Price,Original Price,Stock Count,Image URL\nColor,Red,5000,5500,1000,\nColor,Blue,5000,5500,1000,',
        reviews: 'Reviewer Name,Rating,Review Text\nJohn Doe,5,"Great product! Highly recommended."\nSarah Smith,4,"Good quality product."',
        features: 'Feature Name,Feature Description\nMaterial,"Premium Cotton Fabric"\nSize,"Available in S, M, L, XL"\nWarranty,"1 Year Manufacturer Warranty"'
    };
    
    const csv = templates[currentModule];
    const filename = `${currentModule}_template.csv`;
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
}

/* ========================================
   UTILITY FUNCTIONS
   ======================================== */

function showToast(message, type = 'info') {
    // Create toast notification
    const toast = document.createElement('div');
    toast.className = `alert alert-${type} position-fixed top-0 end-0 m-3`;
    toast.style.zIndex = '9999';
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
        ${message}
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}
</script>

<?php require_once 'includes/footer.php'; ?>