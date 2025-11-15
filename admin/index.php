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

// Get dashboard stats
$stats = [];

// Total Orders
$stmt = $db->prepare("SELECT COUNT(*) as total FROM orders");
$stmt->execute();
$stats['total_orders'] = $stmt->fetchColumn();

// Total Revenue
$stmt = $db->prepare("SELECT SUM(total_amount) as total FROM orders WHERE status = 'Delivered'");
$stmt->execute();
$stats['total_revenue'] = floatval($stmt->fetchColumn());

// Total Users
$stmt = $db->prepare("SELECT COUNT(*) as total FROM users");
$stmt->execute();
$stats['total_users'] = $stmt->fetchColumn();

// Total Affiliates
$stmt = $db->prepare("SELECT COUNT(*) as total FROM affiliates");
$stmt->execute();
$stats['total_affiliates'] = $stmt->fetchColumn();

// Total Products
$stmt = $db->prepare("SELECT COUNT(*) as total FROM products");
$stmt->execute();
$stats['total_products'] = $stmt->fetchColumn();

// Recent Users
$stmt = $db->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Orders
$stmt = $db->prepare("SELECT * FROM orders ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Affiliates
$stmt = $db->prepare("SELECT a.*, u.full_name, u.email FROM affiliates a JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 5");
$stmt->execute();
$recent_affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pending Withdrawals
$stmt = $db->prepare("SELECT w.*, u.full_name, u.email FROM withdrawals w JOIN users u ON w.user_id = u.id WHERE w.status = 'Pending' ORDER BY w.created_at DESC LIMIT 5");
$stmt->execute();
$pending_withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Contacts
$stmt = $db->prepare("SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Product Requests
$stmt = $db->prepare("SELECT pr.*, u.full_name, u.email FROM product_requests pr JOIN users u ON pr.user_id = u.id ORDER BY pr.created_at DESC LIMIT 5");
$stmt->execute();
$recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Dashboard";
require_once 'includes/header.php';
?>

<style>
/* ========================================
   DASHBOARD STATS CARDS - MOBILE RESPONSIVE
   ======================================== */

/* Mobile View - 2x2 Grid with Glossy Animated Look */
@media (max-width: 768px) {
    /* Hide the row structure and create custom grid */
    .stats-cards-mobile-wrapper {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 12px !important;
        margin-bottom: 24px !important;
    }
    
    /* Stats Card - Glossy Animated Style */
    .stats-card-simple {
        background: var(--card-gradient, linear-gradient(135deg, #667eea 0%, #764ba2 100%)) !important;
        border-radius: 16px !important;
        padding: 20px 16px !important;
        min-height: 120px !important;
        position: relative !important;
        overflow: hidden !important;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15) !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        border: none !important;
    }
    
    /* Glossy overlay effect */
    .stats-card-simple::before {
        content: '' !important;
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        height: 50% !important;
        background: linear-gradient(
            to bottom,
            rgba(255, 255, 255, 0.3) 0%,
            rgba(255, 255, 255, 0) 100%
        ) !important;
        border-radius: 16px 16px 0 0 !important;
        pointer-events: none !important;
        z-index: 1 !important;
    }
    
    /* Animated shine effect */
    .stats-card-simple::after {
        content: '' !important;
        position: absolute !important;
        top: -50% !important;
        left: -50% !important;
        width: 200% !important;
        height: 200% !important;
        background: linear-gradient(
            45deg,
            transparent 30%,
            rgba(255, 255, 255, 0.3) 50%,
            transparent 70%
        ) !important;
        transform: rotate(45deg) !important;
        animation: cardShine 3s infinite !important;
        pointer-events: none !important;
        z-index: 2 !important;
    }
    
    @keyframes cardShine {
        0% {
            transform: translateX(-100%) translateY(-100%) rotate(45deg);
        }
        100% {
            transform: translateX(100%) translateY(100%) rotate(45deg);
        }
    }
    
    /* Hover effect */
    .stats-card-simple:active {
        transform: scale(0.98) !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2) !important;
    }
    
    /* Content styling */
    .stats-content-simple {
        position: relative !important;
        z-index: 3 !important;
        text-align: center !important;
        color: white !important;
    }
    
    .stats-content-simple h3 {
        font-size: 2.2rem !important;
        font-weight: 800 !important;
        color: white !important;
        margin-bottom: 8px !important;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2) !important;
        line-height: 1 !important;
    }
    
    .stats-content-simple p {
        font-size: 0.75rem !important;
        font-weight: 600 !important;
        color: rgba(255, 255, 255, 0.95) !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        margin: 0 !important;
        text-shadow: 0 1px 4px rgba(0, 0, 0, 0.15) !important;
    }
    
    /* Hide original Bootstrap columns on mobile */
    .row.mb-4 > [class*="col-"] {
        display: none !important;
    }
    
    /* Show mobile wrapper */
    .stats-cards-mobile-wrapper {
        display: grid !important;
    }
}

/* Desktop - Keep original layout */
@media (min-width: 769px) {
    .stats-cards-mobile-wrapper {
        display: none !important;
    }
}

/* ========================================
   SHINY GRADIENT EYE ICON - VIEW ALL REPLACEMENT
   ======================================== */
.view-all-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1rem;
    text-decoration: none;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
}

