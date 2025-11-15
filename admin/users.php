<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session_manager.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle user block/unblock
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_block'])) {
    $user_id = intval($_POST['user_id']);
    $is_blocked = intval($_POST['is_blocked']);
    
    $stmt = $db->prepare("UPDATE users SET is_blocked = ? WHERE id = ?");
    if ($stmt->execute([$is_blocked, $user_id])) {
        // If blocking the user, immediately terminate their session
        if ($is_blocked) {
            clearUserSessions($user_id);
            flagUserForLogout($user_id);
        }
        
        $success = $is_blocked ? "User blocked and logged out successfully!" : "User unblocked successfully!";
    } else {
        $error = "Error updating user status.";
    }
}

// Get search parameter
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query
$where_condition = '';
$params = [];

if ($search) {
    $where_condition = "WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ?";
    $search_param = '%' . $search . '%';
    $params = [$search_param, $search_param, $search_param];
}

$stmt = $db->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
           (SELECT SUM(total_amount) FROM orders WHERE user_id = u.id AND status = 'Delivered') as total_spent,
           (SELECT COUNT(*) FROM affiliates WHERE user_id = u.id) as is_affiliate
    FROM users u 
    $where_condition
    ORDER BY u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Users Management";
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

<!-- Page Header with Stats -->
<div class="row mb-4">
    <div class="col-12">
        <div class="page-header-card">
            <div class="page-header-content">
                <div class="page-header-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Users Management</h1>
                    <p class="page-subtitle">Manage and monitor all registered users</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo count($users); ?></h3>
                        <p>Total Users</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3>
                            <?php
                            $activeUsers = 0;
                            foreach ($users as $user) {
                                if (!$user['is_blocked']) $activeUsers++;
                            }
                            echo $activeUsers;
                            ?>
                        </h3>
                        <p>Active Users</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3>
                            <?php
                            $affiliateCount = 0;
                            foreach ($users as $user) {
                                if ($user['is_affiliate']) $affiliateCount++;
                            }
                            echo $affiliateCount;
                            ?>
                        </h3>
                        <p>Affiliates</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Search Bar (visible only on mobile) - Below stats cards -->
<div class="row mb-3 mobile-search-bar-bottom">
    <div class="col-12">
        <div class="mobile-search-container">
            <div class="d-flex gap-2 align-items-center">
                <input type="text" 
                       id="mobileSearchInput"
                       placeholder="Search users..."
                       class="mobile-search-input">
                <div class="dropdown">
                    <button type="button" class="btn-mobile-export" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-download"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="exportUsers('csv', event);">
                            <i class="fas fa-file-csv me-2"></i>CSV
                        </a></li>
                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="exportUsers('pdf', event);">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                    </ul>
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
                    <i class="fas fa-search me-2"></i>Find Users
                </div>
                <div class="search-actions">
                    <a href="users.php" class="btn btn-clear-modern">
                        <i class="fas fa-redo me-2"></i>Clear Search
                    </a>
                </div>
            </div>

            <form method="GET" class="search-form">
                <div class="search-input-group">
                    <div class="search-input-modern">
                        <div class="search-input-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text"
                                   id="search"
                                   name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search by name, email, or phone..."
                                   class="search-field">
                            <button type="submit" class="search-btn-modern">
                                <i class="fas fa-search"></i>
                                <span>Search</span>
                            </button>
                        </div>
                    </div>

                    <div class="search-actions-group">
                        <div class="dropdown">
                            <button type="button" class="btn btn-export-modern" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-download me-2"></i>Export Users
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="exportUsers('csv', event);">
                                    <i class="fas fa-file-csv me-2"></i>Export CSV
                                </a></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="exportUsers('pdf', event);">
                                    <i class="fas fa-file-pdf me-2"></i>Export PDF
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mobile Search Bar (visible only on mobile) -->
<div class="row mb-3 mobile-search-bar">
    <div class="col-12">
        <div class="mobile-search-container">
            <form method="GET" class="d-flex gap-2">
                <input type="text" 
                       name="search" 
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Search users..."
                       class="mobile-search-input">
                <div class="dropdown">
                    <button type="button" class="btn-mobile-export" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-download"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="exportUsers('csv', event);">
                            <i class="fas fa-file-csv me-2"></i>CSV
                        </a></li>
                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="exportUsers('pdf', event);">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Users List - Modern List Design -->
