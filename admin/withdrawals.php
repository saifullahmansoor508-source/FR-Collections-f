<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $withdrawal_id = intval($_POST['withdrawal_id']);
    $new_status = sanitizeInput($_POST['status']);
    
    $valid_statuses = ['Pending', 'Completed', 'Rejected'];
    
    if (in_array($new_status, $valid_statuses)) {
        $stmt = $db->prepare("UPDATE withdrawals SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$new_status, $withdrawal_id])) {
            $success_message = "Withdrawal status updated successfully!";
        } else {
            $error_message = "Error updating withdrawal status.";
        }
    } else {
        $error_message = "Invalid status.";
    }
}

// Handle withdrawal deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_withdrawal'])) {
    $withdrawal_id = intval($_POST['withdrawal_id']);
    
    $stmt = $db->prepare("DELETE FROM withdrawals WHERE id = ?");
    if ($stmt->execute([$withdrawal_id])) {
        $success_message = "Withdrawal request deleted successfully!";
    } else {
        $error_message = "Error deleting withdrawal request.";
    }
}

// Handle bulk withdrawal deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_delete_withdrawals'])) {
    $withdrawal_ids = json_decode($_POST['withdrawal_ids'], true);
    
    if (is_array($withdrawal_ids) && count($withdrawal_ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($withdrawal_ids), '?'));
        $stmt = $db->prepare("DELETE FROM withdrawals WHERE id IN ($placeholders)");
        
        if ($stmt->execute($withdrawal_ids)) {
            $success_message = count($withdrawal_ids) . " withdrawal(s) deleted successfully!";
        } else {
            $error_message = "Error deleting withdrawal requests.";
        }
    } else {
        $error_message = "No withdrawals selected for deletion.";
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

$base_query = "
    SELECT w.*, u.full_name, u.email, a.partner_id
    FROM withdrawals w
    JOIN users u ON w.user_id = u.id
    LEFT JOIN affiliates a ON w.user_id = a.user_id
    WHERE 1=1
";

if ($status_filter) {
    $where_conditions[] = "w.status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR a.partner_id LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($where_conditions)) {
    $base_query .= " AND " . implode(" AND ", $where_conditions);
}

$base_query .= " ORDER BY w.created_at DESC";

$stmt = $db->prepare($base_query);
$stmt->execute($params);
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$stmt = $db->prepare("SELECT COUNT(*) FROM withdrawals");
$stmt->execute();
$total_withdrawals = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM withdrawals WHERE status = 'Pending'");
$stmt->execute();
$pending_withdrawals = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM withdrawals WHERE status = 'Completed'");
$stmt->execute();
$completed_withdrawals = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE status = 'Completed'");
$stmt->execute();
$total_paid = $stmt->fetchColumn();
$total_paid = $total_paid ?: 0;

$page_title = "Withdrawals Management";
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
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Withdrawals Management</h1>
                    <p class="page-subtitle">Manage and process withdrawal requests</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($total_withdrawals); ?></h3>
                        <p>Total Requests</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($pending_withdrawals); ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($completed_withdrawals); ?></h3>
                        <p>Completed</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo formatPrice($total_paid); ?></h3>
                        <p>Total Paid</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Search Bar (visible only on mobile) - Below stats cards -->
<div class="row mobile-search-bar-bottom">
    <div class="col-12">
        <div class="mobile-search-container">
            <form method="GET" class="d-flex gap-2 align-items-center">
                <input type="text" 
                       name="search"
                       id="mobileSearchInput"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Search withdrawals..."
                       class="mobile-search-input">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div class="export-dropdown-container">
                    <button type="button" class="btn-mobile-export" onclick="toggleExportDropdown(event)">
                        <i class="fas fa-download"></i>
                    </button>
                    <div class="export-dropdown-menu" id="exportDropdownMobile">
                        <a href="javascript:void(0);" onclick="exportWithdrawals('csv'); closeExportDropdown();">
                            <i class="fas fa-file-csv"></i>
                            <span>CSV</span>
                        </a>
                        <a href="javascript:void(0);" onclick="exportWithdrawals('pdf'); closeExportDropdown();">
                            <i class="fas fa-file-pdf"></i>
                            <span>PDF</span>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modern Search Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="search-card">
            <div class="search-header">
                <div class="search-title">
                    <i class="fas fa-search me-2"></i>Find Withdrawals
                </div>
                <div class="search-actions">
                    <div class="export-dropdown-container">
                        <button type="button" class="btn btn-export-desktop" onclick="toggleExportDropdown(event)">
                            <i class="fas fa-download me-2"></i>Export
                            <i class="fas fa-chevron-down ms-1" style="font-size: 0.8rem;"></i>
                        </button>
                        <div class="export-dropdown-menu" id="exportDropdown">
                            <a href="javascript:void(0);" onclick="exportWithdrawals('csv'); closeExportDropdown();">
                                <i class="fas fa-file-csv"></i>
                                <span>Export as CSV</span>
                            </a>
                            <a href="javascript:void(0);" onclick="exportWithdrawals('pdf'); closeExportDropdown();">
                                <i class="fas fa-file-pdf"></i>
                                <span>Export as PDF</span>
                            </a>
                        </div>
                    </div>
                    <a href="withdrawals.php" class="btn btn-clear-modern">
                        <i class="fas fa-redo me-2"></i>Clear Search
                    </a>
                </div>
            </div>

            <form method="GET" class="search-form">
                <div class="search-input-group">
                    <div class="search-input-modern">
                        <div class="search-input-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="search-field" placeholder="Search by name, email, or partner ID...">
                            <button type="submit" class="search-btn-modern">
                                <i class="fas fa-search"></i>
                                <span>Search</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="search-filters">
                    <!-- Status Filter -->
                    <div class="custom-dropdown-modern" id="statusDropdown">
                        <div class="dropdown-selected-modern" onclick="toggleModernDropdown('statusDropdown')">
                            <span id="selectedStatus">
                                <?php 
                                if ($status_filter === 'Pending') echo '⏳ Pending';
                                elseif ($status_filter === 'Completed') echo '✅ Completed';
                                elseif ($status_filter === 'Rejected') echo '❌ Rejected';
                                else echo 'All Status';
                                ?>
                            </span>
                            <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                        </div>
                        <div class="dropdown-options-modern">
                            <div class="dropdown-option-modern <?php echo !$status_filter ? 'selected' : ''; ?>" onclick="selectStatus('', 'All Status')">
                                <i class="fas fa-list me-2"></i>All Status
                            </div>
                            <div class="dropdown-option-modern <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>" onclick="selectStatus('Pending', '⏳ Pending')">
                                <i class="fas fa-clock me-2"></i>Pending
                            </div>
                            <div class="dropdown-option-modern <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>" onclick="selectStatus('Completed', '✅ Completed')">
                                <i class="fas fa-check-circle me-2"></i>Completed
                            </div>
                            <div class="dropdown-option-modern <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>" onclick="selectStatus('Rejected', '❌ Rejected')">
                                <i class="fas fa-times-circle me-2"></i>Rejected
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($status_filter); ?>">

                    <a href="withdrawals.php" class="btn btn-clear-modern">Clear</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Withdrawals List -->
<div class="row">
    <div class="col-12">
        <div class="orders-grid-card">
            <div class="grid-header">
                <div class="grid-title">
                    <i class="fas fa-wallet me-2"></i>
                    Withdrawal Requests <span class="order-count">(<?php echo count($withdrawals); ?>)</span>
                </div>
                <div class="grid-actions">
                    <button class="btn btn-icon-action btn-delete-selected" onclick="deleteSelectedWithdrawals()" title="Delete Selected" style="display: none;">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                    <button class="btn btn-icon-action btn-cancel-selected" onclick="cancelSelection()" title="Cancel Selection" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                    <button class="btn btn-refresh" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh
                    </button>
                </div>
            </div>

            <?php if (!empty($withdrawals)): ?>
                <div class="orders-list-container">
                    <div class="orders-list-header">
                        <div class="select-all-container">
                            <input type="checkbox" id="selectAll" class="order-checkbox-select-all" onclick="toggleSelectAll()">
                            <label for="selectAll" class="select-all-label">Select All</label>
                        </div>
                        <div class="mobile-bulk-actions">
                            <button class="btn-icon-mobile btn-delete-selected-mobile" onclick="deleteSelectedWithdrawals()" title="Delete Selected" style="display: none;">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                        <span class="selected-count" id="selectedCount" style="display: none;">
                            <i class="fas fa-check-circle me-1"></i><span id="selectedCountText">0</span> selected
                        </span>
                    </div>
                    
                    <div class="orders-list">
                        <?php foreach ($withdrawals as $withdrawal): ?>
                            <div class="withdrawal-card-wrapper" 
                                 data-withdrawal-id="<?php echo $withdrawal['id']; ?>"
                                 data-email="<?php echo htmlspecialchars($withdrawal['email']); ?>"
                                 data-partner-id="<?php echo htmlspecialchars($withdrawal['partner_id'] ?? ''); ?>"
                                 data-method="<?php echo htmlspecialchars($withdrawal['method']); ?>"
                                 data-account="<?php echo htmlspecialchars($withdrawal['account_number']); ?>">
                                <div class="withdrawal-card-main">
                                    <div class="order-checkbox-container">
                                        <input type="checkbox" class="order-checkbox" value="<?php echo $withdrawal['id']; ?>" onclick="updateSelectedCount()">
                                    </div>
                                    
                                    <!-- Avatar with initial (desktop only) -->
                                    <div class="withdrawal-avatar desktop-only">
                                        <?php echo strtoupper(substr($withdrawal['full_name'], 0, 1)); ?>
                                    </div>
                                    
                                    <!-- Card content wrapper -->
                                    <div class="withdrawal-card-content">
                                        <div class="withdrawal-card-header" onclick="toggleWithdrawalDetails(<?php echo $withdrawal['id']; ?>)">
                                            <!-- Avatar circle (mobile only) -->
                                            <div class="withdrawal-circle mobile-only">
                                                <?php echo strtoupper(substr($withdrawal['full_name'], 0, 1)); ?>
                                            </div>
                                            
                                            <!-- Main info section -->
                                            <div class="withdrawal-info-section">
                                                <div class="withdrawal-user-info">
                                                    <i class="fas fa-user withdrawal-mini-icon"></i>
                                                    <span class="withdrawal-user-text"><?php echo htmlspecialchars($withdrawal['full_name']); ?></span>
                                                </div>
                                                <div class="withdrawal-meta-row">
                                                    <span class="withdrawal-amount-display"><?php echo formatPrice($withdrawal['amount']); ?></span>
                                                    <span class="withdrawal-date-display"><?php echo date('d M Y', strtotime($withdrawal['created_at'])); ?></span>
                                                </div>
                                            </div>
                                            
                                            <!-- Right section with status and actions -->
                                            <div class="withdrawal-right-section">
                                                <span class="withdrawal-status-badge status-<?php echo strtolower($withdrawal['status']); ?>">
                                                    <i class="fas fa-circle"></i> <?php echo htmlspecialchars($withdrawal['status']); ?>
                                                </span>
                                                <div class="withdrawal-actions-inline">
                                                    <?php if ($withdrawal['status'] === 'Pending'): ?>
                                                    <button class="btn-inline-action btn-approve-inline" onclick="event.stopPropagation(); approveWithdrawal(<?php echo $withdrawal['id']; ?>)" title="Approve">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="btn-inline-action btn-reject-inline" onclick="event.stopPropagation(); rejectWithdrawal(<?php echo $withdrawal['id']; ?>)" title="Reject">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <button class="withdrawal-expand-btn" title="View Details">
                                                        <i class="fas fa-chevron-down"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Expandable details section -->
                                        <div class="withdrawal-details-container" id="withdrawal-details-<?php echo $withdrawal['id']; ?>">
                                            <div class="withdrawal-details-content">
                                                <div class="detail-row">
                                                    <div class="detail-item-full">
                                                        <i class="fas fa-envelope"></i>
                                                        <span class="detail-label">Email:</span>
                                                        <span class="detail-value"><?php echo htmlspecialchars($withdrawal['email']); ?></span>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($withdrawal['partner_id']): ?>
                                                <div class="detail-row">
                                                    <div class="detail-item-full">
                                                        <i class="fas fa-handshake"></i>
                                                        <span class="detail-label">Partner ID:</span>
                                                        <span class="partner-badge"><?php echo htmlspecialchars($withdrawal['partner_id']); ?></span>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="detail-row">
                                                    <div class="detail-item-full">
                                                        <i class="fas fa-credit-card"></i>
                                                        <span class="detail-label">Payment Method:</span>
                                                        <span class="method-badge"><?php echo htmlspecialchars($withdrawal['method']); ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="detail-row">
                                                    <div class="detail-item-full">
                                                        <i class="fas fa-wallet"></i>
                                                        <span class="detail-label">Account:</span>
                                                        <code class="account-code"><?php echo htmlspecialchars($withdrawal['account_number']); ?></code>
                                                    </div>
                                                </div>
                                                
                                                <div class="detail-actions">
                                                    <button class="btn-detail-action btn-delete-withdrawal" onclick="event.stopPropagation(); deleteWithdrawal(<?php echo $withdrawal['id']; ?>)" title="Delete Withdrawal">
                                                        <i class="fas fa-trash-alt"></i>
                                                        <span>Delete Withdrawal</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid-footer">
                    <div class="grid-info">
                        Showing <?php echo count($withdrawals); ?> withdrawals
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state-modern">
                    <div class="empty-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <h4>No withdrawal requests found</h4>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Withdrawal Details Modal -->
<div class="modal fade" id="withdrawalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 25px 30px;">
                <h5 class="modal-title" style="font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-money-bill-wave"></i><span>Withdrawal Details</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 30px;">
                <div class="review-modal-content">
                    <div class="modal-review-header">
                        <div class="modal-user-info">
                            <div class="modal-avatar" id="modalAvatar"></div>
                            <div>
                                <h6 class="modal-user-name" id="modalUserName"></h6>
                                <p class="modal-product-name" id="modalUserEmail"></p>
                                <p class="modal-product-name" id="modalPartnerId"></p>
                            </div>
                        </div>
                        <div class="modal-rating" id="modalStatus"></div>
                    </div>
                    <div class="withdrawal-modal-details" id="modalDetails"></div>
                </div>
            </div>
            <div class="modal-footer" style="background: #f8f9fa; border-top: 2px solid #e5e7eb; padding: 20px 30px;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 12px 25px; font-weight: 600;">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Modern Dropdown Functions
function toggleModernDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    const isActive = dropdown.classList.contains('active');

    // Close other dropdowns
    document.querySelectorAll('.custom-dropdown-modern').forEach(dd => {
        if (dd.id !== dropdownId) {
            dd.classList.remove('active');
        }
    });

    if (isActive) {
        dropdown.classList.remove('active');
    } else {
        dropdown.classList.add('active');
    }
}

