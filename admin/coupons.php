<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$page_title = "Coupon Codes";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_coupon':
                $code = strtoupper(sanitizeInput($_POST['code']));
                $type = sanitizeInput($_POST['type']);
                $value = floatval($_POST['value']);
                $min_amount = floatval($_POST['min_amount']);
                $max_uses = intval($_POST['max_uses']);
                $expires_at = sanitizeInput($_POST['expires_at']);
                $is_active = isset($_POST['is_active']) ? 'active' : 'inactive';
                
                if (!empty($code) && $value > 0) {
                    // Check if code already exists
                    $stmt = $db->prepare("SELECT id FROM coupons WHERE code = ?");
                    $stmt->execute([$code]);
                    
                    if ($stmt->fetchColumn()) {
                        $error_message = "Coupon code already exists!";
                    } else {
                        $stmt = $db->prepare("INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, usage_limit, expiry_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt->execute([$code, $type, $value, $min_amount, $max_uses > 0 ? $max_uses : null, $expires_at, $is_active])) {
                            $success_message = "Coupon added successfully!";
                        } else {
                            $error_message = "Error adding coupon.";
                        }
                    }
                } else {
                    $error_message = "Code and value are required.";
                }
                break;
                
            case 'toggle_status':
                $coupon_id = intval($_POST['coupon_id']);
                // Get current status
                $stmt = $db->prepare("SELECT status FROM coupons WHERE id = ?");
                $stmt->execute([$coupon_id]);
                $current_status = $stmt->fetchColumn();
                
                // Toggle status
                $new_status = ($current_status === 'active') ? 'inactive' : 'active';
                
                $stmt = $db->prepare("UPDATE coupons SET status = ? WHERE id = ?");
                if ($stmt->execute([$new_status, $coupon_id])) {
                    $success_message = "Coupon status updated!";
                } else {
                    $error_message = "Error updating coupon status.";
                }
                break;
                
            case 'delete_coupon':
                $coupon_id = intval($_POST['coupon_id']);
                $stmt = $db->prepare("DELETE FROM coupons WHERE id = ?");
                if ($stmt->execute([$coupon_id])) {
                    $success_message = "Coupon deleted successfully!";
                } else {
                    $error_message = "Error deleting coupon.";
                }
                break;
                
            case 'delete_multiple':
                if (isset($_POST['coupon_ids']) && is_array($_POST['coupon_ids'])) {
                    $coupon_ids = array_map('intval', $_POST['coupon_ids']);
                    $placeholders = str_repeat('?,', count($coupon_ids) - 1) . '?';
                    $stmt = $db->prepare("DELETE FROM coupons WHERE id IN ($placeholders)");
                    if ($stmt->execute($coupon_ids)) {
                        $success_message = count($coupon_ids) . " coupon(s) deleted successfully!";
                    } else {
                        $error_message = "Error deleting coupons.";
                    }
                }
                break;
                
            case 'deactivate_multiple':
                if (isset($_POST['coupon_ids']) && is_array($_POST['coupon_ids'])) {
                    $coupon_ids = array_map('intval', $_POST['coupon_ids']);
                    $placeholders = str_repeat('?,', count($coupon_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE coupons SET status = 'inactive' WHERE id IN ($placeholders)");
                    if ($stmt->execute($coupon_ids)) {
                        $success_message = count($coupon_ids) . " coupon(s) deactivated successfully!";
                    } else {
                        $error_message = "Error deactivating coupons.";
                    }
                }
                break;
        }
    }
}

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

if ($status_filter === 'active') {
    $where_conditions[] = "status = 'active' AND (expiry_date IS NULL OR expiry_date > CURDATE())";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "status = 'inactive'";
} elseif ($status_filter === 'expired') {
    $where_conditions[] = "expiry_date IS NOT NULL AND expiry_date <= CURDATE()";
}

if ($type_filter) {
    $where_conditions[] = "discount_type = ?";
    $params[] = $type_filter;
}

