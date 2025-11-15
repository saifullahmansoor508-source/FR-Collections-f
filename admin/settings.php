<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_settings'])) {
        $delivery_charges = floatval($_POST['delivery_charges']);
        $payment_account_name = sanitizeInput($_POST['payment_account_name']);
        $payment_account_number = sanitizeInput($_POST['payment_account_number']);
        $commission_rate = floatval($_POST['commission_rate']);
        
        try {
            $db->beginTransaction();
            
            // Update settings
            $settings = [
                'delivery_charges' => $delivery_charges,
                'payment_account_name' => $payment_account_name,
                'payment_account_number' => $payment_account_number,
                'commission_rate' => $commission_rate
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->execute([$value, $key]);
            }
            
            $db->commit();
            $success = "Settings updated successfully!";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error updating settings.";
        }
    } elseif (isset($_POST['upload_logo'])) {
        // Handle logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'svg'];
            $file_ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            
            if (in_array($file_ext, $allowed_types) && $_FILES['logo']['size'] <= 2097152) {
                $upload_dir = '../' . LOGO_DIR;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'logo.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                    $stmt = $db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'site_logo'");
                    $stmt->execute([$new_filename]);
                    $success = "Logo uploaded successfully!";
                } else {
                    $error = "Error uploading logo.";
                }
            } else {
                $error = "Invalid file type or size too large.";
            }
        } else {
            $error = "Please select a logo file.";
        }
    } elseif (isset($_POST['upload_favicon'])) {
        // Handle favicon upload
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] == 0) {
            $allowed_types = ['ico', 'png', 'jpg', 'jpeg', 'gif', 'svg'];
            $file_ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
            
            if (in_array($file_ext, $allowed_types) && $_FILES['favicon']['size'] <= 1048576) { // 1MB max
                $upload_dir = '../' . FAVICON_DIR;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'favicon.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['favicon']['tmp_name'], $upload_path)) {
                    // Update or insert favicon setting
                    $stmt = $db->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'site_favicon'");
                    $stmt->execute();
                    $exists = $stmt->fetchColumn();
                    
                    if ($exists) {
                        $stmt = $db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'site_favicon'");
                        $stmt->execute([$new_filename]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('site_favicon', ?)");
                        $stmt->execute([$new_filename]);
                    }
                    
                    $success = "Favicon uploaded successfully! It will appear on all pages.";
                } else {
                    $error = "Error uploading favicon.";
                }
            } else {
                $error = "Invalid file type or size too large. Use ICO, PNG, JPG, SVG up to 1MB.";
            }
        } else {
            $error = "Please select a favicon file.";
        }
    } elseif (isset($_POST['add_slider'])) {
        // Handle slider image upload
        if (isset($_FILES['slider_image']) && $_FILES['slider_image']['error'] == 0) {
            $title = sanitizeInput($_POST['slider_title']);
            $description = sanitizeInput($_POST['slider_description']);
            
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $file_ext = strtolower(pathinfo($_FILES['slider_image']['name'], PATHINFO_EXTENSION));
            
            if (in_array($file_ext, $allowed_types) && $_FILES['slider_image']['size'] <= 5242880) {
                $upload_dir = '../' . SLIDER_IMAGES_DIR;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = uniqid() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['slider_image']['tmp_name'], $upload_path)) {
                    $stmt = $db->prepare("INSERT INTO slider_images (image_path, title, description) VALUES (?, ?, ?)");
                    $stmt->execute([$new_filename, $title, $description]);
                    $success = "Slider image added successfully!";
                } else {
                    $error = "Error uploading slider image.";
                }
            } else {
                $error = "Invalid file type or size too large.";
            }
        } else {
            $error = "Please select a slider image.";
        }
    }
}

// Get current settings
$stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get slider images
$stmt = $db->prepare("SELECT * FROM slider_images ORDER BY sort_order ASC, created_at DESC");
$stmt->execute();
$slider_images = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Settings";
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

