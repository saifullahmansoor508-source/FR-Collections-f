<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $order_id = intval($_POST['order_id']);
    $new_status = sanitizeInput($_POST['status']);
    
    $valid_statuses = ['Pending', 'Confirmed', 'On The Way', 'Delivered', 'Canceled'];
    
    if (in_array($new_status, $valid_statuses)) {
        $stmt = $db->prepare("UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$new_status, $order_id])) {
            $success = "Order status updated successfully!";
        } else {
            $error = "Error updating order status.";
        }
    } else {
        $error = "Invalid status.";
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$partner_id_filter = isset($_GET['partner_id']) ? sanitizeInput($_GET['partner_id']) : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

$base_query = "
    SELECT o.*, u.full_name as user_name, u.email as user_email
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE 1=1
";

if ($status_filter) {
    $where_conditions[] = "o.status = ?";
    $params[] = $status_filter;
}

if ($partner_id_filter) {
    $where_conditions[] = "o.partner_id = ?";
    $params[] = $partner_id_filter;
}

if ($search) {
    $where_conditions[] = "(o.order_number LIKE ? OR o.full_name LIKE ? OR o.email LIKE ? OR o.partner_id LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($where_conditions)) {
    $base_query .= " AND " . implode(" AND ", $where_conditions);
}

$base_query .= " ORDER BY o.created_at DESC";

$stmt = $db->prepare($base_query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all partner IDs for filter dropdown
$stmt = $db->prepare("SELECT DISTINCT partner_id FROM orders WHERE partner_id IS NOT NULL AND partner_id != '' ORDER BY partner_id");
$stmt->execute();
$partner_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Orders Management";
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
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Orders Management</h1>
                    <p class="page-subtitle">Track and manage all customer orders</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo count($orders); ?></h3>
                        <p>Total Orders</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3>
                        <?php
                        $totalRevenue = 0;
                        foreach ($orders as $order) {
                            if (in_array($order['status'], ['Confirmed', 'On The Way', 'Delivered'])) { 
                                $totalRevenue += $order['total_amount']; 
                            }
                        }
                        echo formatPrice($totalRevenue);
                        ?>
                        </h3>
                        <p>Total Revenue</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3>
                        <?php
                        $pendingCount = 0;
                        foreach ($orders as $order) { if ($order['status'] === 'Pending') { $pendingCount++; } }
                        echo $pendingCount;
                        ?>
                        </h3>
                        <p>Pending Orders</p>
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
                       placeholder="Search orders..."
                       class="mobile-search-input">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <input type="hidden" name="partner_id" value="<?php echo htmlspecialchars($partner_id_filter); ?>">
                <div class="dropdown">
                    <button type="button" class="btn-mobile-export" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-download"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="exportOrders('csv');">
                            <i class="fas fa-file-csv me-2"></i>CSV
                        </a></li>
                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="exportOrders('pdf');">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                    </ul>
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
                    <i class="fas fa-search me-2"></i>Find Orders
                </div>
                <div class="search-actions">
                    <a href="orders.php" class="btn btn-clear-modern">
                        <i class="fas fa-redo me-2"></i>Clear Search
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
                                   placeholder="Order #, customer, or email..."
                                   class="search-field">
                            <button type="submit" class="search-btn-modern">
                                <i class="fas fa-search"></i>
                                <span>Search</span>
                            </button>
                        </div>
                    </div>

                    <div class="search-actions-group">
                        <button type="button" class="btn btn-export-modern" data-bs-toggle="dropdown">
                            <i class="fas fa-download me-2"></i>Export Orders
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="#" onclick="exportOrders('csv')">
                                <i class="fas fa-file-csv me-2"></i>Export CSV
                            </a>
                            <a class="dropdown-item" href="#" onclick="exportOrders('pdf')">
                                <i class="fas fa-file-pdf me-2"></i>Export PDF
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modern Filters Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="filters-card">
            <div class="filters-header">
                <div class="filters-title">
                    <i class="fas fa-filter me-2"></i>Filter Orders
                </div>
            </div>

            <form method="GET" id="filterForm" class="filters-form">
                <div class="filters-grid">
                    <!-- Status Filter -->
                    <div class="filter-group">
                        <label class="filter-label">Order Status</label>
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
                                <div class="dropdown-option-modern <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('Pending', 'Pending')">
                                    <span class="status-dot status-pending"></span>Pending
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'Confirmed' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('Confirmed', 'Confirmed')">
                                    <span class="status-dot status-confirmed"></span>Confirmed
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'On The Way' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('On The Way', 'On The Way')">
                                    <span class="status-dot status-on-the-way"></span>On The Way
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'Delivered' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('Delivered', 'Delivered')">
                                    <span class="status-dot status-delivered"></span>Delivered
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'Canceled' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('Canceled', 'Canceled')">
                                    <span class="status-dot status-canceled"></span>Canceled
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($status_filter); ?>">
                    </div>

                    <!-- Partner ID Filter -->
                    <div class="filter-group">
                        <label class="filter-label">Partner ID</label>
                        <div class="custom-dropdown-modern" id="partnerDropdown">
                            <div class="dropdown-selected-modern" onclick="toggleModernDropdown('partnerDropdown')">
                                <span id="selectedPartner">
                                    <?php echo $partner_id_filter ? htmlspecialchars($partner_id_filter) : 'All Partners'; ?>
                                </span>
                                <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                            </div>
                            <div class="dropdown-options-modern">
                                <div class="dropdown-option-modern <?php echo !$partner_id_filter ? 'selected' : ''; ?>"
                                     onclick="selectPartner('', 'All Partners')">
                                    <i class="fas fa-users me-2"></i>All Partners
                                </div>
                                <?php foreach ($partner_ids as $pid): ?>
                                    <div class="dropdown-option-modern <?php echo $partner_id_filter === $pid ? 'selected' : ''; ?>"
                                         onclick="selectPartner('<?php echo htmlspecialchars($pid); ?>', '<?php echo htmlspecialchars($pid); ?>')">
                                        <i class="fas fa-handshake me-2"></i><?php echo htmlspecialchars($pid); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="hidden" name="partner_id" id="partnerInput" value="<?php echo htmlspecialchars($partner_id_filter); ?>">
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Orders List -->
<div class="row">
    <div class="col-12">
        <div class="orders-grid-card">
            <div class="grid-header">
                <div class="grid-title">
                    <i class="fas fa-list me-2"></i>
                    Orders List <span class="order-count">(<?php echo count($orders); ?>)</span>
                </div>
                <div class="grid-actions">
                    <button class="btn btn-icon-action btn-delete-selected" onclick="deleteSelectedOrders()" title="Delete Selected" style="display: none;">
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

            <?php if (!empty($orders)): ?>
                <div class="orders-list-container">
                    <div class="orders-list-header">
                        <div class="select-all-container">
                            <input type="checkbox" id="selectAll" class="order-checkbox-select-all" onclick="toggleSelectAll()">
                            <label for="selectAll" class="select-all-label">Select All</label>
                        </div>
                        <div class="mobile-bulk-actions">
                            <button class="btn-icon-mobile btn-delete-selected-mobile" onclick="deleteSelectedOrders()" title="Delete Selected" style="display: none;">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                        <span class="selected-count" id="selectedCount" style="display: none;">
                            <i class="fas fa-check-circle me-1"></i><span id="selectedCountText">0</span> selected
                        </span>
                    </div>
                    
                    <div class="orders-list">
                        <?php foreach ($orders as $order): ?>
                            <div class="order-list-item" data-order-id="<?php echo $order['id']; ?>">
                                <div class="order-checkbox-container">
                                    <input type="checkbox" class="order-checkbox" value="<?php echo $order['id']; ?>" onclick="updateSelectedCount()">
                                </div>
                                
                                <div class="order-list-avatar">
                                    <i class="fas fa-shopping-bag"></i>
                                </div>
                                
                                <div class="order-list-content">
                                    <div class="order-list-header" onclick="toggleOrderDetails(<?php echo $order['id']; ?>)">
                                        <div class="order-header-info">
                                            <div class="order-info-primary">
                                                <div class="order-number-badge">
                                                    <i class="fas fa-hashtag"></i>
                                                    <?php echo htmlspecialchars($order['order_number']); ?>
                                                </div>
                                                <div class="order-status-actions">
                                                    <span class="status-indicator status-<?php echo strtolower(str_replace(' ', '-', $order['status'])); ?>">
                                                        <i class="fas fa-circle"></i>
                                                        <?php echo htmlspecialchars($order['status']); ?>
                                                    </span>
                                                    <div class="order-quick-actions">
                                                        <button class="btn-icon-mini btn-edit-order" onclick="event.stopPropagation(); openEditStatusModal(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['status']); ?>')" title="Edit Status">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn-icon-mini btn-view-order" onclick="event.stopPropagation(); viewOrder(<?php echo $order['id']; ?>)" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="order-info-secondary">
                                                <span class="order-date">
                                                    <i class="fas fa-calendar-alt me-1"></i>
                                                    <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="order-expand-icon">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    
                                    <div class="order-list-details collapsed" id="order-details-<?php echo $order['id']; ?>">
                                        <div class="order-detail-item">
                                            <i class="fas fa-user me-2"></i>
                                            <span class="customer-name"><?php echo htmlspecialchars($order['full_name']); ?></span>
                                        </div>
                                        
                                        <div class="order-detail-item">
                                            <i class="fas fa-envelope me-2"></i>
                                            <span><?php echo htmlspecialchars($order['email']); ?></span>
                                        </div>
                                        
                                        <div class="order-detail-item">
                                            <i class="fas fa-dollar-sign me-2"></i>
                                            <span class="stat-value"><?php echo formatPrice($order['total_amount']); ?></span>
                                            <?php if ($order['discount_amount'] > 0): ?>
                                                <span class="discount-tag">-<?php echo formatPrice($order['discount_amount']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($order['partner_id']): ?>
                                        <div class="order-detail-item">
                                            <i class="fas fa-handshake me-2"></i>
                                            <span class="partner-badge"><?php echo htmlspecialchars($order['partner_id']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="order-actions-mobile">
                                            <button class="btn-icon btn-delete-order" onclick="event.stopPropagation(); deleteOrder(<?php echo $order['id']; ?>)" title="Delete Order">
                                                <i class="fas fa-trash-alt"></i>
                                                <span class="mobile-btn-text">Delete Order</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="order-list-actions desktop-only">
                                    <button class="btn-icon btn-edit-order" onclick="openEditStatusModal(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['status']); ?>')" title="Edit Status">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-icon btn-view-order" onclick="viewOrder(<?php echo $order['id']; ?>)" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn-icon btn-delete-order" onclick="deleteOrder(<?php echo $order['id']; ?>)" title="Delete Order">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid-footer">
                    <div class="grid-info">
                        Showing <?php echo count($orders); ?> orders
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state-modern">
                    <div class="empty-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <h4>No orders found</h4>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Order Details Modal -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 20px 30px;">
                <h5 class="modal-title" style="font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-file-invoice"></i>Order Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="orderDetails" style="padding: 0; background: #f8f9fa;">
                <!-- Order details will be loaded here -->
            </div>
            <div class="modal-footer" style="background: white; border-top: 2px solid #e5e7eb; padding: 20px 30px; gap: 10px;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 12px 25px; font-weight: 600;">
                    <i class="fas fa-times me-2"></i>Close
                </button>
                <button type="button" class="btn btn-primary" onclick="downloadOrderAsImage()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; border-radius: 50px; padding: 12px 25px; font-weight: 600; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);">
                    <i class="fas fa-download me-2"></i>Download Recipt
                </button>
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

<!-- Edit Status Modal -->
<div class="modal fade" id="editStatusModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" id="editModalContent" style="border-radius: 20px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: visible; transition: all 0.3s ease;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 25px 30px;">
                <h5 class="modal-title" style="font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-edit"></i>Update Order Status
                </h5>
                <button type="button" class="btn-close btn-close-white" onclick="closeEditStatusModal()"></button>
            </div>
            <div class="modal-body" id="editModalBody" style="padding: 35px 30px; transition: all 0.3s ease;">
                <form id="editStatusForm" method="POST">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="order_id" id="editOrderId">
                    
                    <div class="mb-4">
                        <label class="form-label" style="font-weight: 600; color: #2c3e50; font-size: 1rem; margin-bottom: 12px;">
                            <i class="fas fa-list-ul me-2"></i>Select New Status
                        </label>
                        
                        <!-- Custom Status Dropdown -->
                        <div class="custom-status-dropdown" id="statusDropdownEdit">
                            <div class="status-dropdown-selected" onclick="toggleStatusDropdown()">
                                <span id="selectedStatusText" class="status-selected-text">Select Status</span>
                                <i class="fas fa-chevron-down status-dropdown-arrow"></i>
                            </div>
                            <div class="status-dropdown-options" id="statusDropdownOptions">
                                <div class="status-dropdown-option" data-status="Pending" onclick="selectOrderStatus('Pending')">
                                    <div class="status-option-indicator" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);"></div>
                                    <div class="status-option-content">
                                        <div class="status-option-title">Pending</div>
                                        <div class="status-option-desc">Order is awaiting confirmation</div>
                                    </div>
                                    <i class="fas fa-check status-option-check"></i>
                                </div>
                                
                                <div class="status-dropdown-option" data-status="Confirmed" onclick="selectOrderStatus('Confirmed')">
                                    <div class="status-option-indicator" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);"></div>
                                    <div class="status-option-content">
                                        <div class="status-option-title">Confirmed</div>
                                        <div class="status-option-desc">Order has been confirmed</div>
                                    </div>
                                    <i class="fas fa-check status-option-check"></i>
                                </div>
                                
                                <div class="status-dropdown-option" data-status="On The Way" onclick="selectOrderStatus('On The Way')">
                                    <div class="status-option-indicator" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);"></div>
                                    <div class="status-option-content">
                                        <div class="status-option-title">On The Way</div>
                                        <div class="status-option-desc">Order is out for delivery</div>
                                    </div>
                                    <i class="fas fa-check status-option-check"></i>
                                </div>
                                
                                <div class="status-dropdown-option" data-status="Delivered" onclick="selectOrderStatus('Delivered')">
                                    <div class="status-option-indicator" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);"></div>
                                    <div class="status-option-content">
                                        <div class="status-option-title">Delivered</div>
                                        <div class="status-option-desc">Order has been delivered</div>
                                    </div>
                                    <i class="fas fa-check status-option-check"></i>
                                </div>
                                
                                <div class="status-dropdown-option" data-status="Canceled" onclick="selectOrderStatus('Canceled')">
                                    <div class="status-option-indicator" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);"></div>
                                    <div class="status-option-content">
                                        <div class="status-option-title">Canceled</div>
                                        <div class="status-option-desc">Order has been canceled</div>
                                    </div>
                                    <i class="fas fa-check status-option-check"></i>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="status" id="selectedStatusValue">
                    </div>
                    
                    <button type="submit" class="btn w-100" id="updateStatusBtn"
                            style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 15px; border-radius: 50px; font-weight: 700; font-size: 1.1rem; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 88, 163, 0.3);"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0, 88, 163, 0.4)'"
                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 88, 163, 0.3)'">
                        <i class="fas fa-check-circle me-2"></i>Update Status
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Modern Dropdown Functions
function toggleModernDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
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

