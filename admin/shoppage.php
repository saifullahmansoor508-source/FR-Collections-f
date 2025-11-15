<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$page_title = "Shop Page Management";

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_slider':
                $title = sanitizeInput($_POST['title']);
                $subtitle = sanitizeInput($_POST['subtitle']);
                $sort_order = intval($_POST['sort_order']);
                
                // Handle image upload
                $image_path = '';
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/shop_sliders/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $filename = 'shop_slider_' . time() . '.' . $file_extension;
                    $target_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                        $image_path = $filename;
                    }
                }
                
                if ($image_path) {
                    // Use 'image' column name as per database schema
                    $stmt = $db->prepare("INSERT INTO shop_sliders (title, subtitle, image, sort_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$title, $subtitle, $image_path, $sort_order]);
                    $success_message = "Shop slider added successfully!";
                } else {
                    $error_message = "Failed to upload image.";
                }
                break;
                
            case 'toggle_slider':
                $slider_id = intval($_POST['slider_id']);
                
                // First check if is_active column exists
                try {
                    $check_column = $db->query("SHOW COLUMNS FROM shop_sliders LIKE 'is_active'");
                    $column_exists = $check_column->rowCount() > 0;
                    
                    if ($column_exists) {
                        $stmt = $db->prepare("UPDATE shop_sliders SET is_active = NOT is_active WHERE id = ?");
                        $stmt->execute([$slider_id]);
                        $success_message = "Slider status updated successfully!";
                    } else {
                        // If column doesn't exist, set status to 1 (active) by default
                        $stmt = $db->prepare("UPDATE shop_sliders SET status = 1 WHERE id = ?");
                        $stmt->execute([$slider_id]);
                        $success_message = "Slider activated successfully!";
                    }
                } catch (PDOException $e) {
                    $error_message = "Error updating slider status: " . $e->getMessage();
                }
                break;
                
            case 'delete_slider':
                $slider_id = intval($_POST['slider_id']);
                
                // Get image before deleting (use 'image' column name)
                $stmt = $db->prepare("SELECT image FROM shop_sliders WHERE id = ?");
                $stmt->execute([$slider_id]);
                $slider = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($slider) {
                    // Delete image file
                    $image_file = '../uploads/shop_sliders/' . $slider['image'];
                    if (file_exists($image_file)) {
                        unlink($image_file);
                    }
                    
                    // Delete database record
                    $stmt = $db->prepare("DELETE FROM shop_sliders WHERE id = ?");
                    $stmt->execute([$slider_id]);
                    $success_message = "Slider deleted successfully!";
                }
                break;
                
            case 'bulk_delete_sliders':
                if (isset($_POST['slider_ids']) && is_array($_POST['slider_ids'])) {
                    $slider_ids = array_map('intval', $_POST['slider_ids']);
                    $deleted_count = 0;
                    
                    foreach ($slider_ids as $slider_id) {
                        // Get image before deleting
                        $stmt = $db->prepare("SELECT image FROM shop_sliders WHERE id = ?");
                        $stmt->execute([$slider_id]);
                        $slider = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($slider) {
                            // Delete image file
                            $image_file = '../uploads/shop_sliders/' . $slider['image'];
                            if (file_exists($image_file)) {
                                unlink($image_file);
                            }
                            
                            // Delete database record
                            $stmt = $db->prepare("DELETE FROM shop_sliders WHERE id = ?");
                            if ($stmt->execute([$slider_id])) {
                                $deleted_count++;
                            }
                        }
                    }
                    
                    if ($deleted_count > 0) {
                        $success_message = "$deleted_count slider(s) deleted successfully!";
                    } else {
                        $error_message = "Failed to delete sliders.";
                    }
                }
                break;
        }
    }
}

// Get all shop sliders - handle both with and without is_active column
try {
    // Check if is_active column exists
    $check_column = $db->query("SHOW COLUMNS FROM shop_sliders LIKE 'is_active'");
    $has_is_active = $check_column->rowCount() > 0;
    
    if ($has_is_active) {
        $stmt = $db->prepare("SELECT * FROM shop_sliders ORDER BY sort_order ASC, created_at DESC");
    } else {
        // If is_active doesn't exist, add a default value of 1 for all records
        $stmt = $db->prepare("SELECT *, 1 as is_active FROM shop_sliders ORDER BY sort_order ASC, created_at DESC");
    }
    $stmt->execute();
    $sliders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching sliders: " . $e->getMessage();
    $sliders = [];
}