<div class="row">
    <div class="col-12">
        <div class="users-container">
            <div class="users-header">
                <div class="users-header-content">
                    <div class="users-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="users-title">
                        <h4>Registered Users</h4>
                        <span class="users-count"><?php echo count($users); ?> users found</span>
                    </div>
                </div>
                <div class="users-actions">
                    <button class="btn btn-icon-action btn-delete-selected" onclick="deleteSelectedUsers()" title="Delete Selected" style="display: none;">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                    <button class="btn btn-icon-action btn-block-selected" onclick="blockSelectedUsers()" title="Block Selected" style="display: none;">
                        <i class="fas fa-ban"></i>
                    </button>
                    <button class="btn btn-icon-action btn-cancel-selected" onclick="cancelUserSelection()" title="Cancel Selection" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                    <button class="btn btn-refresh-modern" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh
                    </button>
                </div>
            </div>

                <?php if (!empty($users)): ?>
                    <div class="users-list-container">
                        <div class="users-list-header">
                            <div class="select-all-container">
                                <input type="checkbox" id="selectAllUsers" class="user-checkbox-select-all" onclick="toggleSelectAllUsers()">
                                <label for="selectAllUsers" class="select-all-label">Select All</label>
                            </div>
                            <div class="mobile-bulk-actions">
                                <button class="btn-icon-mobile btn-delete-selected-mobile" onclick="deleteSelectedUsers()" title="Delete Selected" style="display: none;">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                <button class="btn-icon-mobile btn-block-selected-mobile" onclick="blockSelectedUsers()" title="Block Selected" style="display: none;">
                                    <i class="fas fa-ban"></i>
                                </button>
                            </div>
                            <span class="selected-count" id="selectedUserCount" style="display: none;">
                                <i class="fas fa-check-circle me-1"></i><span id="selectedUserCountText">0</span> selected
                            </span>
                        </div>
                    
                    <div class="users-list">
                        <?php foreach ($users as $user): ?>
                            <div class="user-list-item" data-user-id="<?php echo $user['id']; ?>">
                                <div class="user-checkbox-container">
                                    <input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>" onclick="updateUserSelectedCount()">
                                </div>
                                
                                <div class="user-list-avatar">
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                </div>
                                
                                <div class="user-list-content">
                                    <div class="user-list-header" onclick="toggleUserDetails(<?php echo $user['id']; ?>)">
                                        <div class="user-header-info">
                                            <div class="user-info-primary">
                                                <span class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                                <div class="user-badges">
                                                    <?php if ($user['is_affiliate']): ?>
                                                        <span class="status-badge affiliate-badge">
                                                            <i class="fas fa-handshake me-1"></i>Affiliate
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="status-badge <?php echo $user['is_blocked'] ? 'blocked-badge' : 'active-badge'; ?>">
                                                        <i class="fas fa-<?php echo $user['is_blocked'] ? 'ban' : 'check-circle'; ?> me-1"></i>
                                                        <?php echo $user['is_blocked'] ? 'Blocked' : 'Active'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="user-info-secondary">
                                                <span class="user-joined">
                                                    <i class="fas fa-calendar-plus me-1"></i>
                                                    <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                                </span>
                                                <span class="user-orders-badge">
                                                    <i class="fas fa-shopping-cart me-1"></i>
                                                    <?php echo $user['total_orders']; ?> orders
                                                </span>
                                            </div>
                                        </div>
                                        <div class="user-expand-icon">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    
                                    <div class="user-list-details collapsed" id="user-details-<?php echo $user['id']; ?>">
                                        <div class="user-detail-checkbox-mobile">
                                            <input type="checkbox" class="user-checkbox-mobile" value="<?php echo $user['id']; ?>" onclick="updateUserSelectedCount()">
                                            <label>Select this user</label>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-envelope me-2"></i>
                                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-phone me-2"></i>
                                            <span><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : 'Not provided'; ?></span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-shopping-cart me-2"></i>
                                            <span class="stat-value"><?php echo $user['total_orders']; ?> orders</span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-dollar-sign me-2"></i>
                                            <span class="stat-value"><?php echo $user['total_spent'] ? formatPrice($user['total_spent']) : 'PKR 0'; ?></span>
                                        </div>
                                        
                                        <div class="user-actions-mobile">
                                            <button class="btn-icon btn-view-user" onclick="event.stopPropagation(); viewUser(<?php echo $user['id']; ?>)" title="View Profile">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn-icon <?php echo $user['is_blocked'] ? 'btn-unblock-user' : 'btn-block-user'; ?>" 
                                                    onclick="event.stopPropagation(); toggleBlockUser(<?php echo $user['id']; ?>, <?php echo $user['is_blocked'] ? 0 : 1; ?>)" 
                                                    title="<?php echo $user['is_blocked'] ? 'Unblock User' : 'Block User'; ?>">
                                                <i class="fas fa-<?php echo $user['is_blocked'] ? 'unlock' : 'ban'; ?>"></i>
                                            </button>
                                            <button class="btn-icon btn-delete-user" onclick="event.stopPropagation(); deleteUser(<?php echo $user['id']; ?>)" title="Delete User">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="user-list-actions desktop-only">
                                    <button class="btn-icon btn-view-user" onclick="viewUser(<?php echo $user['id']; ?>)" title="View Profile">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn-icon <?php echo $user['is_blocked'] ? 'btn-unblock-user' : 'btn-block-user'; ?>" 
                                            onclick="toggleBlockUser(<?php echo $user['id']; ?>, <?php echo $user['is_blocked'] ? 0 : 1; ?>)" 
                                            title="<?php echo $user['is_blocked'] ? 'Unblock User' : 'Block User'; ?>">
                                        <i class="fas fa-<?php echo $user['is_blocked'] ? 'unlock' : 'ban'; ?>"></i>
                                    </button>
                                    <button class="btn-icon btn-delete-user" onclick="deleteUser(<?php echo $user['id']; ?>)" title="Delete User">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Users Footer -->
                <div class="users-footer">
                    <div class="users-summary">
                        Showing <?php echo count($users); ?> registered users
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-users">
                    <div class="empty-users-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h5>No users found</h5>
                    <p>No users match your search criteria. Try adjusting your search terms.</p>
                    <a href="users.php" class="btn btn-primary-modern">
                        <i class="fas fa-redo me-2"></i>Clear Search
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- User Details Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">User Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="userDetails">
                <!-- User details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Confirmation Dialog -->
<div class="custom-dialog-overlay" id="customDialogOverlay">
    <div class="custom-dialog" id="customDialog">
        <div class="custom-dialog-icon" id="dialogIcon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="custom-dialog-content">
            <h3 class="custom-dialog-title" id="dialogTitle">Confirm Action</h3>
            <p class="custom-dialog-message" id="dialogMessage">Are you sure you want to proceed?</p>
        </div>
        <div class="custom-dialog-actions">
            <button type="button" class="btn-dialog-cancel" id="dialogCancel">
                <i class="fas fa-times me-2"></i>Cancel
            </button>
            <button type="button" class="btn-dialog-confirm" id="dialogConfirm">
                <i class="fas fa-check me-2"></i>Confirm
            </button>
        </div>
    </div>
</div>

<script>
// ===== Custom Dialog System =====
function showCustomDialog(options) {
    return new Promise((resolve) => {
        const overlay = document.getElementById('customDialogOverlay');
        const dialog = document.getElementById('customDialog');
        const icon = document.getElementById('dialogIcon');
        const title = document.getElementById('dialogTitle');
        const message = document.getElementById('dialogMessage');
        const confirmBtn = document.getElementById('dialogConfirm');
        const cancelBtn = document.getElementById('dialogCancel');
        
        // Set dialog content
        title.textContent = options.title || 'Confirm Action';
        message.textContent = options.message || 'Are you sure you want to proceed?';
        
        // Set icon type
        icon.className = 'custom-dialog-icon ' + (options.type || 'warning');
        const iconElement = icon.querySelector('i');
        
        switch(options.type) {
            case 'danger':
                iconElement.className = 'fas fa-exclamation-circle';
                break;
            case 'warning':
                iconElement.className = 'fas fa-exclamation-triangle';
                break;
            case 'info':
                iconElement.className = 'fas fa-info-circle';
                break;
            default:
                iconElement.className = 'fas fa-exclamation-triangle';
        }
        
        // Set confirm button style
        if (options.confirmStyle === 'primary') {
            confirmBtn.className = 'btn-dialog-confirm primary';
        } else {
            confirmBtn.className = 'btn-dialog-confirm';
        }
        
        // Set button text
        const confirmText = confirmBtn.querySelector('span') || document.createElement('span');
        confirmText.textContent = options.confirmText || 'Confirm';
        if (!confirmBtn.querySelector('span')) {
            confirmBtn.innerHTML = '<i class="fas fa-check me-2"></i>';
            confirmBtn.appendChild(confirmText);
        }
        
        // Show dialog
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Handle confirm
        const handleConfirm = () => {
            hideDialog();
            resolve(true);
        };
        
        // Handle cancel
        const handleCancel = () => {
            hideDialog();
            resolve(false);
        };
        
        // Hide dialog
        const hideDialog = () => {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            confirmBtn.removeEventListener('click', handleConfirm);
            cancelBtn.removeEventListener('click', handleCancel);
            overlay.removeEventListener('click', handleOverlayClick);
        };
        
        // Handle overlay click
        const handleOverlayClick = (e) => {
            if (e.target === overlay) {
                handleCancel();
            }
        };
        
        // Add event listeners
        confirmBtn.addEventListener('click', handleConfirm);
        cancelBtn.addEventListener('click', handleCancel);
        overlay.addEventListener('click', handleOverlayClick);
        
        // Handle ESC key
        const handleEsc = (e) => {
            if (e.key === 'Escape') {
                handleCancel();
                document.removeEventListener('keydown', handleEsc);
            }
        };
        document.addEventListener('keydown', handleEsc);
    });
}