// Select status
function selectStatus(value, text) {
    document.getElementById('selectedStatus').textContent = text;
    document.getElementById('statusInput').value = value;
    document.getElementById('statusDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#statusDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    event.target.classList.add('selected');

    // Submit form
    document.getElementById('filterForm').submit();
}

// Select partner
function selectPartner(value, text) {
    document.getElementById('selectedPartner').textContent = text;
    document.getElementById('partnerInput').value = value;
    document.getElementById('partnerDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#partnerDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    event.target.classList.add('selected');

    // Submit form
    document.getElementById('filterForm').submit();
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

// Update order status with loading animation
function updateOrderStatus(selectElement, orderId) {
    const form = selectElement.closest('.status-form');
    const originalText = selectElement.options[selectElement.selectedIndex].text;

    // Show loading state
    selectElement.disabled = true;
    selectElement.style.opacity = '0.6';

    // Submit form after brief delay
    setTimeout(function() {
        form.submit();
    }, 300);
}

// View order details
function viewOrder(orderId) {
    // Show loading in modal
    $('#orderModal .modal-body').html(`
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3">Loading order details...</p>
        </div>
    `);

    // Load order details via AJAX
    $.get('ajax/get_order_details.php', {id: orderId}, function(data) {
        $('#orderModal .modal-body').html(data);
        $('#orderModal').modal('show');
    }).fail(function() {
        $('#orderModal .modal-body').html(`
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Error loading order details. Please try again.
            </div>
        `);
        $('#orderModal').modal('show');
    });
}

// Print order
function printOrder(orderId) {
    if (orderId) {
        window.open('print_order.php?id=' + orderId, '_blank');
    } else {
        // Print current modal content
        const printContent = document.querySelector('#orderModal .modal-body').innerHTML;
        const printWindow = window.open('', '_blank');
        const htmlContent = '<html>' +
            '<head>' +
            '<title>Order Details</title>' +
            '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' +
            '<style>@media print { .no-print { display: none !important; } }</style>' +
            '</head>' +
            '<body class="p-4">' +
            printContent +
            '<script>window.print(); window.close();<\/script>' +
            '</body>' +
            '</html>';
        printWindow.document.write(htmlContent);
    }
}

// Open Edit Status Modal
function openEditStatusModal(orderId, currentStatus) {
    document.getElementById('editOrderId').value = orderId;
    document.getElementById('selectedStatusText').textContent = currentStatus;
    document.getElementById('selectedStatusValue').value = currentStatus;
    
    // Mark current status as selected
    document.querySelectorAll('.status-dropdown-option').forEach(option => {
        option.classList.remove('selected');
        if (option.getAttribute('data-status') === currentStatus) {
            option.classList.add('selected');
        }
    });
    
    const modal = new bootstrap.Modal(document.getElementById('editStatusModal'));
    modal.show();
}

// Close Edit Status Modal
function closeEditStatusModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('editStatusModal'));
    if (modal) modal.hide();
}

// Toggle Status Dropdown
function toggleStatusDropdown() {
    const dropdown = document.getElementById('statusDropdownEdit');
    const modalBody = document.getElementById('editModalBody');
    const isActive = dropdown.classList.contains('active');
    
    if (!isActive) {
        // Opening dropdown - increase modal body padding
        dropdown.classList.add('active');
        modalBody.style.paddingBottom = '420px'; // Add space for dropdown
    } else {
        // Closing dropdown - restore original padding
        dropdown.classList.remove('active');
        modalBody.style.paddingBottom = '35px';
    }
}

// Select Order Status
function selectOrderStatus(status) {
    document.getElementById('selectedStatusText').textContent = status;
    document.getElementById('selectedStatusValue').value = status;
    
    // Update selected state
    document.querySelectorAll('.status-dropdown-option').forEach(option => {
        option.classList.remove('selected');
        if (option.getAttribute('data-status') === status) {
            option.classList.add('selected');
        }
    });
    
    // Close dropdown and restore padding
    document.getElementById('statusDropdownEdit').classList.remove('active');
    document.getElementById('editModalBody').style.paddingBottom = '35px';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('statusDropdownEdit');
    const modalBody = document.getElementById('editModalBody');
    if (dropdown && modalBody && !event.target.closest('.custom-status-dropdown')) {
        dropdown.classList.remove('active');
        modalBody.style.paddingBottom = '35px';
    }
});