// Get counts for stats
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM shop_sliders");
    $stmt->execute();
    $total_sliders = $stmt->fetchColumn();

    // Check if is_active column exists for active/inactive counts
    $check_column = $db->query("SHOW COLUMNS FROM shop_sliders LIKE 'is_active'");
    $has_is_active = $check_column->rowCount() > 0;
    
    if ($has_is_active) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM shop_sliders WHERE is_active = 1");
        $stmt->execute();
        $active_sliders = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM shop_sliders WHERE is_active = 0");
        $stmt->execute();
        $inactive_sliders = $stmt->fetchColumn();
    } else {
        // If no is_active column, consider all as active
        $active_sliders = $total_sliders;
        $inactive_sliders = 0;
    }

    $stmt = $db->prepare("SELECT MAX(sort_order) FROM shop_sliders");
    $stmt->execute();
    $max_sort_order = $stmt->fetchColumn();
} catch (PDOException $e) {
    $total_sliders = 0;
    $active_sliders = 0;
    $inactive_sliders = 0;
    $max_sort_order = 0;
    $error_message = "Error fetching statistics: " . $e->getMessage();
}

require_once 'includes/header.php';
?>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" style="border-radius: 12px; border-left: 4px solid #10b981;">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius: 12px; border-left: 4px solid #ef4444;">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header with Stats -->
<div class="row mb-4">
    <div class="col-12">
        <div class="page-header-card">
            <div class="page-header-content">
                <div class="page-header-icon">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Shop Page Management</h1>
                    <p class="page-subtitle">Manage shop page header sliders and content</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($total_sliders); ?></h3>
                        <p>Total Sliders</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($active_sliders); ?></h3>
                        <p>Active Sliders</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($inactive_sliders); ?></h3>
                        <p>Inactive Sliders</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3>#<?php echo number_format($max_sort_order ?: 0); ?></h3>
                        <p>Max Sort Order</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sliders Grid -->