// Select status
function selectStatus(value, text) {
    document.getElementById('selectedStatus').textContent = text;
    document.getElementById('statusInput').value = value;
    document.getElementById('statusDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#statusDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    if (event && event.target) {
        const option = event.target.closest('.dropdown-option-modern');
        if (option) option.classList.add('selected');
    }

    // Submit form
    document.querySelector('.search-form').submit();
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

// Withdrawal functions - wait for custom notifications to load
function ensureNotificationsLoaded(callback) {
    if (typeof showConfirm === 'function') {
        callback();
    } else {
        setTimeout(() => ensureNotificationsLoaded(callback), 100);
    }
}

async function changeWithdrawalStatus(selectElement) {
    const form = selectElement.closest('form');
    const newStatus = selectElement.value;
    const statusText = selectElement.options[selectElement.selectedIndex].text;
    
    ensureNotificationsLoaded(async () => {
        const confirmed = await showConfirm(`Are you sure you want to change the status to ${statusText}?`, 'Change Status', {confirmText: 'Yes, Change', cancelText: 'Cancel', type: 'warning'});
        
        if (confirmed) {
            form.submit();
        } else {
            // Reset to original value - get the original status from the select element
            const originalValue = selectElement.getAttribute('data-original-value') || '';
            selectElement.value = originalValue;
        }
    });
}

async function viewWithdrawalDetails(withdrawalId) {
    // In a real implementation, you would fetch this data via AJAX
    // For now, we'll show a simple alert
    ensureNotificationsLoaded(() => {
        showAlert('View withdrawal details for ID: ' + withdrawalId, 'info', 'Withdrawal Details');
    });
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('withdrawalModal'));
    modal.show();
}