<div class="row">
    <!-- General Settings -->
    <div class="col-lg-6 mb-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h5 class="text-primary-custom mb-4">General Settings</h5>
            
            <form method="POST">
                <div class="mb-3">
                    <label for="delivery_charges" class="form-label">Delivery Charges (PKR)</label>
                    <input type="number" class="form-control" id="delivery_charges" name="delivery_charges" 
                           value="<?php echo $settings['delivery_charges'] ?? 150; ?>" min="0" step="0.01" required>
                </div>
                
                <div class="mb-3">
                    <label for="commission_rate" class="form-label">Affiliate Commission Rate (%)</label>
                    <input type="number" class="form-control" id="commission_rate" name="commission_rate" 
                           value="<?php echo $settings['commission_rate'] ?? 10; ?>" min="0" max="100" step="0.01" required>
                </div>
                
                <div class="mb-3">
                    <label for="payment_account_name" class="form-label">Payment Account Name</label>
                    <input type="text" class="form-control" id="payment_account_name" name="payment_account_name" 
                           value="<?php echo htmlspecialchars($settings['payment_account_name'] ?? 'Shameem Mansoor'); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label for="payment_account_number" class="form-label">Payment Account Number</label>
                    <input type="text" class="form-control" id="payment_account_number" name="payment_account_number" 
                           value="<?php echo htmlspecialchars($settings['payment_account_number'] ?? '03455836944'); ?>" required>
                </div>
                
                <button type="submit" name="update_settings" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Save Settings
                </button>
            </form>
        </div>
    </div>
    
    <!-- Logo Upload -->
    <div class="col-lg-6 mb-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h5 class="text-primary-custom mb-4">Site Logo</h5>
            
            <?php if (!empty($settings['site_logo']) && file_exists('../' . LOGO_DIR . $settings['site_logo'])): ?>
                <div class="text-center mb-3">
                    <img src="../<?php echo LOGO_DIR . $settings['site_logo']; ?>" alt="Current Logo" 
                         class="img-fluid" style="max-height: 100px;">
                    <p class="text-muted mt-2">Current Logo</p>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="logo" class="form-label">Upload New Logo</label>
                    <input type="file" class="form-control" id="logo" name="logo" accept="image/*" required>
                    <small class="form-text text-muted">JPG, PNG, GIF, SVG up to 2MB</small>
                </div>
                
                <button type="submit" name="upload_logo" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>Upload Logo
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Favicon Section -->
<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h5 class="text-primary-custom mb-4">
                <i class="fas fa-star me-2"></i>Site Favicon
            </h5>
            <p class="text-muted mb-3">
                The favicon appears in browser tabs, bookmarks, and shortcuts. Recommended size: 32x32 or 64x64 pixels.
            </p>
            
            <?php if (!empty($settings['site_favicon']) && file_exists('../' . FAVICON_DIR . $settings['site_favicon'])): ?>
                <div class="text-center mb-3">
                    <div class="favicon-preview-wrapper">
                        <img src="../<?php echo FAVICON_DIR . $settings['site_favicon']; ?>" alt="Current Favicon" 
                             class="favicon-preview">
                    </div>
                    <p class="text-muted mt-2">Current Favicon</p>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No favicon uploaded yet. Upload one to brand your site!
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="favicon" class="form-label">Upload Favicon</label>
                    <input type="file" class="form-control" id="favicon" name="favicon" 
                           accept=".ico,.png,.jpg,.jpeg,.gif,.svg" required>
                    <small class="form-text text-muted">
                        <strong>Formats:</strong> ICO, PNG, JPG, SVG (max 1MB)<br>
                        <strong>Best:</strong> Use 32x32 or 64x64 pixel square image
                    </small>
                </div>
                
                <button type="submit" name="upload_favicon" class="btn btn-success">
                    <i class="fas fa-upload me-2"></i>Upload Favicon
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Slider Management -->
<div class="row">
    <div class="col-12">
        <div class="bg-white rounded-lg shadow p-4">
            <h5 class="text-primary-custom mb-4">Slider Images</h5>
            
            <!-- Add New Slider -->
            <div class="row mb-4">
                <div class="col-12">
                    <h6>Add New Slider Image</h6>
                    <form method="POST" enctype="multipart/form-data" class="row g-3">
                        <div class="col-md-4">
                            <label for="slider_image" class="form-label">Slider Image</label>
                            <input type="file" class="form-control" id="slider_image" name="slider_image" accept="image/*" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="slider_title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="slider_title" name="slider_title">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="slider_description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="slider_description" name="slider_description">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" name="add_slider" class="btn btn-primary">
                                    <i class="fas fa-plus me-1"></i>Add
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Current Slider Images -->
            <?php if (!empty($slider_images)): ?>
                <div class="row">
                    <?php foreach ($slider_images as $slide): ?>
                        <div class="col-lg-4 col-md-6 mb-3">
                            <div class="card">
                                <img src="../<?php echo SLIDER_IMAGES_DIR . $slide['image_path']; ?>" 
                                     class="card-img-top" style="height: 200px; object-fit: cover;" 
                                     alt="<?php echo htmlspecialchars($slide['title']); ?>">
                                <div class="card-body">
                                    <?php if ($slide['title']): ?>
                                        <h6 class="card-title"><?php echo htmlspecialchars($slide['title']); ?></h6>
                                    <?php endif; ?>
                                    <?php if ($slide['description']): ?>
                                        <p class="card-text"><?php echo htmlspecialchars($slide['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <?php echo $slide['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </small>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" onclick="toggleSlider(<?php echo $slide['id']; ?>)">
                                                <i class="fas fa-<?php echo $slide['is_active'] ? 'eye-slash' : 'eye'; ?>"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" onclick="deleteSlider(<?php echo $slide['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-images fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No slider images added yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
async function toggleSlider(sliderId) {
    if (await showConfirm('Are you sure you want to toggle this slider?', 'Toggle Slider', {confirmText: 'Yes, Toggle', cancelText: 'Cancel', type: 'warning'})) {
        $.post('ajax/toggle_slider.php', {id: sliderId}, function(data) {
            if (data.success) {
                location.reload();
            } else {
                showAlert(data.message || 'Error toggling slider', 'error');
            }
        }, 'json');
    }
}

async function deleteSlider(sliderId) {
    if (await showConfirm('This slider image will be permanently deleted. Are you sure?', 'Delete Slider', {confirmText: 'Yes, Delete', cancelText: 'Cancel', type: 'danger'})) {
        $.post('ajax/delete_slider.php', {id: sliderId}, function(data) {
            if (data.success) {
                location.reload();
            } else {
                showAlert(data.message || 'Error deleting slider', 'error');
            }
        }, 'json');
    }
}
</script>

<style>
.favicon-preview-wrapper {
    display: inline-block;
    padding: 20px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.favicon-preview {
    width: 64px;
    height: 64px;
    object-fit: contain;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    background: white;
    padding: 4px;
}

.text-primary-custom {
    color: <?php echo PRIMARY_COLOR; ?> !important;
}
</style>

<?php require_once 'includes/footer.php'; ?>