<div class="row">
    <div class="col-12">
        <div class="reviews-container">
            <div class="reviews-header">
                <div class="reviews-header-content">
                    <div class="reviews-icon">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <div class="reviews-title">
                        <h4>Shop Header Sliders</h4>
                        <span class="reviews-count"><?php echo count($sliders); ?> sliders</span>
                    </div>
                </div>
                <div class="reviews-actions-icons">
                    <button class="header-icon-btn select-icon" onclick="toggleSelectAllShopSliders()" id="selectAllBtnShopSliders" title="Select All">
                        <i class="fas fa-check-double"></i>
                    </button>
                    <button class="header-icon-btn delete-icon" onclick="bulkDeleteShopSliders()" id="deleteSelectedBtnShopSliders" title="Delete Selected">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                    <button class="header-icon-btn refresh-icon" onclick="location.reload()" title="Refresh List">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button class="header-icon-btn add-icon" data-bs-toggle="modal" data-bs-target="#addSliderModal" title="Add New Slider">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>

            <?php if (empty($sliders)): ?>
                <div class="empty-reviews">
                    <div class="empty-reviews-icon"><i class="fas fa-sliders-h"></i></div>
                    <h5>No shop sliders found</h5>
                    <p>Add your first shop slider to get started.</p>
                    <button class="btn btn-primary-modern" data-bs-toggle="modal" data-bs-target="#addSliderModal">
                        <i class="fas fa-plus me-2"></i>Add First Slider
                    </button>
                </div>
            <?php else: ?>
                <div class="reviews-grid">
                    <?php foreach ($sliders as $slider): ?>
                        <div class="review-card <?php echo !$slider['is_active'] ? 'pending-review' : ''; ?>">
                            <div class="review-card-header">
                                <div class="desktop-checkbox-section">
                                    <input type="checkbox" class="shop-slider-checkbox desktop-checkbox" value="<?php echo $slider['id']; ?>">
                                </div>
                                <div class="review-user-info">
                                    <div class="user-avatar-review" style="background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%);">
                                        <i class="fas fa-image"></i>
                                    </div>
                                    <div class="user-details-review">
                                        <div class="user-name-review">Slider #<?php echo $slider['id']; ?></div>
                                        <div class="review-date">
                                            <i class="fas fa-sort me-1"></i>
                                            Order: <?php echo $slider['sort_order']; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="review-status-badge">
                                    <?php if ($slider['is_active']): ?>
                                        <span class="status-badge-review approved">
                                            <i class="fas fa-check-circle"></i> Active
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge-review pending">
                                            <i class="fas fa-clock"></i> Inactive
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="review-card-body">
                                <div class="slide-image-section">
                                    <img src="../uploads/shop_sliders/<?php echo htmlspecialchars($slider['image']); ?>" 
                                         alt="Slider Image" 
                                         class="slide-image-preview">
                                </div>

                                <div class="product-info-review">
                                    <i class="fas fa-heading me-2" style="color: #8b5cf6;"></i>
                                    <span class="product-name-review"><?php echo htmlspecialchars($slider['title']); ?></span>
                                </div>

                                <div class="rating-display">
                                    <i class="fas fa-text-height me-2" style="color: #6b7280;"></i>
                                    <span class="rating-text">
                                        <?php echo htmlspecialchars(substr($slider['subtitle'], 0, 30)) . (strlen($slider['subtitle']) > 30 ? '...' : ''); ?>
                                    </span>
                                </div>

                                <div class="slide-details">
                                    <div class="detail-row">
                                        <div class="detail-item full-width">
                                            <i class="fas fa-image me-2" style="color: #3b82f6;"></i>
                                            <strong>Image:</strong>
                                            <span class="link-text"><?php echo $slider['image']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="review-card-footer">
                                <div class="review-actions-icons">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_slider">
                                        <input type="hidden" name="slider_id" value="<?php echo $slider['id']; ?>">
                                        <button type="submit" class="action-icon-btn <?php echo $slider['is_active'] ? 'pause-icon' : 'play-icon'; ?>" 
                                                title="<?php echo $slider['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="fas <?php echo $slider['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'This slider will be permanently deleted. Are you sure?', 'Delete Slider')">
                                        <input type="hidden" name="action" value="delete_slider">
                                        <input type="hidden" name="slider_id" value="<?php echo $slider['id']; ?>">
                                        <button type="submit" class="action-icon-btn delete-icon" title="Delete Slider">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Mobile Action Buttons -->
                <div class="mobile-action-icons">
                    <button class="mobile-icon-btn select-icon" onclick="toggleSelectAllShopSliders()" id="selectAllBtnShopSlidersMobile" title="Select All">
                        <i class="fas fa-check-double"></i>
                    </button>
                    <button class="mobile-icon-btn delete-icon" onclick="bulkDeleteShopSliders()" id="deleteSelectedBtnShopSlidersMobile" title="Delete Selected">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                    <button class="mobile-icon-btn refresh-icon" onclick="location.reload()" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button class="mobile-icon-btn add-icon" data-bs-toggle="modal" data-bs-target="#addSliderModal" title="Add Slider">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                
                <!-- Mobile List View -->
                <div class="mobile-list">
                    <?php foreach ($sliders as $slider): ?>
                        <div class="user-list-item">
                            <div class="user-list-header" onclick="toggleDetails(this)">
                                <div class="shop-slider-checkbox-section">
                                    <input type="checkbox" class="shop-slider-checkbox" value="<?php echo $slider['id']; ?>" onclick="event.stopPropagation()">
                                </div>
                                <div class="user-list-avatar" style="background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%);">
                                    <i class="fas fa-sliders-h"></i>
                                </div>
                                <div class="user-info-primary">
                                    <div class="user-name-mobile">Slider #<?php echo $slider['id']; ?></div>
                                    <div class="user-badges-mobile">
                                        <?php if ($slider['is_active']): ?>
                                            <span class="badge-mobile badge-approved">
                                                <i class="fas fa-check-circle"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-mobile badge-pending">
                                                <i class="fas fa-clock"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="user-expand-icon">
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                            </div>
                            
                            <div class="user-list-details collapsed">
                                <div class="detail-row-mobile">
                                    <i class="fas fa-heading detail-icon"></i>
                                    <span class="detail-label">Title:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($slider['title']); ?></span>
                                </div>
                                <div class="detail-row-mobile">
                                    <i class="fas fa-text-height detail-icon"></i>
                                    <span class="detail-label">Subtitle:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars(substr($slider['subtitle'], 0, 40)) . '...'; ?></span>
                                </div>
                                <div class="detail-row-mobile">
                                    <i class="fas fa-sort detail-icon"></i>
                                    <span class="detail-label">Order:</span>
                                    <span class="detail-value"><?php echo $slider['sort_order']; ?></span>
                                </div>
                                <div class="detail-row-mobile">
                                    <i class="fas fa-image detail-icon"></i>
                                    <span class="detail-label">Image:</span>
                                    <span class="detail-value"><?php echo $slider['image']; ?></span>
                                </div>
                                
                                <div class="user-actions-mobile">
                                    <form method="POST" class="d-inline" style="flex: 1;">
                                        <input type="hidden" name="action" value="toggle_slider">
                                        <input type="hidden" name="slider_id" value="<?php echo $slider['id']; ?>">
                                        <button type="submit" class="mobile-btn mobile-btn-view" style="width: 100%;">
                                            <i class="fas <?php echo $slider['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i> <?php echo $slider['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline" style="flex: 1;" onsubmit="return handleDeleteConfirm(event, 'Delete this slider?', 'Delete')">
                                        <input type="hidden" name="action" value="delete_slider">
                                        <input type="hidden" name="slider_id" value="<?php echo $slider['id']; ?>">
                                        <button type="submit" class="mobile-btn mobile-btn-delete" style="width: 100%;">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Slider Modal -->
<div class="modal fade" id="addSliderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 25px 30px;">
                <h5 class="modal-title" style="font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-plus-circle"></i><span>Add New Shop Slider</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body" style="padding: 30px;">
                    <input type="hidden" name="action" value="add_slider">
                    
                    <div class="form-group-modern">
                        <label for="title" class="form-label-modern">
                            <i class="fas fa-heading me-2"></i>Title *
                        </label>
                        <input type="text" class="form-control-modern" id="title" name="title" required>
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="subtitle" class="form-label-modern">
                            <i class="fas fa-text-height me-2"></i>Subtitle
                        </label>
                        <textarea class="form-control-modern" id="subtitle" name="subtitle" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <label for="sort_order" class="form-label-modern">
                                    <i class="fas fa-sort me-2"></i>Sort Order
                                </label>
                                <input type="number" class="form-control-modern" id="sort_order" name="sort_order" value="1" min="1">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <label for="image" class="form-label-modern">
                                    <i class="fas fa-image me-2"></i>Background Image *
                                </label>
                                <input type="file" class="form-control-modern" id="image" name="image" accept="image/*" required>
                                <div class="form-text-modern">Recommended size: 1920x400px</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: #f8f9fa; border-top: 2px solid #e5e7eb; padding: 20px 30px;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 12px 25px; font-weight: 600;">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary-modern" style="padding: 12px 25px;">
                        <i class="fas fa-plus-circle me-2"></i>Add Slider
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Card hover effects
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.review-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.classList.add('card-hover');
        });
        card.addEventListener('mouseleave', function() {
            this.classList.remove('card-hover');
        });
    });
});
</script>

