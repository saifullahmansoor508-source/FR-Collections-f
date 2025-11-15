<?php
require_once 'config/config.php';

if (!isLoggedIn()) {
    redirectTo('auth.php');
}

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    redirectTo('profile.php?tab=orders');
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get order details
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$order_id, $_SESSION['user_id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    redirectTo('profile.php?tab=orders');
}

$page_title = "Order Details";
require_once 'includes/header.php';

// Include variant helpers for combination string parsing
require_once 'config/variant_helpers.php';

// Get order items with proper image handling
$stmt = $db->prepare("
    SELECT oi.*, 
           p.name as product_name, 
           p.id as product_id,
           pv.variant_name, 
           pv.variant_image,
           c.name as category_name, 
           pi.image_path as primary_image,
           pvc.price as combination_price, 
           pvc.sku as combination_sku,
           pvc.image_path as combination_image
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN product_variants pv ON oi.variant_id = pv.id
    LEFT JOIN product_variant_combinations pvc ON oi.variant_combination_id = pvc.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
    WHERE oi.order_id = ?
");
$stmt->execute([$order_id]);
$order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all variant details for items with variant_selections and combination details
foreach ($order_items as &$item) {
    // Handle simple variants (old format)
    if (!empty($item['variant_selections'])) {
        $variant_ids = json_decode($item['variant_selections'], true);
        if (is_array($variant_ids) && !empty($variant_ids)) {
            $placeholders = implode(',', array_fill(0, count($variant_ids), '?'));
            $stmt = $db->prepare("SELECT id, variant_type, variant_name FROM product_variants WHERE id IN ($placeholders)");
            $stmt->execute(array_values($variant_ids));
            $item['all_variants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Handle combination variants (new format)
    if (!empty($item['variant_combination_id'])) {
        $stmt = $db->prepare("
            SELECT 
                GROUP_CONCAT(
                    CONCAT(va.attribute_name, ':', vav.value_name) 
                    ORDER BY va.attribute_name 
                    SEPARATOR '|'
                ) as combination_string
            FROM product_variant_combinations pvc
            INNER JOIN combination_attribute_map cam ON pvc.id = cam.combination_id
            INNER JOIN variant_attribute_values vav ON cam.attribute_value_id = vav.id
            INNER JOIN variant_attributes va ON vav.attribute_id = va.id
            WHERE pvc.id = ?
            GROUP BY pvc.id
        ");
        $stmt->execute([$item['variant_combination_id']]);
        $combination = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($combination) {
            $item['combination_details'] = parseCombinationString($combination['combination_string']);
        }
    }
}
unset($item);
?>

<div class="container my-5" id="receipt-container">
    <div class="row">
        <div class="col-12">
            <div class="receipt-header">
                <h1 class="receipt-title"><i class="fas fa-receipt"></i> Receipt</h1>
                <p class="receipt-number">#{<?php echo htmlspecialchars($order['order_number']); ?>}</p>
            </div>
            
            <!-- Professional Receipt Card -->
            <div class="receipt-card">
                <!-- Receipt Details -->
                <div class="receipt-details-section">
                    <div class="info-row highlight-row">
                        <span class="info-label"><i class="fas fa-user"></i> Issued to:</span>
                        <span class="info-value fw-bold"><?php echo htmlspecialchars($order['full_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Receipt Number:</span>
                        <span class="info-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Date:</span>
                        <span class="info-value"><?php echo date('F d, Y g:i A', strtotime($order['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Total Items:</span>
                        <span class="info-value"><span class="badge-items"><?php echo count($order_items); ?> item<?php echo count($order_items) > 1 ? 's' : ''; ?></span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($order['phone']); ?></span>
                    </div>
                </div>

                <!-- Delivery Address -->
                <div class="address-section">
                    <h6 class="section-divider"><i class="fas fa-map-marker-alt"></i> Delivery Address</h6>
                    <div class="address-box">
                        <strong><?php echo htmlspecialchars($order['full_name']); ?></strong><br>
                        <?php echo nl2br(htmlspecialchars($order['address'])); ?><br>
                        <strong>City:</strong> <?php echo htmlspecialchars($order['city']); ?>
                    </div>
                </div>

                <!-- Pricing Summary -->
                <div class="pricing-section">
                    <h6 class="section-divider"><i class="fas fa-money-bill-wave"></i> Pricing Summary</h6>
                    <div class="pricing-table">
                        <div class="pricing-row">
                            <span class="pricing-label">Subtotal:</span>
                            <span class="pricing-value">PKR <?php echo number_format($order['subtotal']); ?></span>
                        </div>
                        <div class="pricing-row">
                            <span class="pricing-label">Delivery Charges:</span>
                            <span class="pricing-value" style="color: #f59e0b;">PKR <?php echo number_format($order['delivery_charges']); ?></span>
                        </div>
                        <?php if ($order['discount_amount'] > 0): ?>
                        <div class="pricing-row discount-row">
                            <span class="pricing-label">Discount:</span>
                            <span class="pricing-value" style="color: #059669;">-PKR <?php echo number_format($order['discount_amount']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="pricing-row total-row">
                            <span class="pricing-label">Total Amount:</span>
                            <span class="pricing-value">PKR <?php echo number_format($order['total_amount']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Receipt Footer -->
                <div class="receipt-footer">
                    <p class="footer-text">
                        <i class="fas fa-check-circle"></i> This is an official receipt from <strong>FR Collections</strong>
                    </p>
                    <p class="footer-note">Thank you for your order!</p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="profile.php?tab=orders" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Orders
                </a>
                <button class="btn btn-download" onclick="downloadReceipt()">
                    <i class="fas fa-download"></i> Download Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
// Download receipt as high-quality PNG
function downloadReceipt() {
    const button = document.querySelector('.btn-download');
    const originalText = button ? button.innerHTML : null;
    
    const container = document.getElementById('receipt-container');
    const actionsDiv = document.querySelector('.action-buttons');
    
    // Show loading state on button if it exists
    if (button) {
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
        button.disabled = true;
    }
    
    // Hide buttons temporarily
    if (actionsDiv) {
        actionsDiv.style.display = 'none';
    }
    
    // Wait a moment for rendering
    setTimeout(() => {
        html2canvas(container, {
            scale: 2.5,
            useCORS: true,
            logging: false,
            backgroundColor: '#f8f9fa',
            windowWidth: container.scrollWidth,
            windowHeight: container.scrollHeight
        }).then(canvas => {
            // Create download link
            const link = document.createElement('a');
            link.download = 'Receipt-<?php echo $order["order_number"]; ?>.png';
            link.href = canvas.toDataURL('image/png', 1.0);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Show buttons again
            if (actionsDiv) {
                actionsDiv.style.display = 'flex';
            }
            
            // Reset button
            if (button && originalText) {
                button.innerHTML = '<i class="fas fa-check"></i> Downloaded!';
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 2000);
            }
        }).catch(err => {
            console.error('Download error:', err);
            if (actionsDiv) {
                actionsDiv.style.display = 'flex';
            }
            if (button && originalText) {
                button.innerHTML = '<i class="fas fa-times"></i> Error';
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 2000);
            }
        });
    }, 100);
}

// Check if autodownload parameter is present
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('autodownload') === '1') {
        // Wait for html2canvas to load, then trigger download
        const checkAndDownload = () => {
            if (typeof html2canvas !== 'undefined') {
                downloadReceipt();
            } else {
                // Retry after 500ms if html2canvas not loaded yet
                setTimeout(checkAndDownload, 500);
            }
        };
        
        // Start checking after 1 second
        setTimeout(checkAndDownload, 1000);
    }
});
</script>

<style>
/* Receipt Container */
#receipt-container {
    background: #f8f9fa;
    padding: 20px;
    max-width: 900px;
    margin: 0 auto;
}

/* Receipt Header */
.receipt-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: white;
    padding: 25px 30px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
    text-align: center;
}

.receipt-title {
    font-size: 2rem;
    font-weight: 800;
    margin: 0;
    letter-spacing: 1px;
}

.receipt-title i {
    margin-right: 10px;
    font-size: 1.8rem;
}

.receipt-number {
    font-size: 1.1rem;
    margin: 8px 0 0 0;
    opacity: 0.95;
    font-weight: 600;
    letter-spacing: 2px;
}

/* Professional Receipt Card */
.receipt-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

.receipt-details-section,
.address-section,
.pricing-section {
    padding: 20px 25px;
    border-bottom: 2px solid #f1f5f9;
}

.receipt-details-section:last-child,
.pricing-section {
    border-bottom: none;
}

.section-divider {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #3b82f6;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-divider i {
    color: #3b82f6;
}

/* Receipt Information */
.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
    gap: 15px;
}

.info-row:last-child {
    border-bottom: none;
}

.info-row.highlight-row {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
    border: 2px solid #3b82f6;
    border-bottom: 2px solid #3b82f6;
}

.info-label {
    font-weight: 600;
    color: #64748b;
    font-size: 0.9rem;
    white-space: nowrap;
    flex-shrink: 0;
}

.info-label i {
    margin-right: 5px;
    color: #3b82f6;
}

.info-value {
    color: #1e293b;
    font-size: 0.9rem;
    text-align: right;
    word-break: break-word;
}

.badge-items {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
}

/* Address Box */
.address-box {
    background: #f8fafc;
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid #667eea;
}


/* Pricing Summary */
.pricing-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

.pricing-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
}

.pricing-row:last-child {
    border-bottom: none;
}

.pricing-label {
    font-weight: 600;
    color: #1e293b;
}

.pricing-value {
    font-weight: 600;
    color: #1e293b;
}

.pricing-row.discount-row {
    background: #f0fdf4;
}

.pricing-row.discount-row .pricing-value {
    color: #059669;
    font-weight: 700;
}

.pricing-row.total-row {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    padding: 16px 20px;
    border-top: 2px solid #3b82f6;
}

.pricing-row.total-row .pricing-label {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1e3a8a;
}

.pricing-row.total-row .pricing-value {
    font-size: 1.2rem;
    font-weight: 800;
    color: #1e3a8a;
}

/* Receipt Footer */
.receipt-footer {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    padding: 20px;
    text-align: center;
    border-top: 2px solid #10b981;
}

.footer-text {
    margin: 0 0 8px 0;
    font-weight: 600;
    color: #059669;
    font-size: 0.95rem;
}

.footer-text i {
    margin-right: 5px;
}

.footer-note {
    margin: 0;
    color: #64748b;
    font-size: 0.9rem;
    font-style: italic;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-top: 20px;
    padding: 0 15px;
}

.btn-back,
.btn-download {
    flex: 1;
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
}

.btn-back {
    background: #f1f5f9;
    color: #475569;
}

.btn-back:hover {
    background: #e2e8f0;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.btn-download {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-download:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.4);
}

/* Mobile Responsive Styles */
@media (max-width: 768px) {
    #receipt-container {
        padding: 10px;
    }
    
    .receipt-header {
        padding: 20px 15px;
        margin-bottom: 15px;
    }
    
    .receipt-title {
        font-size: 1.5rem;
    }
    
    .receipt-title i {
        font-size: 1.3rem;
    }
    
    .receipt-number {
        font-size: 0.95rem;
    }
    
    .receipt-details-section,
    .address-section,
    .pricing-section {
        padding: 15px;
    }
    
    .section-divider {
        font-size: 0.95rem;
        margin-bottom: 12px;
    }
    
    .info-row {
        padding: 8px 0;
        gap: 10px;
    }
    
    .info-row.highlight-row {
        padding: 12px;
        margin-bottom: 12px;
    }
    
    .info-label {
        font-size: 0.85rem;
    }
    
    .info-value {
        font-size: 0.85rem;
    }
    
    .action-buttons {
        flex-direction: column;
        padding: 0;
    }
    
    .btn-back,
    .btn-download {
        width: 100%;
    }
    
    .address-box {
        padding: 12px;
    }
    
    .pricing-row {
        padding: 12px 15px;
        font-size: 0.9rem;
    }
    
    .pricing-row.total-row .pricing-label {
        font-size: 1rem;
    }
    
    .pricing-row.total-row .pricing-value {
        font-size: 1.1rem;
    }
}

@media (max-width: 576px) {
    .receipt-header {
        padding: 15px 10px;
    }
    
    .receipt-title {
        font-size: 1.3rem;
    }
    
    .receipt-details-section,
    .address-section,
    .pricing-section {
        padding: 12px;
    }
    
    .info-label {
        font-size: 0.8rem;
    }
    
    .info-value {
        font-size: 0.8rem;
    }
    
    .section-divider {
        font-size: 0.9rem;
    }
    
    .receipt-footer {
        padding: 15px;
    }
    
    .footer-text {
        font-size: 0.85rem;
    }
    
    .footer-note {
        font-size: 0.8rem;
    }
    
    .pricing-row {
        padding: 10px 12px;
        font-size: 0.85rem;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>
