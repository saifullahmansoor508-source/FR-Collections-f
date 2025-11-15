<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$page_title = "Navbar Management";

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Create tables if they don't exist
try {
    // Create nav_items table if it doesn't exist
    $db->exec("CREATE TABLE IF NOT EXISTS nav_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        url VARCHAR(255) NOT NULL,
        sort_order INT DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create site_settings table if it doesn't exist
    $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(255) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    $error = "Database setup error: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_logo':
                // Handle logo upload
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/logo/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                    $filename = 'logo.' . $file_extension;
                    $target_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                        $logo_path = 'uploads/logo/' . $filename;
                        
                        // Update or insert logo setting
                        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('site_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                        if ($stmt->execute([$logo_path, $logo_path])) {
                            $success = "Logo updated successfully!";
                        } else {
                            $error = "Failed to update logo in database.";
                        }
                    } else {
                        $error = "Failed to upload logo file.";
                    }
                } else {
                    $error = "Please select a valid logo file.";
                }
                break;
                
            case 'add_nav_item':
                $name = sanitizeInput($_POST['name']);
                $url = sanitizeInput($_POST['url']);
                $sort_order = intval($_POST['sort_order']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                $stmt = $db->prepare("INSERT INTO nav_items (name, url, sort_order, is_active) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$name, $url, $sort_order, $is_active])) {
                    $success = "Navigation item added successfully!";
                } else {
                    $error = "Failed to add navigation item.";
                }
                break;
                
            case 'update_nav_item':
                $id = intval($_POST['nav_id']);
                $name = sanitizeInput($_POST['name']);
                $url = sanitizeInput($_POST['url']);
                $sort_order = intval($_POST['sort_order']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                $stmt = $db->prepare("UPDATE nav_items SET name = ?, url = ?, sort_order = ?, is_active = ? WHERE id = ?");
                if ($stmt->execute([$name, $url, $sort_order, $is_active, $id])) {
                    $success = "Navigation item updated successfully!";
                } else {
                    $error = "Failed to update navigation item.";
                }
                break;
                
            case 'delete_nav_item':
                $id = intval($_POST['nav_id']);
                
                $stmt = $db->prepare("DELETE FROM nav_items WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $success = "Navigation item deleted successfully!";
                } else {
                    $error = "Failed to delete navigation item.";
                }
                break;
        }
    }
}

// Get current logo
$current_logo = '';
try {
    $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_logo'");
    $stmt->execute();
    $current_logo = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Table doesn't exist yet, will be created automatically
}

// Get navigation items
$nav_items = [];
try {
    $stmt = $db->prepare("SELECT * FROM nav_items ORDER BY sort_order ASC, id ASC");
    $stmt->execute();
    $nav_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table doesn't exist yet, will be empty
}

// Get counts for stats
$total_items = 0;
$active_items = 0;
$inactive_items = 0;

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM nav_items");
    $stmt->execute();
    $total_items = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM nav_items WHERE is_active = 1");
    $stmt->execute();
    $active_items = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM nav_items WHERE is_active = 0");
    $stmt->execute();
    $inactive_items = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Tables don't exist yet, counts will be 0
}

require_once 'includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" style="border-radius: 12px; border-left: 4px solid #10b981;">
        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius: 12px; border-left: 4px solid #ef4444;">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header with Amazing Stats -->