async function approveWithdrawal(withdrawalId) {
    ensureNotificationsLoaded(async () => {
        const confirmed = await showConfirm('Are you sure you want to approve this withdrawal?', 'Approve Withdrawal', {confirmText: 'Yes, Approve', cancelText: 'Cancel', type: 'info'});
        
        if (confirmed) {
            updateWithdrawalStatus(withdrawalId, 'Completed');
        }
    });
}

async function rejectWithdrawal(withdrawalId) {
    ensureNotificationsLoaded(async () => {
        const confirmed = await showConfirm('Are you sure you want to reject this withdrawal?', 'Reject Withdrawal', {confirmText: 'Yes, Reject', cancelText: 'Cancel', type: 'danger'});
        
        if (confirmed) {
            updateWithdrawalStatus(withdrawalId, 'Rejected');
        }
    });
}

function updateWithdrawalStatus(withdrawalId, status) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="withdrawal_id" value="${withdrawalId}">
        <input type="hidden" name="status" value="${status}">
        <input type="hidden" name="update_status" value="1">
    `;
    document.body.appendChild(form);
    form.submit();
}

async function deleteWithdrawal(withdrawalId) {
    ensureNotificationsLoaded(async () => {
        const confirmed = await showConfirm('This withdrawal request will be permanently deleted. This action cannot be undone.', 'Delete Withdrawal', {confirmText: 'Yes, Delete', cancelText: 'Cancel', type: 'danger'});
        
        if (confirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="withdrawal_id" value="${withdrawalId}">
                <input type="hidden" name="delete_withdrawal" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Toggle withdrawal details (mobile expand/collapse)
function toggleWithdrawalDetails(withdrawalId) {
    const wrapper = document.querySelector(`[data-withdrawal-id="${withdrawalId}"]`);
    
    if (wrapper) {
        wrapper.classList.toggle('expanded');
    }
}

// Export dropdown toggle
function toggleExportDropdown(event) {
    event.stopPropagation();
    const button = event.currentTarget;
    const dropdown = button.nextElementSibling;
    const allDropdowns = document.querySelectorAll('.export-dropdown-menu');
    
    // Close all other dropdowns
    allDropdowns.forEach(dd => {
        if (dd !== dropdown) {
            dd.classList.remove('show');
        }
    });
    
    // Toggle current dropdown
    dropdown.classList.toggle('show');
    console.log('Export dropdown toggled');
}

function closeExportDropdown() {
    document.querySelectorAll('.export-dropdown-menu').forEach(dd => {
        dd.classList.remove('show');
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.export-dropdown-container')) {
        closeExportDropdown();
    }
});

// Checkbox selection functions
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.order-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    const count = checkboxes.length;
    const selectedCountEl = document.getElementById('selectedCount');
    const selectedCountText = document.getElementById('selectedCountText');
    const deleteBtn = document.querySelector('.btn-delete-selected');
    const deleteBtnMobile = document.querySelector('.btn-delete-selected-mobile');
    const cancelBtn = document.querySelector('.btn-cancel-selected');
    
    if (selectedCountText) selectedCountText.textContent = count;
    
    if (count > 0) {
        if (selectedCountEl) selectedCountEl.style.display = 'inline-flex';
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
        if (deleteBtnMobile) deleteBtnMobile.style.display = 'inline-flex';
        if (cancelBtn) cancelBtn.style.display = 'inline-flex';
    } else {
        if (selectedCountEl) selectedCountEl.style.display = 'none';
        if (deleteBtn) deleteBtn.style.display = 'none';
        if (deleteBtnMobile) deleteBtnMobile.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';
    }
}

function cancelSelection() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.order-checkbox');
    checkboxes.forEach(checkbox => checkbox.checked = false);
    if (selectAllCheckbox) selectAllCheckbox.checked = false;
    updateSelectedCount();
}

async function deleteSelectedWithdrawals() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    if (checkboxes.length === 0) return;
    
    ensureNotificationsLoaded(async () => {
        const confirmed = await showConfirm(`Delete ${checkboxes.length} selected withdrawal(s)? This action cannot be undone.`, 'Confirm Delete', {confirmText: 'Yes, Delete', cancelText: 'Cancel', type: 'danger'});
        
        if (confirmed) {
            const ids = Array.from(checkboxes).map(cb => cb.value);
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="withdrawal_ids" value='${JSON.stringify(ids)}'>
                <input type="hidden" name="bulk_delete_withdrawals" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Export functions - REAL EXPORT FUNCTIONALITY
function exportWithdrawals(format) {
    console.log('Export button clicked for format:', format);
    
    // Get all withdrawal rows
    const withdrawals = [];
    document.querySelectorAll('[data-withdrawal-id]').forEach(card => {
        const id = card.getAttribute('data-withdrawal-id');
        const userName = card.querySelector('.withdrawal-user-text')?.textContent || '';
        const amount = card.querySelector('.withdrawal-amount-display')?.textContent || '';
        const date = card.querySelector('.withdrawal-date-display')?.textContent || '';
        const status = card.querySelector('.withdrawal-status-badge')?.textContent.trim() || '';
        const email = card.getAttribute('data-email') || '';
        const method = card.getAttribute('data-method') || '';
        const account = card.getAttribute('data-account') || '';
        const partnerId = card.getAttribute('data-partner-id') || '';
        
        withdrawals.push({
            id: id,
            name: userName.trim(),
            email: email,
            amount: amount.trim(),
            date: date.trim(),
            status: status,
            method: method,
            account: account,
            partnerId: partnerId
        });
    });
    
    if (format === 'csv') {
        exportToCSV(withdrawals);
    } else if (format === 'pdf') {
        exportToPDF(withdrawals);
    }
}

// Export to CSV
function exportToCSV(data) {
    if (data.length === 0) {
        ensureNotificationsLoaded(() => {
            showAlert('No data to export!', 'warning', 'Export');
        });
        return;
    }
    
    // CSV Headers
    let csv = 'ID,Name,Email,Amount,Date,Status,Payment Method,Account Number,Partner ID\n';
    
    // CSV Data
    data.forEach(row => {
        csv += `${row.id},"${row.name}","${row.email}","${row.amount}","${row.date}","${row.status}","${row.method}","${row.account}","${row.partnerId}"\n`;
    });
    
    // Create download link
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    const timestamp = new Date().toISOString().slice(0, 10);
    
    link.setAttribute('href', url);
    link.setAttribute('download', `withdrawals_${timestamp}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    ensureNotificationsLoaded(() => {
        showToast('CSV file downloaded successfully!', 'success');
    });
}