if ($search) {
    $where_conditions[] = "code LIKE ?";
    $params[] = '%' . $search . '%';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get all coupons with usage stats
$stmt = $db->prepare("
    SELECT c.*,
           c.used_count as usage_count,
           COALESCE(SUM(o.discount_amount), 0) as total_discount_given
    FROM coupons c
    LEFT JOIN orders o ON c.code = o.coupon_code
    $where_clause
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$stmt->execute($params);
$coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats for the dashboard
$stmt = $db->prepare("SELECT COUNT(*) FROM coupons");
$stmt->execute();
$total_coupons = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM coupons WHERE status = 'active' AND (expiry_date IS NULL OR expiry_date > CURDATE())");
$stmt->execute();
$active_coupons = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM coupons WHERE expiry_date IS NOT NULL AND expiry_date <= CURDATE()");
$stmt->execute();
$expired_coupons = $stmt->fetchColumn();

// Calculate total discount given
$stmt = $db->prepare("SELECT COALESCE(SUM(discount_amount), 0) FROM orders WHERE coupon_code IS NOT NULL");
$stmt->execute();
$total_discount_given = $stmt->fetchColumn();

require_once 'includes/header.php';
?>

<?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header with Stats -->
<div class="row mb-4">
    <div class="col-12">
        <div class="page-header-card">
            <div class="page-header-content">
                <div class="page-header-icon">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Coupon Management</h1>
                    <p class="page-subtitle">Manage discount coupons and track usage</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($total_coupons); ?></h3>
                        <p>Total Coupons</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($active_coupons); ?></h3>
                        <p>Active Coupons</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3>Rs <?php echo number_format($total_discount_given, 0); ?></h3>
                        <p>Total Discount</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modern Search Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="search-card">
            <div class="search-header">
                <div class="search-title">
                    <i class="fas fa-search me-2"></i>Find Coupons
                </div>
                <div class="search-actions">
                    <button class="btn btn-primary-modern" data-bs-toggle="modal" data-bs-target="#addCouponModal">
                        <i class="fas fa-plus-circle me-2"></i>Add Coupon
                    </button>
                </div>
            </div>

            <form method="GET" class="search-form">
                <div class="search-input-group">
                    <div class="search-input-modern">
                        <div class="search-input-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="search-field" placeholder="Search by coupon code...">
                            <button type="submit" class="search-btn-modern">
                                <i class="fas fa-search"></i>
                                <span>Search</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="search-filters">
                    <div class="custom-dropdown-modern" id="statusDropdown">
                        <div class="dropdown-selected-modern" onclick="toggleModernDropdown('statusDropdown')">
                            <span id="selectedStatus">
                                <?php echo $status_filter ? ucfirst($status_filter) : 'All Status'; ?>
                            </span>
                            <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                        </div>
                        <div class="dropdown-options-modern">
                            <div class="dropdown-option-modern <?php echo !$status_filter ? 'selected' : ''; ?>" onclick="selectStatus('', 'All Status')">
                                <i class="fas fa-list me-2"></i>All Status
                            </div>
                            <div class="dropdown-option-modern <?php echo $status_filter === 'active' ? 'selected' : ''; ?>" onclick="selectStatus('active', 'Active')">
                                <i class="fas fa-check-circle me-2"></i>Active
                            </div>
                            <div class="dropdown-option-modern <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>" onclick="selectStatus('inactive', 'Inactive')">
                                <i class="fas fa-pause-circle me-2"></i>Inactive
                            </div>
                            <div class="dropdown-option-modern <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>" onclick="selectStatus('expired', 'Expired')">
                                <i class="fas fa-clock me-2"></i>Expired
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($status_filter); ?>">

                    <div class="custom-dropdown-modern" id="typeDropdown">
                        <div class="dropdown-selected-modern" onclick="toggleModernDropdown('typeDropdown')">
                            <span id="selectedType">
                                <?php echo $type_filter ? ucfirst($type_filter) : 'All Types'; ?>
                            </span>
                            <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                        </div>
                        <div class="dropdown-options-modern">
                            <div class="dropdown-option-modern <?php echo !$type_filter ? 'selected' : ''; ?>" onclick="selectType('', 'All Types')">
                                <i class="fas fa-tags me-2"></i>All Types
                            </div>
                            <div class="dropdown-option-modern <?php echo $type_filter === 'percentage' ? 'selected' : ''; ?>" onclick="selectType('percentage', 'Percentage')">
                                <i class="fas fa-percent me-2"></i>Percentage
                            </div>
                            <div class="dropdown-option-modern <?php echo $type_filter === 'fixed' ? 'selected' : ''; ?>" onclick="selectType('fixed', 'Fixed Amount')">
                                <i class="fas fa-rupee-sign me-2"></i>Fixed Amount
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="type" id="typeInput" value="<?php echo htmlspecialchars($type_filter); ?>">

                    <a href="coupons.php" class="btn btn-clear-modern">
                        <i class="fas fa-redo me-2"></i>Clear
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mobile Action Bar (Hidden on Desktop) -->
<div class="mobile-action-bar">
    <button class="mobile-action-btn gradient-blue" data-bs-toggle="modal" data-bs-target="#addCouponModal" title="Add Coupon">
        <div class="mobile-btn-icon">
            <span class="icon-letter">A</span>
        </div>
        <span class="mobile-btn-label">Add Coupon</span>
    </button>
    
    <button class="mobile-action-btn gradient-green" id="mobileSelectBtn" onclick="toggleSelectAll()" title="Select All">
        <div class="mobile-btn-icon">
            <span class="icon-letter">S</span>
        </div>
        <span class="mobile-btn-label">Select All</span>
    </button>
    
    <button class="mobile-action-btn gradient-orange" id="mobileDeactivateBtn" onclick="deactivateSelectedMobile()" disabled title="Deactivate Selected">
        <div class="mobile-btn-icon">
            <span class="icon-letter">D</span>
        </div>
        <span class="mobile-btn-label">Deactivate</span>
    </button>
    
    <button class="mobile-action-btn gradient-red" id="mobileDeleteBtn" onclick="deleteSelectedMobile()" disabled title="Delete Selected">
        <div class="mobile-btn-icon">
            <span class="icon-letter">X</span>
        </div>
        <span class="mobile-btn-label">Delete</span>
    </button>
</div>

<!-- Coupons Grid -->
<div class="row">
    <div class="col-12">
        <div class="affiliates-container">
            <div class="users-header">
                <div class="users-header-content">
                    <div class="users-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="users-title">
                        <h4>Coupons</h4>
                        <span class="users-count"><?php echo count($coupons); ?> found</span>
                    </div>
                </div>
                <div class="users-actions">
                    <button class="btn btn-refresh-modern" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh List
                    </button>
                </div>
            </div>

            <?php if (empty($coupons)): ?>
                <div class="empty-users">
                    <div class="empty-users-icon"><i class="fas fa-ticket-alt"></i></div>
                    <h5>No coupons found</h5>
                    <p>Try adjusting your search terms.</p>
                    <button class="btn btn-primary-modern" data-bs-toggle="modal" data-bs-target="#addCouponModal">
                        <i class="fas fa-plus-circle me-2"></i>Add Coupon
                    </button>
                </div>
            <?php else: ?>
                <!-- Desktop Grid View -->
                <div class="affiliates-grid desktop-grid">
                    <?php foreach ($coupons as $coupon): ?>
                        <?php
                        $is_expired = $coupon['expiry_date'] && strtotime($coupon['expiry_date']) <= time();
                        $is_max_used = $coupon['usage_limit'] > 0 && ($coupon['usage_count'] ?? 0) >= $coupon['usage_limit'];
                        $status = $is_expired ? 'expired' : ($is_max_used ? 'max_used' : ($coupon['status'] === 'active' ? 'active' : 'inactive'));
                        ?>
                        <div class="affiliate-card">
                            <div class="user-card-header">
                                <div class="user-avatar-large" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                                    <?php echo htmlspecialchars(substr($coupon['code'], 0, 2)); ?>
                                </div>
                                <div class="user-status-badges" style="align-items:flex-end;">
                                    <span class="status-badge <?php echo $status; ?>-badge">
                                        <i class="fas <?php 
                                            if ($status === 'expired') echo 'fa-clock';
                                            elseif ($status === 'max_used') echo 'fa-exclamation-circle';
                                            elseif ($status === 'active') echo 'fa-check-circle';
                                            else echo 'fa-pause-circle';
                                        ?> me-1"></i>
                                        <?php 
                                            if ($status === 'expired') echo 'Expired';
                                            elseif ($status === 'max_used') echo 'Max Used';
                                            elseif ($status === 'active') echo 'Active';
                                            else echo 'Inactive';
                                        ?>
                                    </span>
                                </div>
                            </div>

                            <div class="user-card-body">
                                <div class="user-info">
                                    <div class="user-name"><?php echo htmlspecialchars($coupon['code']); ?></div>
                                    <div class="user-email">
                                        <?php if (($coupon['discount_type'] ?? 'percentage') === 'percentage'): ?>
                                            <?php echo $coupon['discount_value'] ?? 0; ?>%
                                        <?php else: ?>
                                            Rs <?php echo number_format($coupon['discount_value'] ?? 0, 2); ?>
                                        <?php endif; ?>
                                        Discount
                                    </div>
                                </div>

                                <div class="user-details">
                                    <div class="detail-item">
                                        <i class="fas fa-shopping-cart"></i>
                                        <span class="detail-label">Min Order:</span>
                                        <span class="detail-value">Rs <?php echo number_format($coupon['min_order_amount'] ?? 0, 2); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-calendar-plus"></i>
                                        <span class="detail-label">Created:</span>
                                        <span class="detail-value"><?php echo date('M d, Y', strtotime($coupon['created_at'])); ?></span>
                                    </div>
                                </div>

                                <div class="user-stats">
                                    <div class="stat-box">
                                        <div class="stat-number"><?php echo $coupon['usage_count'] ?? 0; ?></div>
                                        <div class="stat-label">Used</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-number">
                                            <?php if (($coupon['usage_limit'] ?? 0) > 0): ?>
                                                <?php echo $coupon['usage_limit']; ?>
                                            <?php else: ?>
                                                ∞
                                            <?php endif; ?>
                                        </div>
                                        <div class="stat-label">Max Uses</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-number">Rs <?php echo number_format($coupon['total_discount_given'] ?? 0, 0); ?></div>
                                        <div class="stat-label">Discount</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-number">
                                            <?php if ($coupon['expiry_date']): ?>
                                                <?php echo date('M d, Y', strtotime($coupon['expiry_date'])); ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </div>
                                        <div class="stat-label">Expires</div>
                                    </div>
                                </div>
                            </div>

                            <div class="user-card-footer">
                                <div class="user-actions">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="coupon_id" value="<?php echo $coupon['id']; ?>">
                                        <button type="submit" class="btn-action-modern <?php echo ($coupon['status'] === 'active') ? 'btn-block-modern' : 'btn-unblock-modern'; ?>" title="<?php echo ($coupon['status'] === 'active') ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="fas <?php echo ($coupon['status'] === 'active') ? 'fa-pause' : 'fa-play'; ?>"></i>
                                            <span><?php echo ($coupon['status'] === 'active') ? 'Deactivate' : 'Activate'; ?></span>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'This coupon will be permanently deleted. Are you sure?', 'Delete Coupon')">
                                        <input type="hidden" name="action" value="delete_coupon">
                                        <input type="hidden" name="coupon_id" value="<?php echo $coupon['id']; ?>">
                                        <button type="submit" class="btn-action-modern btn-view-modern">
                                            <i class="fas fa-trash"></i>
                                            <span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Mobile List View -->
                <div class="users-list-container mobile-list">
                    <div class="users-list">
                        <?php foreach ($coupons as $coupon): ?>
                            <?php
                            $is_expired = $coupon['expiry_date'] && strtotime($coupon['expiry_date']) <= time();
                            $is_max_used = $coupon['usage_limit'] > 0 && ($coupon['usage_count'] ?? 0) >= $coupon['usage_limit'];
                            $status = $is_expired ? 'expired' : ($is_max_used ? 'max_used' : ($coupon['status'] === 'active' ? 'active' : 'inactive'));
                            ?>
                            <div class="user-list-item" data-coupon-id="<?php echo $coupon['id']; ?>">
                                <div class="coupon-checkbox-section">
                                    <label class="checkbox-modern">
                                        <input type="checkbox" class="coupon-checkbox" value="<?php echo $coupon['id']; ?>" onchange="updateMobileBulkActions();">
                                        <span class="checkmark-modern"></span>
                                    </label>
                                </div>
                                
                                <div class="user-list-avatar" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                                    <?php echo htmlspecialchars(substr($coupon['code'], 0, 2)); ?>
                                </div>
                                
                                <div class="user-list-content">
                                    <div class="user-list-header" onclick="toggleCouponDetails(<?php echo $coupon['id']; ?>)">
                                        <div class="user-header-info">
                                            <div class="user-info-primary">
                                                <span class="user-name"><?php echo htmlspecialchars($coupon['code']); ?></span>
                                                <div class="user-badges">
                                                    <span class="status-badge <?php echo $status; ?>-badge">
                                                        <i class="fas <?php 
                                                            if ($status === 'expired') echo 'fa-clock';
                                                            elseif ($status === 'max_used') echo 'fa-exclamation-circle';
                                                            elseif ($status === 'active') echo 'fa-check-circle';
                                                            else echo 'fa-pause-circle';
                                                        ?> me-1"></i>
                                                        <?php 
                                                            if ($status === 'expired') echo 'Expired';
                                                            elseif ($status === 'max_used') echo 'Max Used';
                                                            elseif ($status === 'active') echo 'Active';
                                                            else echo 'Inactive';
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="user-info-secondary">
                                                <span class="user-joined">
                                                    <i class="fas fa-tag me-1"></i>
                                                    <?php if (($coupon['discount_type'] ?? 'percentage') === 'percentage'): ?>
                                                        <?php echo $coupon['discount_value'] ?? 0; ?>% OFF
                                                    <?php else: ?>
                                                        Rs <?php echo number_format($coupon['discount_value'] ?? 0, 0); ?> OFF
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="user-expand-icon">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    
                                    <div class="user-list-details collapsed" id="coupon-details-<?php echo $coupon['id']; ?>">
                                        <div class="user-actions-mobile">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="coupon_id" value="<?php echo $coupon['id']; ?>">
                                                <button type="submit" class="btn-icon <?php echo ($coupon['status'] === 'active') ? 'btn-delete-user' : 'btn-view-user'; ?>" title="<?php echo ($coupon['status'] === 'active') ? 'Deactivate' : 'Activate'; ?>">
                                                    <i class="fas <?php echo ($coupon['status'] === 'active') ? 'fa-pause' : 'fa-play'; ?>"></i>
                                                </button>
                                            </form>
                                            
                                            <button type="button" class="btn-icon btn-delete-user" title="Delete" onclick="deleteSingleCoupon(<?php echo $coupon['id']; ?>, '<?php echo htmlspecialchars($coupon['code']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Coupon Modal -->
<div class="modal fade" id="addCouponModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Coupon</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_coupon">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="code" class="form-label">Coupon Code *</label>
                            <input type="text" class="form-control" id="code" name="code" required style="text-transform: uppercase;">
                            <small class="text-muted">Will be converted to uppercase</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="type" class="form-label">Discount Type *</label>
                            <select class="form-select" id="type" name="type" required onchange="updateValueLabel()">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (Rs)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="value" class="form-label">Discount Value *</label>
                            <input type="number" class="form-control" id="value" name="value" min="0" step="0.01" required>
                            <small class="text-muted" id="valueHelp">Enter percentage (0-100)</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="min_amount" class="form-label">Minimum Order Amount</label>
                            <input type="number" class="form-control" id="min_amount" name="min_amount" min="0" step="0.01" value="0">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="max_uses" class="form-label">Maximum Uses</label>
                            <input type="number" class="form-control" id="max_uses" name="max_uses" min="0" value="0">
                            <small class="text-muted">0 = unlimited</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="expires_at" class="form-label">Expires At</label>
                            <input type="date" class="form-control" id="expires_at" name="expires_at">
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">
                            Active
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Coupon</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateValueLabel() {
    const type = document.getElementById('type').value;
    const valueHelp = document.getElementById('valueHelp');
    
    if (type === 'percentage') {
        valueHelp.textContent = 'Enter percentage (0-100)';
    } else {
        valueHelp.textContent = 'Enter fixed amount in Rs';
    }
}

// Modern Dropdown Functions
function toggleModernDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    const isActive = dropdown.classList.contains('active');

    // Close other dropdowns
    document.querySelectorAll('.custom-dropdown-modern').forEach(function(dd) {
        if (dd.id !== dropdownId) { dd.classList.remove('active'); }
    });

    if (isActive) {
        dropdown.classList.remove('active');
    } else {
        dropdown.classList.add('active');
    }
}