<style>
/* All the same CSS styles from previous code remain here */
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
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.status-badge-review.pending {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.review-card-body {
    padding: 20px;
}

/* Slide Image Section */
.slide-image-section {
    margin-bottom: 15px;
    text-align: center;
}

.slide-image-preview {
    width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 12px;
    border: 2px solid #e5e7eb;
}

.product-info-review {
    background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
    padding: 10px 15px;
    border-radius: 10px;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    border: 1px solid #c084fc;
}

.product-name-review {
    font-weight: 600;
    color: #6b21a8;
    font-size: 0.95rem;
}

.rating-display {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 15px;
}

.rating-display .star-filled {
    color: #fbbf24;
    font-size: 1.2rem;
}

.rating-display .star-empty {
    color: #d1d5db;
    font-size: 1.2rem;
}

.rating-text {
    margin-left: 8px;
    font-weight: 700;
    color: #1f2937;
    font-size: 1rem;
}

/* Slide Details */
.slide-details {
    background: #f9fafb;
    padding: 15px;
    border-radius: 12px;
    border-left: 3px solid var(--primary-color);
}

.detail-row {
    display: flex;
    gap: 15px;
    margin-bottom: 10px;
}

.detail-row:last-child {
    margin-bottom: 0;
}

.detail-item {
    flex: 1;
    display: flex;
    align-items: center;
    font-size: 0.9rem;
}

.detail-item.full-width {
    flex: 100%;
}

.button-text {
    color: #10b981;
    font-weight: 500;
    margin-left: 5px;
}

.link-text {
    color: #3b82f6;
    font-weight: 500;
    margin-left: 5px;
}

.review-card-footer {
    padding: 15px 20px;
    background: #f9fafb;
    border-top: 2px solid #e5e7eb;
}

.review-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-action-review {
    flex: 1;
    min-width: 100px;
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-approve {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-approve:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
}

.btn-unapprove {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.btn-unapprove:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
}

.btn-delete {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-delete:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

/* Empty State */
.empty-reviews {
    text-align: center;
    padding: 80px 40px;
}

.empty-reviews-icon {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 24px;
}

.empty-reviews h5 {
    color: #6b7280;
    font-weight: 700;
    margin-bottom: 10px;
}

.empty-reviews p {
    color: #9ca3af;
    margin-bottom: 20px;
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: #fff;
    border: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: all .3s ease;
    box-shadow: 0 4px 12px rgba(0,88,163,.3);
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,88,163,.4);
    color: #fff;
    text-decoration: none;
}

/* Modal Form Styles */
.form-group-modern {
    margin-bottom: 20px;
}

.form-label-modern {
    display: flex;
    align-items: center;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    font-size: 0.95rem;
}

.form-control-modern {
    width: 100%;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
}

.form-control-modern:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0,88,163,.1);
}