// Export orders
function exportOrders(format) {
    const statusInput = document.getElementById('statusInput');
    const partnerInput = document.getElementById('partnerInput');
    const searchInput = document.getElementById('searchInput') || document.getElementById('mobileSearchInput');
    
    const statusValue = statusInput ? statusInput.value : '';
    const partnerValue = partnerInput ? partnerInput.value : '';
    const searchValue = searchInput ? searchInput.value : '';
    
    const url = 'export_orders.php?format=' + format +
                '&status=' + encodeURIComponent(statusValue) +
                '&partner_id=' + encodeURIComponent(partnerValue) +
                '&search=' + encodeURIComponent(searchValue);

    window.open(url, '_blank');
    showNotification('success', 'Export started! Check your downloads.');
}

// Custom Dialog System
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
        if (options.confirmStyle === 'danger') {
            confirmBtn.className = 'btn-dialog-confirm';
        } else {
            confirmBtn.className = 'btn-dialog-confirm primary';
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

// Delete single order
async function deleteOrder(orderId) {
    const confirmed = await showCustomDialog({
        title: 'Delete Order',
        message: 'Are you sure you want to permanently delete this order? This action cannot be undone.',
        type: 'danger',
        confirmStyle: 'danger'
    });
    
    if (!confirmed) {
        return;
    }
    
    $.ajax({
        url: 'ajax/delete_order.php',
        method: 'POST',
        data: { order_id: orderId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Remove order from DOM with animation
                $('[data-order-id="' + orderId + '"]').fadeOut(400, function() {
                    $(this).remove();
                    updateSelectedCount();
                    location.reload();
                });
                
                // Show success message
                showNotification('success', response.message);
            } else {
                showNotification('error', response.message);
            }
        },
        error: function() {
            showNotification('error', 'Failed to delete order. Please try again.');
        }
    });
}