// Delete single user
async function deleteUser(userId) {
    console.log('deleteUser called for user ID:', userId);
    
    const confirmed = await showCustomDialog({
        title: 'Delete User',
        message: 'Are you sure you want to permanently delete this user? This will also delete all their orders, reviews, and related data. This action cannot be undone.',
        type: 'danger',
        confirmText: 'Delete',
        confirmStyle: 'danger'
    });
    
    console.log('User confirmed delete:', confirmed);
    
    if (!confirmed) {
        return;
    }
    
    $.ajax({
        url: 'ajax/delete_user.php',
        method: 'POST',
        data: { user_id: userId },
        dataType: 'json',
        success: function(response) {
            console.log('Delete response:', response);
            if (response.success) {
                showUserNotification('success', response.message);
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                showUserNotification('error', response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('Delete AJAX Error:', status, error);
            console.error('Response:', xhr.responseText);
            showUserNotification('error', 'Failed to delete user. Please try again.');
        }
    });
}

// Delete selected users
async function deleteSelectedUsers() {
    console.log('deleteSelectedUsers called');
    
    const selectedUsers = $('.user-checkbox:checked, .user-checkbox-mobile:checked').map(function() {
        return $(this).val();
    }).get();
    
    console.log('Selected users:', selectedUsers);
    
    if (selectedUsers.length === 0) {
        showUserNotification('warning', 'Please select at least one user to delete.');
        return;
    }
    
    const confirmed = await showCustomDialog({
        title: 'Delete Multiple Users',
        message: `Are you sure you want to permanently delete ${selectedUsers.length} user(s)? This will also delete all their orders, reviews, and related data. This action cannot be undone.`,
        type: 'danger',
        confirmText: 'Delete All',
        confirmStyle: 'danger'
    });
    
    if (!confirmed) {
        return;
    }
    
    $.ajax({
        url: 'ajax/delete_user.php',
        method: 'POST',
        data: { user_ids: selectedUsers },
        dataType: 'json',
        success: function(response) {
            console.log('Bulk delete response:', response);
            if (response.success) {
                showUserNotification('success', response.message);
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                showUserNotification('error', response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('Bulk delete AJAX Error:', status, error);
            console.error('Response:', xhr.responseText);
            showUserNotification('error', 'Failed to delete users. Please try again.');
        }
    });
}

// Block selected users
async function blockSelectedUsers() {
    console.log('blockSelectedUsers called');
    
    const selectedUsers = $('.user-checkbox:checked, .user-checkbox-mobile:checked').map(function() {
        return $(this).val();
    }).get();
    
    console.log('Selected users for blocking:', selectedUsers);
    
    if (selectedUsers.length === 0) {
        showUserNotification('warning', 'Please select at least one user to block.');
        return;
    }
    
    const confirmed = await showCustomDialog({
        title: 'Block Multiple Users',
        message: `Are you sure you want to block ${selectedUsers.length} user(s)? They will be immediately logged out and unable to access their accounts.`,
        type: 'warning',
        confirmText: 'Block Users',
        confirmStyle: 'danger'
    });
    
    if (!confirmed) {
        return;
    }
    
    $.ajax({
        url: 'ajax/block_users.php',
        method: 'POST',
        data: { user_ids: selectedUsers, is_blocked: 1 },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showUserNotification('success', response.message);
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                showUserNotification('error', response.message);
            }
        },
        error: function() {
            showUserNotification('error', 'Failed to block users. Please try again.');
        }
    });
}

// Toggle block/unblock single user
async function toggleBlockUser(userId, blockStatus) {
    console.log('toggleBlockUser called for user ID:', userId, 'blockStatus:', blockStatus);
    
    const action = blockStatus === 1 ? 'block' : 'unblock';
    const confirmed = await showCustomDialog({
        title: blockStatus === 1 ? 'Block User' : 'Unblock User',
        message: blockStatus === 1 
            ? 'Are you sure you want to block this user? They will be immediately logged out and unable to access their account.'
            : 'Are you sure you want to unblock this user? They will be able to access their account again.',
        type: blockStatus === 1 ? 'warning' : 'info',
        confirmText: blockStatus === 1 ? 'Block User' : 'Unblock User',
        confirmStyle: blockStatus === 1 ? 'danger' : 'primary'
    });
    
    if (!confirmed) {
        return;
    }
    
    $.ajax({
        url: 'ajax/block_users.php',
        method: 'POST',
        data: { user_ids: [userId], is_blocked: blockStatus },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showUserNotification('success', response.message);
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                showUserNotification('error', response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            showUserNotification('error', 'Failed to ' + action + ' user. Please try again.');
        }
    });
}

// Toggle select all checkboxes
function toggleSelectAllUsers() {
    const selectAll = document.getElementById('selectAllUsers');
    const checkboxes = document.querySelectorAll('.user-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
    
    updateUserSelectedCount();
}

// Update selected count display
function updateUserSelectedCount() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    const count = checkboxes.length;
    const selectAll = document.getElementById('selectAllUsers');
    const totalCheckboxes = document.querySelectorAll('.user-checkbox').length;
    
    // Update select all checkbox state
    selectAll.checked = count === totalCheckboxes && count > 0;
    selectAll.indeterminate = count > 0 && count < totalCheckboxes;
    
    // Show/hide desktop bulk action buttons
    const deleteBtn = document.querySelector('.btn-delete-selected');
    const blockBtn = document.querySelector('.btn-block-selected');
    const cancelBtn = document.querySelector('.btn-cancel-selected');
    
    // Show/hide mobile bulk action buttons
    const deleteBtnMobile = document.querySelector('.btn-delete-selected-mobile');
    const blockBtnMobile = document.querySelector('.btn-block-selected-mobile');
    
    const selectedCountElement = document.getElementById('selectedUserCount');
    const selectedCountText = document.getElementById('selectedUserCountText');
    
    if (count > 0) {
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
        if (blockBtn) blockBtn.style.display = 'inline-flex';
        if (cancelBtn) cancelBtn.style.display = 'inline-flex';
        if (deleteBtnMobile) deleteBtnMobile.style.display = 'inline-flex';
        if (blockBtnMobile) blockBtnMobile.style.display = 'inline-flex';
        selectedCountElement.style.display = 'inline-flex';
        selectedCountText.textContent = count;
    } else {
        if (deleteBtn) deleteBtn.style.display = 'none';
        if (blockBtn) blockBtn.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';
        if (deleteBtnMobile) deleteBtnMobile.style.display = 'none';
        if (blockBtnMobile) blockBtnMobile.style.display = 'none';
        selectedCountElement.style.display = 'none';
    }
}

// Cancel selection
function cancelUserSelection() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    const selectAll = document.getElementById('selectAllUsers');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    selectAll.checked = false;
    
    updateUserSelectedCount();
}

// Toggle user details dropdown (mobile)
function toggleUserDetails(userId) {
    const detailsElement = document.getElementById('user-details-' + userId);
    const userItem = document.querySelector('[data-user-id="' + userId + '"]');
    const expandIcon = userItem.querySelector('.user-expand-icon i');
    
    if (detailsElement.classList.contains('collapsed')) {
        // Expand
        detailsElement.classList.remove('collapsed');
        detailsElement.classList.add('expanded');
        expandIcon.style.transform = 'rotate(180deg)';
    } else {
        // Collapse
        detailsElement.classList.remove('expanded');
        detailsElement.classList.add('collapsed');
        expandIcon.style.transform = 'rotate(0deg)';
    }
}

// Show notification
function showUserNotification(type, message) {
    const alertClass = type === 'success' ? 'alert-success' : type === 'warning' ? 'alert-warning' : 'alert-danger';
    const icon = type === 'success' ? 'fa-check-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle';
    
    const alert = $('<div class="alert ' + alertClass + ' alert-dismissible fade show" role="alert">' +
        '<i class="fas ' + icon + ' me-2"></i>' + message +
        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
        '</div>');
    
    $('body').prepend(alert);
    
    setTimeout(function() {
        alert.fadeOut(400, function() {
            $(this).remove();
        });
    }, 3000);
}

// View user details with enhanced modal
function viewUser(userId) {
    // Show loading in modal
    $('#userModal .modal-body').html(`
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3">Loading user details...</p>
        </div>
    `);

    // Load user details via AJAX
    $.get('ajax/get_user_details.php', {id: userId}, function(data) {
        $('#userModal .modal-body').html(data);
        $('#userModal').modal('show');
    }).fail(function() {
        $('#userModal .modal-body').html(`
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Error loading user details. Please try again.
            </div>
        `);
        $('#userModal').modal('show');
    });
}

// Export users function
function exportUsers(format, evt) {
    // Prevent default if event is passed
    if (evt) {
        evt.preventDefault();
        evt.stopPropagation();
    }
    
    try {
        const searchField = document.getElementById('search');
        const searchValue = searchField ? searchField.value : '';
        const url = 'export_users.php?format=' + format + '&search=' + encodeURIComponent(searchValue);
        
        console.log('Exporting users as ' + format);
        console.log('URL:', url);
        
        // Open in new window
        window.open(url, '_blank');
        
        // Show success notification
        showUserNotification('success', 'Export started! Check your downloads.');
    } catch (error) {
        console.error('Export error:', error);
        showUserNotification('error', 'Failed to export users. Please try again.');
    }
    
    return false;
}

// Real-time search functionality
function searchUsers(searchTerm) {
    const userItems = document.querySelectorAll('.user-list-item');
    let visibleCount = 0;
    
    userItems.forEach(item => {
        const userName = item.querySelector('.user-name').textContent.toLowerCase();
        const userEmail = item.querySelector('.user-detail-item .fas.fa-envelope')?.parentElement.textContent.toLowerCase() || '';
        const userPhone = item.querySelector('.user-detail-item .fas.fa-phone')?.parentElement.textContent.toLowerCase() || '';
        
        const searchLower = searchTerm.toLowerCase();
        
        if (userName.includes(searchLower) || userEmail.includes(searchLower) || userPhone.includes(searchLower)) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Show/hide empty state
    const usersList = document.querySelector('.users-list');
    if (visibleCount === 0 && searchTerm !== '') {
        if (!document.querySelector('.no-results-message')) {
            const noResults = document.createElement('div');
            noResults.className = 'no-results-message';
            noResults.innerHTML = '<i class="fas fa-search"></i><p>No users found matching "' + searchTerm + '"</p>';
            usersList.appendChild(noResults);
        }
    } else {
        const noResults = document.querySelector('.no-results-message');
        if (noResults) noResults.remove();
    }
}

// Test button functionality
function testButtons() {
    console.log('Testing buttons...');
    console.log('Export button:', document.querySelector('.btn-export-modern'));
    console.log('Delete selected button:', document.querySelector('.btn-delete-selected'));
    console.log('Block selected button:', document.querySelector('.btn-block-selected'));
    console.log('Refresh button:', document.querySelector('.btn-refresh-modern'));
    
    // Test export function
    console.log('Testing export function...');
    console.log('exportUsers function exists:', typeof exportUsers !== 'undefined');
    
    // Test if Bootstrap is loaded
    console.log('Bootstrap loaded:', typeof bootstrap !== 'undefined');
    
    // Initialize dropdowns if Bootstrap is loaded
    if (typeof bootstrap !== 'undefined') {
        console.log('Initializing Bootstrap dropdowns...');
        var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle, [data-bs-toggle="dropdown"]'));
        dropdownElementList.map(function (dropdownToggleEl) {
            if (!bootstrap.Dropdown.getInstance(dropdownToggleEl)) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            }
        });
        console.log('Dropdowns initialized:', dropdownElementList.length);
    }
}

// Initialize components on page load
$(document).ready(function() {
    // Test buttons after page load
    console.log('Page loaded, testing buttons in 1 second...');
    setTimeout(testButtons, 1000);
    
    // Initialize Bootstrap 5 tooltips (wait for Bootstrap to load)
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"], [title]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            // Add data-bs-toggle if only title exists
            if (!tooltipTriggerEl.hasAttribute('data-bs-toggle')) {
                tooltipTriggerEl.setAttribute('data-bs-toggle', 'tooltip');
            }
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    } else {
        console.warn('Bootstrap not loaded yet, tooltips will be initialized by footer');
    }

    // Auto-submit search on enter
    $('#search').on('keypress', function(e) {
        if (e.which === 13) {
            $(this).closest('form').submit();
        }
    });
    
    // Real-time mobile search
    $('#mobileSearchInput').on('input', function() {
        searchUsers($(this).val());
    });

    // Add loading animation to refresh button
    $('.btn-refresh-modern').on('click', function() {
        const btn = $(this);
        const originalHtml = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Refreshing...');
        btn.prop('disabled', true);

        setTimeout(function() {
            btn.html(originalHtml);
            btn.prop('disabled', false);
        }, 1000);
    });

    // Initial update of selected count
    updateUserSelectedCount();
    
    // Add click handlers for export dropdown items (backup)
    $('.dropdown-item').on('click', function(e) {
        const href = $(this).attr('href');
        if (href === 'javascript:void(0);' || href === '#') {
            e.preventDefault();
            const onclickAttr = $(this).attr('onclick');
            if (onclickAttr) {
                console.log('Executing onclick:', onclickAttr);
                try {
                    eval(onclickAttr);
                } catch(err) {
                    console.error('Error executing onclick:', err);
                }
            }
        }
    });
    
    // Direct event binding for export buttons (backup method)
    $(document).on('click', '.dropdown-item[onclick*="exportUsers"]', function(e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Export dropdown item clicked via jQuery');
        
        const text = $(this).text().trim();
        if (text.includes('CSV')) {
            console.log('Exporting CSV');
            exportUsers('csv', e);
        } else if (text.includes('PDF')) {
            console.log('Exporting PDF');
            exportUsers('pdf', e);
        }
    });
    
    // Alternative: Direct click on dropdown items
    $(document).on('click', '.dropdown-menu .dropdown-item', function(e) {
        const href = $(this).attr('href');
        if (href && href.includes('javascript:void(0)')) {
            console.log('Dropdown item clicked, href:', href);
            console.log('onclick attribute:', $(this).attr('onclick'));
        }
    });

    // Highlight user cards on hover
    $('.user-card').hover(
        function() {
            $(this).addClass('card-highlight');
        },
        function() {
            $(this).removeClass('card-highlight');
        }
    );

    // Add click animation to action buttons
    $('.btn-action-modern').on('click', function() {
        const btn = $(this);
        btn.addClass('btn-clicked');
        setTimeout(function() {
            btn.removeClass('btn-clicked');
        }, 150);
    });
});
</script>

<style>
/* Page Header with Stats - Reused from dashboard */
.page-header-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

.page-header-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 200px;
    height: 200px;
    background: linear-gradient(135deg, rgba(0, 88, 163, 0.1) 0%, rgba(255, 107, 0, 0.1) 100%);
    border-radius: 50%;
    transform: translate(100px, -100px);
}