.form-text-modern {
    font-size: 0.85rem;
    color: #6b7280;
    margin-top: 4px;
}

.form-check-modern {
    display: flex;
    align-items: center;
    margin-top: 25px;
}

.form-check-input-modern {
    width: 18px;
    height: 18px;
    margin-right: 8px;
    cursor: pointer;
}

.form-check-label-modern {
    display: flex;
    align-items: center;
    font-weight: 500;
    color: #374151;
    cursor: pointer;
}

.current-image-preview {
    text-align: center;
    margin-bottom: 15px;
}

.current-image {
    max-width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 12px;
    border: 2px solid #e5e7eb;
}

/* Desktop Checkbox & Buttons */
.desktop-checkbox-section {
    display: flex;
    align-items: center;
    margin-right: 12px;
}

.desktop-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: #3b82f6;
}

.btn-select-all-modern {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .3s ease;
    box-shadow: 0 4px 12px rgba(59,130,246,.3);
}

.btn-select-all-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(59,130,246,.4);
    color: #fff;
}

.btn-delete-all-modern {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .3s ease;
    box-shadow: 0 4px 12px rgba(239,68,68,.3);
}

.btn-delete-all-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(239,68,68,.4);
    color: #fff;
}

/* Mobile Action Buttons */
.mobile-action-buttons {
    display: none;
    padding: 12px 16px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    gap: 8px;
    flex-wrap: wrap;
}