<div class="row mb-4">
    <div class="col-12">
        <div class="navbar-hero-header">
            <div class="hero-background-pattern"></div>
            <div class="hero-content-wrapper">
                <div class="hero-title-section">
                    <div class="hero-icon-animated">
                        <i class="fas fa-bars"></i>
                        <div class="icon-pulse"></div>
                    </div>
                    <div class="hero-text">
                        <h1 class="hero-title">Navigation Management</h1>
                        <p class="hero-subtitle">Design your perfect navigation experience</p>
                    </div>
                </div>
                <div class="hero-stats-container">
                    <div class="hero-stat-card" data-stat="total">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <div class="stat-content">
                            <h3 class="stat-number" data-count="<?php echo $total_items; ?>"><?php echo number_format($total_items); ?></h3>
                            <p class="stat-label">Total Items</p>
                        </div>
                        <div class="stat-glow"></div>
                    </div>
                    
                    <div class="hero-stat-card" data-stat="active">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3 class="stat-number" data-count="<?php echo $active_items; ?>"><?php echo number_format($active_items); ?></h3>
                            <p class="stat-label">Active Items</p>
                        </div>
                        <div class="stat-glow"></div>
                    </div>
                    
                    <div class="hero-stat-card" data-stat="inactive">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-pause-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3 class="stat-number" data-count="<?php echo $inactive_items; ?>"><?php echo number_format($inactive_items); ?></h3>
                            <p class="stat-label">Inactive Items</p>
                        </div>
                        <div class="stat-glow"></div>
                    </div>
                    
                    <div class="hero-stat-card" data-stat="default">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="stat-content">
                            <h3 class="stat-number" data-count="6">6</h3>
                            <p class="stat-label">Default Pages</p>
                        </div>
                        <div class="stat-glow"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Amazing Logo Management -->
    <div class="col-md-6 mb-4">
        <div class="logo-management-card">
            <div class="card-header-gradient">
                <div class="header-content-flex">
                    <div class="header-icon-box">
                        <i class="fas fa-image"></i>
                        <div class="icon-shine"></div>
                    </div>
                    <div class="header-text-content">
                        <h4 class="card-title-modern">Logo Management</h4>
                        <span class="card-subtitle-modern">Upload & preview your brand logo</span>
                    </div>
                </div>
                <button class="btn-refresh-animated" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
            <div class="card-body-modern">
                <?php if ($current_logo): ?>
                    <div class="logo-preview-section">
                        <label class="section-label-modern">
                            <i class="fas fa-eye me-2"></i>Current Logo
                        </label>
                        <div class="logo-preview-container">
                            <div class="logo-frame">
                                <img src="../<?php echo htmlspecialchars($current_logo); ?>" 
                                     alt="Current Logo" class="logo-image" id="currentLogoImg">
                                <div class="logo-overlay">
                                    <i class="fas fa-search-plus"></i>
                                </div>
                            </div>
                            <div class="logo-info-badge">
                                <i class="fas fa-check-circle me-2"></i>Active Logo
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" id="logoUploadForm">
                    <input type="hidden" name="action" value="update_logo">
                    
                    <div class="upload-section-modern">
                        <label class="section-label-modern">
                            <i class="fas fa-cloud-upload-alt me-2"></i>Upload New Logo
                        </label>
                        
                        <div class="drag-drop-zone" id="dragDropZone">
                            <input type="file" class="file-input-hidden" id="logo" name="logo" accept="image/*" required>
                            <div class="drag-drop-content">
                                <div class="upload-icon-animated">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>
                                <h5 class="upload-title">Drag & Drop your logo here</h5>
                                <p class="upload-text">or click to browse files</p>
                                <div class="upload-specs">
                                    <span class="spec-badge"><i class="fas fa-image me-1"></i>PNG, JPG, SVG</span>
                                    <span class="spec-badge"><i class="fas fa-ruler-combined me-1"></i>200x60px</span>
                                </div>
                            </div>
                            <div class="file-preview" id="filePreview" style="display: none;">
                                <img src="" alt="Preview" id="previewImage">
                                <div class="preview-overlay">
                                    <button type="button" class="btn-remove-preview" onclick="removePreview()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-upload-modern" id="uploadBtn" disabled>
                            <span class="btn-content">
                                <i class="fas fa-upload me-2"></i>
                                <span>Update Logo</span>
                            </span>
                            <div class="btn-glow"></div>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Amazing Navigation Items -->
    <div class="col-md-6 mb-4">
        <div class="nav-items-card">
            <div class="card-header-gradient">
                <div class="header-content-flex">
                    <div class="header-icon-box">
                        <i class="fas fa-list-ul"></i>
                        <div class="icon-shine"></div>
                    </div>
                    <div class="header-text-content">
                        <h4 class="card-title-modern">Custom Navigation Items</h4>
                        <span class="card-subtitle-modern"><?php echo count($nav_items); ?> items configured</span>
                    </div>
                </div>
                <div class="header-actions-group">
                    <button class="btn-refresh-animated" onclick="location.reload()" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button class="btn-add-animated" data-bs-toggle="modal" data-bs-target="#addNavModal">
                        <i class="fas fa-plus me-2"></i>Add Item
                    </button>
                </div>
            </div>
            <div class="card-body-modern">
                <?php if (empty($nav_items)): ?>
                    <div class="empty-state-modern">
                        <div class="empty-icon-animated">
                            <i class="fas fa-list-ul"></i>
                            <div class="empty-icon-pulse"></div>
                        </div>
                        <h5 class="empty-title">No Custom Items Yet</h5>
                        <p class="empty-description">Create your first navigation item to enhance your menu</p>
                        <button class="btn-create-first" data-bs-toggle="modal" data-bs-target="#addNavModal">
                            <i class="fas fa-plus-circle me-2"></i>Create First Item
                        </button>
                    </div>
                <?php else: ?>
                    <div class="nav-items-list">
                        <?php foreach ($nav_items as $index => $item): ?>
                            <div class="nav-item-card <?php echo !$item['is_active'] ? 'inactive-item' : ''; ?>" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                <div class="nav-item-header">
                                    <div class="nav-item-icon-wrapper">
                                        <div class="nav-item-icon">
                                            <i class="fas fa-link"></i>
                                        </div>
                                        <div class="nav-item-order">#<?php echo $item['sort_order']; ?></div>
                                    </div>
                                    <div class="nav-item-content">
                                        <h6 class="nav-item-name"><?php echo htmlspecialchars($item['name']); ?></h6>
                                        <p class="nav-item-url">
                                            <i class="fas fa-external-link-alt me-1"></i>
                                            <?php echo htmlspecialchars($item['url']); ?>
                                        </p>
                                    </div>
                                    <div class="nav-item-status">
                                        <?php if ($item['is_active']): ?>
                                            <span class="status-badge-modern active-badge">
                                                <i class="fas fa-check-circle"></i>
                                                <span>Active</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge-modern inactive-badge">
                                                <i class="fas fa-pause-circle"></i>
                                                <span>Inactive</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="nav-item-meta">
                                    <div class="meta-item">
                                        <i class="fas fa-hashtag"></i>
                                        <span>ID: <?php echo $item['id']; ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo date('M d, Y', strtotime($item['created_at'])); ?></span>
                                    </div>
                                </div>
                                
                                <div class="nav-item-actions">
                                    <button type="button" class="action-btn edit-btn" 
                                            onclick="editNavItem(<?php echo htmlspecialchars(json_encode($item)); ?>)"
                                            title="Edit Item">
                                        <i class="fas fa-edit"></i>
                                        <span>Edit</span>
                                    </button>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this navigation item?')">
                                        <input type="hidden" name="action" value="delete_nav_item">
                                        <input type="hidden" name="nav_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="action-btn delete-btn" title="Delete Item">
                                            <i class="fas fa-trash-alt"></i>
                                            <span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Amazing Default Navigation Structure -->
