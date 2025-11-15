<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    http_response_code(403);
    exit('Access denied');
}

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id <= 0) {
    exit('Invalid user ID');
}

$database = new Database();
$db = $database->getConnection();

// Get user details
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    exit('User not found');
}

// Get affiliate info if exists
$stmt = $db->prepare("SELECT * FROM affiliates WHERE user_id = ?");
$stmt->execute([$user_id]);
$affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user stats
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'Delivered' THEN total_amount ELSE 0 END) as total_spent,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN status = 'Canceled' THEN 1 ELSE 0 END) as canceled_orders
    FROM orders WHERE user_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent orders
$stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get wishlist count
$stmt = $db->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = ?");
$stmt->execute([$user_id]);
$wishlist_count = $stmt->fetchColumn();
?>

<div class="user-details">
    <!-- User Information -->
    <div class="row mb-4">
        <div class="col-md-6">
            <h6 class="text-primary-custom">Personal Information</h6>
            <table class="table table-sm table-borderless">
                <tr>
                    <td><strong>Full Name:</strong></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                </tr>
                <tr>
                    <td><strong>Email:</strong></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                </tr>
                <tr>
                    <td><strong>Phone:</strong></td>
                    <td><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : 'Not provided'; ?></td>
                </tr>
                <tr>
                    <td><strong>Status:</strong></td>
                    <td>
                        <span class="badge bg-<?php echo $user['is_blocked'] ? 'danger' : 'success'; ?>">
                            <?php echo $user['is_blocked'] ? 'Blocked' : 'Active'; ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><strong>Joined:</strong></td>
                    <td><?php echo date('F d, Y', strtotime($user['created_at'])); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="col-md-6">
            <h6 class="text-primary-custom">Statistics</h6>
            <table class="table table-sm table-borderless">
                <tr>
                    <td><strong>Total Orders:</strong></td>
                    <td><?php echo $stats['total_orders']; ?></td>
                </tr>
                <tr>
                    <td><strong>Total Spent:</strong></td>
                    <td><?php echo formatPrice($stats['total_spent'] ?: 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Pending Orders:</strong></td>
                    <td><?php echo $stats['pending_orders']; ?></td>
                </tr>
                <tr>
                    <td><strong>Canceled Orders:</strong></td>
                    <td><?php echo $stats['canceled_orders']; ?></td>
                </tr>
                <tr>
                    <td><strong>Wishlist Items:</strong></td>
                    <td><?php echo $wishlist_count; ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Address -->
    <?php if ($user['address']): ?>
    <div class="row mb-4">
        <div class="col-12">
            <h6 class="text-primary-custom">Address</h6>
            <div class="bg-light p-3 rounded">
                <?php echo nl2br(htmlspecialchars($user['address'])); ?>
                <?php if ($user['city']): ?>
                    <br><strong>City:</strong> <?php echo htmlspecialchars($user['city']); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Affiliate Information -->
    <?php if ($affiliate): ?>
    <div class="row mb-4">
        <div class="col-12">
            <h6 class="text-primary-custom">Affiliate Information</h6>
            <div class="row">
                <div class="col-md-3">
                    <div class="text-center bg-light p-3 rounded">
                        <h5 class="text-primary-custom"><?php echo htmlspecialchars($affiliate['partner_id']); ?></h5>
                        <small>Partner ID</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center bg-light p-3 rounded">
                        <h5 class="text-primary-custom"><?php echo $affiliate['total_sales']; ?></h5>
                        <small>Total Sales</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center bg-light p-3 rounded">
                        <h5 class="text-primary-custom"><?php echo formatPrice($affiliate['total_revenue']); ?></h5>
                        <small>Total Revenue</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center bg-light p-3 rounded">
                        <h5 class="text-primary-custom"><?php echo formatPrice($affiliate['balance']); ?></h5>
                        <small>Balance</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent Orders -->
    <?php if (!empty($recent_orders)): ?>
    <div class="row">
        <div class="col-12">
            <h6 class="text-primary-custom">Recent Orders</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Order #</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                <td><?php echo formatPrice($order['total_amount']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo getOrderStatusColor($order['status']); ?>">
                                        <?php echo $order['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

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
?>