.page-header-content {
    display: flex;
    align-items: center;
    position: relative;
    z-index: 1;
}

.page-header-icon {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2rem;
    margin-right: 24px;
    box-shadow: 0 8px 24px rgba(0, 88, 163, 0.3);
}

.page-header-text h1 {
    font-size: 2.2rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 4px;
}

.page-header-text p {
    color: #718096;
    font-size: 1.1rem;
    margin: 0;
}

.page-header-stats {
    display: flex;
    gap: 32px;
    position: relative;
    z-index: 1;
}

.stat-item {
    text-align: center;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary-color);
    display: block;
    line-height: 1;
}

.stat-label {
    font-size: 0.9rem;
    color: #718096;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Search Card */
.search-card {
    background: white;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
}

.search-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e9ecef;
}

.search-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0;
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
    gap: 16px;
    align-items: flex-end;
}

.search-input-modern {
    flex: 1;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-field {
    width: 100%;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px 60px 14px 50px; /* icon-only button; smaller right padding */
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: white;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.search-field:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
}

.search-icon {
    position: absolute;
    left: 16px;
    color: #9ca3af;
    font-size: 1rem;
    z-index: 1;
}

.search-btn-modern {
    position: absolute;
    right: 8px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border: none;
    border-radius: 8px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 88, 163, 0.2);
}