<div class="row">
    <div class="col-12">
        <div class="default-nav-card">
            <div class="card-header-gradient">
                <div class="header-content-flex">
                    <div class="header-icon-box">
                        <i class="fas fa-sitemap"></i>
                        <div class="icon-shine"></div>
                    </div>
                    <div class="header-text-content">
                        <h4 class="card-title-modern">Default Navigation Structure</h4>
                        <span class="card-subtitle-modern">6 core pages always available</span>
                    </div>
                </div>
                <div class="nav-structure-badge">
                    <i class="fas fa-lock me-2"></i>System Pages
                </div>
            </div>
            <div class="card-body-modern">
                <div class="default-nav-intro">
                    <div class="intro-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <p class="intro-text">These essential pages form the foundation of your website navigation and are always accessible to visitors.</p>
                </div>
                
                <div class="default-nav-grid">
                    <div class="default-nav-item" style="animation-delay: 0s;">
                        <div class="nav-item-icon-circle home-icon">
                            <i class="fas fa-home"></i>
                        </div>
                        <div class="nav-item-details">
                            <h6 class="nav-item-title">Home</h6>
                            <p class="nav-item-path">index.php</p>
                            <span class="nav-item-desc">Landing page & welcome</span>
                        </div>
                        <div class="nav-item-badge">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                    
                    <div class="default-nav-item" style="animation-delay: 0.1s;">
                        <div class="nav-item-icon-circle about-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div class="nav-item-details">
                            <h6 class="nav-item-title">About</h6>
                            <p class="nav-item-path">about.php</p>
                            <span class="nav-item-desc">Company information</span>
                        </div>
                        <div class="nav-item-badge">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                    
                    <div class="default-nav-item" style="animation-delay: 0.2s;">
                        <div class="nav-item-icon-circle shop-icon">
                            <i class="fas fa-shopping-bag"></i>
                        </div>
                        <div class="nav-item-details">
                            <h6 class="nav-item-title">Shop</h6>
                            <p class="nav-item-path">shop.php</p>
                            <span class="nav-item-desc">Product catalog</span>
                        </div>
                        <div class="nav-item-badge">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                    
                    <div class="default-nav-item" style="animation-delay: 0.3s;">
                        <div class="nav-item-icon-circle affiliate-icon">
                            <i class="fas fa-handshake"></i>
                        </div>
                        <div class="nav-item-details">
                            <h6 class="nav-item-title">Affiliate</h6>
                            <p class="nav-item-path">affiliate.php</p>
                            <span class="nav-item-desc">Partnership program</span>
                        </div>
                        <div class="nav-item-badge">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                    
                    <div class="default-nav-item" style="animation-delay: 0.4s;">
                        <div class="nav-item-icon-circle contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="nav-item-details">
                            <h6 class="nav-item-title">Contact</h6>
                            <p class="nav-item-path">contact.php</p>
                            <span class="nav-item-desc">Get in touch</span>
                        </div>
                        <div class="nav-item-badge">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                    
                    <div class="default-nav-item" style="animation-delay: 0.5s;">
                        <div class="nav-item-icon-circle blog-icon">
                            <i class="fas fa-newspaper"></i>
                        </div>
                        <div class="nav-item-details">
                            <h6 class="nav-item-title">Blog</h6>
                            <p class="nav-item-path">blog.php</p>
                            <span class="nav-item-desc">Latest news & articles</span>
                        </div>
                        <div class="nav-item-badge">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                </div>
                
                <div class="info-banner-modern">
                    <div class="banner-icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <div class="banner-content">
                        <strong>Pro Tip:</strong> Custom navigation items you create will appear after these default pages in the navbar menu.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Navigation Item Modal -->