function selectStatus(value, text) {
    document.getElementById('selectedStatus').textContent = text;
    document.getElementById('statusInput').value = value;
    document.getElementById('statusDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#statusDropdown .dropdown-option-modern').forEach(function(option) {
        option.classList.remove('selected');
    });
    if (event && event.target) {
        const option = event.target.closest('.dropdown-option-modern');
        if (option) option.classList.add('selected');
    }

    // Submit the search form
    const form = document.querySelector('.search-form');
    if (form) form.submit();
}

function selectType(value, text) {
    document.getElementById('selectedType').textContent = text;
    document.getElementById('typeInput').value = value;
    document.getElementById('typeDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#typeDropdown .dropdown-option-modern').forEach(function(option) {
        option.classList.remove('selected');
    });
    if (event && event.target) {
        const option = event.target.closest('.dropdown-option-modern');
        if (option) option.classList.add('selected');
    }

    // Submit the search form
    const form = document.querySelector('.search-form');
    if (form) form.submit();
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    const isDropdown = e.target.closest('.custom-dropdown-modern');
    if (!isDropdown) {
        document.querySelectorAll('.custom-dropdown-modern').forEach(function(dd) { dd.classList.remove('active'); });
    }
});

// Select All / Deselect All functionality
function toggleSelectAll() {
    const checkboxes = document.querySelectorAll('.coupon-checkbox');
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
}