.search-btn-modern span { display: none; }

.search-btn-modern:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.search-actions-group {
    display: flex;
    align-items: center;
    position: relative;
    z-index: 10;
}

.btn-export-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border: none;
    padding: 14px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer !important;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
    position: relative;
    z-index: 10;
    pointer-events: auto;
}

.btn-export-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
    color: white;
}

/* Users Container */
.users-container {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.users-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 24px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
}

.users-header-content {
    display: flex;
    align-items: center;
}

.users-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
    margin-right: 16px;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.users-title h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
}

.users-count {
    display: block;
    font-size: 0.9rem;
    color: #718096;
    font-weight: 500;
    margin-top: 2px;
}

.users-actions {
    display: flex;
    gap: 12px;
    position: relative;
    z-index: 10;
}

.btn-refresh-modern {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer !important;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    position: relative;
    z-index: 10;
    pointer-events: auto;
}

.btn-refresh-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    color: white;
}

/* Users Grid */
.users-grid {
    padding: 24px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}

/* Dashboard-like stat boxes */
.stats-grid {
    display: flex;
    gap: 16px;
    align-items: stretch;
    flex-wrap: wrap;
    justify-content: center;
}

.user-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    position: relative;
}

.user-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    border-color: var(--primary-color);
}

.card-highlight {
    border-color: var(--accent-color) !important;
    box-shadow: 0 8px 24px rgba(255, 107, 0, 0.2) !important;
}

/* User Card Header */
.user-card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 1px solid #dee2e6;
}