<div class="modal fade" id="addNavModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 25px 30px;">
                <h5 class="modal-title" style="font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-plus-circle"></i><span>Add Navigation Item</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body" style="padding: 30px;">
                    <input type="hidden" name="action" value="add_nav_item">
                    
                    <div class="form-group-modern">
                        <label for="name" class="form-label-modern">
                            <i class="fas fa-heading me-2"></i>Name *
                        </label>
                        <input type="text" class="form-control-modern" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="url" class="form-label-modern">
                            <i class="fas fa-link me-2"></i>URL *
                        </label>
                        <input type="text" class="form-control-modern" id="url" name="url" required>
                        <div class="form-text-modern">Example: custom-page.php or https://external-link.com</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <label for="sort_order" class="form-label-modern">
                                    <i class="fas fa-sort me-2"></i>Sort Order
                                </label>
                                <input type="number" class="form-control-modern" id="sort_order" name="sort_order" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <div class="form-check-modern">
                                    <input class="form-check-input-modern" type="checkbox" id="is_active" name="is_active" checked>
                                    <label class="form-check-label-modern" for="is_active">
                                        <i class="fas fa-toggle-on me-2"></i>Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: #f8f9fa; border-top: 2px solid #e5e7eb; padding: 20px 30px;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 12px 25px; font-weight: 600;">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary-modern" style="padding: 12px 25px;">
                        <i class="fas fa-plus-circle me-2"></i>Add Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Navigation Item Modal -->
<div class="modal fade" id="editNavModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 25px 30px;">
                <h5 class="modal-title" style="font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-edit"></i><span>Edit Navigation Item</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editNavForm">
                <div class="modal-body" style="padding: 30px;">
                    <input type="hidden" name="action" value="update_nav_item">
                    <input type="hidden" name="nav_id" id="edit_nav_id">
                    
                    <div class="form-group-modern">
                        <label for="edit_name" class="form-label-modern">
                            <i class="fas fa-heading me-2"></i>Name *
                        </label>
                        <input type="text" class="form-control-modern" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="edit_url" class="form-label-modern">
                            <i class="fas fa-link me-2"></i>URL *
                        </label>
                        <input type="text" class="form-control-modern" id="edit_url" name="url" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <label for="edit_sort_order" class="form-label-modern">
                                    <i class="fas fa-sort me-2"></i>Sort Order
                                </label>
                                <input type="number" class="form-control-modern" id="edit_sort_order" name="sort_order">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <div class="form-check-modern">
                                    <input class="form-check-input-modern" type="checkbox" id="edit_is_active" name="is_active">
                                    <label class="form-check-label-modern" for="edit_is_active">
                                        <i class="fas fa-toggle-on me-2"></i>Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: #f8f9fa; border-top: 2px solid #e5e7eb; padding: 20px 30px;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 12px 25px; font-weight: 600;">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary-modern" style="padding: 12px 25px;">
                        <i class="fas fa-save me-2"></i>Update Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editNavItem(item) {
    document.getElementById('edit_nav_id').value = item.id;
    document.getElementById('edit_name').value = item.name;
    document.getElementById('edit_url').value = item.url;
    document.getElementById('edit_sort_order').value = item.sort_order;
    document.getElementById('edit_is_active').checked = item.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editNavModal')).show();
}

// Remove preview function
function removePreview() {
    document.getElementById('filePreview').style.display = 'none';
    document.querySelector('.drag-drop-content').style.display = 'flex';
    document.getElementById('logo').value = '';
    document.getElementById('uploadBtn').disabled = true;
}

// Enhanced interactions and animations
document.addEventListener('DOMContentLoaded', function() {
    // Drag and Drop functionality
    const dragDropZone = document.getElementById('dragDropZone');
    const fileInput = document.getElementById('logo');
    const filePreview = document.getElementById('filePreview');
    const previewImage = document.getElementById('previewImage');
    const dragDropContent = document.querySelector('.drag-drop-content');
    const uploadBtn = document.getElementById('uploadBtn');
    
    if (dragDropZone && fileInput) {
        // Click to upload
        dragDropZone.addEventListener('click', function(e) {
            if (!e.target.closest('.btn-remove-preview')) {
                fileInput.click();
            }
        });
        
        // File input change
        fileInput.addEventListener('change', function(e) {
            handleFiles(this.files);
        });
        
        // Drag events
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dragDropZone.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dragDropZone.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dragDropZone.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            dragDropZone.classList.add('drag-active');
        }
        
        function unhighlight() {
            dragDropZone.classList.remove('drag-active');
        }
        
        dragDropZone.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            handleFiles(files);
        }
        
        function handleFiles(files) {
            if (files.length > 0) {
                const file = files[0];
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImage.src = e.target.result;
                        dragDropContent.style.display = 'none';
                        filePreview.style.display = 'flex';
                        uploadBtn.disabled = false;
                    };
                    reader.readAsDataURL(file);
                }
            }
        }
    }
    
    // Animate stats on load
    const statNumbers = document.querySelectorAll('.stat-number');
    statNumbers.forEach(stat => {
        const target = parseInt(stat.getAttribute('data-count'));
        animateValue(stat, 0, target, 1000);
    });
    
    function animateValue(element, start, end, duration) {
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            element.textContent = Math.floor(progress * (end - start) + start);
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        };
        window.requestAnimationFrame(step);
    }
    
    // Logo image hover effect
    const logoFrame = document.querySelector('.logo-frame');
    if (logoFrame) {
        logoFrame.addEventListener('mouseenter', function() {
            this.querySelector('.logo-overlay').style.opacity = '1';
        });
        logoFrame.addEventListener('mouseleave', function() {
            this.querySelector('.logo-overlay').style.opacity = '0';
        });
    }
    
    // Nav item cards animation on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in');
            }
        });
    }, observerOptions);
    
    document.querySelectorAll('.nav-item-card, .default-nav-item').forEach(card => {
        observer.observe(card);
    });
    
    // Refresh button animation
    document.querySelectorAll('.btn-refresh-animated').forEach(btn => {
        btn.addEventListener('click', function() {
            this.querySelector('i').style.animation = 'spin 0.5s linear';
            setTimeout(() => {
                this.querySelector('i').style.animation = '';
            }, 500);
        });
    });
});

