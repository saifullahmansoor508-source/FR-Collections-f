<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

// Check admin authentication
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    header('Location: login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $request_id = intval($_POST['request_id']);
    $status = sanitizeInput($_POST['status']);
    
    $stmt = $db->prepare("UPDATE product_requests SET status = ? WHERE id = ?");
    if ($stmt->execute([$status, $request_id])) {
        $success_message = "Request status updated successfully!";
    } else {
        $error_message = "Failed to update request status.";
    }
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $request_id = intval($_POST['request_id']);
    
    // Get image path to delete file
    $stmt = $db->prepare("SELECT image_path FROM product_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $image_path = $stmt->fetchColumn();
    
    // Delete the request
    $stmt = $db->prepare("DELETE FROM product_requests WHERE id = ?");
    if ($stmt->execute([$request_id])) {
        // Delete image file if exists
        if ($image_path && file_exists('../uploads/product_requests/' . $image_path)) {
            unlink('../uploads/product_requests/' . $image_path);
        }
        $success_message = "Request deleted successfully!";
    } else {
        $error_message = "Failed to delete request.";
    }
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if (isset($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
        $delete_ids = array_map('intval', $_POST['delete_ids']);
        $deleted_count = 0;
        
        foreach ($delete_ids as $request_id) {
            // Get image path to delete file
            $stmt = $db->prepare("SELECT image_path FROM product_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $image_path = $stmt->fetchColumn();
            
            // Delete the request
            $stmt = $db->prepare("DELETE FROM product_requests WHERE id = ?");
            if ($stmt->execute([$request_id])) {
                // Delete image file if exists
                if ($image_path && file_exists('../uploads/product_requests/' . $image_path)) {
                    unlink('../uploads/product_requests/' . $image_path);
                }
                $deleted_count++;
            }
        }
        
        if ($deleted_count > 0) {
            $success_message = "$deleted_count request(s) deleted successfully!";
        } else {
            $error_message = "Failed to delete requests.";
        }
    }
}

// Get filter
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';

// Fetch product requests
$query = "SELECT pr.*, u.full_name, u.email 
          FROM product_requests pr 
          JOIN users u ON pr.user_id = u.id";

if ($status_filter !== 'all') {
    $query .= " WHERE pr.status = :status";
}

$query .= " ORDER BY pr.created_at DESC";

$stmt = $db->prepare($query);
if ($status_filter !== 'all') {
    $stmt->bindParam(':status', $status_filter);
}
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for stats
$stmt = $db->prepare("SELECT COUNT(*) FROM product_requests WHERE status = 'Pending'");
$stmt->execute();
$pending_count = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM product_requests WHERE status = 'Approved'");
$stmt->execute();
$approved_count = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM product_requests WHERE status = 'Rejected'");
$stmt->execute();
$rejected_count = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM product_requests");
$stmt->execute();
$total_count = $stmt->fetchColumn();

$page_title = "Product Requests";
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
                    <i class="fas fa-cube"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Product Requests</h1>
                    <p class="page-subtitle">Manage and review product submission requests</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($total_count); ?></h3>
                        <p>Total Requests</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($pending_count); ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($approved_count); ?></h3>
                        <p>Approved</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($rejected_count); ?></h3>
                        <p>Rejected</p>
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
                    <i class="fas fa-search me-2"></i>Find Requests
                </div>
                <div class="search-actions">
                    <a href="product-requests.php" class="btn btn-clear-modern">
                        <i class="fas fa-redo me-2"></i>Clear Search
                    </a>
                </div>
            </div>

            <div class="search-filters">
                <!-- Status Filter Tabs -->
                <div class="filter-tabs">
                    <a href="product-requests.php?status=all" 
                       class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-list me-2"></i>All (<?php echo $total_count; ?>)
                    </a>
                    <a href="product-requests.php?status=Pending" 
                       class="filter-tab <?php echo $status_filter === 'Pending' ? 'active' : ''; ?>">
                        <i class="fas fa-clock me-2"></i>Pending (<?php echo $pending_count; ?>)
                    </a>
                    <a href="product-requests.php?status=Approved" 
                       class="filter-tab <?php echo $status_filter === 'Approved' ? 'active' : ''; ?>">
                        <i class="fas fa-check-circle me-2"></i>Approved (<?php echo $approved_count; ?>)
                    </a>
                    <a href="product-requests.php?status=Rejected" 
                       class="filter-tab <?php echo $status_filter === 'Rejected' ? 'active' : ''; ?>">
                        <i class="fas fa-times-circle me-2"></i>Rejected (<?php echo $rejected_count; ?>)
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Product Requests Grid -->
<div class="row">
    <div class="col-12">
        <div class="reviews-container">
            <div class="reviews-header">
                <div class="reviews-header-content">
                    <div class="reviews-icon">
                        <i class="fas fa-box-open"></i>
                    </div>
                    <div class="reviews-title">
                        <h4>Product Requests</h4>
                        <span class="reviews-count"><?php echo count($requests); ?> found</span>
                    </div>
                </div>
                <div class="reviews-actions">
                    <button class="btn btn-select-all-modern" onclick="toggleSelectAll()" id="selectAllBtn">
                        <i class="fas fa-check-double me-2"></i>Select All
                    </button>
                    <button class="btn btn-delete-all-modern" onclick="bulkDelete()" id="deleteSelectedBtn">
                        <i class="fas fa-trash-alt me-2"></i>Delete Selected
                    </button>
                    <button class="btn btn-refresh-modern" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh List
                    </button>
                </div>
            </div>

            <?php if (empty($requests)): ?>
                <div class="empty-reviews">
                    <div class="empty-reviews-icon"><i class="fas fa-cube"></i></div>
                    <h5>No product requests found</h5>
                    <p>Try adjusting your search terms.</p>
                    <a href="product-requests.php" class="btn btn-primary-modern"><i class="fas fa-redo me-2"></i>Clear Search</a>
                </div>
            <?php else: ?>
                <div class="reviews-grid">
                    <?php foreach ($requests as $request): ?>
                        <div class="review-card <?php echo $request['status'] === 'Pending' ? 'pending-review' : ''; ?>">
                            <div class="review-card-header">
                                <div class="desktop-checkbox-section">
                                    <input type="checkbox" class="request-checkbox desktop-checkbox" value="<?php echo $request['id']; ?>">
                                </div>
                                <div class="review-user-info">
                                    <div class="user-avatar-review">
                                        <?php echo strtoupper(substr($request['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="user-details-review">
                                        <div class="user-name-review"><?php echo htmlspecialchars($request['full_name']); ?></div>
                                        <div class="review-date">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            <?php echo date('M d, Y', strtotime($request['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="review-status-badge">
                                    <?php if ($request['status'] === 'Approved'): ?>
                                        <span class="status-badge-review approved">
                                            <i class="fas fa-check-circle"></i> Approved
                                        </span>
                                    <?php elseif ($request['status'] === 'Rejected'): ?>
                                        <span class="status-badge-review pending" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                                            <i class="fas fa-times-circle"></i> Rejected
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge-review pending">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="review-card-body">
                                <div class="product-image-section">
                                    <?php if ($request['image_path']): ?>
                                        <img src="../uploads/product_requests/<?php echo htmlspecialchars($request['image_path']); ?>" 
                                             alt="Product" 
                                             class="product-request-image">
                                    <?php else: ?>
                                        <div class="no-image-placeholder">
                                            <i class="fas fa-image"></i>
                                            <span>No Image</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="product-info-review">
                                    <i class="fas fa-cube me-2" style="color: #8b5cf6;"></i>
                                    <span class="product-name-review"><?php echo htmlspecialchars($request['product_name']); ?></span>
                                </div>

                                <div class="rating-display">
                                    <i class="fas fa-tags me-2" style="color: #6b7280;"></i>
                                    <span class="rating-text"><?php echo htmlspecialchars($request['category']); ?></span>
                                </div>

                                <div class="request-details">
                                    <div class="detail-row">
                                        <div class="detail-item full-width">
                                            <i class="fas fa-envelope me-2" style="color: #3b82f6;"></i>
                                            <strong>Email:</strong>
                                            <span class="email-value"><?php echo htmlspecialchars($request['email']); ?></span>
                                        </div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-item full-width">
                                            <i class="fas fa-id-card me-2" style="color: #10b981;"></i>
                                            <strong>Request ID:</strong>
                                            <code class="request-id">#<?php echo $request['id']; ?></code>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="review-card-footer">
                                <div class="review-actions">
                                    <form method="POST" class="d-inline status-form-desktop">
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <select name="status" class="status-select-desktop" onchange="confirmStatusChange(this, '<?php echo htmlspecialchars($request['product_name']); ?>')">
                                            <option value="Pending" <?php echo $request['status'] === 'Pending' ? 'selected' : ''; ?>>⏳ Pending</option>
                                            <option value="Approved" <?php echo $request['status'] === 'Approved' ? 'selected' : ''; ?>>✅ Approved</option>
                                            <option value="Rejected" <?php echo $request['status'] === 'Rejected' ? 'selected' : ''; ?>>❌ Rejected</option>
                                        </select>
                                    </form>
                                    
                                    <button type="button" class="btn-action-review btn-approve" title="View Details"
                                            onclick="viewRequestDetails(<?php echo $request['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                        <span>View</span>
                                    </button>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'This product request will be permanently deleted. Are you sure?', 'Delete Request')">
                                        <input type="hidden" name="delete_request" value="1">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <button type="submit" class="btn-action-review btn-delete" title="Delete Request">
                                            <i class="fas fa-trash-alt"></i>
                                            <span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Mobile Action Buttons -->
                <div class="mobile-action-buttons">
                    <button class="mobile-action-btn-top select-all" onclick="toggleSelectAll()" id="selectAllBtnMobile">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button class="mobile-action-btn-top delete-selected" onclick="bulkDelete()" id="deleteSelectedBtnMobile">
                        <i class="fas fa-trash-alt"></i> Delete
                    </button>
                </div>
                
                <!-- Mobile List View -->
                <div class="mobile-list">
                    <?php foreach ($requests as $request): ?>
                        <div class="user-list-item">
                            <div class="user-list-header" onclick="toggleDetails(this)">
                                <div class="request-checkbox-section">
                                    <input type="checkbox" class="request-checkbox" value="<?php echo $request['id']; ?>" onclick="event.stopPropagation()">
                                </div>
                                <div class="user-list-avatar">
                                    <?php echo strtoupper(substr($request['full_name'], 0, 1)); ?>
                                </div>
                                <div class="user-info-primary">
                                    <div class="user-name-mobile"><?php echo htmlspecialchars($request['full_name']); ?></div>
                                    <div class="user-badges-mobile">
                                        <?php if ($request['status'] === 'Approved'): ?>
                                            <span class="badge-mobile badge-approved">
                                                <i class="fas fa-check-circle"></i> Approved
                                            </span>
                                        <?php elseif ($request['status'] === 'Rejected'): ?>
                                            <span class="badge-mobile badge-rejected">
                                                <i class="fas fa-times-circle"></i> Rejected
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-mobile badge-pending">
                                                <i class="fas fa-clock"></i> Pending
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
                                    <i class="fas fa-cube detail-icon"></i>
                                    <span class="detail-label">Product:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($request['product_name']); ?></span>
                                </div>
                                <div class="detail-row-mobile">
                                    <i class="fas fa-tags detail-icon"></i>
                                    <span class="detail-label">Category:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($request['category']); ?></span>
                                </div>
                                <div class="detail-row-mobile">
                                    <i class="fas fa-envelope detail-icon"></i>
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($request['email']); ?></span>
                                </div>
                                <div class="detail-row-mobile">
                                    <i class="far fa-calendar-alt detail-icon"></i>
                                    <span class="detail-label">Date:</span>
                                    <span class="detail-value"><?php echo date('M d, Y', strtotime($request['created_at'])); ?></span>
                                </div>
                                
                                <div class="user-actions-mobile">
                                    <button class="mobile-btn mobile-btn-view" onclick="viewRequestDetails(<?php echo $request['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'Delete this request?', 'Delete')">
                                        <input type="hidden" name="delete_request" value="1">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <button type="submit" class="mobile-btn mobile-btn-delete">
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

<!-- Request Details Modal -->
<div class="modal fade" id="requestModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 25px 30px;">
                <h5 class="modal-title" style="font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-cube"></i><span>Product Request Details</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 30px;">
                <div class="review-modal-content">
                    <div id="modalContent">
                        <!-- Content will be loaded via JavaScript -->
                    </div>
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
async function confirmStatusChange(selectElement, productName) {
    const form = selectElement.closest('form');
    const newStatus = selectElement.value;
    const statusText = selectElement.options[selectElement.selectedIndex].text;
    
    if (await showConfirm(`Are you sure you want to change the status of "${productName}" to ${statusText}?`, 'Change Status', {confirmText: 'Yes, Change', cancelText: 'Cancel', type: 'warning'})) {
        form.submit();
    } else {
        // Reset to original value
        selectElement.value = form.querySelector('input[name="current_status"]')?.value || '';
    }
}

function viewRequestDetails(requestId) {
    // In a real implementation, you would fetch this data via AJAX
    // For now, we'll redirect to the same page with a parameter or show a simple alert
    showAlert('View detailed information for request ID: ' + requestId, 'info', 'Request Details');
    // You can implement AJAX to load detailed data into the modal
}

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

// Select All / Deselect All functions
let allSelected = false;

function toggleSelectAll() {
    allSelected = !allSelected;
    const checkboxes = document.querySelectorAll('.request-checkbox');
    checkboxes.forEach(cb => cb.checked = allSelected);
    
    // Update desktop button
    const btnDesktop = document.getElementById('selectAllBtn');
    if (btnDesktop) {
        if (allSelected) {
            btnDesktop.innerHTML = '<i class="fas fa-times me-2"></i>Deselect All';
            btnDesktop.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
        } else {
            btnDesktop.innerHTML = '<i class="fas fa-check-double me-2"></i>Select All';
            btnDesktop.style.background = 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';
        }
    }
    
    // Update mobile button
    const btnMobile = document.getElementById('selectAllBtnMobile');
    if (btnMobile) {
        if (allSelected) {
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

async function bulkDelete() {
    const checkboxes = document.querySelectorAll('.request-checkbox:checked');
    
    // Get unique IDs only (avoid counting both desktop and mobile checkboxes)
    const uniqueIds = [...new Set(Array.from(checkboxes).map(cb => cb.value))];
    
    if (uniqueIds.length === 0) {
        showAlert('Please select at least one request to delete', 'warning', 'No Selection');
        return;
    }
    
    if (await showConfirm(`Delete ${uniqueIds.length} selected request(s)?`, 'Bulk Delete', {confirmText: 'Yes, Delete', cancelText: 'Cancel', type: 'danger'})) {
        // Implement bulk delete via AJAX or form submission
        showAlert(`Deleting ${uniqueIds.length} requests...`, 'info', 'Processing');
        
        // Create form for bulk delete
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        uniqueIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_ids[]';
            input.value = id;
            form.appendChild(input);
        });
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'bulk_delete';
        actionInput.value = '1';
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

<style>
/* ===== COPY ALL STYLES FROM PREVIOUS PAGES ===== */

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
.search-filters { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px; }

/* Filter Tabs */
.filter-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    width: 100%;
}

.filter-tab {
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 12px 20px;
    text-decoration: none;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 150px;
    justify-content: center;
}

.filter-tab:hover {
    background: #e9ecef;
    border-color: #d1d5db;
    color: #374151;
    text-decoration: none;
}

.filter-tab.active {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border-color: var(--primary-color);
    box-shadow: 0 4px 12px rgba(0,88,163,.3);
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

/* Desktop Checkbox */
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

/* Desktop Status Dropdown */
.status-form-desktop {
    flex: 1;
    min-width: 160px;
}

.status-select-desktop {
    width: 100%;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.status-select-desktop:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0,88,163,.1);
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

/* Product Image Section */
.product-image-section {
    margin-bottom: 15px;
    text-align: center;
}

.product-request-image {
    width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 12px;
    border: 2px solid #e5e7eb;
}

.no-image-placeholder {
    width: 100%;
    height: 200px;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    border-radius: 12px;
    border: 2px dashed #d1d5db;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 0.9rem;
}

.no-image-placeholder i {
    font-size: 2rem;
    margin-bottom: 8px;
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

/* Request Details */
.request-details {
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

.email-value {
    color: #3b82f6;
    font-weight: 500;
    margin-left: 5px;
}

.request-id {
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

/* Responsive */
@media (max-width: 768px) {
    .reviews-grid {
        grid-template-columns: 1fr;
        padding: 15px;
    }
    
    .review-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .review-actions {
        flex-direction: column;
    }
    
    .btn-action-review {
        min-width: 100%;
    }
    
    .search-input-group {
        flex-direction: column;
    }
    
    .search-filters {
        flex-direction: column;
        align-items: stretch;
    }
    
    .stats-grid {
        flex-direction: column;
    }
    
    .stats-card-simple {
        min-height: 100px;
    }
    
    .detail-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .detail-item {
        flex: 100%;
    }
    
    .filter-tabs {
        flex-direction: column;
    }
    
    .filter-tab {
        min-width: 100%;
    }
}

/* Mobile Action Buttons - Above List */
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

/* Mobile List Styles */
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

.request-checkbox-section {
    display: none;
    flex-shrink: 0;
}

.request-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: #3b82f6;
}

.user-list-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.2rem;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
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

.badge-rejected {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
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
    min-width: 70px;
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
    .request-checkbox-section { display: flex !important; }
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
    
    .filter-tabs {
        flex-direction: column;
    }
    
    .filter-tab {
        min-width: 100%;
    }
}

/* Custom color variables */
:root {
    --primary-color: #0058A3;
    --accent-color: #FF6B00;
}

/* Sidebar Link Hover Color */
.sidebar .nav-link:hover {
    background-color: #d1f7c4 !important;
    color: #166534 !important;
}

.sidebar .nav-link.active {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color)) !important;
    color: white !important;
}
</style>

<?php require_once 'includes/footer.php'; ?>