// Delete selected orders
async function deleteSelectedOrders() {
    const selectedOrders = $('.order-checkbox:checked').map(function() {
        return $(this).val();
    }).get();
    
    if (selectedOrders.length === 0) {
        showNotification('warning', 'Please select at least one order to delete.');
        return;
    }
    
    const confirmed = await showCustomDialog({
        title: 'Delete Multiple Orders',
        message: `Are you sure you want to permanently delete ${selectedOrders.length} order(s)? This action cannot be undone.`,
        type: 'danger',
        confirmStyle: 'danger'
    });
    
    if (!confirmed) {
        return;
    }
    
    $.ajax({
        url: 'ajax/delete_order.php',
        method: 'POST',
        data: { order_ids: selectedOrders },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showNotification('success', response.message);
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                showNotification('error', response.message);
            }
        },
        error: function() {
            showNotification('error', 'Failed to delete orders. Please try again.');
        }
    });
}

// Toggle select all checkboxes
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.order-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
    
    updateSelectedCount();
}

// Toggle order details dropdown (mobile)
function toggleOrderDetails(orderId) {
    const detailsElement = document.getElementById('order-details-' + orderId);
    const orderItem = document.querySelector('[data-order-id="' + orderId + '"]');
    const expandIcon = orderItem.querySelector('.order-expand-icon i');
    
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

// Update selected count display
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    const count = checkboxes.length;
    const selectAll = document.getElementById('selectAll');
    const totalCheckboxes = document.querySelectorAll('.order-checkbox').length;
    
    // Update select all checkbox state
    selectAll.checked = count === totalCheckboxes && count > 0;
    selectAll.indeterminate = count > 0 && count < totalCheckboxes;
    
    // Show/hide desktop bulk action buttons
    const deleteBtn = document.querySelector('.btn-delete-selected');
    const cancelBtn = document.querySelector('.btn-cancel-selected');
    
    // Show/hide mobile bulk action buttons
    const deleteBtnMobile = document.querySelector('.btn-delete-selected-mobile');
    
    const selectedCountElement = document.getElementById('selectedCount');
    const selectedCountText = document.getElementById('selectedCountText');
    
    if (count > 0) {
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
        if (cancelBtn) cancelBtn.style.display = 'inline-flex';
        if (deleteBtnMobile) deleteBtnMobile.style.display = 'inline-flex';
        selectedCountElement.style.display = 'inline-flex';
        selectedCountText.textContent = count;
    } else {
        if (deleteBtn) deleteBtn.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';
        if (deleteBtnMobile) deleteBtnMobile.style.display = 'none';
        selectedCountElement.style.display = 'none';
    }
}

// Cancel selection
function cancelSelection() {
    const checkboxes = document.querySelectorAll('.order-checkbox');
    const selectAll = document.getElementById('selectAll');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    selectAll.checked = false;
    
    updateSelectedCount();
}

// Show notification
function showNotification(type, message) {
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

    // Highlight current row on hover
    $('.order-row').hover(
        function() {
            $(this).addClass('row-highlight');
        },
        function() {
            $(this).removeClass('row-highlight');
        }
    );
});
</script>

<style>
/* Page Header with Stats */
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