function updateMobileBulkActions() {
    const checkedBoxes = document.querySelectorAll('.coupon-checkbox:checked');
    const deleteBtn = document.getElementById('mobileDeleteBtn');
    const deactivateBtn = document.getElementById('mobileDeactivateBtn');
    
    if (checkedBoxes.length > 0) {
        deleteBtn.disabled = false;
        deactivateBtn.disabled = false;
        deleteBtn.querySelector('.mobile-btn-label').textContent = `Delete (${checkedBoxes.length})`;
        deactivateBtn.querySelector('.mobile-btn-label').textContent = `Deactivate (${checkedBoxes.length})`;
    } else {
        deleteBtn.disabled = true;
        deactivateBtn.disabled = true;
        deleteBtn.querySelector('.mobile-btn-label').textContent = 'Delete';
        deactivateBtn.querySelector('.mobile-btn-label').textContent = 'Deactivate';
    }
}

function deleteSelectedMobile() {
    const checkedBoxes = document.querySelectorAll('.coupon-checkbox:checked');
    if (checkedBoxes.length === 0) return;
    
    showBeautifulConfirm({
        title: 'Delete Coupons',
        message: `${checkedBoxes.length} coupon(s) will be permanently deleted. This action cannot be undone.`,
        icon: 'trash',
        iconColor: '#EF4444',
        confirmText: 'YES, DELETE',
        cancelText: 'CANCEL',
        onConfirm: () => {
            // Create a form to submit multiple deletions
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            checkedBoxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'coupon_ids[]';
                input.value = checkbox.value;
                form.appendChild(input);
            });
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_multiple';
            form.appendChild(actionInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function deactivateSelectedMobile() {
    const checkedBoxes = document.querySelectorAll('.coupon-checkbox:checked');
    if (checkedBoxes.length === 0) return;
    
    showBeautifulConfirm({
        title: 'Deactivate Coupons',
        message: `${checkedBoxes.length} coupon(s) will be deactivated and won't be usable anymore.`,
        icon: 'pause-circle',
        iconColor: '#F59E0B',
        confirmText: 'YES, DEACTIVATE',
        cancelText: 'CANCEL',
        onConfirm: () => {
            // Create a form to submit multiple deactivations
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            checkedBoxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'coupon_ids[]';
                input.value = checkbox.value;
                form.appendChild(input);
            });
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'deactivate_multiple';
            form.appendChild(actionInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Beautiful confirmation dialog
function showBeautifulConfirm(options) {
    const {
        title = 'Confirm Action',
        message = 'Are you sure?',
        icon = 'exclamation-triangle',
        iconColor = '#EF4444',
        confirmText = 'CONFIRM',
        cancelText = 'CANCEL',
        onConfirm = () => {},
        onCancel = () => {}
    } = options;
    
    // Create modal HTML
    const modalHTML = `
        <div class="beautiful-confirm-overlay" id="beautifulConfirmModal">
            <div class="beautiful-confirm-modal">
                <div class="beautiful-confirm-icon" style="background: ${iconColor};">
                    <i class="fas fa-${icon}"></i>
                </div>
                <h3 class="beautiful-confirm-title">${title}</h3>
                <p class="beautiful-confirm-message">${message}</p>
                <div class="beautiful-confirm-buttons">
                    <button class="beautiful-btn beautiful-btn-cancel" onclick="closeBeautifulConfirm()">
                        <i class="fas fa-times me-2"></i>${cancelText}
                    </button>
                    <button class="beautiful-btn beautiful-btn-confirm" onclick="confirmBeautifulAction()">
                        <i class="fas fa-check me-2"></i>${confirmText}
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Add to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Store callback
    window.beautifulConfirmCallback = onConfirm;
    
    // Animate in
    setTimeout(() => {
        document.getElementById('beautifulConfirmModal').classList.add('show');
    }, 10);
}

function closeBeautifulConfirm() {
    const modal = document.getElementById('beautifulConfirmModal');
    if (modal) {
        modal.classList.remove('show');
        setTimeout(() => modal.remove(), 300);
    }
}

function confirmBeautifulAction() {
    if (window.beautifulConfirmCallback) {
        window.beautifulConfirmCallback();
    }
    closeBeautifulConfirm();
}

// Delete single coupon with beautiful confirm
function deleteSingleCoupon(couponId, couponCode) {
    showBeautifulConfirm({
        title: 'Delete Coupon',
        message: `Coupon "${couponCode}" will be permanently deleted. This action cannot be undone.`,
        icon: 'trash',
        iconColor: '#EF4444',
        confirmText: 'YES, DELETE',
        cancelText: 'CANCEL',
        onConfirm: () => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_coupon';
            form.appendChild(actionInput);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'coupon_id';
            idInput.value = couponId;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Toggle coupon details dropdown (mobile)
function toggleCouponDetails(couponId) {
    const detailsElement = document.getElementById('coupon-details-' + couponId);
    const couponItem = document.querySelector('[data-coupon-id="' + couponId + '"]');
    const expandIcon = couponItem.querySelector('.user-expand-icon i');
    
    if (detailsElement.classList.contains('collapsed')) {
        detailsElement.classList.remove('collapsed');
        detailsElement.classList.add('expanded');
        expandIcon.style.transform = 'rotate(180deg)';
    } else {
        detailsElement.classList.remove('expanded');
        detailsElement.classList.add('collapsed');
        expandIcon.style.transform = 'rotate(0deg)';
    }
}

// Enhance interactions similar to Affiliates tab
document.addEventListener('DOMContentLoaded', function() {
    // Button click ripple
    document.querySelectorAll('.btn-action-modern').forEach(function(btn) {
        btn.addEventListener('click', function() {
            btn.classList.add('btn-clicked');
            setTimeout(function(){ btn.classList.remove('btn-clicked'); }, 150);
        });
    });

    // Card hover highlight
    document.querySelectorAll('.affiliate-card').forEach(function(card){
        card.addEventListener('mouseenter', function(){ card.classList.add('card-highlight'); });
        card.addEventListener('mouseleave', function(){ card.classList.remove('card-highlight'); });
    });
});
</script>

<style>
/* Reuse styles from affiliates.php */
/* Flatpickr styles */
@import url('https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css');
@import url('https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/themes/material_blue.css');

/* Enhance theme to match brand */
.flatpickr-calendar { box-shadow:0 16px 36px rgba(0,0,0,.18); border:1px solid #e5e7eb; border-radius:14px; }
.flatpickr-calendar .flatpickr-months { border-bottom:1px solid #eef2f7; }
.flatpickr-day.today { border-color: var(--accent-color); }
.flatpickr-day.selected,
.flatpickr-day.startRange,
.flatpickr-day.endRange { background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); border-color: transparent; color:#fff; }
.flatpickr-day:hover { background:#eef6ff; }
.flatpickr-weekday { color:#6b7280; font-weight:700; }
.flatpickr-day { border-radius:8px; }
.flatpickr-days { padding:6px 8px; }
.flatpickr-monthDropdown-months, .numInputWrapper input { font-weight:600; }
.flatpickr-calendar .flatpickr-time input { font-weight:600; }

/* Date input wrapper */
.date-input { position:relative; display:flex; align-items:center; }
.date-icon { position:absolute; left:10px; color:#9ca3af; font-size:.9rem; z-index:1; }
.date-input .filter-date { padding-left:34px; min-width:200px; }

/* Modern Dropdowns */
.custom-dropdown-modern { position: relative; width: 100%; min-width: 180px; }
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
.page-header-text p { color:#718096; font-size:.82rem; margin:0; }
.page-header-stats { display:flex; gap:14px; position:relative; z-index:1; align-items:center; }

/* Search card */
.search-card { background:#fff; border-radius:16px; padding:32px; box-shadow:0 4px 20px rgba(0,0,0,.08); margin-bottom:24px; }
.search-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid #e9ecef; }
.search-title { font-size:1.25rem; font-weight:600; color:#2d3748; margin:0; }
.btn-clear-modern { background:#fff; border:2px solid #e9ecef; color:#6b7280; padding:8px 16px; border-radius:8px; text-decoration:none; font-size:.9rem; font-weight:500; transition:all .3s ease; }
.btn-clear-modern:hover { background:#f8f9fa; border-color:#d1d5db; color:#374151; text-decoration:none; }
.search-input-group { display:flex; gap:16px; align-items:flex-end; }
.search-input-modern { flex:1; }
.search-input-wrapper { position:relative; display:flex; align-items:center; }
.search-field { width:100%; border:2px solid #e5e7eb; border-radius:12px; padding:14px 60px 14px 50px; font-size:.95rem; transition:all .3s ease; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.05); }
.search-field:focus { outline:none; border-color:var(--primary-color); box-shadow:0 0 0 3px rgba(0,88,163,.1); }
.search-icon { position:absolute; left:16px; color:#9ca3af; font-size:1rem; z-index:1; }
.search-btn-modern { position:absolute; right:8px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); border:none; border-radius:8px; width:36px; height:36px; display:flex; align-items:center; justify-content:center; color:#fff; cursor:pointer; transition:all .3s ease; box-shadow:0 2px 8px rgba(0,88,163,.2); }
.search-btn-modern span { display:none; }
.search-btn-modern:hover { transform:scale(1.05); box-shadow:0 4px 12px rgba(0,88,163,.3); }
.btn-export-modern { background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); color:#fff; border:none; padding:10px 14px; border-radius:10px; font-size:.85rem; font-weight:600; cursor:pointer; transition:all .3s ease; box-shadow:0 4px 12px rgba(0,88,163,.3); }
.btn-export-modern:hover { transform: translateY(-2px); box-shadow:0 6px 16px rgba(0,88,163,.4); color:#fff; }

/* Dashboard-like stat boxes */
.stats-grid {
    display: flex;
    gap: 16px;
    align-items: stretch;
    flex-wrap: wrap;
    justify-content: center;
}
.stat-card { background:#fff; border:1px solid #e9ecef; border-radius:12px; padding:12px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); transition:all .3s ease; }
.stat-card:hover { transform: translateY(-2px); box-shadow:0 8px 18px rgba(0,0,0,.1); }
.stat-card-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); box-shadow:0 4px 12px rgba(0,88,163,.3); }
.stat-card-icon.success { background:linear-gradient(135deg, #10b981, #059669); box-shadow:0 4px 12px rgba(16,185,129,.3); }
.stat-card-icon.accent { background:linear-gradient(135deg, #f59e0b, #d97706); box-shadow:0 4px 12px rgba(245,158,11,.3); }
.stat-card-content { display:flex; flex-direction:column; }
.stat-card-number { font-size:1.05rem; font-weight:700; color:#1f2937; }
.stat-card-label { font-size:.75rem; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
.stat-card.action { justify-content:center; }

/* Container and grid */
.affiliates-container { background:#fff; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.08); overflow:hidden; }
.users-header { background:linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding:24px 32px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #dee2e6; }
.users-header-content { display:flex; align-items:center; }
.users-icon { width:48px; height:48px; border-radius:12px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.25rem; margin-right:16px; box-shadow:0 4px 12px rgba(0,88,163,.3); }
.users-title h4 { margin:0; font-size:1.25rem; font-weight:600; color:#2d3748; }
.users-count { display:block; font-size:.9rem; color:#718096; font-weight:500; margin-top:2px; }
.users-actions { display:flex; gap:12px; }
.btn-refresh-modern { background:linear-gradient(135deg, #10b981, #059669); color:#fff; border:none; padding:10px 20px; border-radius:10px; font-size:.9rem; font-weight:600; cursor:pointer; transition:all .3s ease; box-shadow:0 4px 12px rgba(16,185,129,.3); }
.btn-refresh-modern:hover { transform: translateY(-2px); box-shadow:0 6px 16px rgba(16,185,129,.4); color:#fff; }

.affiliates-grid { padding:24px; display:grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap:20px; }
.affiliate-card { background:#fff; border:1px solid #e9ecef; border-radius:16px; overflow:hidden; transition:all .3s ease; box-shadow:0 2px 8px rgba(0,0,0,.06); position:relative; }
.affiliate-card:hover { transform: translateY(-4px); box-shadow:0 8px 24px rgba(0,0,0,.12); border-color:var(--primary-color); }
.card-highlight { border-color: var(--accent-color) !important; box-shadow:0 8px 24px rgba(255,107,0,.2) !important; }

/* Reuse user card pieces */
.user-card-header { background:linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding:20px; display:flex; justify-content:space-between; align-items:flex-start; border-bottom:1px solid #dee2e6; }
.user-avatar-large { width:60px; height:60px; border-radius:16px; background:linear-gradient(135deg, #667eea, #764ba2); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:1.5rem; box-shadow:0 4px 12px rgba(102,126,234,.3); }
.user-status-badges { display:flex; flex-direction:column; gap:6px; }
.status-badge { padding:4px 10px; border-radius:20px; font-size:.75rem; font-weight:600; display:flex; align-items:center; color:#fff; }
.status-badge.active-badge { background:linear-gradient(135deg, #10b981, #059669); }
.status-badge.inactive-badge { background:linear-gradient(135deg, #ef4444, #dc2626); }
.status-badge.expired-badge { background:linear-gradient(135deg, #f59e0b, #d97706); }
.status-badge.max_used-badge { background:linear-gradient(135deg, #8b5cf6, #7c3aed); }
.user-card-body { padding:20px; }
.user-info { margin-bottom:16px; }
.user-name { font-size:1.1rem; font-weight:700; color:#2d3748; margin-bottom:4px; }
.user-email { font-size:.9rem; color:#718096; }
.user-details { display:flex; flex-direction:column; gap:10px; margin-bottom:16px; }
.detail-item { display:flex; align-items:center; font-size:.85rem; }
.detail-item i { width:14px; color:var(--primary-color); margin-right:8px; }
.detail-label { color:#718096; font-weight:500; margin-right:6px; min-width:50px; }
.detail-value { color:#2d3748; font-weight:600; }
.user-stats { display:flex; gap:16px; padding:16px 0; border-top:1px solid #f1f5f9; border-bottom:1px solid #f1f5f9; }
.stat-box { flex:1; text-align:center; }
.stat-box .stat-number { font-size:1.2rem; font-weight:700; color:var(--primary-color); display:block; margin-bottom:2px; }
.stat-box .stat-label { font-size:.75rem; color:#718096; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.user-card-footer { background:#f8f9fa; padding:16px 20px; border-top:1px solid #e9ecef; }
.user-actions { display:flex; gap:8px; }
.btn-action-modern { flex:1; display:flex; align-items:center; justify-content:center; gap:6px; padding:10px 12px; border:none; border-radius:8px; font-size:.85rem; font-weight:600; cursor:pointer; transition:all .3s ease; text-decoration:none; position:relative; overflow:hidden; }
.btn-action-modern::before { content:''; position:absolute; top:50%; left:50%; width:0; height:0; background:rgba(255,255,255,.2); border-radius:50%; transition:all .3s ease; transform:translate(-50%,-50%); }
.btn-clicked::before { width:200px; height:200px; }
.btn-view-modern { background:linear-gradient(135deg, #3b82f6, #2563eb); color:#fff; box-shadow:0 2px 8px rgba(59,130,246,.3); }
.btn-unblock-modern { background:linear-gradient(135deg, #10b981, #059669); color:#fff; box-shadow:0 2px 8px rgba(16,185,129,.3); }
.btn-block-modern { background:linear-gradient(135deg, #ef4444, #dc2626); color:#fff; box-shadow:0 2px 8px rgba(239,68,68,.3); }

/* Empty state */
.empty-users { text-align:center; padding:80px 40px; }
.empty-users-icon { font-size:4rem; color:#cbd5e0; margin-bottom:24px; }
.btn-primary-modern { background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); color:#fff; border:none; padding:12px 24px; border-radius:10px; font-size:.9rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; transition:all .3s ease; box-shadow:0 4px 12px rgba(0,88,163,.3); }
.btn-primary-modern:hover { transform: translateY(-2px); box-shadow:0 6px 16px rgba(0,88,163,.4); color:#fff; text-decoration:none; }

/* Search filters layout */
.search-filters { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px; }
.filter-select { border:2px solid #e5e7eb; border-radius:10px; padding:8px 10px; background:#fff; min-width:180px; }
.filter-dates { display:flex; gap:8px; align-items:center; }
.filter-label { font-size:.8rem; color:#6b7280; }
.filter-date { border:2px solid #e5e7eb; border-radius:10px; padding:10px 12px; background:#fff; transition:all .3s ease; box-shadow:0 1px 3px rgba(0,0,0,.05); }
.filter-date:focus { outline:none; border-color: var(--primary-color); box-shadow:0 0 0 3px rgba(0,88,163,.1); }

/* Scrollbar styling for dropdown */
.dropdown-options-modern::-webkit-scrollbar { width: 6px; }
.dropdown-options-modern::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 10px; }
.dropdown-options-modern::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
.dropdown-options-modern::-webkit-scrollbar-thumb:hover { background: #9ca3af; }

/* Responsive */
@media (max-width:1024px){ .affiliates-grid{ grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:18px; } .page-header-card{ flex-direction:column; text-align:center; gap:24px; } .page-header-stats{ justify-content:center; } .search-input-group{ flex-direction:column; gap:16px; align-items:stretch; } }
@media (max-width:768px){ .page-header-card,.search-card,.affiliates-container{ margin:0 -15px; border-radius:0; } .affiliates-grid{ grid-template-columns:1fr; padding:16px; gap:16px; } .users-header{ flex-direction:column; gap:16px; text-align:center; padding:20px 24px; } .user-card-header{ flex-direction:column; gap:12px; align-items:center; text-align:center; } .user-actions{ flex-direction:column; gap:6px; } .btn-action-modern{ width:100%; } .user-stats{ flex-direction:column; gap:12px; } .stat-box{ padding:8px 0; border-bottom:1px solid #f1f5f9; } .stat-box:last-child{ border-bottom:none; } }
@media (max-width:576px){ .page-header-icon{ width:48px; height:48px; font-size:1.5rem; margin-right:16px; } .page-header-text h1{ font-size:1.8rem; } .page-header-stats{ gap:20px; flex-wrap:wrap; } .stat-number{ font-size:1.5rem; } .search-card{ padding:24px 20px; } .affiliates-grid{ gap:12px; padding:16px; } .user-card-body{ padding:16px; } .user-card-footer{ padding:12px 16px; } }

/* Simple Stats Cards */
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

.stats-content-simple i {
    position: absolute;
    top: 20px;
    right: 20px;
}

/* ========================================
   USER LIST STYLES (FROM USERS.PHP)
   ======================================== */
.users-list-container { padding: 24px; }
.users-list { display: flex; flex-direction: column; gap: 16px; }
.user-list-item { display: flex; align-items: center; gap: 16px; padding: 20px; background: white; border: 2px solid #e9ecef; border-radius: 16px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); position: relative; overflow: hidden; }
.user-list-item::before { content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 3px; background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); transform: scaleY(0); transition: transform 0.3s ease; }
.user-list-item:hover { transform: translateX(4px); box-shadow: 0 8px 24px rgba(0, 88, 163, 0.15); border-color: var(--primary-color); }
.user-list-item:hover::before { transform: scaleY(1); }
.user-list-avatar { width: 50px; height: 50px; border-radius: 12px; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.3rem; flex-shrink: 0; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
.user-list-content { flex: 1; display: flex; flex-direction: column; gap: 12px; }
.user-list-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.user-header-info { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.user-expand-icon { display: none; width: 32px; height: 32px; border-radius: 8px; background: #f3f4f6; align-items: center; justify-content: center; flex-shrink: 0; transition: background 0.3s ease; }
.user-expand-icon i { color: #6b7280; font-size: 0.9rem; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.user-list-header:hover .user-expand-icon { background: #e5e7eb; }
.user-info-primary { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.user-info-primary .user-name { font-size: 1.1rem; font-weight: 700; color: #2d3748; }
.user-badges { display: flex; gap: 6px; }
.user-info-secondary { display: flex; align-items: center; gap: 16px; }
.user-joined { color: #718096; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; }
.user-list-details { display: flex; flex-wrap: wrap; gap: 20px; padding-top: 12px; border-top: 1px solid #f1f5f9; }
.user-list-details.collapsed { }
.user-list-details.expanded { }
.user-actions-mobile { display: none; width: 100%; gap: 8px; padding-top: 12px; border-top: 1px solid #f1f5f9; margin-top: 8px; }
.desktop-only { display: flex; }
.user-list-actions { display: flex; gap: 8px; align-items: center; }
.btn-icon { width: 40px; height: 40px; border-radius: 10px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; transition: all 0.3s ease; }
.btn-view-user { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
.btn-view-user:hover { transform: translateY(-2px) scale(1.05); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4); }
.btn-delete-user { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
.btn-delete-user:hover { transform: translateY(-2px) scale(1.05); box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4); }

/* Hide mobile list on desktop */
.mobile-list { display: none; }

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
.gradient-blue .mobile-btn-icon {
    background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
}

.gradient-green .mobile-btn-icon {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
}

.gradient-orange .mobile-btn-icon {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
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
.coupon-checkbox-section {
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
   BEAUTIFUL CONFIRMATION MODAL
   ======================================== */
.beautiful-confirm-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.beautiful-confirm-overlay.show {
    opacity: 1;
}

.beautiful-confirm-modal {
    background: white;
    border-radius: 24px;
    padding: 40px 32px;
    max-width: 420px;
    width: 90%;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    transform: scale(0.7) translateY(-20px);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
    overflow: hidden;
}

.beautiful-confirm-overlay.show .beautiful-confirm-modal {
    transform: scale(1) translateY(0);
}

.beautiful-confirm-modal::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
    animation: modalShine 3s infinite;
}

@keyframes modalShine {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

.beautiful-confirm-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    margin: 0 auto 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    animation: iconPulse 2s infinite;
}

@keyframes iconPulse {
    0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); }
}

.beautiful-confirm-icon i {
    font-size: 2.5rem;
    color: white;
    position: relative;
    z-index: 1;
}

.beautiful-confirm-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 16px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.beautiful-confirm-message {
    font-size: 1rem;
    color: #6b7280;
    line-height: 1.6;
    margin-bottom: 32px;
}

.beautiful-confirm-buttons {
    display: flex;
    gap: 12px;
    flex-direction: column;
}

.beautiful-btn {
    padding: 14px 28px;
    border: none;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
    letter-spacing: 0.5px;
}

.beautiful-btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.beautiful-btn:active::before {
    width: 300px;
    height: 300px;
}

.beautiful-btn-cancel {
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    color: #4b5563;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.beautiful-btn-cancel:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
}

.beautiful-btn-confirm {
    background: linear-gradient(135deg, #EF4444, #DC2626);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

.beautiful-btn-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.5);
}

.beautiful-btn i {
    position: relative;
    z-index: 1;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    /* Show mobile action bar */
    .mobile-action-bar {
        display: flex !important;
    }
    
    /* Show checkboxes on mobile */
    .coupon-checkbox-section {
        display: flex !important;
    }
    
    /* Hide users header section on mobile */
    .users-header { display: none !important; }
    
    /* Hide search card on mobile */
    .search-card { display: none !important; }
    
    /* Adjust stats cards to display in a row */
    .stats-grid { display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 8px !important; width: 100%; }
    .stats-card-simple { min-height: 120px !important; padding: 16px 8px !important; aspect-ratio: 1 / 1 !important; display: flex !important; align-items: center !important; justify-content: center !important; }
    .stats-content-simple h3 { font-size: 1.5rem !important; margin-bottom: 6px !important; }
    .stats-content-simple p { font-size: 0.75rem !important; margin-bottom: 0 !important; }
    
    /* Hide desktop grid, show mobile list */
    .desktop-grid { display: none !important; }
    .mobile-list { display: block !important; }
    
    .users-list-container { padding: 12px 15px; background: #f8f9fa; }
    .users-list { animation: fadeInUp 0.5s ease; }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    
    .user-list-item { display: flex !important; flex-direction: row; flex-wrap: nowrap; align-items: flex-start; padding: 12px; gap: 12px; border-radius: 12px; margin-bottom: 12px; animation: slideIn 0.3s ease; animation-fill-mode: both; }
    @keyframes slideIn { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }
    
    .user-list-avatar { width: 45px; height: 45px; font-size: 1.2rem; flex-shrink: 0; }
    .user-list-content { flex: 1; min-width: 0; gap: 0; }
    .user-expand-icon { display: flex !important; -webkit-tap-highlight-color: transparent; }
    .user-expand-icon:active { background: #d1d5db; transform: scale(0.95); }
    .user-list-header { cursor: pointer; padding: 0; -webkit-tap-highlight-color: transparent; }
    .user-header-info { gap: 6px; }
    .user-info-primary { width: 100%; flex-direction: column; align-items: flex-start; gap: 6px; }
    .user-info-primary .user-name { font-size: 1rem; font-weight: 600; }
    .user-badges { flex-wrap: wrap; gap: 4px; }
    .user-info-secondary { width: 100%; }
    .user-joined { font-size: 0.8rem; }
    .user-list-details { flex-direction: column; gap: 8px; padding-top: 12px; margin-top: 0; }
    .user-list-details.collapsed { display: none !important; }
    .user-list-details.expanded { display: flex; animation: expandDetails 0.3s ease; }
    @keyframes expandDetails { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .user-list-actions.desktop-only { display: none !important; }
    .user-actions-mobile { display: flex !important; justify-content: center; gap: 12px; }
    .btn-icon { width: 38px; height: 38px; font-size: 0.9rem; }
    .btn-icon:active { transform: scale(0.9); }
}

@media (max-width: 576px) {
    .stats-grid { gap: 6px !important; }
    .stats-card-simple { min-height: 100px !important; padding: 12px 6px !important; }
    .stats-content-simple h3 { font-size: 1.2rem !important; }
    .stats-content-simple p { font-size: 0.65rem !important; }
    .user-list-item { padding: 10px; gap: 10px; }
    .user-list-avatar { width: 40px; height: 40px; font-size: 1.1rem; }
    .user-info-primary .user-name { font-size: 0.95rem; }
    .btn-icon { width: 36px; height: 36px; font-size: 0.85rem; }
}
</style>

<?php require_once 'includes/footer.php'; ?>