/* Glossy overlay */
.view-all-icon::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(
        to bottom,
        rgba(255, 255, 255, 0.3) 0%,
        rgba(255, 255, 255, 0) 100%
    );
    border-radius: 10px 10px 0 0;
    pointer-events: none;
}

/* Shine animation */
.view-all-icon::after {
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
    animation: iconShineEffect 2.5s infinite;
    pointer-events: none;
}

@keyframes iconShineEffect {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

.view-all-icon:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.view-all-icon:active {
    transform: scale(0.95);
}

.view-all-icon i {
    position: relative;
    z-index: 1;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}
</style>

<div class="row">
    <div class="col-12 mb-4">
        <h1 class="text-primary-custom">Dashboard</h1>
        <p class="text-muted">Welcome to FR Collections Admin Panel</p>
    </div>
</div>

<!-- Stats Cards - Simple Original Design -->
<div class="row mb-4">
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12 mb-4">
        <div class="stats-card-simple">
            <div class="stats-content-simple">
                <h3><?php echo number_format($stats['total_orders']); ?></h3>
                <p>Total Orders</p>
            </div>
        </div>
    </div>
    
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12 mb-4">
        <div class="stats-card-simple">
            <div class="stats-content-simple">
                <h3><?php echo formatPrice($stats['total_revenue']); ?></h3>
                <p>Total Revenue</p>
            </div>
        </div>
    </div>
    
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12 mb-4">
        <div class="stats-card-simple">
            <div class="stats-content-simple">
                <h3><?php echo number_format($stats['total_users']); ?></h3>
                <p>Total Users</p>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
        <div class="stats-card-simple">
            <div class="stats-content-simple">
                <h3><?php echo number_format($stats['total_affiliates']); ?></h3>
                <p>Total Affiliates</p>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
        <div class="stats-card-simple">
            <div class="stats-content-simple">
                <h3><?php echo number_format($stats['total_products']); ?></h3>
                <p>Total Products</p>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Stats Cards - 3x2 Grid with Glossy Look -->
<div class="stats-cards-mobile-wrapper">
    <div class="stats-card-simple" style="--card-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="stats-content-simple">
            <h3><?php echo number_format($stats['total_orders']); ?></h3>
            <p>Total Orders</p>
        </div>
    </div>
    
    <div class="stats-card-simple" style="--card-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
        <div class="stats-content-simple">
            <h3><?php echo formatPrice($stats['total_revenue']); ?></h3>
            <p>Total Revenue</p>
        </div>
    </div>
    
    <div class="stats-card-simple" style="--card-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
        <div class="stats-content-simple">
            <h3><?php echo number_format($stats['total_users']); ?></h3>
            <p>Total Users</p>
        </div>
    </div>
    
    <div class="stats-card-simple" style="--card-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
        <div class="stats-content-simple">
            <h3><?php echo number_format($stats['total_affiliates']); ?></h3>
            <p>Total Affiliates</p>
        </div>
    </div>
    
    <div class="stats-card-simple" style="--card-gradient: linear-gradient(135deg, #fa709a 0%, #f7971e 100%);">
        <div class="stats-content-simple">
            <h3><?php echo number_format($stats['total_products']); ?></h3>
            <p>Total Products</p>
        </div>
    </div>
    
    <div class="stats-card-simple" style="--card-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="stats-content-simple">
            <h3><?php echo count($pending_withdrawals); ?></h3>
            <p>Pending Withdrawals</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="quick-actions-card">
            <h5 class="mb-3">
                <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
            </h5>
            <div class="quick-actions-grid">
                <a href="add-product.php" class="quick-action-btn">
                    <i class="fas fa-plus-circle"></i>
                    <span>Add Product</span>
                </a>
                <a href="orders.php" class="quick-action-btn">
                    <i class="fas fa-shopping-cart"></i>
                    <span>View Orders</span>
                </a>
                <a href="users.php" class="quick-action-btn">
                    <i class="fas fa-users"></i>
                    <span>Manage Users</span>
                </a>
                <a href="withdrawals.php" class="quick-action-btn">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Withdrawals</span>
                </a>
                <a href="product-requests.php" class="quick-action-btn">
                    <i class="fas fa-plus-circle"></i>
                    <span>Product Requests</span>
                </a>
                <a href="contact.php" class="quick-action-btn">
                    <i class="fas fa-envelope"></i>
                    <span>Messages</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Recent Data - Enhanced Cards -->
<div class="row">
    <!-- Recent Orders -->
    <div class="col-lg-6 mb-4">
        <div class="data-card">
            <div class="card-header-custom collapsible-header" data-target="recent-orders">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h5 class="mb-0">
                        <i class="fas fa-shopping-cart text-primary me-2"></i>Recent Orders
                    </h5>
                    <div class="d-flex align-items-center gap-3">
                        <a href="orders.php" class="view-all-icon" onclick="event.stopPropagation();" title="View All Orders">
                            <i class="fas fa-eye"></i>
                        </a>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
            </div>
            <div class="card-body-custom collapsible-content collapsed" id="recent-orders">
                <?php if (!empty($recent_orders)): ?>
                    <div class="recent-items">
                        <?php foreach ($recent_orders as $order): ?>
                            <div class="recent-item">
                                <div class="item-icon">
                                    <i class="fas fa-shopping-bag"></i>
                                </div>
                                <div class="item-content">
                                    <div class="item-title">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                                    <div class="item-subtitle"><?php echo formatPrice($order['total_amount']); ?></div>
                                </div>
                                <div class="item-status">
                                    <span class="badge status-<?php echo strtolower(str_replace(' ', '-', $order['status'])); ?>">
                                        <?php echo $order['status']; ?>
                                    </span>
                                </div>
                                <div class="item-date">
                                    <?php echo date('M d', strtotime($order['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-shopping-cart"></i>
                        <p>No orders yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Users -->
    <div class="col-lg-6 mb-4">
        <div class="data-card">
            <div class="card-header-custom collapsible-header" data-target="recent-users">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h5 class="mb-0">
                        <i class="fas fa-users text-success me-2"></i>Recent Users
                    </h5>
                    <div class="d-flex align-items-center gap-3">
                        <a href="users.php" class="view-all-icon" onclick="event.stopPropagation();" title="View All Users">
                            <i class="fas fa-eye"></i>
                        </a>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
            </div>
            <div class="card-body-custom collapsible-content collapsed" id="recent-users">
                <?php if (!empty($recent_users)): ?>
                    <div class="recent-items">
                        <?php foreach ($recent_users as $user): ?>
                            <div class="recent-item">
                                <div class="item-avatar">
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                </div>
                                <div class="item-content">
                                    <div class="item-title"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div class="item-subtitle"><?php echo htmlspecialchars($user['email']); ?></div>
                                </div>
                                <div class="item-date">
                                    <?php echo date('M d', strtotime($user['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>No users yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Affiliates -->
    <div class="col-lg-6 mb-4">
        <div class="data-card">
            <div class="card-header-custom collapsible-header" data-target="recent-affiliates">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h5 class="mb-0">
                        <i class="fas fa-handshake text-info me-2"></i>Recent Affiliates
                    </h5>
                    <div class="d-flex align-items-center gap-3">
                        <a href="affiliates.php" class="view-all-icon" onclick="event.stopPropagation();" title="View All Affiliates">
                            <i class="fas fa-eye"></i>
                        </a>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
            </div>
            <div class="card-body-custom collapsible-content collapsed" id="recent-affiliates">
                <?php if (!empty($recent_affiliates)): ?>
                    <div class="recent-items">
                        <?php foreach ($recent_affiliates as $affiliate): ?>
                            <div class="recent-item">
                                <div class="item-avatar">
                                    <?php echo strtoupper(substr($affiliate['full_name'], 0, 1)); ?>
                                </div>
                                <div class="item-content">
                                    <div class="item-title"><?php echo htmlspecialchars($affiliate['full_name']); ?></div>
                                    <div class="item-subtitle"><?php echo htmlspecialchars($affiliate['partner_id']); ?></div>
                                </div>
                                <div class="item-stats">
                                    <span class="stat-number"><?php echo $affiliate['total_sales']; ?></span>
                                    <span class="stat-label">sales</span>
                                </div>
                                <div class="item-date">
                                    <?php echo date('M d', strtotime($affiliate['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-handshake"></i>
                        <p>No affiliates yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Pending Withdrawals -->
    <div class="col-lg-6 mb-4">
        <div class="data-card">
            <div class="card-header-custom collapsible-header" data-target="pending-withdrawals">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h5 class="mb-0">
                        <i class="fas fa-money-bill-wave text-warning me-2"></i>Pending Withdrawals
                    </h5>
                    <div class="d-flex align-items-center gap-3">
                        <a href="withdrawals.php" class="view-all-icon" onclick="event.stopPropagation();" title="Manage All Withdrawals">
                            <i class="fas fa-eye"></i>
                        </a>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
            </div>
            <div class="card-body-custom collapsible-content collapsed" id="pending-withdrawals">
                <?php if (!empty($pending_withdrawals)): ?>
                    <div class="recent-items">
                        <?php foreach ($pending_withdrawals as $withdrawal): ?>
                            <div class="recent-item">
                                <div class="item-icon">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <div class="item-content">
                                    <div class="item-title"><?php echo htmlspecialchars($withdrawal['full_name']); ?></div>
                                    <div class="item-subtitle"><?php echo formatPrice($withdrawal['amount']); ?> • <?php echo htmlspecialchars($withdrawal['method']); ?></div>
                                </div>
                                <div class="item-status">
                                    <span class="badge bg-warning">Pending</span>
                                </div>
                                <div class="item-date">
                                    <?php echo date('M d', strtotime($withdrawal['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No pending withdrawals</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Product Requests -->
    <div class="col-lg-6 mb-4">
        <div class="data-card">
            <div class="card-header-custom collapsible-header" data-target="product-requests">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h5 class="mb-0">
                        <i class="fas fa-plus-circle text-danger me-2"></i>Product Requests
                    </h5>
                    <div class="d-flex align-items-center gap-3">
                        <a href="product-requests.php" class="view-all-icon" onclick="event.stopPropagation();" title="View All Product Requests">
                            <i class="fas fa-eye"></i>
                        </a>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
            </div>
            <div class="card-body-custom collapsible-content collapsed" id="product-requests">
                <?php if (!empty($recent_requests)): ?>
                    <div class="recent-items">
                        <?php foreach ($recent_requests as $request): ?>
                            <div class="recent-item">
                                <div class="item-icon">
                                    <i class="fas fa-box-open"></i>
                                </div>
                                <div class="item-content">
                                    <div class="item-title"><?php echo htmlspecialchars($request['product_name']); ?></div>
                                    <div class="item-subtitle"><?php echo htmlspecialchars($request['category']); ?> • <?php echo htmlspecialchars($request['full_name']); ?></div>
                                </div>
                                <div class="item-status">
                                    <span class="badge status-<?php echo strtolower(str_replace(' ', '-', $request['status'])); ?>">
                                        <?php echo $request['status']; ?>
                                    </span>
                                </div>
                                <div class="item-date">
                                    <?php echo date('M d', strtotime($request['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-plus-circle"></i>
                        <p>No requests yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Contacts -->
    <div class="col-lg-6 mb-4">
        <div class="data-card">
            <div class="card-header-custom collapsible-header" data-target="recent-messages">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h5 class="mb-0">
                        <i class="fas fa-envelope text-secondary me-2"></i>Recent Messages
                    </h5>
                    <div class="d-flex align-items-center gap-3">
                        <a href="contact.php" class="view-all-icon" onclick="event.stopPropagation();" title="View All Messages">
                            <i class="fas fa-eye"></i>
                        </a>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
            </div>
            <div class="card-body-custom collapsible-content collapsed" id="recent-messages">
                <?php if (!empty($recent_contacts)): ?>
                    <div class="recent-items">
                        <?php foreach ($recent_contacts as $contact): ?>
                            <div class="recent-item">
                                <div class="item-avatar">
                                    <?php echo strtoupper(substr($contact['full_name'], 0, 1)); ?>
                                </div>
                                <div class="item-content">
                                    <div class="item-title"><?php echo htmlspecialchars($contact['full_name']); ?></div>
                                    <div class="item-subtitle"><?php echo htmlspecialchars($contact['subject']); ?></div>
                                </div>
                                <div class="item-date">
                                    <?php echo formatTimestamp($contact['created_at']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-envelope"></i>
                        <p>No messages yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
/* Modern Stats Cards */
.stats-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border: none;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
    height: 100%;
    min-height: 140px;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
}

.stats-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    transform: translate(30px, -30px);
}

.stats-icon {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.stats-orders .stats-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stats-revenue .stats-icon { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
.stats-users .stats-icon { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.stats-affiliates .stats-icon { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
.stats-products .stats-icon { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }

.stats-content {
    position: relative;
    z-index: 1;
}

.stats-content h2 {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 4px;
    color: #2d3748;
}

.stats-content p {
    color: #718096;
    font-size: 0.9rem;
    margin-bottom: 8px;
    font-weight: 500;
}

.stats-trend {
    display: flex;
    align-items: center;
    font-size: 0.8rem;
    font-weight: 600;
    color: #48bb78;
}

.stats-trend.positive {
    color: #48bb78;
}

.stats-trend i {
    margin-right: 4px;
}

/* Simple Stats Cards */
.stats-card-simple {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
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

/* Quick Actions */
.quick-actions-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 16px;
}

.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 16px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 2px solid #e9ecef;
    border-radius: 12px;
    text-decoration: none;
    color: #495057;
    transition: all 0.3s ease;
    text-align: center;
}

.quick-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
    border-color: var(--accent-color);
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
    color: white;
}

.quick-action-btn i {
    font-size: 1.8rem;
    margin-bottom: 8px;
}

.quick-action-btn span {
    font-size: 0.9rem;
    font-weight: 600;
}

/* Data Cards */
.data-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    height: 100%;
    transition: all 0.3s ease;
}

.data-card:hover {
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
}

.card-header-custom {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 20px 24px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.collapsible-header {
    cursor: pointer;
    user-select: none;
    transition: background 0.3s ease;
}

.collapsible-header:hover {
    background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
}

.toggle-icon {
    font-size: 1rem;
    transition: transform 0.3s ease;
    color: #495057;
    cursor: pointer;
}

.collapsible-header.active .toggle-icon {
    transform: rotate(180deg);
}

.collapsible-content {
    max-height: 1000px;
    overflow: hidden;
    transition: max-height 0.3s ease, padding 0.3s ease;
}

.collapsible-content.collapsed {
    max-height: 0;
    padding: 0 24px;
    border-top: none;
}

.card-header-custom h5 {
    font-weight: 600;
    color: #2d3748;
    margin: 0;
}

.btn-view-all {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.btn-view-all:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    color: white;
    text-decoration: none;
}

.card-body-custom {
    padding: 24px;
}

.recent-items {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.recent-item {
    display: flex;
    align-items: center;
    padding: 16px;
    background: #f8f9fa;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.recent-item:hover {
    background: #e9ecef;
    transform: translateX(4px);
}

.item-icon, .item-avatar {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 16px;
    font-weight: 600;
    color: white;
}

.item-icon {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
}

.item-avatar {
    background: linear-gradient(135deg, #667eea, #764ba2);
    font-size: 1.1rem;
}

.item-content {
    flex: 1;
}

.item-title {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 2px;
    font-size: 0.95rem;
}

.item-subtitle {
    color: #718096;
    font-size: 0.85rem;
}

.item-status {
    margin-right: 16px;
}

.item-stats {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-right: 16px;
}

.stat-number {
    font-weight: 700;
    font-size: 1.1rem;
    color: #2d3748;
}

.stat-label {
    font-size: 0.75rem;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.item-date {
    color: #a0aec0;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Status Badges */
.badge {
    font-size: 0.75rem;
    padding: 4px 8px;
    border-radius: 6px;
    font-weight: 600;
}

.status-pending { background-color: #fed7d7; color: #c53030; }
.status-confirmed { background-color: #c6f6d5; color: #22543d; }
.status-on-the-way { background-color: #bee3f8; color: #2a4365; }
.status-delivered { background-color: #c6f6d5; color: #22543d; }
.status-canceled { background-color: #fed7d7; color: #c53030; }
.status-approved { background-color: #c6f6d5; color: #22543d; }
.status-rejected { background-color: #fed7d7; color: #c53030; }

/* Empty States */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #a0aec0;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state p {
    margin: 0;
    font-size: 1rem;
}

/* Responsive */
@media (max-width: 768px) {
    .quick-actions-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .stats-card-simple {
        min-height: 100px;
    }
    
    .stats-content-simple h3 {
        font-size: 1.5rem;
    }
    
    .card-header-custom {
        flex-direction: column;
        gap: 12px;
        text-align: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get all collapsible headers
    const collapsibleHeaders = document.querySelectorAll('.collapsible-header');
    
    collapsibleHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const content = document.getElementById(targetId);
            
            // Toggle active class on header
            this.classList.toggle('active');
            
            // Toggle collapsed class on content
            content.classList.toggle('collapsed');
        });
    });
});
</script>

<?php
function getOrderStatusColor($status) {
    switch ($status) {
        case 'Pending':
            return 'warning';
        case 'Confirmed':
            return 'info';
        case 'On The Way':
            return 'primary';
        case 'Delivered':
            return 'success';
        case 'Canceled':
            return 'danger';
        default:
            return 'secondary';
    }
}

function getRequestStatusColor($status) {
    switch ($status) {
        case 'Pending':
            return 'warning';
        case 'Approved':
            return 'success';
        case 'Rejected':
            return 'danger';
        default:
            return 'secondary';
    }
}

function formatTimestamp($datetime) {
    return date('M d/ y', strtotime($datetime));
}

require_once 'includes/footer.php';
?>
