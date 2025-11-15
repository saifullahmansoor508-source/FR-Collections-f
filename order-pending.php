<?php
require_once 'config/config.php';

if (!isLoggedIn()) {
    redirectTo('auth.php');
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get order number from URL
$order_number = isset($_GET['order']) ? sanitizeInput($_GET['order']) : '';

if (empty($order_number)) {
    redirectTo('index.php');
}

// Verify order belongs to user
$stmt = $db->prepare("SELECT * FROM orders WHERE order_number = ? AND user_id = ?");
$stmt->execute([$order_number, $_SESSION['user_id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    redirectTo('index.php');
}

// Clear the session flag
unset($_SESSION['order_placed']);

$page_title = "Order Pending";
require_once 'includes/header.php';
?>

<style>
.pending-hero {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    padding: 60px 0;
    text-align: center;
}

.pending-container {
    max-width: 700px;
    margin: -40px auto 60px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
    padding: 50px;
    text-align: center;
}

.hourglass-icon {
    width: 100px;
    height: 100px;
    margin: 0 auto 30px;
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: pulse 2s ease-in-out infinite;
}

.hourglass-icon i {
    font-size: 50px;
    color: white;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.7);
    }
    50% {
        transform: scale(1.05);
        box-shadow: 0 0 0 20px rgba(251, 191, 36, 0);
    }
}

.pending-title {
    font-size: 2.5rem;
    font-weight: 800;
    color: #1e3a8a;
    margin-bottom: 20px;
}

.pending-subtitle {
    font-size: 1.2rem;
    color: #64748b;
    margin-bottom: 40px;
    line-height: 1.8;
}

.order-info {
    background: #f8fafc;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
}

.order-info-item {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #e2e8f0;
}

.order-info-item:last-child {
    border-bottom: none;
}

.order-info-label {
    color: #64748b;
    font-weight: 500;
}

.order-info-value {
    color: #1e3a8a;
    font-weight: 600;
}

.btn-my-orders {
    background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
    color: white;
    padding: 18px 50px;
    border-radius: 50px;
    font-size: 1.1rem;
    font-weight: 600;
    border: none;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-my-orders:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.5);
    color: white;
}

.info-box {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    padding: 20px;
    border-radius: 8px;
    margin-top: 30px;
    text-align: left;
}

.info-box h6 {
    color: #92400e;
    font-weight: 600;
    margin-bottom: 10px;
}

.info-box p {
    color: #78350f;
    margin: 0;
    font-size: 0.95rem;
}
</style>

<div class="pending-hero">
    <div class="container">
        <h1 style="color: #1e3a8a; font-size: 2rem; font-weight: 700;">Order Status</h1>
    </div>
</div>

<div class="container">
    <div class="pending-container">
        <div class="hourglass-icon">
            <i class="fas fa-hourglass-half"></i>
        </div>
        
        <h1 class="pending-title">Your Order is Pending</h1>
        
        <p class="pending-subtitle">
            After payment confirmation, your order will be confirmed.<br>
            You can see your order status here.
        </p>
        
        <div class="order-info">
            <div class="order-info-item">
                <span class="order-info-label">Order Number:</span>
                <span class="order-info-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
            </div>
            <div class="order-info-item">
                <span class="order-info-label">Order Date:</span>
                <span class="order-info-value"><?php echo date('F j, Y', strtotime($order['created_at'])); ?></span>
            </div>
            <div class="order-info-item">
                <span class="order-info-label">Total Amount:</span>
                <span class="order-info-value"><?php echo formatPrice($order['total_amount']); ?></span>
            </div>
            <div class="order-info-item">
                <span class="order-info-label">Status:</span>
                <span class="order-info-value" style="color: #f59e0b;">
                    <i class="fas fa-clock me-1"></i>Pending
                </span>
            </div>
        </div>
        
        <a href="profile.php?tab=orders" class="btn-my-orders">
            <i class="fas fa-box me-2"></i>My Orders
        </a>
        
        <div class="info-box">
            <h6><i class="fas fa-info-circle me-2"></i>What's Next?</h6>
            <p>Our team will verify your payment and confirm your order within 24 hours. You'll receive updates on your registered email and can track your order status in the "My Orders" section.</p>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
