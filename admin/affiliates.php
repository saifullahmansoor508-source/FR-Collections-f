<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$page_title = "Affiliates Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_partner_id':
                $user_id = intval($_POST['user_id']);
                $partner_id_suffix = trim($_POST['partner_id_suffix']);
                $partner_id = 'FR' . $partner_id_suffix;
                
                // Check if partner_id already exists
                $stmt = $db->prepare("SELECT id FROM affiliates WHERE partner_id = ?");
                $stmt->execute([$partner_id]);
                if ($stmt->fetchColumn()) {
                    $error_message = "Partner ID already exists! Please choose a different number.";
                } else {
                    // Check if user already has affiliate account
                    $stmt = $db->prepare("SELECT id FROM affiliates WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    if ($stmt->fetchColumn()) {
                        $error_message = "This user is already an affiliate!";
                    } else {
                        // Create new affiliate
                        $stmt = $db->prepare("INSERT INTO affiliates (user_id, partner_id) VALUES (?, ?)");
                        $stmt->execute([$user_id, $partner_id]);
                        $success_message = "Partner ID assigned successfully!";
                    }
                }
                break;
                
            case 'update_partner_id':
                $affiliate_id = intval($_POST['affiliate_id']);
                $new_partner_id = strtoupper(sanitizeInput($_POST['new_partner_id']));
                
                // Check if new partner_id already exists
                $stmt = $db->prepare("SELECT id FROM affiliates WHERE partner_id = ? AND id != ?");
                $stmt->execute([$new_partner_id, $affiliate_id]);
                if ($stmt->fetchColumn()) {
                    $error_message = "Partner ID already exists! Please choose a different one.";
                } else {
                    $stmt = $db->prepare("UPDATE affiliates SET partner_id = ? WHERE id = ?");
                    $stmt->execute([$new_partner_id, $affiliate_id]);
                    
                    // Also update orders table
                    $stmt = $db->prepare("SELECT partner_id FROM affiliates WHERE id = ?");
                    $stmt->execute([$affiliate_id]);
                    $old_partner_id = $stmt->fetchColumn();
                    
                    $success_message = "Partner ID updated successfully!";
                }
                break;
        }
    }
}