// Export to PDF
function exportToPDF(data) {
    if (data.length === 0) {
        ensureNotificationsLoaded(() => {
            showAlert('No data to export!', 'warning', 'Export');
        });
        return;
    }
    
    // Create a printable HTML table
    let html = `
    <!DOCTYPE html>
    <html>
    <head>
        <title>Withdrawals Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            h1 { color: #0058A3; text-align: center; margin-bottom: 30px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: linear-gradient(135deg, #0058A3, #FF6B00); color: white; padding: 12px; text-align: left; }
            td { padding: 10px; border-bottom: 1px solid #ddd; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .header { text-align: center; margin-bottom: 20px; }
            .date { color: #666; font-size: 14px; }
            .status-pending { color: #f59e0b; font-weight: bold; }
            .status-completed { color: #10b981; font-weight: bold; }
            .status-rejected { color: #ef4444; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Withdrawals Report</h1>
            <p class="date">Generated on: ${new Date().toLocaleDateString()}</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Method</th>
                    <th>Account</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    data.forEach(row => {
        const statusClass = 'status-' + row.status.toLowerCase();
        html += `
            <tr>
                <td>${row.id}</td>
                <td>${row.name}</td>
                <td>${row.email}</td>
                <td>${row.amount}</td>
                <td>${row.date}</td>
                <td class="${statusClass}">${row.status}</td>
                <td>${row.method}</td>
                <td>${row.account}</td>
            </tr>
        `;
    });
    
    html += `
            </tbody>
        </table>
    </body>
    </html>
    `;
    
    // Open print dialog
    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    
    setTimeout(() => {
        printWindow.print();
        ensureNotificationsLoaded(() => {
            showToast('PDF export opened! Use browser print dialog to save as PDF.', 'success', 4000);
        });
    }, 250);
}

// Expose functions to window object for inline onclick handlers
window.viewWithdrawalDetails = viewWithdrawalDetails;
window.approveWithdrawal = approveWithdrawal;
window.rejectWithdrawal = rejectWithdrawal;
window.deleteWithdrawal = deleteWithdrawal;
window.changeWithdrawalStatus = changeWithdrawalStatus;
window.updateWithdrawalStatus = updateWithdrawalStatus;
window.exportWithdrawals = exportWithdrawals;
window.toggleWithdrawalDetails = toggleWithdrawalDetails;
window.toggleSelectAll = toggleSelectAll;
window.updateSelectedCount = updateSelectedCount;
window.cancelSelection = cancelSelection;
window.deleteSelectedWithdrawals = deleteSelectedWithdrawals;
window.toggleExportDropdown = toggleExportDropdown;
window.closeExportDropdown = closeExportDropdown;

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
    
    // Log to confirm functions are loaded
    console.log('✓ Withdrawal functions loaded successfully');
});
</script>

<style>
/* ===== COPY ALL STYLES FROM REVIEWS PAGE ===== */

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

/* Search card */
.search-card { background:#fff; border-radius:16px; padding:32px; box-shadow:0 4px 20px rgba(0,0,0,.08); margin-bottom:24px; }
.search-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid #e9ecef; }
.search-title { font-size:1.25rem; font-weight:600; color:#2d3748; margin:0; }
.search-actions { display: flex; align-items: center; gap: 10px; }
.btn-clear-modern { background:#fff; border:2px solid #e9ecef; color:#6b7280; padding:8px 16px; border-radius:8px; text-decoration:none; font-size:.9rem; font-weight:500; transition:all .3s ease; }
.btn-clear-modern:hover { background:#f8f9fa; border-color:#d1d5db; color:#374151; text-decoration:none; }

.btn-export-desktop { 
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); 
    border: none; 
    color: white; 
    padding: 8px 16px; 
    border-radius: 8px; 
    font-size: .9rem; 
    font-weight: 600; 
    transition: all .3s ease; 
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0, 88, 163, 0.3);
}
.btn-export-desktop:hover { 
    background: linear-gradient(135deg, #0047a0, #ff5500); 
    transform: translateY(-2px); 
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.4);
    color: white;
}

/* Export dropdown container */
.export-dropdown-container {
    position: relative;
    display: inline-block;
}

.export-dropdown-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    min-width: 180px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1000;
    overflow: hidden;
}

.export-dropdown-menu.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.export-dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #374151;
    text-decoration: none;
    transition: all 0.2s ease;
    border-bottom: 1px solid #f3f4f6;
}

.export-dropdown-menu a:last-child {
    border-bottom: none;
}

.export-dropdown-menu a:hover {
    background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
    color: var(--primary-color);
}

.export-dropdown-menu a i {
    font-size: 1rem;
    width: 20px;
    text-align: center;
}

.export-dropdown-menu a span {
    font-size: 0.95rem;
    font-weight: 500;
}
.search-input-group { display:flex; gap:16px; align-items:flex-end; }
.search-input-modern { flex:1; }
.search-input-wrapper { position:relative; display:flex; align-items:center; }
.search-field { width:100%; border:2px solid #e5e7eb; border-radius:12px; padding:14px 60px 14px 50px; font-size:.95rem; transition:all .3s ease; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.05); }
.search-field:focus { outline:none; border-color:var(--primary-color); box-shadow:0 0 0 3px rgba(0,88,163,.1); }
.search-icon { position:absolute; left:16px; color:#9ca3af; font-size:1rem; z-index:1; }
.search-btn-modern { position:absolute; right:8px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); border:none; border-radius:8px; width:36px; height:36px; display:flex; align-items:center; justify-content:center; color:#fff; cursor:pointer; transition:all .3s ease; box-shadow:0 2px 8px rgba(0,88,163,.2); }
.search-btn-modern span { display:none; }
.search-btn-modern:hover { transform:scale(1.05); box-shadow:0 4px 12px rgba(0,88,163,.3); }
.search-filters { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px; }

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

/* Withdrawal Specific Styles */
.withdrawal-details {
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

.amount-value {
    font-weight: 700;
    color: #10b981;
    margin-left: 5px;
}

.method-badge {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-left: 5px;
}

.account-number {
    background: #1f2937;
    color: #fbbf24;
    padding: 4px 8px;
    border-radius: 6px;
    font-family: monospace;
    margin-left: 5px;
}

.status-select {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.status-select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0,88,163,.1);
}

.withdrawal-modal-details {
    background: white;
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid var(--primary-color);
    line-height: 1.7;
    color: #374151;
    font-size: 1rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.review-comment {
    background: #f9fafb;
    padding: 15px;
    border-radius: 12px;
    border-left: 3px solid var(--primary-color);
}

.comment-text {
    color: #374151;
    line-height: 1.6;
    margin: 0;
    font-size: 0.95rem;
}

.btn-read-more {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    margin-top: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.btn-read-more:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 88, 163, 0.4);
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
    align-items: center;
}

.review-actions .status-form {
    flex: 1;
    min-width: 150px;
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

/* Modal Styles */
.review-modal-content {
    background: #f9fafb;
    border-radius: 12px;
    padding: 20px;
}

.modal-review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e5e7eb;
}

.modal-user-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.modal-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.5rem;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.modal-user-name {
    font-weight: 700;
    color: #1f2937;
    font-size: 1.2rem;
    margin-bottom: 5px;
}

.modal-product-name {
    color: #6b7280;
    font-size: 0.95rem;
    margin-bottom: 2px;
}

.modal-rating {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    padding: 10px 20px;
    border-radius: 50px;
    font-weight: 700;
    color: #92400e;
    font-size: 1.1rem;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.modal-comment-text {
    background: white;
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid var(--primary-color);
    line-height: 1.7;
    color: #374151;
    font-size: 1rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

/* ===== ORDERS GRID CARD (List Layout) ===== */
.orders-grid-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.grid-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
}

.grid-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
    display: flex;
    align-items: center;
}

.order-count {
    font-size: 0.9rem;
    color: #718096;
    margin-left: 8px;
}

.grid-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.btn-refresh {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-refresh:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
}

.grid-footer {
    background: #f8f9fa;
    padding: 15px 30px;
    border-top: 1px solid #dee2e6;
    text-align: center;
}

.grid-info {
    color: #718096;
    font-size: 0.9rem;
}

.empty-state-modern {
    text-align: center;
    padding: 80px 40px;
}

.empty-icon {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 24px;
}

.empty-state-modern h4 {
    color: #6b7280;
    font-weight: 700;
    margin-bottom: 10px;
}

/* Orders List Container */
.orders-list-container {
    padding: 25px;
}

.orders-list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 0 20px 0;
    margin-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
}

.select-all-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

.select-all-label {
    font-weight: 600;
    color: #374151;
    margin: 0;
    cursor: pointer;
}

.order-checkbox-select-all {
    width: 18px;
    height: 18px;
    accent-color: var(--primary-color);
    cursor: pointer;
}

.selected-count {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Order List Items */
.orders-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.order-list-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    transition: all 0.3s ease;
}

.order-list-item:hover {
    border-color: var(--primary-color);
    box-shadow: 0 8px 25px rgba(0, 88, 163, 0.12);
    transform: translateY(-2px);
}

.order-checkbox-container {
    display: flex;
    align-items: center;
}

.order-checkbox {
    width: 18px;
    height: 18px;
    accent-color: var(--primary-color);
    cursor: pointer;
}

.order-list-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.order-number-badge {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 8px 16px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-indicator {
    padding: 6px 14px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-indicator i {
    font-size: 0.7rem;
}

.status-pending {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

.status-completed {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.status-rejected {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.status-confirmed {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.status-on-the-way {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.status-delivered {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
}

.status-canceled {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.order-date {
    color: #6b7280;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 4px;
}

.order-list-details {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding-top: 16px;
    margin-top: 12px;
    border-top: 2px solid #f1f5f9;
}

.partner-badge {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
}

.discount-tag {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-left: 8px;
}

.method-badge {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
}

/* Action Buttons */
.order-list-actions {
    display: flex;
    gap: 8px;
    align-items: flex-start;
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

.btn-edit-order {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.btn-edit-order:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
}

.btn-view-order {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-view-order:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

.btn-delete-order {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-delete-order:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

.btn-icon-action {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    margin-right: 8px;
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

.btn-cancel-selected {
    background: linear-gradient(135deg, #6b7280, #4b5563);
    color: white;
    box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
}

.btn-cancel-selected:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(107, 114, 128, 0.4);
}

.order-list-avatar {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: linear-gradient(135deg, #10b981, #059669);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.3rem;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    display: none;
}

/* ===== NEW CLEAN WITHDRAWAL DESIGN (Matches Orders) ===== */

/* Withdrawal card wrapper - main container */
.withdrawal-card-wrapper {
    margin-bottom: 16px;
}

.withdrawal-card-main {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    transition: all 0.3s ease;
}

.withdrawal-card-main:hover {
    border-color: var(--primary-color);
    box-shadow: 0 8px 25px rgba(0, 88, 163, 0.12);
    transform: translateY(-2px);
}

/* Desktop avatar (hidden on mobile) */
.withdrawal-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #10b981, #059669);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* Withdrawal card content - contains header and details */
.withdrawal-card-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0;
    min-width: 0;
}

/* Mobile circular badge (hidden on desktop) */
.withdrawal-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #10b981, #059669);
    display: none;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.1rem;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
}

/* Withdrawal card header */
.withdrawal-card-header {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
    cursor: pointer;
    padding: 0;
}

/* Info section */
.withdrawal-info-section {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.withdrawal-user-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.withdrawal-mini-icon {
    color: #6b7280;
    font-size: 0.85rem;
}

.withdrawal-user-text {
    font-weight: 600;
    color: #1f2937;
    font-size: 0.95rem;
}

.withdrawal-meta-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.withdrawal-amount-display {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    padding: 4px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
}

.withdrawal-date-display {
    color: #64748b;
    font-size: 0.85rem;
}

/* Right section */
.withdrawal-right-section {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.withdrawal-status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}

.withdrawal-status-badge i {
    font-size: 0.5rem;
}

/* Action buttons inline */
.withdrawal-actions-inline {
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-inline-action {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    transition: all 0.3s ease;
}

.btn-inline-action.btn-approve-inline {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-inline-action.btn-approve-inline:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
}

.btn-inline-action.btn-reject-inline {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.btn-inline-action.btn-reject-inline:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

.withdrawal-expand-btn {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(16, 185, 129, 0.1);
    border: none;
    color: #059669;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.7rem;
    transition: all 0.3s ease;
}

.withdrawal-expand-btn:hover {
    background: rgba(16, 185, 129, 0.2);
}

.withdrawal-expand-btn i {
    transition: transform 0.3s ease;
}

.withdrawal-card-wrapper.expanded .withdrawal-expand-btn i {
    transform: rotate(180deg);
}

/* Withdrawal details container */
.withdrawal-details-container {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
    opacity: 0;
}

.withdrawal-card-wrapper.expanded .withdrawal-details-container {
    max-height: 600px;
    opacity: 1;
    margin-top: 16px;
}

.withdrawal-details-content {
    padding: 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 12px;
    border: 2px solid #e5e7eb;
    animation: slideDown 0.4s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.detail-row {
    margin-bottom: 14px;
}

.detail-row:last-child {
    margin-bottom: 0;
}

.detail-item-full {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    background: white;
    border-radius: 8px;
    border-left: 3px solid var(--primary-color);
}

.detail-item-full i {
    color: var(--primary-color);
    font-size: 1rem;
    width: 20px;
    text-align: center;
}

.detail-label {
    font-weight: 600;
    color: #64748b;
    margin-right: 8px;
}

.detail-value {
    color: #1e293b;
    font-weight: 500;
}

.account-code {
    background: #1f2937;
    color: #fbbf24;
    padding: 6px 12px;
    border-radius: 8px;
    font-family: monospace;
    font-size: 0.9rem;
    font-weight: 600;
}

/* Detail actions */
.detail-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #e5e7eb;
    display: flex;
    justify-content: center;
}

.btn-detail-action {
    padding: 12px 24px;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-delete-withdrawal {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-delete-withdrawal:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

/* Hide/Show elements based on screen size */
.mobile-only {
    display: none !important;
}

.desktop-only {
    display: flex !important;
}

/* Desktop Layout Improvements */
.withdrawal-card-wrapper {
    cursor: default;
}

.order-expand-icon {
    display: none;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #f3f4f6;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background 0.3s ease;
}

.order-expand-icon i {
    color: #6b7280;
    font-size: 0.9rem;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.order-quick-actions {
    display: none;
    gap: 6px;
}

.btn-icon-mini {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.btn-icon-mini.btn-edit-order {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-icon-mini.btn-view-order {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.order-actions-mobile {
    display: none;
    width: 100%;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
    margin-top: 8px;
    justify-content: center;
}

.mobile-btn-text {
    display: none;
}

.desktop-only {
    display: flex;
}

.order-detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #374151;
    font-size: 0.9rem;
}

.order-detail-item i {
    color: var(--primary-color);
}

.order-detail-item .stat-value {
    font-weight: 600;
    color: var(--primary-color);
}

/* Mobile Search Bar Styles */
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

.mobile-search-container {
    background: white;
    padding: 12px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    margin-bottom: 16px;
}

.mobile-search-input {
    flex: 1;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px 15px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.mobile-search-input:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
}

.btn-mobile-export {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border: none;
    color: white;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 88, 163, 0.3);
}

.btn-mobile-export:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.4);
}

/* Responsive Design for Mobile */
@media (max-width: 768px) {
    .page-header-card,
    .orders-grid-card {
        margin: 0 -15px;
        border-radius: 0;
    }
    
    /* Hide desktop search */
    .search-card {
        display: none !important;
    }
    
    /* Show mobile search bar */
    .mobile-search-bar-bottom {
        display: block !important;
    }
    
    /* Hide desktop export button on mobile */
    .btn-export-desktop {
        display: none !important;
    }
    
    .mobile-search-container {
        margin: 0 -15px;
        border-radius: 0;
        padding: 10px 15px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    /* Prevent text selection on interactive elements */
    .btn-mobile-export,
    .btn-icon,
    .order-list-item,
    .stats-card-simple {
        -webkit-tap-highlight-color: transparent;
        -webkit-touch-callout: none;
        user-select: none;
    }
    
    /* Hide grid header and footer on mobile */
    .grid-header {
        display: none !important;
    }
    
    .grid-footer {
        display: none !important;
    }
    
    /* Compact stats cards - 2x2 grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        width: 100%;
    }
    
    .stats-card-simple {
        min-height: 110px;
        padding: 14px 12px;
    }
    
    .stats-content-simple h3 {
        font-size: 1.8rem;
        margin-bottom: 4px;
    }
    
    .stats-content-simple p {
        font-size: 0.7rem;
        margin-bottom: 0;
        line-height: 1.2;
    }
    
    /* Clean list container on mobile */
    .orders-list-container {
        padding: 12px 15px;
        background: #f8f9fa;
    }
    
    /* Show select all header */
    .orders-list-header {
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
    
    .order-checkbox-select-all {
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
        display: none !important;
    }
    
    /* Compact list items */
    .orders-list {
        animation: fadeInUp 0.5s ease;
    }
    
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .order-list-item {
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
        from { opacity: 0; transform: translateX(-10px); }
        to { opacity: 1; transform: translateX(0); }
    }
    
    .order-list-item:nth-child(1) { animation-delay: 0.05s; }
    .order-list-item:nth-child(2) { animation-delay: 0.1s; }
    .order-list-item:nth-child(3) { animation-delay: 0.15s; }
    .order-list-item:nth-child(4) { animation-delay: 0.2s; }
    .order-list-item:nth-child(5) { animation-delay: 0.25s; }
    .order-list-item:nth-child(n+6) { animation-delay: 0.3s; }
    
    /* Show checkboxes */
    .order-checkbox-container {
        display: flex !important;
        align-items: flex-start;
        padding: 0;
        margin: 0;
        order: -2;
    }
    
    .order-checkbox {
        width: 20px;
        height: 20px;
        accent-color: var(--primary-color);
        cursor: pointer;
        margin: 2px 0 0 0;
        flex-shrink: 0;
    }
    
    .order-list-avatar {
        display: flex !important;
        width: 45px;
        height: 45px;
        font-size: 1.2rem;
        order: -1;
        flex-shrink: 0;
    }
    
    /* Show mobile circular badge, hide desktop avatar */
    .withdrawal-circle {
        display: flex !important;
        width: 50px;
        height: 50px;
        font-size: 1.1rem;
    }
    
    .withdrawal-avatar.desktop-only {
        display: none !important;
    }
    
    /* Mobile card adjustments */
    .withdrawal-card-main {
        padding: 15px 12px;
        gap: 12px;
    }
    
    /* Mobile layout adjustments */
    .withdrawal-card-header {
        flex: 1;
        gap: 12px;
    }
    
    .withdrawal-info-section {
        flex: 1;
        min-width: 0;
    }
    
    .withdrawal-user-info {
        gap: 6px;
    }
    
    .withdrawal-mini-icon {
        font-size: 0.75rem;
    }
    
    .withdrawal-user-text {
        font-size: 0.9rem;
    }
    
    .withdrawal-meta-row {
        gap: 8px;
    }
    
    .withdrawal-amount-display {
        font-size: 0.95rem;
        padding: 3px 10px;
    }
    
    .withdrawal-date-display {
        font-size: 0.75rem;
    }
    
    /* Mobile right section */
    .withdrawal-right-section {
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }
    
    .withdrawal-status-badge {
        font-size: 0.75rem;
        padding: 4px 10px;
    }
    
    .withdrawal-actions-inline {
        gap: 4px;
    }
    
    .btn-inline-action {
        width: 30px;
        height: 30px;
        font-size: 0.8rem;
    }
    
    .withdrawal-expand-btn {
        width: 28px;
        height: 28px;
        font-size: 0.65rem;
    }
    
    /* Mobile details */
    .withdrawal-details-content {
        padding: 15px;
    }
    
    .detail-item-full {
        flex-wrap: wrap;
        padding: 10px;
    }
    
    .detail-label,
    .detail-value {
        font-size: 0.85rem;
    }
    
    .account-code {
        font-size: 0.8rem;
        padding: 5px 10px;
    }
    
    .btn-detail-action {
        width: 100%;
        justify-content: center;
        padding: 14px 20px;
    }
    
    .order-list-content {
        flex: 1;
        min-width: 0;
        gap: 0;
    }
    
    /* Show expand icon */
    .order-expand-icon {
        display: flex !important;
        -webkit-tap-highlight-color: transparent;
    }
    
    .order-expand-icon:active {
        background: #d1d5db;
        transform: scale(0.95);
    }
    
    .order-list-header {
        cursor: pointer;
        padding: 0;
        -webkit-tap-highlight-color: transparent;
    }
    
    .order-list-header:active .order-list-avatar {
        transform: scale(0.95);
    }
    
    .order-header-info {
        gap: 6px;
    }
    
    .order-info-primary {
        width: 100%;
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    /* Mobile withdrawal styles */
    .withdrawal-user-name {
        margin-bottom: 4px;
    }
    
    .withdrawal-icon {
        font-size: 0.8rem;
    }
    
    .name-text {
        font-size: 0.9rem;
    }
    
    .withdrawal-amount-badge {
        font-size: 0.95rem;
        padding: 3px 10px;
    }
    
    .withdrawal-date {
        font-size: 0.75rem;
    }
    
    .order-number-badge {
        font-size: 0.85rem;
        padding: 6px 12px;
    }
    
    .order-status-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        width: 100%;
    }
    
    .order-quick-actions {
        display: flex !important;
    }
    
    .btn-icon-mini {
        width: 30px;
        height: 30px;
        font-size: 0.8rem;
        -webkit-tap-highlight-color: transparent;
    }
    
    .btn-icon-mini:active {
        transform: scale(0.9);
    }
    
    /* Withdrawal action buttons mobile */
    .btn-action-mini {
        width: 30px;
        height: 30px;
        font-size: 0.8rem;
        -webkit-tap-highlight-color: transparent;
    }
    
    .btn-action-mini:active {
        transform: scale(0.9) !important;
    }
    
    .withdrawal-action-buttons {
        gap: 4px;
    }
    
    .withdrawal-status-actions {
        gap: 6px;
        width: 100%;
        justify-content: space-between;
    }
    
    /* Ensure proper order list item structure on mobile */
    .order-list-item {
        align-items: center;
    }
    
    .status-indicator {
        font-size: 0.75rem;
        padding: 4px 10px;
    }
    
    .status-badge-withdrawal {
        font-size: 0.75rem;
        padding: 4px 10px;
    }
    
    .order-info-secondary {
        width: 100%;
    }
    
    .order-date {
        font-size: 0.8rem;
    }
    
    /* Collapse/expand details */
    .order-list-details {
        flex-direction: column;
        gap: 8px;
        padding-top: 12px;
        margin-top: 0;
    }
    
    .order-list-details.collapsed {
        display: none !important;
    }
    
    .order-list-details.expanded {
        display: flex;
        animation: expandDetails 0.3s ease;
    }
    
    @keyframes expandDetails {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .order-detail-item {
        font-size: 0.85rem;
        gap: 6px;
        padding: 6px 0;
    }
    
    .order-detail-item i {
        font-size: 0.85rem;
        width: 18px;
    }
    
    /* Hide desktop actions, show mobile */
    .order-list-actions.desktop-only {
        display: none !important;
    }
    
    .order-actions-mobile {
        display: flex !important;
        justify-content: center;
        gap: 12px;
    }
    
    .btn-icon {
        width: 44px;
        height: 44px;
        font-size: 1rem;
        position: relative;
    }
    
    .order-actions-mobile .btn-icon {
        width: 100%;
        max-width: 200px;
        padding: 12px;
        border-radius: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    
    .order-actions-mobile .btn-icon i {
        font-size: 1.1rem;
    }
    
    .mobile-btn-text {
        display: inline !important;
        font-size: 0.95rem;
    }
    
    .btn-icon:active {
        transform: scale(0.9);
    }
    
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
    
    /* Optimize page header for mobile */
    .page-header-card {
        padding: 12px;
    }
    
    .page-header-content {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        width: 100%;
    }
    
    .page-header-icon {
        width: 32px;
        height: 32px;
        font-size: 1rem;
    }
    
    .page-title {
        font-size: 1.1rem;
    }
    
    .page-subtitle {
        font-size: 0.75rem;
    }
}

/* Custom color variables */
:root {
    --primary-color: #0058A3;
    --accent-color: #FF6B00;
}
</style>

<?php require_once 'includes/footer.php'; ?>