/* Dashboard-like stat boxes */
.stats-grid {
    display: flex;
    gap: 16px;
    align-items: stretch;
    flex-wrap: wrap;
    justify-content: center;
}
.stat-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    transition: all .3s ease;
}
.stat-card:hover { transform: translateY(-2px); box-shadow:0 8px 18px rgba(0,0,0,.1); }
.stat-card-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); box-shadow:0 4px 12px rgba(0,88,163,.3); }
.stat-card-icon.success { background:linear-gradient(135deg, #10b981, #059669); box-shadow:0 4px 12px rgba(16,185,129,.3); }
.stat-card-icon.accent { background:linear-gradient(135deg, #f59e0b, #d97706); box-shadow:0 4px 12px rgba(245,158,11,.3); }
.stat-card-content { display:flex; flex-direction:column; }
.stat-card-number { font-size:1.2rem; font-weight:700; color:#1f2937; }
.stat-card-label { font-size:.75rem; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }

/* Filters Card */
.filters-card {
    background: white;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
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

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
}

.filter-group {
    display: flex;
    flex-direction: column;
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

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 8px;
    display: inline-block;
}

.status-pending { background-color: #fbbf24; }
.status-confirmed { background-color: #3b82f6; }
.status-on-the-way { background-color: #f59e0b; }
.status-delivered { background-color: #10b981; }
.status-canceled { background-color: #ef4444; }

/* Modern Search Input */
.search-input-modern {
    position: relative;
    display: flex;
    align-items: center;
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

/* Quick Actions Group */
.quick-actions-group {
    display: flex;
    align-items: center;
}

.btn-export {
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
}

.btn-export:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
    color: white;
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
}

.search-actions-group {
    display: flex;
    align-items: center;
}

.btn-export-modern {
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
}

.btn-export-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
    color: white;
}

/* Orders Grid Card */
.orders-grid-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.grid-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 24px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
    position: relative;
}

.grid-header::after {
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

.grid-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0;
    display: flex;
    align-items: center;
}

.grid-actions {
    display: flex;
    gap: 12px;
}

.orders-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
    gap: 24px;
    padding: 32px;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 0 0 16px 16px;
}

.order-card {
    background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
    border: 1px solid #e9ecef;
    border-radius: 16px;
    padding: 24px;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    position: relative;
    overflow: hidden;
}

.order-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border-radius: 16px 16px 0 0;
}

.order-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 12px 32px rgba(0, 88, 163, 0.15);
}

.order-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #f1f5f9;
    position: relative;
}

.order-number-section {
    display: flex;
    align-items: center;
    gap: 12px;
}

.order-number-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.9rem;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.order-number-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.order-number {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--primary-color);
    line-height: 1;
}

.order-date {
    color: #718096;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 4px;
}

.order-status-badge {
    display: flex;
    align-items: center;
}

.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.status-indicator i {
    font-size: 0.6rem;
}

.status-pending {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: white;
}

.status-confirmed {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}

.status-on-the-way {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.status-delivered {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.status-canceled {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.order-card-body {
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.info-section {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 12px;
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
}

.info-section:hover {
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.section-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.8rem;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0, 88, 163, 0.2);
}

.section-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.customer-name {
    font-weight: 600;
    color: #2d3748;
    font-size: 0.95rem;
}

.customer-email {
    color: #718096;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 4px;
}

.amount-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.total-amount {
    font-weight: 700;
    color: var(--primary-color);
    font-size: 1.1rem;
}

.discount-amount {
    color: #10b981;
    font-size: 0.85rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 4px;
}

.partner-badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.order-card-footer {
    display: flex;
    justify-content: center;
    padding-top: 16px;
    border-top: 1px solid #f1f5f9;
}

.action-buttons {
    display: flex;
    gap: 12px;
}

/* Enhanced Action Buttons */
.btn-action {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.btn-action::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: inherit;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.btn-action:hover::before {
    opacity: 0.2;
}

.btn-view {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-view:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

.btn-print {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-print:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
}

.grid-footer {
    background: #f8f9fa;
    padding: 16px 32px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: center;
    align-items: center;
}

.grid-info {
    color: #718096;
    font-size: 0.9rem;
    font-weight: 500;
}

.table-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 24px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
}

.table-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0;
    display: flex;
    align-items: center;
}

.order-count {
    background: var(--primary-color);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-left: 8px;
}

.table-actions {
    display: flex;
    gap: 12px;
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
    color: white;
}

/* Modern Table */
.table-responsive-modern {
    overflow-x: auto;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
}

.orders-table thead th {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 20px 16px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #dee2e6;
    position: sticky;
    top: 0;
    z-index: 10;
}

.orders-table tbody td {
    padding: 20px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.order-row {
    transition: all 0.3s ease;
}

.order-row:hover {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.row-highlight {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%) !important;
}

/* Table Cell Content */
.order-number {
    font-weight: 600;
    color: #2d3748;
    font-size: 0.95rem;
}

.customer-info .customer-name {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 2px;
    font-size: 0.9rem;
}

.customer-info .customer-email {
    color: #718096;
    font-size: 0.8rem;
}

.amount-info .total-amount {
    font-weight: 700;
    color: var(--primary-color);
    font-size: 0.95rem;
}

.amount-info .discount-amount {
    color: #10b981;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Table Footer */
.table-footer {
    background: #f8f9fa;
    padding: 16px 32px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: between;
    align-items: center;
}

.table-info {
    color: #718096;
    font-size: 0.9rem;
    font-weight: 500;
}

/* Empty State */
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
    color: #4a5568;
    margin-bottom: 8px;
    font-weight: 600;
}

.empty-state-modern p {
    color: #718096;
    margin-bottom: 24px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .filters-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .page-header-card {
        flex-direction: column;
        text-align: center;
        gap: 24px;
    }

    .page-header-stats {
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .page-header-card,
    .filters-card,
    .orders-grid-card {
        margin: 0 -15px;
        border-radius: 0;
    }

    .orders-grid {
        grid-template-columns: 1fr;
        padding: 16px;
    }

    .order-card {
        padding: 20px;
    }

    .order-card-header {
        flex-direction: column;
        gap: 16px;
        align-items: flex-start;
    }

    .order-number-section {
        width: 100%;
    }

    .order-status-badge {
        align-self: flex-end;
    }

    .info-section {
        padding: 10px 12px;
    }

    .section-icon {
        width: 28px;
        height: 28px;
        font-size: 0.7rem;
    }

    .order-number {
        font-size: 1.1rem;
    }

    .total-amount {
        font-size: 1rem;
    }

    .grid-header {
        flex-direction: column;
        gap: 16px;
        text-align: center;
        padding: 20px 24px;
    }

    .filters-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .table-header {
        flex-direction: column;
        gap: 16px;
        text-align: center;
        padding: 20px 24px;
    }

    .orders-table thead th {
        padding: 12px 8px;
        font-size: 0.75rem;
    }

    .orders-table tbody td {
        padding: 12px 8px;
    }

    .action-buttons {
        flex-direction: column;
        gap: 4px;
    }

    .btn-action {
        width: 40px;
        height: 40px;
        font-size: 0.9rem;
    }

    .stat-item {
        margin: 0 12px;
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
        font-size: 1.8rem;
    }

    .page-header-stats {
        gap: 20px;
    }

    .stat-number {
        font-size: 1.5rem;
    }

    .filters-card {
        padding: 24px 20px;
    }

    .table-responsive-modern {
        font-size: 0.85rem;
    }
}

/* Scrollbar Styling */
.dropdown-options-modern::-webkit-scrollbar,
.table-responsive-modern::-webkit-scrollbar {
    width: 6px;
}

.dropdown-options-modern::-webkit-scrollbar-track,
.table-responsive-modern::-webkit-scrollbar-track {
    background: #f3f4f6;
    border-radius: 10px;
}

.dropdown-options-modern::-webkit-scrollbar-thumb,
.table-responsive-modern::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
}

.dropdown-options-modern::-webkit-scrollbar-thumb:hover,
.table-responsive-modern::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}

/* Simple Stats Cards */
.stats-card-simple {
    background: linear-gradient(135deg, #0058A3, #FF6B00);
    color: white;
    border-radius: 16px;
    padding: 24px 16px;
    text-align: center;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
    height: 100%;
    min-height: 150px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.stats-card-simple:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
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
    font-size: 2.8rem;
    font-weight: 800;
    margin-bottom: 8px;
    line-height: 1;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.stats-content-simple p {
    margin-bottom: 0;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.95;
}

.stats-content-simple i {
    position: absolute;
    top: 20px;
    right: 20px;
}

/* Edit Button Styles */
.btn-edit {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.btn-edit:hover {
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
    box-shadow: 0 6px 16px rgba(139, 92, 246, 0.4);
    color: white;
}

/* Custom Status Dropdown Styles */
.custom-status-dropdown {
    position: relative;
    width: 100%;
}

.status-dropdown-selected {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px 20px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.status-dropdown-selected:hover {
    border-color: #0058a3;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.15);
}

.custom-status-dropdown.active .status-dropdown-selected {
    border-color: #0058a3;
    box-shadow: 0 0 0 3px rgba(0, 88, 163, 0.1);
    border-radius: 12px 12px 0 0;
}

.status-selected-text {
    font-weight: 600;
    color: #1f2937;
    font-size: 1rem;
}

.status-dropdown-arrow {
    color: #6b7280;
    transition: transform 0.3s ease;
    font-size: 0.9rem;
}

.custom-status-dropdown.active .status-dropdown-arrow {
    transform: rotate(180deg);
    color: #0058a3;
}

.status-dropdown-options {
    position: absolute;
    top: calc(100% - 2px);
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #0058a3;
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

.custom-status-dropdown.active .status-dropdown-options {
    max-height: 400px;
    opacity: 1;
    transform: translateY(0);
    overflow-y: auto;
}

.status-dropdown-option {
    padding: 16px 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.2s ease;
    border-bottom: 1px solid #f3f4f6;
    position: relative;
}

.status-dropdown-option:last-child {
    border-bottom: none;
}

.status-dropdown-option:hover {
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    padding-left: 25px;
}

.status-dropdown-option.selected {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
}

.status-dropdown-option.selected .status-option-check {
    opacity: 1;
}

.status-option-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.status-option-content {
    flex: 1;
}

.status-option-title {
    font-weight: 600;
    color: #1f2937;
    font-size: 1rem;
    margin-bottom: 2px;
}

.status-option-desc {
    font-size: 0.85rem;
    color: #6b7280;
}

.status-option-check {
    color: #0058a3;
    font-size: 1.1rem;
    opacity: 0;
    transition: opacity 0.2s ease;
}

/* Status Dropdown Scrollbar */
.status-dropdown-options::-webkit-scrollbar {
    width: 6px;
}

.status-dropdown-options::-webkit-scrollbar-track {
    background: #f3f4f6;
    border-radius: 10px;
}

.status-dropdown-options::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
}

.status-dropdown-options::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}

/* ===== Modern Order Details Styles ===== */
.order-details-modern {
    padding: 30px;
    background: #f8f9fa;
}

/* Order Header */
.order-header-modern {
    position: relative;
    background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%);
    border-radius: 20px;
    padding: 35px;
    margin-bottom: 30px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0, 88, 163, 0.2);
}

.order-header-bg {
    position: absolute;
    top: -50%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    z-index: 0;
}

.order-header-content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.order-main-info {
    display: flex;
    align-items: center;
    gap: 20px;
}

.order-icon-badge {
    width: 70px;
    height: 70px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: white;
    backdrop-filter: blur(10px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.order-number-display {
    font-size: 2rem;
    font-weight: 800;
    color: white;
    margin: 0;
    letter-spacing: 1px;
}

.order-date-display {
    color: rgba(255, 255, 255, 0.9);
    margin: 5px 0 0 0;
    font-size: 1rem;
    font-weight: 500;
}

.order-status-modern {
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: flex-end;
}

.status-badge-modern {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 50px;
    font-weight: 700;
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    backdrop-filter: blur(10px);
}

.status-badge-modern.status-pending {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

.status-badge-modern.status-confirmed {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.status-badge-modern.status-on-the-way {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.status-badge-modern.status-delivered {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
}

.status-badge-modern.status-canceled {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.status-dot-icon {
    font-size: 0.6rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.partner-badge-modern {
    background: rgba(255, 255, 255, 0.25);
    color: white;
    padding: 10px 20px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.95rem;
    backdrop-filter: blur(10px);
    border: 2px solid rgba(255, 255, 255, 0.3);
}

/* Information Grid */
.info-grid-modern {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.info-card-modern {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.info-card-modern:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
}

.info-card-header {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #e5e7eb;
}

.info-card-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.info-card-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.info-card-body {
    padding: 20px;
}

.info-item-modern {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f3f4f6;
}

.info-item-modern:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #6b7280;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
}

.info-value {
    font-weight: 600;
    color: #1f2937;
    font-size: 1rem;
    text-align: right;
}

.address-box-modern {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-left: 4px solid #10b981;
    padding: 20px;
    border-radius: 12px;
    display: flex;
    gap: 15px;
    align-items: flex-start;
}

.address-icon {
    color: #10b981;
    font-size: 1.5rem;
    margin-top: 3px;
}

.address-text {
    color: #1f2937;
    font-weight: 500;
    line-height: 1.6;
    margin: 0;
}

.account-code {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
    border: 2px solid #fbbf24;
}

/* Order Items Section */
.order-items-section-modern {
    background: white;
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
}

.section-header-modern {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e5e7eb;
}

.section-icon-modern {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.section-title-modern {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.items-table-modern {
    overflow-x: auto;
}

.modern-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.modern-table thead tr {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.modern-table thead th {
    padding: 15px;
    text-align: left;
    font-weight: 700;
    color: #374151;
    font-size: 0.95rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.modern-table thead th:first-child {
    border-radius: 12px 0 0 0;
}

.modern-table thead th:last-child {
    border-radius: 0 12px 0 0;
}

.modern-table tbody tr {
    transition: background 0.2s ease;
}

.modern-table tbody tr:hover {
    background: #f9fafb;
}

.modern-table tbody td {
    padding: 15px;
    border-bottom: 1px solid #f3f4f6;
    color: #1f2937;
    font-size: 0.95rem;
}

.modern-table tbody tr:last-child td {
    border-bottom: none;
}

.product-name-cell {
    font-weight: 600;
    display: flex;
    align-items: center;
}

.variant-badge {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    padding: 4px 12px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    border: 1px solid #93c5fd;
}

.price-cell, .total-cell {
    font-weight: 700;
    color: #059669;
}

.quantity-badge {
    background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
    color: #6b21a8;
    padding: 4px 12px;
    border-radius: 50px;
    font-weight: 700;
    border: 1px solid #c084fc;
}

/* Order Summary */
.order-summary-modern {
    display: flex;
    justify-content: flex-end;
}

.summary-card {
    background: white;
    border-radius: 16px;
    padding: 25px;
    min-width: 400px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
}

.summary-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 20px 0;
    padding-bottom: 15px;
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    align-items: center;
}

.summary-items {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
}

.summary-label {
    font-weight: 600;
    color: #6b7280;
    font-size: 1rem;
}

.summary-value {
    font-weight: 700;
    color: #1f2937;
    font-size: 1rem;
}

.discount-row {
    background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
    padding: 12px 15px;
    border-radius: 10px;
    margin: 5px 0;
}

.discount-row .summary-label,
.discount-row .summary-value {
    color: #15803d;
}

.summary-divider {
    height: 2px;
    background: linear-gradient(90deg, transparent 0%, #e5e7eb 50%, transparent 100%);
    margin: 10px 0;
}

.total-row {
    background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%);
    padding: 15px;
    border-radius: 12px;
    margin-top: 10px;
}

.total-row .summary-label,
.total-row .summary-value {
    color: white;
    font-size: 1.2rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    .order-details-modern {
        padding: 15px;
    }
    
    .order-header-modern {
        padding: 20px;
    }
    
    .order-header-content {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .order-status-modern {
        align-items: flex-start;
    }
    
    .info-grid-modern {
        grid-template-columns: 1fr;
    }
    
    .summary-card {
        min-width: 100%;
    }
    
    .modern-table {
        font-size: 0.85rem;
    }
    
    .modern-table thead th,
    .modern-table tbody td {
        padding: 10px 8px;
    }
}

/* ===== Orders List View Styles ===== */
.orders-list-container {
    padding: 24px 32px;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

.orders-list-header {
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

.order-checkbox-select-all {
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

.orders-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.order-list-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 16px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.order-list-item:hover {
    transform: translateX(4px);
    box-shadow: 0 8px 24px rgba(0, 88, 163, 0.15);
    border-color: var(--primary-color);
}

.order-checkbox-container {
    display: flex;
    align-items: center;
    padding-left: 4px;
}

.order-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: var(--primary-color);
}

.order-list-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.order-list-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.order-info-primary {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.order-number-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border-radius: 20px;
    font-weight: 700;
    font-size: 0.95rem;
}

.order-info-secondary {
    display: flex;
    align-items: center;
    gap: 16px;
}

.order-date {
    color: #718096;
    font-size: 0.9rem;
    font-weight: 500;
    display: flex;
    align-items: center;
}

.order-list-details {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
}

.order-customer,
.order-amount,
.order-partner {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #374151;
    font-size: 0.9rem;
}

.order-customer i,
.order-amount i,
.order-partner i {
    color: var(--primary-color);
}

.customer-name {
    font-weight: 600;
    margin-right: 8px;
}

.customer-email {
    color: #6b7280;
}

.amount-value {
    font-weight: 700;
    color: var(--primary-color);
    font-size: 1rem;
}

.discount-tag {
    padding: 4px 8px;
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    color: #15803d;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
}

.order-list-actions {
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

.btn-edit-order {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.btn-edit-order:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
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

.order-list-avatar {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.3rem;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    display: none;
}

.order-list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.order-header-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
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

.order-list-header:hover .order-expand-icon {
    background: #e5e7eb;
}

.order-status-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.order-quick-actions {
    display: none; /* Hidden on desktop by default */
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
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.btn-icon-mini.btn-edit-order:hover,
.btn-icon-mini.btn-edit-order:active {
    transform: scale(0.95);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
}

.btn-icon-mini.btn-view-order {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.btn-icon-mini.btn-view-order:hover,
.btn-icon-mini.btn-view-order:active {
    transform: scale(0.95);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
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
    display: none; /* Hidden on desktop */
}

.desktop-only {
    display: flex;
}

.order-detail-item {
    display: flex;
    align-items: center;
    gap: 4px;
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

.order-list-details.collapsed {
    /* Will be overridden on mobile */
}

.order-list-details.expanded {
    /* Will be overridden on mobile */
}

/* Responsive Design for List View */
@media (max-width: 768px) {
    .page-header-card,
    .orders-grid-card {
        margin: 0 -15px;
        border-radius: 0;
    }
    
    /* Hide the full search card on mobile */
    .search-card,
    .filters-card {
        display: none !important;
    }
    
    /* Show bottom mobile search bar (below stats) */
    .mobile-search-bar-bottom {
        display: block !important;
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
    
    /* Hide orders header section on mobile */
    .grid-header {
        display: none !important;
    }
    
    /* Hide grid footer on mobile */
    .grid-footer {
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
        min-height: 130px;
        padding: 16px 10px;
    }
    
    .stats-content-simple h3 {
        font-size: 2.2rem;
        margin-bottom: 6px;
    }
    
    .stats-content-simple p {
        font-size: 0.72rem;
        margin-bottom: 0;
    }
    
    /* Clean list container on mobile */
    .orders-list-container {
        padding: 12px 15px;
        background: #f8f9fa;
    }
    
    /* Show select all header on mobile with inline bulk actions */
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
    
    /* Compact and clean order list items for mobile */
    .orders-list {
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
        from {
            opacity: 0;
            transform: translateX(-10px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    .order-list-item:nth-child(1) { animation-delay: 0.05s; }
    .order-list-item:nth-child(2) { animation-delay: 0.1s; }
    .order-list-item:nth-child(3) { animation-delay: 0.15s; }
    .order-list-item:nth-child(4) { animation-delay: 0.2s; }
    .order-list-item:nth-child(5) { animation-delay: 0.25s; }
    .order-list-item:nth-child(n+6) { animation-delay: 0.3s; }
    
    /* Show checkboxes on mobile */
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
    
    .order-list-content {
        flex: 1;
        min-width: 0;
        gap: 0;
    }
    
    /* Show expand icon on mobile */
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
        display: flex !important; /* Show on mobile */
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
    
    .status-indicator {
        font-size: 0.75rem;
        padding: 4px 10px;
    }
    
    .order-info-secondary {
        width: 100%;
    }
    
    .order-date {
        font-size: 0.8rem;
    }
    
    /* Details collapsed by default on mobile */
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
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
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
    
    /* Hide desktop actions, show mobile actions */
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
        display: inline !important; /* Show on mobile */
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
    
    /* Even smaller stats cards */
    .stats-grid {
        gap: 6px;
    }
    
    .stats-card-simple {
        min-height: 110px;
        padding: 12px 8px;
    }
    
    .stats-content-simple h3 {
        font-size: 1.8rem;
    }
    
    .stats-content-simple p {
        font-size: 0.65rem;
    }
    
    /* Compact mobile search */
    .mobile-search-input {
        padding: 8px 12px;
        font-size: 0.85rem;
    }
    
    .btn-mobile-export {
        width: 40px;
        height: 40px;
        font-size: 0.9rem;
    }
    
    /* Very compact order list items */
    .order-list-item {
        padding: 10px;
        gap: 10px;
    }
    
    .order-list-avatar {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
    }
    
    .order-number-badge {
        font-size: 0.8rem;
        padding: 5px 10px;
    }
    
    .btn-icon-mini {
        width: 28px;
        height: 28px;
        font-size: 0.75rem;
    }
    
    .order-detail-item {
        font-size: 0.8rem;
    }
    
    .btn-icon {
        width: 36px;
        height: 36px;
        font-size: 0.85rem;
    }
    
    .order-actions-mobile .btn-icon {
        max-width: 180px;
        padding: 10px;
    }
    
    .mobile-btn-text {
        font-size: 0.85rem;
    }
}
</style>

<!-- Add html2canvas library for image download -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
// Download order details as image
function downloadOrderAsImage() {
    const button = event.target.closest('button');
    const originalHTML = button.innerHTML;
    
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
    button.disabled = true;
    
    const element = document.getElementById('orderDetailsContent');
    
    // Configure html2canvas options
    html2canvas(element, {
        scale: 2, // Higher quality
        backgroundColor: '#f8f9fa',
        logging: false,
        useCORS: true,
        allowTaint: true,
        scrollY: -window.scrollY,
        scrollX: -window.scrollX,
        windowWidth: element.scrollWidth,
        windowHeight: element.scrollHeight
    }).then(canvas => {
        // Convert canvas to blob
        canvas.toBlob(function(blob) {
            // Create download link
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            
            // Get order number for filename
            const orderNumber = document.querySelector('.order-number-display')?.textContent || 'order';
            link.download = `${orderNumber}_details.png`;
            link.href = url;
            
            // Trigger download
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Clean up
            URL.revokeObjectURL(url);
            
            // Reset button
            button.innerHTML = originalHTML;
            button.disabled = false;
            
            // Show success message
            showToast('Order details downloaded successfully!', 'success');
        }, 'image/png');
    }).catch(error => {
        console.error('Error generating image:', error);
        button.innerHTML = originalHTML;
        button.disabled = false;
        showToast('Error generating image. Please try again.', 'error');
    });
}

// Toast notification function
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? 'linear-gradient(135deg, #10b981 0%, #059669 100%)' : 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)'};
        color: white;
        padding: 15px 25px;
        border-radius: 50px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        z-index: 10000;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideInRight 0.3s ease;
    `;
    
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        ${message}
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => {
            document.body.removeChild(toast);
        }, 300);
    }, 3000);
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Real-time revenue stats update functions
function updateRevenueStats() {
    // Get current filter parameters
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status') || '';
    const partner_id = urlParams.get('partner_id') || '';
    const search = urlParams.get('search') || '';
    
    // Build query string
    const queryParams = new URLSearchParams();
    if (status) queryParams.append('status', status);
    if (partner_id) queryParams.append('partner_id', partner_id);
    if (search) queryParams.append('search', search);
    
    // Fetch updated stats
    fetch(`ajax/get_revenue_stats.php?${queryParams.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update total orders count
                const totalOrdersElement = document.querySelector('.stats-card-simple:nth-child(1) h3');
                if (totalOrdersElement) {
                    totalOrdersElement.textContent = data.stats.totalOrders;
                }
                
                // Update total revenue
                const totalRevenueElement = document.querySelector('.stats-card-simple:nth-child(2) h3');
                if (totalRevenueElement) {
                    totalRevenueElement.textContent = data.stats.totalRevenueFormatted;
                }
                
                // Update pending orders count
                const pendingOrdersElement = document.querySelector('.stats-card-simple:nth-child(3) h3');
                if (pendingOrdersElement) {
                    pendingOrdersElement.textContent = data.stats.pendingCount;
                }
                
                // Update order count in grid header
                const orderCountElement = document.querySelector('.order-count');
                if (orderCountElement) {
                    orderCountElement.textContent = `(${data.stats.totalOrders})`;
                }
                
                // Update grid footer info
                const gridInfoElement = document.querySelector('.grid-info');
                if (gridInfoElement) {
                    gridInfoElement.textContent = `Showing ${data.stats.totalOrders} orders`;
                }
            }
        })
        .catch(error => {
            console.error('Error updating revenue stats:', error);
        });
}

// Update stats when order status is changed
function updateOrderStatusWithStatsRefresh(orderId, newStatus) {
    // First update the order status (existing functionality)
    const form = document.getElementById('editStatusForm');
    const formData = new FormData(form);
    
    fetch('orders.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        // Close the modal
        closeEditStatusModal();
        
        // Show success message
        showToast('Order status updated successfully!', 'success');
        
        // Update the stats in real-time
        setTimeout(() => {
            updateRevenueStats();
        }, 500);
        
        // Reload the page after a short delay to show updated order list
        setTimeout(() => {
            location.reload();
        }, 1500);
    })
    .catch(error => {
        console.error('Error updating order status:', error);
        showToast('Error updating order status. Please try again.', 'error');
    });
}

// Override the existing form submission to include stats refresh
document.addEventListener('DOMContentLoaded', function() {
    const editStatusForm = document.getElementById('editStatusForm');
    if (editStatusForm) {
        editStatusForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const orderId = document.getElementById('editOrderId').value;
            const newStatus = document.getElementById('selectedStatusValue').value;
            
            if (!newStatus) {
                showToast('Please select a status', 'error');
                return;
            }
            
            updateOrderStatusWithStatsRefresh(orderId, newStatus);
        });
    }
    
    // Update stats every 30 seconds for real-time updates
    setInterval(updateRevenueStats, 30000);
});
</script>

<?php require_once 'includes/footer.php'; ?>