.mobile-action-btn-top {
    flex: 1;
    min-width: 120px;
    padding: 12px 16px;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.mobile-action-btn-top:active {
    transform: scale(0.95);
}

.mobile-action-btn-top.select-all {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.mobile-action-btn-top.delete-selected {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

/* Mobile List */
.mobile-list { display: none; }

.user-list-item {
    background: white;
    border-radius: 12px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    transition: all 0.3s ease;
}

.user-list-header {
    display: flex;
    align-items: center;
    padding: 14px;
    gap: 12px;
    cursor: pointer;
    background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
}

.shop-slider-checkbox-section {
    display: none;
    flex-shrink: 0;
}

.shop-slider-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: #3b82f6;
}

.user-list-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.2rem;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.user-info-primary {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.user-name-mobile {
    font-weight: 700;
    color: #1f2937;
    font-size: 0.95rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-badges-mobile {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.badge-mobile {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.badge-approved {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.badge-pending {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

.user-expand-icon {
    display: none;
    flex-shrink: 0;
    color: #6b7280;
    font-size: 1rem;
}

.user-expand-icon i {
    transition: transform 0.3s ease;
}

.user-list-details {
    padding: 0 14px 14px 14px;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
}

.user-list-details.collapsed {
    display: none;
}

.user-list-details.expanded {
    display: block;
}

.detail-row-mobile {
    display: flex;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #e5e7eb;
    gap: 8px;
}

.detail-row-mobile:last-of-type {
    border-bottom: none;
}

.detail-icon {
    color: #6b7280;
    font-size: 0.9rem;
    width: 20px;
}

.detail-label {
    font-weight: 600;
    color: #4b5563;
    font-size: 0.85rem;
    min-width: 80px;
}

.detail-value {
    color: #1f2937;
    font-size: 0.85rem;
    flex: 1;
}

.user-actions-mobile {
    display: none;
    gap: 8px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 2px solid #e5e7eb;
}

.mobile-btn {
    flex: 1;
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.mobile-btn-view {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.mobile-btn-delete {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.mobile-btn:active {
    transform: scale(0.95);
}

/* Responsive */
@media (max-width: 768px) {
    .mobile-action-buttons { display: flex !important; }
    .shop-slider-checkbox-section { display: flex !important; }
    .reviews-header, .search-card { display: none !important; }
    .desktop-checkbox-section { display: none !important; }
    
    /* Keep page-header-card visible but hide only the header content */
    .page-header-card { 
        display: block !important; 
        padding: 12px !important;
        margin-bottom: 16px !important;
    }
    .page-header-content { display: none !important; }
    
    /* Stats Grid - 2x2 layout on mobile */
    .stats-grid { 
        display: grid !important; 
        grid-template-columns: repeat(2, 1fr) !important; 
        gap: 12px !important; 
        margin-bottom: 0 !important;
    }
    
    .stats-card-simple { 
        min-height: 110px !important; 
        padding: 16px 12px !important;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        border-radius: 12px !important;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3) !important;
    }
    
    .stats-card-simple:nth-child(2) {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%) !important;
    }
    
    .stats-card-simple:nth-child(3) {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%) !important;
    }
    
    .stats-card-simple:nth-child(4) {
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%) !important;
    }
    
    .stats-content-simple { 
        text-align: center !important;
        color: white !important;
    }
    
    .stats-content-simple h3 { 
        font-size: 2rem !important; 
        font-weight: 800 !important;
        color: white !important;
        margin-bottom: 8px !important;
    }
    
    .stats-content-simple p { 
        font-size: 0.75rem !important; 
        font-weight: 600 !important;
        color: rgba(255, 255, 255, 0.95) !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
    }
    
    .reviews-grid { display: none !important; }
    .mobile-list { display: block !important; }
    .user-expand-icon { display: flex !important; }
    .user-actions-mobile { display: flex !important; }
    
    .review-actions {
        flex-direction: column;
    }
    
    .btn-action-review {
        min-width: 100%;
    }
    
    .detail-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .detail-item {
        flex: 100%;
    }
}

/* Custom color variables */
:root {
    --primary-color: #0058A3;
    --accent-color: #FF6B00;
}

/* Shiny Gradient Icon Buttons */
.reviews-actions-icons {
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-icon-btn {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.header-icon-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(to bottom, rgba(255, 255, 255, 0.3), transparent);
    border-radius: 12px 12px 0 0;
}

.header-icon-btn::after {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
        45deg,
        transparent 30%,
        rgba(255, 255, 255, 0.4) 50%,
        transparent 70%
    );
    transform: rotate(45deg);
    animation: iconShine 3s infinite;
}

@keyframes iconShine {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

.header-icon-btn i {
    position: relative;
    z-index: 1;
    color: white;
}

.header-icon-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
}

.header-icon-btn:active {
    transform: translateY(-1px);
}

/* Icon Colors */
.select-icon {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
}

.delete-icon {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

.refresh-icon {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
}

.add-icon {
    background: linear-gradient(135deg, #10b981, #059669);
}

.play-icon {
    background: linear-gradient(135deg, #10b981, #059669);
}

.pause-icon {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}

/* Card Action Icons */
.review-actions-icons {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

.action-icon-btn {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.action-icon-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(to bottom, rgba(255, 255, 255, 0.3), transparent);
    border-radius: 10px 10px 0 0;
}

.action-icon-btn::after {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
        45deg,
        transparent 30%,
        rgba(255, 255, 255, 0.4) 50%,
        transparent 70%
    );
    transform: rotate(45deg);
    animation: iconShine 3s infinite;
}

.action-icon-btn i {
    position: relative;
    z-index: 1;
    color: white;
}

.action-icon-btn:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
}

.action-icon-btn:active {
    transform: translateY(0) scale(0.98);
}

/* Mobile Action Icons */
.mobile-action-icons {
    display: none;
    position: sticky;
    top: 0;
    z-index: 100;
    background: white;
    padding: 12px;
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    gap: 10px;
    justify-content: center;
}

.mobile-icon-btn {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.mobile-icon-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(to bottom, rgba(255, 255, 255, 0.3), transparent);
    border-radius: 12px 12px 0 0;
}

.mobile-icon-btn::after {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
        45deg,
        transparent 30%,
        rgba(255, 255, 255, 0.4) 50%,
        transparent 70%
    );
    transform: rotate(45deg);
    animation: iconShine 3s infinite;
}

.mobile-icon-btn i {
    position: relative;
    z-index: 1;
    color: white;
}

.mobile-icon-btn:active {
    transform: scale(0.95);
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .reviews-actions-icons {
        gap: 8px;
    }
    
    .header-icon-btn {
        width: 42px;
        height: 42px;
        font-size: 1rem;
    }
    
    .action-icon-btn {
        width: 40px;
        height: 40px;
        font-size: 0.95rem;
    }
    
    .mobile-action-icons {
        display: flex !important;
    }
    
    .mobile-action-buttons {
        display: none !important;
    }
}
</style>

<script>
// Select All / Deselect All for shop sliders
let allShopSlidersSelected = false;

function toggleSelectAllShopSliders() {
    allShopSlidersSelected = !allShopSlidersSelected;
    const checkboxes = document.querySelectorAll('.shop-slider-checkbox');
    checkboxes.forEach(cb => cb.checked = allShopSlidersSelected);
    
    // Update desktop button
    const btnDesktop = document.getElementById('selectAllBtnShopSliders');
    if (btnDesktop) {
        if (allShopSlidersSelected) {
            btnDesktop.innerHTML = '<i class="fas fa-times me-2"></i>Deselect All';
            btnDesktop.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
        } else {
            btnDesktop.innerHTML = '<i class="fas fa-check-double me-2"></i>Select All';
            btnDesktop.style.background = 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';
        }
    }
    
    // Update mobile button
    const btnMobile = document.getElementById('selectAllBtnShopSlidersMobile');
    if (btnMobile) {
        if (allShopSlidersSelected) {
            btnMobile.innerHTML = '<i class="fas fa-times"></i> Deselect All';
            btnMobile.classList.remove('select-all');
            btnMobile.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
        } else {
            btnMobile.innerHTML = '<i class="fas fa-check-double"></i> Select All';
            btnMobile.classList.add('select-all');
            btnMobile.style.background = 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';
        }
    }
}

async function bulkDeleteShopSliders() {
    const checkboxes = document.querySelectorAll('.shop-slider-checkbox:checked');
    
    // Get unique IDs only
    const uniqueIds = [...new Set(Array.from(checkboxes).map(cb => cb.value))];
    
    if (uniqueIds.length === 0) {
        showAlert('Please select at least one slider to delete', 'warning', 'No Selection');
        return;
    }
    
    if (await showConfirm(`Delete ${uniqueIds.length} selected slider(s)?`, 'Bulk Delete', {confirmText: 'Yes, Delete', cancelText: 'Cancel', type: 'danger'})) {
        // Create form for bulk delete
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        uniqueIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'slider_ids[]';
            input.value = id;
            form.appendChild(input);
        });
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'bulk_delete_sliders';
        form.appendChild(actionInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleDetails(header) {
    const item = header.closest('.user-list-item');
    const details = item.querySelector('.user-list-details');
    const icon = header.querySelector('.user-expand-icon i');
    
    if (details.classList.contains('collapsed')) {
        details.classList.remove('collapsed');
        details.classList.add('expanded');
        icon.style.transform = 'rotate(180deg)';
    } else {
        details.classList.add('collapsed');
        details.classList.remove('expanded');
        icon.style.transform = 'rotate(0deg)';
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>