.user-avatar-large {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.5rem;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.user-status-badges {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-end;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.affiliate-badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.active-badge {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.blocked-badge {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

/* User Card Body */
.user-card-body {
    padding: 20px;
}

.user-info {
    margin-bottom: 16px;
}

.user-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 4px;
}

.user-email {
    font-size: 0.9rem;
    color: #718096;
}

.user-details {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 16px;
}

.detail-item {
    display: flex;
    align-items: center;
    font-size: 0.85rem;
}

.detail-item i {
    width: 14px;
    color: var(--primary-color);
    margin-right: 8px;
}

.detail-label {
    color: #718096;
    font-weight: 500;
    margin-right: 6px;
    min-width: 50px;
}

.detail-value {
    color: #2d3748;
    font-weight: 600;
}

.user-stats {
    display: flex;
    gap: 16px;
    padding: 16px 0;
    border-top: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
}

.stat-box {
    flex: 1;
    text-align: center;
}

.stat-box .stat-number {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--primary-color);
    display: block;
    margin-bottom: 2px;
}

.stat-box .stat-label {
    font-size: 0.75rem;
    color: #718096;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* User Card Footer */
.user-card-footer {
    background: #f8f9fa;
    padding: 16px 20px;
    border-top: 1px solid #e9ecef;
}

.user-actions {
    display: flex;
    gap: 8px;
}

.btn-action-modern {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 12px;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}

.btn-action-modern::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    transition: all 0.3s ease;
    transform: translate(-50%, -50%);
}

.btn-clicked::before {
    width: 200px;
    height: 200px;
}

.btn-view-modern {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.btn-view-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    color: white;
}

.btn-unblock-modern {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-unblock-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    color: white;
}

.btn-block-modern {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.btn-block-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
    color: white;
}

.action-form {
    flex: 1;
}

/* Users Footer */
.users-footer {
    background: #f8f9fa;
    padding: 16px 32px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: center;
    align-items: center;
}

.users-summary {
    color: #718096;
    font-size: 0.9rem;
    font-weight: 500;
}

/* Empty Users State */
.empty-users {
    text-align: center;
    padding: 80px 40px;
}

.empty-users-icon {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 24px;
}

.empty-users h5 {
    color: #4a5568;
    margin-bottom: 8px;
    font-weight: 600;
}

.empty-users p {
    color: #718096;
    margin-bottom: 24px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
    color: white;
    text-decoration: none;
}

/* Smooth scroll for better mobile UX */
html {
    scroll-behavior: smooth;
}

/* Mobile Search Bar - Hidden on desktop */
.mobile-search-bar,
.mobile-search-bar-top,
.mobile-search-bar-bottom {
    display: none;
}

.mobile-bulk-actions {
    display: none;
}

.btn-icon-mobile {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.btn-delete-selected-mobile {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.btn-delete-selected-mobile:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

.btn-block-selected-mobile {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.btn-block-selected-mobile:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
}

.user-detail-checkbox-mobile {
    display: none; /* Hidden on desktop */
}

.no-results-message {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
}

.no-results-message i {
    font-size: 3rem;
    margin-bottom: 16px;
    display: block;
}

.no-results-message p {
    font-size: 1.1rem;
    margin: 0;
}

.mobile-search-container {
    background: white;
    padding: 12px 15px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.mobile-search-input {
    flex: 1;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.mobile-search-input:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
}

.btn-mobile-search {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    flex-shrink: 0;
}

.btn-mobile-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
}

.btn-mobile-export {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    cursor: pointer !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
    flex-shrink: 0;
    position: relative;
    z-index: 10;
    pointer-events: auto;
}

.btn-mobile-export:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
}

/* Dropdown Menu Styles */
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-menu {
    z-index: 1000 !important;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    border: 1px solid #e5e7eb;
    padding: 8px 0;
    margin-top: 8px;
    min-width: 180px;
}

.dropdown-item {
    padding: 10px 20px;
    font-size: 0.9rem;
    color: #374151;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    cursor: pointer !important;
}

.dropdown-item:hover {
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
    color: var(--primary-color);
    padding-left: 24px;
}

.dropdown-item i {
    color: var(--primary-color);
    width: 20px;
}

/* ===== Users List View Styles ===== */
.users-list-container {
    padding: 24px 32px;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

.users-list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: white;
    border-radius: 12px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.select-all-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-checkbox-select-all {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: var(--primary-color);
}

.select-all-label {
    font-weight: 600;
    color: #374151;
    margin: 0;
    cursor: pointer;
    user-select: none;
}

.selected-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.9rem;
}

.users-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.user-list-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 16px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
}

.user-list-item::before {
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

.user-list-item:hover {
    transform: translateX(4px);
    box-shadow: 0 8px 24px rgba(0, 88, 163, 0.15);
    border-color: var(--primary-color);
}

.user-list-item:hover::before {
    transform: scaleY(1);
}

.user-list-item:active {
    transform: translateX(2px) scale(0.98);
    transition-duration: 0.1s;
}

.user-checkbox-container {
    display: flex;
    align-items: center;
    padding-left: 4px;
}

.user-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: var(--primary-color);
}

.user-list-avatar {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.3rem;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.user-list-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.user-list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    /* cursor is set to pointer only on mobile */
}

.user-header-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.user-expand-icon {
    display: none; /* Hidden on desktop */
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #f3f4f6;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background 0.3s ease;
}

.user-expand-icon i {
    color: #6b7280;
    font-size: 0.9rem;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.user-list-header:hover .user-expand-icon {
    background: #e5e7eb;
}

.user-list-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.user-info-primary {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.user-info-primary .user-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2d3748;
}

.user-badges {
    display: flex;
    gap: 6px;
}

.user-info-secondary {
    display: flex;
    align-items: center;
    gap: 16px;
}

.user-joined {
    color: #718096;
    font-size: 0.9rem;
    font-weight: 500;
    display: flex;
    align-items: center;
}

.user-list-details {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
}

/* Collapsed/Expanded states are only active on mobile */
.user-list-details.collapsed {
    /* Will be overridden on mobile */
}

.user-list-details.expanded {
    /* Will be overridden on mobile */
}

.user-actions-mobile {
    display: none; /* Hidden on desktop */
    width: 100%;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
    margin-top: 8px;
}

.desktop-only {
    display: flex;
}

.user-detail-item {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #374151;
    font-size: 0.9rem;
}

.user-detail-item i {
    color: var(--primary-color);
}

.user-detail-item .stat-value {
    font-weight: 600;
    color: var(--primary-color);
}

.user-list-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.btn-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.btn-view-user {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-view-user:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

.btn-delete-user {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-delete-user:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

.btn-block-user {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.btn-block-user:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
}

.btn-unblock-user {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-unblock-user:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
}

.btn-icon-action {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    border: none;
    cursor: pointer !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    margin-right: 8px;
    position: relative;
    z-index: 10;
    pointer-events: auto;
}

.btn-delete-selected {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-delete-selected:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

.btn-block-selected {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.btn-block-selected:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
}

.btn-cancel-selected {
    background: linear-gradient(135deg, #6b7280, #4b5563);
    color: white;
    box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
}

.btn-cancel-selected:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(107, 114, 128, 0.4);
}

/* Responsive Design */
@media (max-width: 1024px) {
    .users-grid {
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 18px;
    }

    .page-header-card {
        flex-direction: column;
        text-align: center;
        gap: 24px;
    }

    .page-header-stats {
        justify-content: center;
    }

    .search-input-group {
        flex-direction: column;
        gap: 16px;
        align-items: stretch;
    }
    
    .users-list-container {
        padding: 16px;
    }
    
    .user-list-item {
        flex-direction: row;
        flex-wrap: wrap;
    }
}

@media (max-width: 768px) {
    .page-header-card,
    .users-container {
        margin: 0 -15px;
        border-radius: 0;
    }
    
    /* Hide the full search card on mobile */
    .search-card {
        display: none !important;
    }
    
    /* Show bottom mobile search bar (below stats) */
    .mobile-search-bar-bottom {
        display: block !important;
    }
    
    /* Hide top mobile search bar */
    .mobile-search-bar-top,
    .mobile-search-bar {
        display: none !important;
    }
    
    .mobile-search-container {
        margin: 0 -15px;
        border-radius: 0;
        padding: 10px 15px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    /* Prevent text selection on interactive elements for better mobile UX */
    .btn-mobile-search,
    .btn-mobile-export,
    .btn-icon,
    .user-list-item,
    .stats-card-simple {
        -webkit-tap-highlight-color: transparent;
        -webkit-touch-callout: none;
        user-select: none;
    }
    
    /* Hide users header section (Registered Users and Refresh) on mobile */
    .users-header {
        display: none !important;
    }
    
    /* Hide users footer on mobile */
    .users-footer {
        display: none !important;
    }
    
    /* Compact stats cards for mobile - 3 in one row */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        width: 100%;
    }
    
    .stats-card-simple {
        min-height: 90px;
        padding: 12px 8px;
    }
    
    .stats-content-simple h3 {
        font-size: 1.3rem;
        margin-bottom: 4px;
    }
    
    .stats-content-simple p {
        font-size: 0.7rem;
        margin-bottom: 0;
    }
    
    /* Clean list container on mobile */
    .users-list-container {
        padding: 12px 15px;
        background: #f8f9fa;
    }
    
    /* Show select all header on mobile with inline bulk actions */
    .users-list-header {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        background: white;
        margin: 0 -15px 16px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
    
    .select-all-container {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .select-all-label {
        font-size: 0.9rem;
        font-weight: 600;
    }
    
    .user-checkbox-select-all {
        width: 18px;
        height: 18px;
    }
    
    .mobile-bulk-actions {
        display: flex !important;
        gap: 8px;
    }
    
    .btn-icon-mobile {
        -webkit-tap-highlight-color: transparent;
    }
    
    .btn-icon-mobile:active {
        transform: scale(0.9);
    }
    
    .selected-count {
        display: none !important; /* Hide count on mobile, show actions instead */
    }

    .users-grid {
        grid-template-columns: 1fr;
        padding: 16px;
        gap: 16px;
    }

    .users-header {
        flex-direction: column;
        gap: 16px;
        text-align: center;
        padding: 20px 24px;
    }

    .search-header {
        flex-direction: column;
        gap: 12px;
        text-align: center;
    }

    .user-card-header {
        flex-direction: column;
        gap: 12px;
        align-items: center;
        text-align: center;
    }

    .user-actions {
        flex-direction: column;
        gap: 6px;
    }

    .btn-action-modern {
        width: 100%;
    }

    .user-stats {
        flex-direction: column;
        gap: 12px;
    }

    .stat-box {
        padding: 8px 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .stat-box:last-child {
        border-bottom: none;
    }
    
    /* Compact and clean user list items for mobile */
    .users-list {
        animation: fadeInUp 0.5s ease;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .user-list-item {
        display: flex !important;
        flex-direction: row;
        flex-wrap: nowrap;
        align-items: flex-start;
        padding: 12px;
        gap: 12px;
        border-radius: 12px;
        margin-bottom: 12px;
        animation: slideIn 0.3s ease;
        animation-fill-mode: both;
    }
    
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateX(-10px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    .user-list-item:nth-child(1) { animation-delay: 0.05s; }
    .user-list-item:nth-child(2) { animation-delay: 0.1s; }
    .user-list-item:nth-child(3) { animation-delay: 0.15s; }
    .user-list-item:nth-child(4) { animation-delay: 0.2s; }
    .user-list-item:nth-child(5) { animation-delay: 0.25s; }
    .user-list-item:nth-child(n+6) { animation-delay: 0.3s; }
    
    /* Show checkboxes on mobile - visible next to user */
    .user-checkbox-container {
        display: flex !important;
        align-items: flex-start;
        padding: 0;
        margin: 0;
        order: -2; /* Move to the far left, before avatar */
    }
    
    .user-checkbox {
        width: 20px;
        height: 20px;
        accent-color: var(--primary-color);
        cursor: pointer;
        margin: 2px 0 0 0;
        flex-shrink: 0;
    }
    
    /* Hide mobile checkbox in dropdown - use main checkbox instead */
    .user-detail-checkbox-mobile {
        display: none !important;
    }
    
    .user-list-avatar {
        width: 45px;
        height: 45px;
        font-size: 1.2rem;
        order: -1; /* After checkbox, before content */
        flex-shrink: 0;
    }
    
    .user-list-content {
        flex: 1;
        min-width: 0;
        gap: 0; /* Remove gap for cleaner collapsed state */
    }
    
    /* Show expand icon on mobile */
    .user-expand-icon {
        display: flex !important;
        -webkit-tap-highlight-color: transparent;
    }
    
    .user-expand-icon:active {
        background: #d1d5db;
        transform: scale(0.95);
    }
    
    .user-list-header {
        cursor: pointer;
        padding: 0;
        -webkit-tap-highlight-color: transparent;
    }
    
    .user-list-header:active .user-list-avatar {
        transform: scale(0.95);
    }
    
    .user-header-info {
        gap: 6px;
    }
    
    .user-info-primary {
        width: 100%;
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .user-info-primary .user-name {
        font-size: 1rem;
        font-weight: 600;
    }
    
    .user-badges {
        flex-wrap: wrap;
        gap: 4px;
    }
    
    .status-badge {
        font-size: 0.7rem;
        padding: 3px 8px;
    }
    
    .user-info-secondary {
        width: 100%;
    }
    
    .user-joined {
        font-size: 0.8rem;
    }
    
    /* Details collapsed by default on mobile */
    .user-list-details {
        flex-direction: column;
        gap: 8px;
        padding-top: 12px;
        margin-top: 0;
    }
    
    .user-list-details.collapsed {
        display: none; /* Completely hide when collapsed on mobile */
    }
    
    .user-list-details.expanded {
        display: flex;
        animation: expandDetails 0.3s ease;
    }
    
    @keyframes expandDetails {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .user-detail-item {
        font-size: 0.85rem;
        gap: 6px;
        padding: 6px 0;
    }
    
    .user-detail-item i {
        font-size: 0.85rem;
        width: 18px;
    }
    
    /* Hide desktop actions, show mobile actions */
    .user-list-actions.desktop-only {
        display: none !important;
    }
    
    .user-actions-mobile {
        display: flex !important;
        justify-content: center;
        gap: 12px;
    }
    
    .btn-icon {
        width: 38px;
        height: 38px;
        font-size: 0.9rem;
        position: relative;
    }
    
    .btn-icon:active {
        transform: scale(0.9);
    }
    
    /* Add subtle ripple effect on tap */
    .btn-icon::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.4);
        transform: translate(-50%, -50%);
        transition: width 0.3s ease, height 0.3s ease;
    }
    
    .btn-icon:active::after {
        width: 100%;
        height: 100%;
    }
}

@media (max-width: 576px) {
    .page-header-icon {
        width: 48px;
        height: 48px;
        font-size: 1.5rem;
        margin-right: 16px;
    }

    .page-header-text h1 {
        font-size: 1.5rem;
    }
    
    .page-header-text p {
        font-size: 0.9rem;
    }

    .page-header-stats {
        gap: 20px;
        flex-wrap: wrap;
    }

    .stat-number {
        font-size: 1.5rem;
    }

    .users-grid {
        gap: 12px;
        padding: 16px;
    }

    .user-card-body {
        padding: 16px;
    }

    .user-card-footer {
        padding: 12px 16px;
    }
    
    /* Even smaller stats cards for very small screens */
    .stats-grid {
        gap: 6px;
    }
    
    .stats-card-simple {
        min-height: 75px;
        padding: 8px 6px;
    }
    
    .stats-content-simple h3 {
        font-size: 1rem;
    }
    
    .stats-content-simple p {
        font-size: 0.6rem;
    }
    
    /* Compact mobile search */
    .mobile-search-input {
        padding: 8px 12px;
        font-size: 0.85rem;
    }
    
    .btn-mobile-search,
    .btn-mobile-export {
        width: 40px;
        height: 40px;
        font-size: 0.9rem;
    }
    
    /* Very compact user list items */
    .user-list-item {
        padding: 10px;
        gap: 10px;
    }
    
    .user-list-avatar {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
    }
    
    .user-info-primary .user-name {
        font-size: 0.95rem;
    }
    
    .user-detail-item {
        font-size: 0.8rem;
    }
    
    .btn-icon {
        width: 36px;
        height: 36px;
        font-size: 0.85rem;
    }
}

/* Scrollbar Styling */
.users-grid::-webkit-scrollbar,
.search-field::-webkit-scrollbar {
    width: 6px;
}

.users-grid::-webkit-scrollbar-track,
.search-field::-webkit-scrollbar-track {
    background: #f3f4f6;
    border-radius: 10px;
}

.users-grid::-webkit-scrollbar-thumb,
.search-field::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
}

.users-grid::-webkit-scrollbar-thumb:hover,
.search-field::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}

/* Loading States */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* User Avatar Styles */
.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1rem;
}

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

/* ===== Custom Confirmation Dialog ===== */
.custom-dialog-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(5px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease, visibility 0.3s ease;
}

.custom-dialog-overlay.active {
    opacity: 1;
    visibility: visible;
}

.custom-dialog {
    background: white;
    border-radius: 20px;
    padding: 40px;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    transform: scale(0.8) translateY(-20px);
    transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    position: relative;
    overflow: hidden;
}

.custom-dialog-overlay.active .custom-dialog {
    transform: scale(1) translateY(0);
}

.custom-dialog::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 5px;
    background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
}

.custom-dialog-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    font-size: 2.5rem;
    animation: iconBounce 0.6s ease;
}

@keyframes iconBounce {
    0%, 100% { transform: scale(1); }
    25% { transform: scale(0.9); }
    50% { transform: scale(1.1); }
    75% { transform: scale(0.95); }
}

.custom-dialog-icon.warning {
    background: linear-gradient(135deg, #fef3c7, #fde047);
    color: #d97706;
    box-shadow: 0 10px 30px rgba(251, 191, 36, 0.3);
}

.custom-dialog-icon.danger {
    background: linear-gradient(135deg, #fee2e2, #fca5a5);
    color: #dc2626;
    box-shadow: 0 10px 30px rgba(239, 68, 68, 0.3);
}

.custom-dialog-icon.info {
    background: linear-gradient(135deg, #dbeafe, #93c5fd);
    color: #2563eb;
    box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3);
}

.custom-dialog-icon i {
    animation: iconPulse 2s ease infinite;
}

@keyframes iconPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.custom-dialog-content {
    text-align: center;
    margin-bottom: 32px;
}

.custom-dialog-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 12px;
    animation: fadeInDown 0.5s ease;
}

@keyframes fadeInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.custom-dialog-message {
    font-size: 1rem;
    color: #6b7280;
    line-height: 1.6;
    margin: 0;
    animation: fadeIn 0.5s ease 0.1s both;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.custom-dialog-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    animation: fadeInUp 0.5s ease 0.2s both;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.btn-dialog-cancel,
.btn-dialog-confirm {
    padding: 12px 32px;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 140px;
    position: relative;
    overflow: hidden;
}

.btn-dialog-cancel::before,
.btn-dialog-confirm::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transform: translate(-50%, -50%);
    transition: width 0.4s ease, height 0.4s ease;
}

.btn-dialog-cancel:active::before,
.btn-dialog-confirm:active::before {
    width: 300px;
    height: 300px;
}

.btn-dialog-cancel {
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    color: #374151;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.btn-dialog-cancel:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    background: linear-gradient(135deg, #d1d5db, #9ca3af);
}

.btn-dialog-confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
}

.btn-dialog-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.5);
    background: linear-gradient(135deg, #dc2626, #b91c1c);
}

.btn-dialog-confirm.primary {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    box-shadow: 0 4px 15px rgba(0, 88, 163, 0.4);
}

.btn-dialog-confirm.primary:hover {
    box-shadow: 0 6px 20px rgba(0, 88, 163, 0.5);
}

/* Responsive Dialog */
@media (max-width: 576px) {
    .custom-dialog {
        padding: 30px 20px;
        border-radius: 16px;
    }
    
    .custom-dialog-icon {
        width: 70px;
        height: 70px;
        font-size: 2rem;
        margin-bottom: 20px;
    }
    
    .custom-dialog-title {
        font-size: 1.4rem;
    }
    
    .custom-dialog-message {
        font-size: 0.9rem;
    }
    
    .custom-dialog-actions {
        flex-direction: column;
    }
    
    .btn-dialog-cancel,
    .btn-dialog-confirm {
        width: 100%;
        min-width: unset;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>
