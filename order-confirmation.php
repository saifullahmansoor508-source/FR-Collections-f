<?php
$page_title = "Order Confirmation";
require_once 'includes/header.php';

$order_number = isset($_GET['order']) ? sanitizeInput($_GET['order']) : '';

if (empty($order_number)) {
    redirectTo('index.php');
}

// Get order details
$stmt = $db->prepare("SELECT * FROM orders WHERE order_number = ? AND user_id = ?");
$stmt->execute([$order_number, $_SESSION['user_id'] ?? 0]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    redirectTo('index.php');
}
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="text-center mb-5">
                <div class="mb-4">
                    <i class="fas fa-clock fa-4x text-warning"></i>
                </div>
                <h1 class="text-primary-custom mb-3">Your Order is Pending</h1>
                <p class="lead">After payment confirmation, your order will be confirmed.</p>
                <p class="text-muted">You can check your order status anytime.</p>
            </div>
            
            <div class="bg-white rounded-lg shadow-lg p-5">
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="text-primary-custom mb-3">Order Details</h5>
                        <p><strong>Order Number:</strong> <?php echo htmlspecialchars($order['order_number']); ?></p>
                        <p><strong>Order Date:</strong> <?php echo date('F d, Y', strtotime($order['created_at'])); ?></p>
                        <p><strong>Status:</strong> <span class="badge bg-warning">Pending</span></p>
                        <p><strong>Total Amount:</strong> <span class="text-primary-custom fw-bold"><?php echo formatPrice($order['total_amount']); ?></span></p>
                    </div>
                    
                    <div class="col-md-6">
                        <h5 class="text-primary-custom mb-3">Delivery Information</h5>
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($order['full_name']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($order['phone']); ?></p>
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($order['address']); ?></p>
                        <p><strong>City:</strong> <?php echo htmlspecialchars($order['city']); ?></p>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="text-center">
                    <h6 class="mb-3">What's Next?</h6>
                    <p class="text-muted mb-4">
                        We will verify your payment and confirm your order within 24 hours. 
                        You'll receive updates via WhatsApp and email.
                    </p>
                    
                    <div class="d-flex flex-column flex-md-row gap-3 justify-content-center">
                        <a href="profile.php?tab=orders" class="btn btn-primary">
                            <i class="fas fa-list me-2"></i>My Orders
                        </a>
                        <a href="shop.php" class="btn btn-outline-primary">
                            <i class="fas fa-shopping-bag me-2"></i>Continue Shopping
                        </a>
                        <a href="contact.php" class="btn btn-outline-secondary">
                            <i class="fas fa-envelope me-2"></i>Contact Support
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Order Timeline -->
            <div class="mt-5">
                <h5 class="text-primary-custom mb-4">Order Timeline</h5>
                <div class="timeline">
                    <div class="timeline-item active">
                        <div class="timeline-marker bg-success"></div>
                        <div class="timeline-content">
                            <h6>Order Placed</h6>
                            <small class="text-muted"><?php echo date('M d, Y - g:i A', strtotime($order['created_at'])); ?></small>
                        </div>
                    </div>
                    
                    <div class="timeline-item">
                        <div class="timeline-marker bg-warning"></div>
                        <div class="timeline-content">
                            <h6>Payment Verification</h6>
                            <small class="text-muted">Pending</small>
                        </div>
                    </div>
                    
                    <div class="timeline-item">
                        <div class="timeline-marker bg-secondary"></div>
                        <div class="timeline-content">
                            <h6>Order Confirmed</h6>
                            <small class="text-muted">Waiting</small>
                        </div>
                    </div>
                    
                    <div class="timeline-item">
                        <div class="timeline-marker bg-secondary"></div>
                        <div class="timeline-content">
                            <h6>On The Way</h6>
                            <small class="text-muted">Waiting</small>
                        </div>
                    </div>
                    
                    <div class="timeline-item">
                        <div class="timeline-marker bg-secondary"></div>
                        <div class="timeline-content">
                            <h6>Delivered</h6>
                            <small class="text-muted">Waiting</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}

.timeline-item {
    position: relative;
    margin-bottom: 30px;
}

.timeline-marker {
    position: absolute;
    left: -22px;
    top: 5px;
    width: 15px;
    height: 15px;
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px #e9ecef;
}

.timeline-item.active .timeline-marker {
    box-shadow: 0 0 0 2px var(--primary-color);
}

.timeline-content h6 {
    margin-bottom: 5px;
    color: var(--text-color);
}

.timeline-item.active .timeline-content h6 {
    color: var(--primary-color);
    font-weight: bold;
}
</style>

<?php require_once 'includes/footer.php'; ?>