// Get filters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$filter_partner = isset($_GET['partner_id']) ? sanitizeInput($_GET['partner_id']) : '';
$filter_from = isset($_GET['from']) ? sanitizeInput($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? sanitizeInput($_GET['to']) : '';

// Build query
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR a.partner_id LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Filter by specific partner id
if ($filter_partner !== '') {
    $where_conditions[] = "a.partner_id = ?";
    $params[] = $filter_partner;
}

// Filter by created_at date interval
if ($filter_from !== '' && $filter_to !== '') {
    $where_conditions[] = "DATE(a.created_at) BETWEEN ? AND ?";
    $params[] = $filter_from;
    $params[] = $filter_to;
} elseif ($filter_from !== '') {
    $where_conditions[] = "DATE(a.created_at) >= ?";
    $params[] = $filter_from;
} elseif ($filter_to !== '') {
    $where_conditions[] = "DATE(a.created_at) <= ?";
    $params[] = $filter_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get all affiliates with their sales data and commission calculations
$stmt = $db->prepare("
    SELECT a.*, u.full_name, u.email, u.phone,
           COUNT(DISTINCT CASE WHEN o.status IN ('Confirmed', 'On The Way', 'Delivered') THEN o.id END) as total_orders,
           COUNT(DISTINCT CASE WHEN o.status IN ('Confirmed', 'On The Way', 'Delivered') THEN o.id END) as total_sales,
           a.balance as current_balance,
           a.total_earnings as lifetime_earnings,
           a.total_revenue
    FROM affiliates a 
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN orders o ON o.partner_id = a.partner_id
    $where_clause
    GROUP BY a.id
    ORDER BY a.created_at DESC
");
$stmt->execute($params);
$affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate available balance, total earnings, and pending commissions for each affiliate
foreach ($affiliates as &$affiliate) {
    // Calculate available balance DYNAMICALLY from database for ALL orders with this partner_id
    // This reads actual commission_amount from order_items which was calculated during checkout
    // Works for confirmed orders only (Confirmed, On The Way, Delivered)
    
    // STEP 1: Get all confirmed orders with this partner_id
    $stmt = $db->prepare("
        SELECT DISTINCT o.id
        FROM orders o
        WHERE o.partner_id = ? 
        AND o.status IN ('Confirmed', 'On The Way', 'Delivered')
    ");
    $stmt->execute([$affiliate['partner_id']]);
    $confirmed_orders = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // STEP 2: For each order, sum up the commission from order_items
    $available_balance = 0;
    
    foreach ($confirmed_orders as $order_id) {
        $stmt = $db->prepare("
            SELECT SUM(commission_amount) as order_commission
            FROM order_items
            WHERE order_id = ?
            AND commission_amount > 0
        ");
        $stmt->execute([$order_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['order_commission']) {
            $available_balance += floatval($result['order_commission']);
        }
    }
    
    // Calculate total withdrawn amount
    $stmt = $db->prepare("
        SELECT SUM(amount) as total_withdrawn 
        FROM withdrawals 
        WHERE user_id = ? AND status = 'Completed'
    ");
    $stmt->execute([$affiliate['user_id']]);
    $withdrawn_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_withdrawn = $withdrawn_data['total_withdrawn'] ? floatval($withdrawn_data['total_withdrawn']) : 0;
    
    // Calculate total earnings (available balance + withdrawn)
    $total_earnings = $available_balance + $total_withdrawn;
    
    // Update affiliate data with calculated values
    $affiliate['current_balance'] = $available_balance;
    $affiliate['lifetime_earnings'] = $total_earnings;
    
    // Calculate pending commissions (from Confirmed and On The Way orders only)
    // These are orders that are confirmed but not yet delivered
    $stmt = $db->prepare("
        SELECT DISTINCT o.id
        FROM orders o
        WHERE o.partner_id = ? 
        AND o.status IN ('Confirmed', 'On The Way')
    ");
    $stmt->execute([$affiliate['partner_id']]);
    $pending_orders = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $pending_commission = 0;
    foreach ($pending_orders as $order_id) {
        $stmt = $db->prepare("
            SELECT SUM(commission_amount) as order_commission
            FROM order_items
            WHERE order_id = ?
            AND commission_amount > 0
        ");
        $stmt->execute([$order_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['order_commission']) {
            $pending_commission += floatval($result['order_commission']);
        }
    }
    
    $affiliate['pending_commission'] = $pending_commission;
}
unset($affiliate);

// Get stats
$stmt = $db->prepare("SELECT COUNT(*) FROM affiliates");
$stmt->execute();
$total_affiliates = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id NOT IN (SELECT user_id FROM affiliates)");
$stmt->execute();
$available_users = $stmt->fetchColumn();

// Get all non-affiliate users for assignment
$stmt = $db->prepare("
    SELECT id, full_name, email FROM users 
    WHERE id NOT IN (SELECT user_id FROM affiliates)
    ORDER BY full_name
");
$stmt->execute();
$available_users_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get list of all partner IDs for filter dropdown
$stmt = $db->prepare("SELECT partner_id FROM affiliates ORDER BY partner_id");
$stmt->execute();
$all_partner_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

require_once 'includes/header.php';
?>

<?php $total_sales_all = 0; foreach ($affiliates as $a) { $total_sales_all += (int)$a['total_sales']; } ?>

<!-- Page Header with Stats -->
    <div class="row mb-4">
        <div class="col-12">
        <div class="page-header-card">
            <div class="page-header-content">
                <div class="page-header-icon">
                    <i class="fas fa-handshake"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Affiliates Management</h1>
                    <p class="page-subtitle">Manage partners, track sales and commissions</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($total_affiliates); ?></h3>
                        <p>Total Affiliates</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($available_users); ?></h3>
                        <p>Assignable Users</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($total_sales_all); ?></h3>
                        <p>Total Sales Count</p>
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
                    <i class="fas fa-search me-2"></i>Find Affiliates
                </div>
                <div class="search-actions">
                    <a href="affiliates.php" class="btn btn-clear-modern">
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
                    <div class="custom-dropdown-modern" id="partnerDropdown">
                        <div class="dropdown-selected-modern" onclick="toggleModernDropdown('partnerDropdown')">
                            <span id="selectedPartner">
                                <?php echo $filter_partner ? htmlspecialchars($filter_partner) : 'All Partners'; ?>
                            </span>
                            <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                        </div>
                        <div class="dropdown-options-modern">
                            <div class="dropdown-option-modern <?php echo !$filter_partner ? 'selected' : ''; ?>" onclick="selectPartner('', 'All Partners')">
                                <i class="fas fa-users me-2"></i>All Partners
                            </div>
                            <?php foreach ($all_partner_ids as $pid): ?>
                                <div class="dropdown-option-modern <?php echo $filter_partner === $pid ? 'selected' : ''; ?>" onclick="selectPartner('<?php echo htmlspecialchars($pid); ?>', '<?php echo htmlspecialchars($pid); ?>')">
                                    <i class="fas fa-handshake me-2"></i><?php echo htmlspecialchars($pid); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="hidden" name="partner_id" id="partnerInput" value="<?php echo htmlspecialchars($filter_partner); ?>">

                    <!-- Assign Partner ID Button -->
                    <button type="button" class="btn-assign-partner-id" onclick="openUserSelectionModal()">
                        <i class="fas fa-plus-circle"></i>
                        <span>Assign Partner ID</span>
                    </button>

                    <div class="filter-dates">
                        <label class="filter-label">From</label>
                        <div class="date-input">
                            <i class="fas fa-calendar-alt date-icon"></i>
                            <input type="text" name="from" class="filter-date datepicker" placeholder="From date" value="<?php echo htmlspecialchars($filter_from); ?>">
                        </div>
                        <label class="filter-label">To</label>
                        <div class="date-input">
                            <i class="fas fa-calendar-alt date-icon"></i>
                            <input type="text" name="to" class="filter-date datepicker" placeholder="To date" value="<?php echo htmlspecialchars($filter_to); ?>">
                        </div>
                    </div>

                    <a href="affiliates.php" class="btn btn-clear-modern">Clear</a>
                        </div>
                    </form>
            </div>
        </div>
    </div>

<!-- Affiliates Grid -->
    <div class="row">
        <div class="col-12">
        <div class="affiliates-container">
            <div class="users-header">
                <div class="users-header-content">
                    <div class="users-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="users-title">
                        <h4>Affiliates</h4>
                        <span class="users-count"><?php echo count($affiliates); ?> found</span>
                    </div>
                </div>
                <div class="users-actions">
                    <button class="btn btn-refresh-modern" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh List
                    </button>
                </div>
            </div>

                    <?php if (empty($affiliates)): ?>
                <div class="empty-users">
                    <div class="empty-users-icon"><i class="fas fa-handshake"></i></div>
                    <h5>No affiliates found</h5>
                    <p>Try adjusting your search terms.</p>
                    <a href="affiliates.php" class="btn btn-primary-modern"><i class="fas fa-redo me-2"></i>Clear Search</a>
                        </div>
                    <?php else: ?>
                <!-- Mobile-friendly list view (same as users.php) -->
                <div class="users-list-container">
                    <div class="users-list">
                        <?php foreach ($affiliates as $affiliate): ?>
                            <div class="user-list-item" data-affiliate-id="<?php echo $affiliate['id']; ?>">
                                <div class="user-list-avatar">
                                    <?php echo strtoupper(substr($affiliate['full_name'], 0, 1)); ?>
                                </div>
                                
                                <div class="user-list-content">
                                    <div class="user-list-header" onclick="toggleAffiliateDetails(<?php echo $affiliate['id']; ?>)">
                                        <div class="user-header-info">
                                            <div class="user-info-primary">
                                                <span class="user-name"><?php echo htmlspecialchars($affiliate['full_name']); ?></span>
                                                <div class="user-badges">
                                                    <span class="status-badge affiliate-badge">
                                                        <i class="fas fa-id-badge me-1"></i><?php echo htmlspecialchars($affiliate['partner_id']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="user-info-secondary">
                                                <span class="user-joined">
                                                    <i class="fas fa-calendar-plus me-1"></i>
                                                    <?php echo date('M d, Y', strtotime($affiliate['created_at'])); ?>
                                                </span>
                                                <span class="user-sales-badge">
                                                    <i class="fas fa-shopping-cart me-1"></i>
                                                    <?php echo $affiliate['total_orders']; ?> sales
                                                </span>
                                            </div>
                                        </div>
                                        <div class="user-expand-icon">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    
                                    <div class="user-list-details collapsed" id="affiliate-details-<?php echo $affiliate['id']; ?>">
                                        <div class="user-detail-item">
                                            <i class="fas fa-envelope me-2"></i>
                                            <span><?php echo htmlspecialchars($affiliate['email']); ?></span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-phone me-2"></i>
                                            <span><?php echo $affiliate['phone'] ? htmlspecialchars($affiliate['phone']) : 'Not provided'; ?></span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-shopping-cart me-2"></i>
                                            <span class="stat-value"><?php echo number_format($affiliate['total_orders']); ?> orders</span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-chart-bar me-2"></i>
                                            <span class="stat-value"><?php echo number_format($affiliate['total_sales']); ?> sales</span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-coins me-2"></i>
                                            <span class="stat-value">Rs <?php echo number_format($affiliate['lifetime_earnings'], 0); ?> total earnings</span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-wallet me-2"></i>
                                            <span class="stat-value">Rs <?php echo number_format($affiliate['current_balance'], 0); ?> available</span>
                                        </div>
                                        
                                        <div class="user-detail-item">
                                            <i class="fas fa-clock me-2"></i>
                                            <span class="stat-value">Rs <?php echo number_format($affiliate['pending_commission'], 0); ?> pending</span>
                                        </div>
                                        
                                        <div class="user-actions-mobile">
                                            <a class="btn-icon btn-view-user" href="orders.php?partner_id=<?php echo urlencode($affiliate['partner_id']); ?>" title="View Orders">
                                                <i class="fas fa-shopping-cart"></i>
                                            </a>
                                            <a class="btn-icon btn-view-user" href="withdrawals.php?user_id=<?php echo $affiliate['user_id']; ?>" title="View Withdrawals">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
                                            <button class="btn-icon btn-delete-user" onclick="event.stopPropagation(); editPartnerId(<?php echo $affiliate['id']; ?>, '<?php echo htmlspecialchars($affiliate['partner_id']); ?>')" title="Edit Partner ID">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="user-list-actions desktop-only">
                                    <a class="btn-icon btn-view-user" href="orders.php?partner_id=<?php echo urlencode($affiliate['partner_id']); ?>" title="View Orders">
                                        <i class="fas fa-shopping-cart"></i>
                                    </a>
                                    <a class="btn-icon btn-view-user" href="withdrawals.php?user_id=<?php echo $affiliate['user_id']; ?>" title="View Withdrawals">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </a>
                                    <button class="btn-icon btn-delete-user" onclick="editPartnerId(<?php echo $affiliate['id']; ?>, '<?php echo htmlspecialchars($affiliate['partner_id']); ?>')" title="Edit Partner ID">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Assign Partner ID Modal -->
<div class="modal fade" id="assignPartnerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Partner ID</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign_partner_id">
                    <input type="hidden" name="user_id" id="selected_user_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Selected User</label>
                        <input type="text" class="form-control" id="selected_user_display" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Partner ID</label>
                        <div class="input-group">
                            <span class="input-group-text" id="fr-prefix">FR</span>
                            <input type="text" name="partner_id_suffix" class="form-control" 
                                   placeholder="Enter number (e.g., 001, 123)" 
                                   pattern="[0-9]+" 
                                   maxlength="10" 
                                   required>
                        </div>
                        <div class="form-text">The partner ID will be: FR + your number</div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Note:</strong> "FR" prefix is fixed and cannot be changed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign Partner ID</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- User Selection Modal -->
<div class="modal fade" id="userSelectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select User to Assign Partner ID</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (!empty($available_users_list)): ?>
                    <div class="user-selection-list">
                        <?php foreach ($available_users_list as $user): ?>
                            <div class="user-selection-item" onclick="selectUserForPartnerId(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>', '<?php echo htmlspecialchars($user['email']); ?>')">
                                <div class="user-avatar-selection">
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                </div>
                                <div class="user-info-selection">
                                    <div class="user-name-selection"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div class="user-email-selection"><?php echo htmlspecialchars($user['email']); ?></div>
                                </div>
                                <div class="selection-indicator">
                                    <i class="fas fa-chevron-right"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        All users are already assigned as affiliates!
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Partner ID Modal -->
<div class="modal fade" id="editPartnerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Update Partner ID</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_partner_id">
                    <input type="hidden" name="affiliate_id" id="edit_affiliate_id">
                    <div class="mb-3">
                        <label class="form-label">Current Partner ID</label>
                        <input type="text" class="form-control" id="current_partner_id" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Partner ID</label>
                        <input type="text" name="new_partner_id" class="form-control" 
                               placeholder="Enter new partner ID" 
                               pattern="[A-Za-z0-9]+" 
                               maxlength="10" 
                               required>
                        <div class="form-text">Enter a unique partner ID (letters and numbers only, max 10 characters)</div>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> Changing the Partner ID will affect future orders. Past orders will still show the old ID.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Partner ID</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editPartnerId(affiliateId, currentPartnerId) {
    document.getElementById('edit_affiliate_id').value = affiliateId;
    document.getElementById('current_partner_id').value = currentPartnerId;
    var modal = new bootstrap.Modal(document.getElementById('editPartnerModal'));
    modal.show();
}

// New functions for the assign partner ID flow
function openUserSelectionModal() {
    var modal = new bootstrap.Modal(document.getElementById('userSelectionModal'));
    modal.show();
}

function selectUserForPartnerId(userId, userName, userEmail) {
    // Close the user selection modal
    var userSelectionModal = bootstrap.Modal.getInstance(document.getElementById('userSelectionModal'));
    userSelectionModal.hide();
    
    // Set the selected user in the assign modal
    document.getElementById('selected_user_id').value = userId;
    document.getElementById('selected_user_display').value = userName + ' (' + userEmail + ')';
    
    // Open the assign modal
    setTimeout(function() {
        var assignModal = new bootstrap.Modal(document.getElementById('assignPartnerModal'));
        assignModal.show();
    }, 300); // Small delay to ensure smooth transition
}

// Enhance interactions similar to Users tab
document.addEventListener('DOMContentLoaded', function() {
    // Tooltip init
    if (window.$) { $('[title]').tooltip && $('[title]').tooltip(); }

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

    // Initialize Flatpickr on date inputs
    var initFlatpickr = function(){
        if (!window.flatpickr) return;
        var options = {
            altInput: true,
            altFormat: 'M j, Y',
            dateFormat: 'Y-m-d',
            allowInput: true,
            disableMobile: true,
            animate: true,
            monthSelectorType: 'static',
            onClose: function() {
                var form = document.querySelector('.search-form');
                if (form) { form.submit(); }
            }
        };
        try {
            window.flatpickr('input[name="from"]', options);
            window.flatpickr('input[name="to"]', options);
        } catch(e) { /* no-op */ }
    };

    if (document.readyState === 'complete' || window.flatpickrLoaded) {
        initFlatpickr();
    } else {
        var checkFp = setInterval(function(){
            if (window.flatpickr) { clearInterval(checkFp); initFlatpickr(); }
        }, 100);
        setTimeout(function(){ clearInterval(checkFp); initFlatpickr(); }, 4000);
    }
});

// Modern Dropdown Functions (matched with Orders page)
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

function selectPartner(value, text) {
    document.getElementById('selectedPartner').textContent = text;
    document.getElementById('partnerInput').value = value;
    document.getElementById('partnerDropdown').classList.remove('active');

    // Update selected state
    document.querySelectorAll('#partnerDropdown .dropdown-option-modern').forEach(function(option) {
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

// Toggle affiliate details dropdown (mobile) - same as users.php
function toggleAffiliateDetails(affiliateId) {
    const detailsElement = document.getElementById('affiliate-details-' + affiliateId);
    const affiliateItem = document.querySelector('[data-affiliate-id="' + affiliateId + '"]');
    const expandIcon = affiliateItem.querySelector('.user-expand-icon i');
    
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

// Flatpickr (Calendar)
if (!window.flatpickrLoaded) {
    var fpScript = document.createElement('script');
    fpScript.src = 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js';
    fpScript.defer = true;
    fpScript.onload = function(){ window.flatpickrLoaded = true; };
    document.head.appendChild(fpScript);
}

</script>

<style>
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
/* Modern Dropdowns (matched with Orders page) */
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

/* Header card (reused styling) */
.page-header-card { background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border-radius: 12px; padding: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; min-height: 1px; }
.page-header-card::before { content:''; position:absolute; top:0; right:0; width:120px; height:120px; background:linear-gradient(135deg, rgba(0,88,163,.07) 0%, rgba(255,107,0,.07) 100%); border-radius:50%; transform: translate(80px,-80px); }
.page-header-content { display:flex; align-items:center; position:relative; z-index:1; }
.page-header-icon { width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.15rem; margin-right:12px; box-shadow:0 4px 14px rgba(0,88,163,.2); }
.page-header-text h1 { font-size:1.2rem; font-weight:600; color:#2d3748; margin-bottom:2px; }
.page-header-text p { color:#718096; font-size:.82rem; margin:0; }
.page-header-stats { display:flex; gap:14px; position:relative; z-index:1; align-items:center; }
.stat-item { text-align:center; }
.stat-number { font-size:1.1rem; font-weight:600; color:var(--primary-color); display:block; line-height:1; }
.stat-label { font-size:.68rem; color:#718096; font-weight:600; text-transform:uppercase; letter-spacing:.2px; margin-top:2px; }

/* Search card (reused) */
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
.affiliate-badge { background:linear-gradient(135deg, #f59e0b, #d97706); }
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

/* Assign Partner ID Button */
.btn-assign-partner-id {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 180px;
    justify-content: center;
}

.btn-assign-partner-id:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    color: white;
}

.btn-assign-partner-id i {
    font-size: 1rem;
}

/* User Selection Modal Styles */
.user-selection-list {
    max-height: 400px;
    overflow-y: auto;
}

.user-selection-item {
    display: flex;
    align-items: center;
    padding: 16px;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.user-selection-item:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.15);
    transform: translateX(4px);
}

.user-avatar-selection {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.2rem;
    margin-right: 16px;
    flex-shrink: 0;
}

.user-info-selection {
    flex: 1;
}

.user-name-selection {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 4px;
    font-size: 1rem;
}

.user-email-selection {
    color: #718096;
    font-size: 0.9rem;
}

.selection-indicator {
    color: var(--primary-color);
    font-size: 1.2rem;
    transition: transform 0.3s ease;
}

.user-selection-item:hover .selection-indicator {
    transform: translateX(4px);
}

/* ========================================
   USER LIST STYLES (FROM USERS.PHP)
   ======================================== */
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
}

.user-header-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.user-expand-icon {
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

.user-expand-icon i {
    color: #6b7280;
    font-size: 0.9rem;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.user-list-header:hover .user-expand-icon {
    background: #e5e7eb;
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

.user-list-details.collapsed {
    /* Will be overridden on mobile */
}

.user-list-details.expanded {
    /* Will be overridden on mobile */
}

.user-actions-mobile {
    display: none;
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

/* Mobile Responsive Styles */
@media (max-width: 768px) {
    /* Hide the full search card on mobile */
    .search-card {
        display: none !important;
    }
    
    /* Hide users header section (Affiliates and Refresh) on mobile */
    .users-header {
        display: none !important;
    }
    
    /* Adjust stats cards to display in a row like users page */
    .stats-grid {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 8px !important;
        width: 100%;
    }
    
    .stats-card-simple {
        min-height: 120px !important;
        padding: 16px 8px !important;
        aspect-ratio: 1 / 1 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    .stats-content-simple h3 {
        font-size: 1.5rem !important;
        margin-bottom: 6px !important;
    }
    
    .stats-content-simple p {
        font-size: 0.75rem !important;
        margin-bottom: 0 !important;
    }
    
    .users-list-container {
        padding: 12px 15px;
        background: #f8f9fa;
    }
    
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
    
    .user-list-avatar {
        width: 45px;
        height: 45px;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    
    .user-list-content {
        flex: 1;
        min-width: 0;
        gap: 0;
    }
    
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
    
    .user-list-details {
        flex-direction: column;
        gap: 8px;
        padding-top: 12px;
        margin-top: 0;
    }
    
    .user-list-details.collapsed {
        display: none !important;
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
    /* Even smaller stats cards for very small screens */
    .stats-grid {
        gap: 6px !important;
    }
    
    .stats-card-simple {
        min-height: 100px !important;
        padding: 12px 6px !important;
        aspect-ratio: 1 / 1 !important;
    }
    
    .stats-content-simple h3 {
        font-size: 1.2rem !important;
    }
    
    .stats-content-simple p {
        font-size: 0.65rem !important;
    }
    
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
</style>

<script>
// Real-time affiliate stats update functions
function updateAffiliateStats() {
    // Get current filter parameters
    const urlParams = new URLSearchParams(window.location.search);
    const search = urlParams.get('search') || '';
    const partner_id = urlParams.get('partner_id') || '';
    const from = urlParams.get('from') || '';
    const to = urlParams.get('to') || '';
    
    // Build query string
    const queryParams = new URLSearchParams();
    if (search) queryParams.append('search', search);
    if (partner_id) queryParams.append('partner_id', partner_id);
    if (from) queryParams.append('from', from);
    if (to) queryParams.append('to', to);
    
    // Fetch updated stats
    fetch(`ajax/get_affiliate_stats.php?${queryParams.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update total affiliates count
                const totalAffiliatesElement = document.querySelector('.stats-card-simple:nth-child(1) h3');
                if (totalAffiliatesElement) {
                    totalAffiliatesElement.textContent = data.stats.totalAffiliates.toLocaleString();
                }
                
                // Update assignable users count
                const assignableUsersElement = document.querySelector('.stats-card-simple:nth-child(2) h3');
                if (assignableUsersElement) {
                    assignableUsersElement.textContent = data.stats.availableUsers.toLocaleString();
                }
                
                // Update total sales count
                const totalSalesElement = document.querySelector('.stats-card-simple:nth-child(3) h3');
                if (totalSalesElement) {
                    totalSalesElement.textContent = data.stats.totalSalesCountFormatted;
                }
                
                // Update individual affiliate sales counts
                data.stats.affiliates.forEach(affiliate => {
                    const salesElement = document.querySelector(`#affiliate-details-${affiliate.id} .user-detail-item:nth-child(4) .stat-value`);
                    if (salesElement) {
                        salesElement.textContent = `${affiliate.total_sales_formatted} sales`;
                    }
                    
                    // Also update the header sales badge
                    const salesBadgeElement = document.querySelector(`[data-affiliate-id="${affiliate.id}"] .user-sales-badge`);
                    if (salesBadgeElement) {
                        salesBadgeElement.innerHTML = `<i class="fas fa-shopping-cart me-1"></i>${affiliate.total_sales} sales`;
                    }
                });
                
                // Update affiliates count in header
                const affiliatesCountElement = document.querySelector('.users-count');
                if (affiliatesCountElement) {
                    affiliatesCountElement.textContent = `${data.stats.totalAffiliates} found`;
                }
            }
        })
        .catch(error => {
            console.error('Error updating affiliate stats:', error);
        });
}

// Toggle affiliate details function (if not already defined)
function toggleAffiliateDetails(affiliateId) {
    const details = document.getElementById('affiliate-details-' + affiliateId);
    const icon = document.querySelector(`[data-affiliate-id="${affiliateId}"] .user-expand-icon i`);
    
    if (details && icon) {
        if (details.classList.contains('collapsed')) {
            details.classList.remove('collapsed');
            icon.style.transform = 'rotate(180deg)';
        } else {
            details.classList.add('collapsed');
            icon.style.transform = 'rotate(0deg)';
        }
    }
}

// Initialize real-time updates
document.addEventListener('DOMContentLoaded', function() {
    // Update stats every 30 seconds for real-time updates
    setInterval(updateAffiliateStats, 30000);
    
    // Add click handlers for affiliate details if not already added
    document.querySelectorAll('.user-list-header').forEach(header => {
        if (!header.hasAttribute('data-click-handler')) {
            header.setAttribute('data-click-handler', 'true');
            header.addEventListener('click', function() {
                const affiliateId = this.closest('.user-list-item').getAttribute('data-affiliate-id');
                if (affiliateId) {
                    toggleAffiliateDetails(affiliateId);
                }
            });
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