// Spin animation keyframes
const style = document.createElement('style');
style.textContent = `
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);
</script>

<style>
/* ===== COPY ALL BASE STYLES FROM HOMEPAGE ===== */

/* Modern Dropdowns */
.custom-dropdown-modern { position: relative; width: 100%; min-width: 220px; }
.dropdown-selected-modern { background:#fff; border:2px solid #e5e7eb; border-radius:12px; padding:12px 14px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; font-size:.95rem; font-weight:500; color:#1f2937; transition:all .3s ease; box-shadow:0 1px 3px rgba(0,0,0,.05); }
.dropdown-selected-modern:hover { border-color: var(--primary-color); box-shadow: 0 4px 12px rgba(0,88,163,.15); }
.custom-dropdown-modern.active .dropdown-selected-modern { border-color: var(--primary-color); box-shadow:0 0 0 3px rgba(0,88,163,.1); border-radius:12px 12px 0 0; }
.dropdown-arrow-modern { font-size:.8rem; color:#6b7280; transition: transform .3s ease; }
.custom-dropdown-modern.active .dropdown-arrow-modern { transform: rotate(180deg); color: var(--primary-color); }
.dropdown-options-modern { position:absolute; top:calc(100% - 2px); left:0; right:0; background:#fff; border:2px solid var(--primary-color); border-top:none; border-radius:0 0 12px 12px; box-shadow:0 10px 25px rgba(0,0,0,.15); z-index:1000; max-height:0; overflow:hidden; opacity:0; transform:translateY(-10px); transition: all .3s cubic-bezier(0.4, 0, 0.2, 1); }
.custom-dropdown-modern.active .dropdown-options-modern { max-height:300px; opacity:1; transform:translateY(0); overflow-y:auto; }
.dropdown-option-modern { padding:12px 18px; cursor:pointer; font-size:.9rem; color:#374151; transition:all .2s ease; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; }
.dropdown-option-modern:last-child { border-bottom:none; }
.dropdown-option-modern:hover { background:#f9fafb; padding-left:24px; }
.dropdown-option-modern.selected { background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); color:#fff; font-weight:600; }

/* Header card */
.page-header-card { background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border-radius: 12px; padding: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; min-height: 1px; }
.page-header-card::before { content:''; position:absolute; top:0; right:0; width:120px; height:120px; background:linear-gradient(135deg, rgba(0,88,163,.07) 0%, rgba(255,107,0,.07) 100%); border-radius:50%; transform: translate(80px,-80px); }
.page-header-content { display:flex; align-items:center; position:relative; z-index:1; }
.page-header-icon { width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.15rem; margin-right:12px; box-shadow:0 4px 14px rgba(0,88,163,.2); }
.page-header-text h1 { font-size:1.2rem; font-weight:600; color:#2d3748; margin-bottom:2px; }
.page-title { font-size:1.2rem; font-weight:600; color:#2d3748; margin-bottom:2px; }
.page-subtitle { color:#718096; font-size:.82rem; margin:0; }

/* Stats Grid */
.stats-grid {
    display: flex;
    gap: 16px;
    align-items: stretch;
    flex-wrap: wrap;
    justify-content: center;
}

.stats-card-simple {
    background: linear-gradient(135deg, #0058A3, #FF6B00);
    color: white;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    height: 100%;
    min-height: 120px;
}

.stats-card-simple:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.stats-card-simple::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}

.stats-content-simple {
    position: relative;
    z-index: 1;
}

.stats-content-simple h3 {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 5px;
}

.stats-content-simple p {
    margin-bottom: 15px;
    font-weight: 500;
}

/* Reviews Container */
.reviews-container {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.reviews-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 24px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
}

.reviews-header-content {
    display: flex;
    align-items: center;
}

.reviews-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.25rem;
    margin-right: 16px;
    box-shadow: 0 4px 12px rgba(0,88,163,.3);
}

.reviews-title h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
}

.reviews-count {
    display: block;
    font-size: .9rem;
    color: #718096;
    font-weight: 500;
    margin-top: 2px;
}

.reviews-actions {
    display: flex;
    gap: 12px;
}

.btn-refresh-modern {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .3s ease;
    box-shadow: 0 4px 12px rgba(16,185,129,.3);
}

.btn-refresh-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16,185,129,.4);
    color: #fff;
}

/* Reviews Grid */
.reviews-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
    padding: 25px;
}

.review-card {
    background: white;
    border-radius: 16px;
    border: 2px solid #e5e7eb;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.review-card.pending-review {
    border-left: 4px solid #f59e0b;
    background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
}

.review-card:hover, .review-card.card-hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    border-color: var(--primary-color);
}

.review-card-header {
    padding: 20px;
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.review-user-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar-review {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.3rem;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.user-name-review {
    font-weight: 700;
    color: #1f2937;
    font-size: 1.05rem;
}

.review-date {
    color: #6b7280;
    font-size: 0.85rem;
    margin-top: 2px;
}

.status-badge-review {
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-badge-review.approved {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.status-badge-review.pending {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.review-card-body {
    padding: 20px;
}

.product-info-review {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 10px;
    border-left: 4px solid var(--primary-color);
}

.product-name-review {
    font-weight: 600;
    color: #374151;
}

.slide-details {
    margin-top: 15px;
}

.detail-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 10px;
}

.detail-item {
    display: flex;
    align-items: center;
    font-size: 0.9rem;
    color: #4b5563;
}

.detail-item strong {
    margin: 0 5px;
    color: #1f2937;
}

.button-text {
    background: #e5e7eb;
    padding: 4px 10px;
    border-radius: 6px;
    font-weight: 600;
    color: #374151;
    font-size: 0.85rem;
}

.link-text {
    color: var(--primary-color);
    font-weight: 600;
    text-decoration: underline;
}

.review-card-footer {
    padding: 15px 20px;
    background: #f9fafb;
    border-top: 2px solid #e5e7eb;
}

.review-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.btn-action-review {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
}

.btn-action-review span {
    font-weight: 600;
}

.btn-approve {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.btn-approve:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    color: white;
}

.btn-delete {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.btn-delete:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
    color: white;
}

/* Empty Reviews */
.empty-reviews {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-reviews-icon {
    font-size: 4rem;
    color: #d1d5db;
    margin-bottom: 20px;
}

.empty-reviews h5 {
    font-size: 1.25rem;
    color: #374151;
    margin-bottom: 10px;
}

.empty-reviews p {
    margin-bottom: 25px;
}

/* Form Styles */
.form-group-modern {
    margin-bottom: 20px;
}

.form-label-modern {
    display: block;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    font-size: 0.95rem;
}

.form-control-modern {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: #fff;
}

.form-control-modern:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0,88,163,.1);
}

.form-text-modern {
    font-size: 0.85rem;
    color: #6b7280;
    margin-top: 6px;
}

.form-check-modern {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 25px;
}

.form-check-input-modern {
    width: 20px;
    height: 20px;
    border-radius: 6px;
    border: 2px solid #d1d5db;
    cursor: pointer;
}

.form-check-label-modern {
    font-weight: 500;
    color: #374151;
    cursor: pointer;
}

/* Image Preview */
.current-image-preview {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #d1d5db;
}

.current-image {
    max-width: 200px;
    max-height: 80px;
    border-radius: 8px;
}

/* Alert Styles */
.alert {
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 20px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}

.alert-success {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    color: #065f46;
}

.alert-danger {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    color: #991b1b;
}

/* Responsive */
@media (max-width: 768px) {
    .reviews-grid {
        grid-template-columns: 1fr;
        padding: 15px;
    }
    
    .reviews-header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .stats-grid {
        flex-direction: column;
    }
    
    .detail-row {
        flex-direction: column;
        gap: 10px;
    }
}

/* ===== AMAZING NEW NAVBAR MANAGEMENT STYLES ===== */

/* Hero Header */
.navbar-hero-header {
    background: linear-gradient(135deg, #0058A3 0%, #FF6B00 100%);
    border-radius: 20px;
    padding: 40px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0, 88, 163, 0.3);
    margin-bottom: 30px;
}

.hero-background-pattern {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: 
        radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
    pointer-events: none;
}

.hero-content-wrapper {
    position: relative;
    z-index: 1;
}

.hero-title-section {
    display: flex;
    align-items: center;
    margin-bottom: 30px;
}

.hero-icon-animated {
    width: 70px;
    height: 70px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 20px;
    position: relative;
}

.hero-icon-animated i {
    font-size: 2rem;
    color: white;
    z-index: 2;
}

.icon-pulse {
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.3);
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.1); opacity: 0.5; }
}

.hero-text .hero-title {
    font-size: 2.5rem;
    font-weight: 800;
    color: white;
    margin: 0;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.hero-text .hero-subtitle {
    font-size: 1.1rem;
    color: rgba(255, 255, 255, 0.9);
    margin: 5px 0 0 0;
}

.hero-stats-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.hero-stat-card {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-radius: 16px;
    padding: 25px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.hero-stat-card:hover {
    transform: translateY(-5px);
    background: rgba(255, 255, 255, 0.25);
    border-color: rgba(255, 255, 255, 0.4);
}

.stat-icon-wrapper {
    width: 50px;
    height: 50px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 15px;
}

.stat-icon-wrapper i {
    font-size: 1.5rem;
    color: white;
}

.stat-content .stat-number {
    font-size: 2.5rem;
    font-weight: 800;
    color: white;
    margin: 0;
    line-height: 1;
}

.stat-content .stat-label {
    font-size: 0.95rem;
    color: rgba(255, 255, 255, 0.9);
    margin: 8px 0 0 0;
    font-weight: 500;
}

.stat-glow {
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
    pointer-events: none;
}

/* Card Headers */
.logo-management-card,
.nav-items-card,
.default-nav-card {
    background: white;
    border-radius: 20px;
    box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    transition: all 0.3s ease;
}

.logo-management-card:hover,
.nav-items-card:hover,
.default-nav-card:hover {
    box-shadow: 0 8px 35px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.card-header-gradient {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 25px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 3px solid #e5e7eb;
}

.header-content-flex {
    display: flex;
    align-items: center;
    gap: 15px;
}

.header-icon-box {
    width: 55px;
    height: 55px;
    background: linear-gradient(135deg, #0058A3, #FF6B00);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    box-shadow: 0 4px 15px rgba(0, 88, 163, 0.3);
}

.header-icon-box i {
    font-size: 1.5rem;
    color: white;
    z-index: 2;
}

.icon-shine {
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    animation: shine 3s infinite;
}

@keyframes shine {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

.header-text-content .card-title-modern {
    font-size: 1.4rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.header-text-content .card-subtitle-modern {
    font-size: 0.9rem;
    color: #6b7280;
    font-weight: 500;
}

.header-actions-group {
    display: flex;
    gap: 12px;
}

.btn-refresh-animated,
.btn-add-animated {
    padding: 12px 20px;
    border-radius: 12px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-refresh-animated {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-refresh-animated:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.4);
}

.btn-add-animated {
    background: linear-gradient(135deg, #0058A3, #FF6B00);
    color: white;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.btn-add-animated:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0, 88, 163, 0.4);
}

.nav-structure-badge {
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: white;
    padding: 10px 20px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.card-body-modern {
    padding: 30px;
}

/* Logo Management Styles */
.logo-preview-section {
    margin-bottom: 30px;
}

.section-label-modern {
    display: block;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.logo-preview-container {
    text-align: center;
}

.logo-frame {
    position: relative;
    display: inline-block;
    padding: 30px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 16px;
    border: 3px dashed #d1d5db;
    margin-bottom: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.logo-frame:hover {
    border-color: #0058A3;
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
}

.logo-image {
    max-width: 250px;
    max-height: 100px;
    display: block;
}

.logo-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 88, 163, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 16px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.logo-overlay i {
    font-size: 2rem;
    color: white;
}

.logo-info-badge {
    display: inline-flex;
    align-items: center;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    padding: 8px 20px;
    border-radius: 50px;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* Drag and Drop Zone */
.upload-section-modern {
    margin-top: 25px;
}

.drag-drop-zone {
    border: 3px dashed #d1d5db;
    border-radius: 16px;
    padding: 50px 30px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    position: relative;
    overflow: hidden;
    min-height: 250px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.drag-drop-zone:hover {
    border-color: #0058A3;
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
}

.drag-drop-zone.drag-active {
    border-color: #FF6B00;
    background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
    transform: scale(1.02);
}

.file-input-hidden {
    display: none;
}

.drag-drop-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
}

.upload-icon-animated {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #0058A3, #FF6B00);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: float 3s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

.upload-icon-animated i {
    font-size: 2.5rem;
    color: white;
}

.upload-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.upload-text {
    font-size: 1rem;
    color: #6b7280;
    margin: 0;
}

.upload-specs {
    display: flex;
    gap: 15px;
    margin-top: 10px;
}

.spec-badge {
    background: white;
    padding: 8px 16px;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #374151;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.file-preview {
    display: none;
    position: relative;
    width: 100%;
    height: 100%;
    align-items: center;
    justify-content: center;
}

.file-preview img {
    max-width: 100%;
    max-height: 200px;
    border-radius: 12px;
}

.preview-overlay {
    position: absolute;
    top: 10px;
    right: 10px;
}

.btn-remove-preview {
    width: 40px;
    height: 40px;
    background: #ef4444;
    border: none;
    border-radius: 50%;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-remove-preview:hover {
    background: #dc2626;
    transform: scale(1.1);
}

.btn-upload-modern {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #0058A3, #FF6B00);
    border: none;
    border-radius: 12px;
    color: white;
    font-weight: 700;
    font-size: 1.1rem;
    cursor: pointer;
    margin-top: 20px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0, 88, 163, 0.3);
}

.btn-upload-modern:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 88, 163, 0.4);
}

.btn-upload-modern:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-content {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-glow {
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.3) 0%, transparent 70%);
    animation: rotate 4s linear infinite;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Navigation Items List */
.nav-items-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.nav-item-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    padding: 20px;
    transition: all 0.3s ease;
    opacity: 0;
    transform: translateX(-20px);
    animation: slideIn 0.5s ease forwards;
}

@keyframes slideIn {
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.nav-item-card:hover {
    border-color: #0058A3;
    box-shadow: 0 8px 25px rgba(0, 88, 163, 0.15);
    transform: translateX(5px);
}

.nav-item-card.inactive-item {
    border-left: 4px solid #f59e0b;
    background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
}

.nav-item-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
}

.nav-item-icon-wrapper {
    position: relative;
}

.nav-item-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.nav-item-order {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #FF6B00;
    color: white;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    box-shadow: 0 2px 8px rgba(255, 107, 0, 0.4);
}

.nav-item-content {
    flex: 1;
}

.nav-item-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 5px 0;
}

.nav-item-url {
    font-size: 0.9rem;
    color: #6b7280;
    margin: 0;
    display: flex;
    align-items: center;
}

.nav-item-status {
    margin-left: auto;
}

.status-badge-modern {
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.active-badge {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.inactive-badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.nav-item-meta {
    display: flex;
    gap: 20px;
    padding: 12px 0;
    border-top: 2px solid #f3f4f6;
    border-bottom: 2px solid #f3f4f6;
    margin-bottom: 15px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: #6b7280;
}

.meta-item i {
    color: #9ca3af;
}

.nav-item-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.action-btn {
    padding: 10px 18px;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.edit-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.edit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.delete-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.delete-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 60px 30px;
}

.empty-icon-animated {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 25px;
    position: relative;
}

.empty-icon-animated i {
    font-size: 3.5rem;
    color: #9ca3af;
}

.empty-icon-pulse {
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: rgba(209, 213, 219, 0.5);
    animation: pulse 2s ease-in-out infinite;
}

.empty-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #374151;
    margin: 0 0 10px 0;
}

.empty-description {
    font-size: 1rem;
    color: #6b7280;
    margin: 0 0 25px 0;
}

.btn-create-first {
    padding: 14px 30px;
    background: linear-gradient(135deg, #0058A3, #FF6B00);
    color: white;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1.05rem;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0, 88, 163, 0.3);
}

.btn-create-first:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 88, 163, 0.4);
}

/* Default Navigation Grid */
.default-nav-intro {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border-radius: 12px;
    margin-bottom: 30px;
}

.intro-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.intro-icon i {
    font-size: 1.5rem;
    color: white;
}

.intro-text {
    font-size: 1rem;
    color: #1e40af;
    margin: 0;
    font-weight: 500;
}

.default-nav-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.default-nav-item {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    padding: 25px;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.3s ease;
    opacity: 0;
    transform: translateY(20px);
    animation: fadeInUp 0.6s ease forwards;
}

@keyframes fadeInUp {
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.default-nav-item:hover {
    border-color: #0058A3;
    box-shadow: 0 8px 25px rgba(0, 88, 163, 0.15);
    transform: translateY(-5px);
}

.nav-item-icon-circle {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.nav-item-icon-circle i {
    font-size: 1.5rem;
    color: white;
}

.home-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
.about-icon { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.shop-icon { background: linear-gradient(135deg, #10b981, #059669); }
.affiliate-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
.contact-icon { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
.blog-icon { background: linear-gradient(135deg, #ec4899, #db2777); }

.nav-item-details {
    flex: 1;
}

.nav-item-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 5px 0;
}

.nav-item-path {
    font-size: 0.9rem;
    color: #6b7280;
    margin: 0 0 5px 0;
    font-family: 'Courier New', monospace;
}

.nav-item-desc {
    font-size: 0.85rem;
    color: #9ca3af;
    font-style: italic;
}

.nav-item-badge {
    width: 35px;
    height: 35px;
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.nav-item-badge i {
    color: white;
    font-size: 1rem;
}

/* Info Banner */
.info-banner-modern {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px 25px;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-radius: 12px;
    border-left: 4px solid #f59e0b;
}

.banner-icon {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.banner-icon i {
    font-size: 1.2rem;
    color: white;
}

.banner-content {
    font-size: 0.95rem;
    color: #92400e;
    font-weight: 500;
}

.banner-content strong {
    font-weight: 700;
    color: #78350f;
}

/* Responsive Design */
@media (max-width: 768px) {
    .hero-title-section {
        flex-direction: column;
        text-align: center;
    }
    
    .hero-text .hero-title {
        font-size: 1.8rem;
    }
    
    .hero-stats-container {
        grid-template-columns: 1fr;
    }
    
    .card-header-gradient {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .default-nav-grid {
        grid-template-columns: 1fr;
    }
    
    .nav-item-header {
        flex-wrap: wrap;
    }
}

/* ===== END OF STYLES ===== */
</style>

<?php
require_once 'includes/footer.php';
?>