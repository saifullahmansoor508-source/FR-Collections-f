<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Access denied');
}

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    exit('Invalid order ID');
}

$database = new Database();
$db = $database->getConnection();

// Include variant helpers for combination string parsing
require_once '../config/variant_helpers.php';

// Get order details - verify it belongs to the logged-in user
$stmt = $db->prepare("
    SELECT o.*, u.full_name as user_name, u.email as user_email
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.id = ? AND o.user_id = ?
");
$stmt->execute([$order_id, $_SESSION['user_id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    exit('Order not found');
}

// Get order items
$stmt = $db->prepare("
    SELECT oi.*, p.name as product_name, pv.variant_name, c.name as category_name,
           pvc.price as combination_price, pvc.sku as combination_sku,
           pi.image_path, pv.variant_image, pvc.image_path as combination_image
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
    
    // Determine which image to display (priority: combination_image > variant_image > primary image)
    $display_image = null;
    if (!empty($item['combination_image'])) {
        // Use combination variant image if available
        $display_image = 'uploads/products/' . $item['combination_image'];
    } elseif (!empty($item['variant_image'])) {
        // Use simple variant image if available
        $display_image = 'uploads/products/' . $item['variant_image'];
    } elseif (!empty($item['image_path'])) {
        // Use primary product image
        $display_image = 'uploads/products/' . $item['image_path'];
    } else {
        // Use default no-image
        $display_image = 'assets/images/no-image.jpg';
    }
    $item['display_image'] = $display_image;
}
unset($item);

// Get status color
$status_colors = [
    'Pending' => '#f59e0b',
    'Confirmed' => '#10b981',
    'On The Way' => '#3b82f6',
    'Delivered' => '#059669',
    'Canceled' => '#ef4444'
];
$status_color = isset($status_colors[$order['status']]) ? $status_colors[$order['status']] : '#6b7280';
?>

<style>
.customer-receipt-container {
    padding: 0;
    background: white;
}

.receipt-header-gradient {
    background: linear-gradient(135deg, #1e40af 0%, #ea580c 100%);
    padding: 30px;
    border-radius: 20px 20px 0 0;
    position: relative;
    overflow: hidden;
}

.receipt-header-gradient::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    filter: blur(60px);
}

.receipt-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    z-index: 1;
}

.receipt-header-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

.receipt-icon {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: white;
}

.receipt-order-info h2 {
    color: white;
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 5px 0;
}

.receipt-date {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.9rem;
    margin: 0;
}

.receipt-status-badge {
    background: <?php echo $status_color; ?>;
    color: white;
    padding: 10px 20px;
    border-radius: 25px;
    font-weight: 600;
    font-size: 0.95rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.receipt-status-badge i {
    font-size: 0.7rem;
}

.partner-badge-receipt {
    background: rgba(255, 255, 255, 0.25);
    backdrop-filter: blur(10px);
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-left: 10px;
}

.receipt-info-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    padding: 30px;
    background: #f8fafc;
}

.receipt-info-card {
    background: white;
    border-radius: 15px;
    padding: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.receipt-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    border-bottom: 2px solid #e2e8f0;
}

.receipt-card-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: white;
}

.icon-blue {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}

.icon-green {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.icon-orange {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}

.receipt-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.receipt-card-body {
    padding: 20px;
}

.receipt-info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
}

.receipt-info-row:last-child {
    border-bottom: none;
}

.receipt-info-label {
    color: #64748b;
    font-size: 0.9rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
}

.receipt-info-value {
    color: #1f2937;
    font-weight: 600;
    font-size: 0.95rem;
    text-align: right;
}

.receipt-address-box {
    background: #f0fdf4;
    border: 2px dashed #10b981;
    border-radius: 10px;
    padding: 15px;
    display: flex;
    align-items: start;
    gap: 12px;
}

.receipt-address-icon {
    color: #10b981;
    font-size: 1.2rem;
    margin-top: 2px;
}

.receipt-address-text {
    color: #047857;
    font-weight: 500;
    line-height: 1.6;
    margin: 0;
    flex: 1;
}

.receipt-account-code {
    background: #fef3c7;
    color: #92400e;
    padding: 6px 12px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-weight: 700;
    font-size: 1rem;
}

.receipt-items-section {
    padding: 30px;
    background: white;
}

.receipt-section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e2e8f0;
}

.receipt-section-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.3rem;
}

.receipt-section-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.receipt-items-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.receipt-items-table thead {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
}

.receipt-items-table th {
    padding: 15px 12px;
    text-align: left;
    font-size: 0.85rem;
    font-weight: 700;
    color: white;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.receipt-items-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.2s;
}

.receipt-items-table tbody tr:last-child {
    border-bottom: none;
}

.receipt-items-table tbody tr:hover {
    background: #f8fafc;
}

.receipt-items-table td {
    padding: 18px 12px;
    font-size: 0.9rem;
    color: #374151;
}

.receipt-product-display {
    display: flex;
    align-items: center;
    gap: 12px;
}

.receipt-product-image {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #e5e7eb;
    flex-shrink: 0;
}

.receipt-product-info {
    flex: 1;
}

.receipt-product-name {
    font-weight: 600;
    color: #1f2937;
    font-size: 0.9rem;
    line-height: 1.3;
}

.receipt-product-icon {
    color: #8b5cf6;
}

.receipt-variant-badge {
    display: inline-block;
    background: #e0e7ff;
    color: #4f46e5;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-bottom: 4px;
}

.receipt-price {
    font-weight: 700;
    color: #0058a3;
}

.receipt-quantity-badge {
    background: #f3e8ff;
    color: #7c3aed;
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 0.9rem;
}

.receipt-total {
    font-weight: 700;
    color: #059669;
    font-size: 1rem;
}

.receipt-summary-section {
    padding: 30px;
    background: #f8fafc;
}

.receipt-summary-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    max-width: 400px;
    margin-left: auto;
}

.receipt-summary-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.receipt-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #e2e8f0;
}

.receipt-summary-row:last-child {
    border-bottom: none;
}

.receipt-summary-label {
    color: #64748b;
    font-size: 0.95rem;
    font-weight: 500;
}

.receipt-summary-value {
    font-weight: 700;
    color: #1f2937;
    font-size: 1rem;
}

.receipt-discount-row {
    color: #059669;
}

.receipt-discount-row .receipt-summary-label {
    color: #059669;
}

.receipt-divider {
    height: 2px;
    background: linear-gradient(90deg, transparent, #e2e8f0, transparent);
    margin: 10px 0;
}

.receipt-total-row {
    padding: 15px 0 0 0 !important;
    border-top: 3px solid #0058a3 !important;
    margin-top: 10px;
}

.receipt-total-row .receipt-summary-label {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
}

.receipt-total-row .receipt-summary-value {
    font-size: 1.4rem;
    background: linear-gradient(135deg, #0058a3 0%, #ea580c 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

@media (max-width: 768px) {
    .receipt-header-content {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .receipt-info-cards {
        grid-template-columns: 1fr;
        padding: 20px;
    }
    
    .receipt-items-table {
        font-size: 0.85rem;
    }
    
    .receipt-items-table th,
    .receipt-items-table td {
        padding: 12px 8px;
    }
}

/* Print Styles */
@media print {
    body * {
        visibility: hidden;
    }
    
    .customer-receipt-container,
    .customer-receipt-container * {
        visibility: visible;
    }
    
    .customer-receipt-container {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    
    .receipt-header-gradient {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        page-break-after: avoid;
    }
    
    .receipt-info-cards {
        page-break-inside: avoid;
    }
    
    .receipt-items-section {
        page-break-inside: avoid;
    }
    
    .receipt-summary-section {
        page-break-inside: avoid;
    }
    
    .receipt-items-table thead {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .receipt-status-badge,
    .partner-badge-receipt,
    .receipt-card-icon,
    .receipt-variant-badge,
    .receipt-quantity-badge {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<div class="customer-receipt-container" id="orderDetailsContent">
    <!-- Beautiful Gradient Header -->
    <div class="receipt-header-gradient">
        <div class="receipt-header-content">
            <div class="receipt-header-left">
                <div class="receipt-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="receipt-order-info">
                    <h2><?php echo htmlspecialchars($order['order_number']); ?></h2>
                    <p class="receipt-date">
                        <i class="far fa-calendar me-2"></i>
                        <?php echo date('F d, Y g:i A', strtotime($order['created_at'])); ?>
                    </p>
                </div>
            </div>
            <div>
                <span class="receipt-status-badge">
                    <i class="fas fa-circle"></i>
                    <?php echo strtoupper($order['status']); ?>
                </span>
                <?php if ($order['partner_id']): ?>
                <span class="partner-badge-receipt">
                    <i class="fas fa-handshake"></i>
                    <?php echo htmlspecialchars($order['partner_id']); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Clean Information Cards -->
    <div class="receipt-info-cards">
        <!-- Customer Information Card -->
        <div class="receipt-info-card">
            <div class="receipt-card-header">
                <div class="receipt-card-icon icon-blue">
                    <i class="fas fa-user"></i>
                </div>
                <h3 class="receipt-card-title">Customer Information</h3>
            </div>
            <div class="receipt-card-body">
                <div class="receipt-info-row">
                    <div class="receipt-info-label"><i class="fas fa-user-circle"></i> Name</div>
                    <div class="receipt-info-value"><?php echo htmlspecialchars($order['full_name']); ?></div>
                </div>
                <div class="receipt-info-row">
                    <div class="receipt-info-label"><i class="fas fa-envelope"></i> Email</div>
                    <div class="receipt-info-value"><?php echo htmlspecialchars($order['email']); ?></div>
                </div>
                <div class="receipt-info-row">
                    <div class="receipt-info-label"><i class="fas fa-phone"></i> Phone</div>
                    <div class="receipt-info-value"><?php echo htmlspecialchars($order['phone']); ?></div>
                </div>
                <div class="receipt-info-row">
                    <div class="receipt-info-label"><i class="fas fa-map-marker-alt"></i> City</div>
                    <div class="receipt-info-value"><?php echo htmlspecialchars($order['city']); ?></div>
                </div>
            </div>
        </div>

        <!-- Delivery Address Card -->
        <div class="receipt-info-card">
            <div class="receipt-card-header">
                <div class="receipt-card-icon icon-green">
                    <i class="fas fa-map-marked-alt"></i>
                </div>
                <h3 class="receipt-card-title">Delivery Address</h3>
            </div>
            <div class="receipt-card-body">
                <div class="receipt-address-box">
                    <i class="fas fa-location-arrow receipt-address-icon"></i>
                    <p class="receipt-address-text"><?php echo nl2br(htmlspecialchars($order['address'])); ?></p>
                </div>
            </div>
        </div>

        <!-- Payment Information Card -->
        <div class="receipt-info-card">
            <div class="receipt-card-header">
                <div class="receipt-card-icon icon-orange">
                    <i class="fas fa-credit-card"></i>
                </div>
                <h3 class="receipt-card-title">Payment Information</h3>
            </div>
            <div class="receipt-card-body">
                <div class="receipt-info-row">
                    <div class="receipt-info-label"><i class="fas fa-user-tag"></i> Account Name</div>
                    <div class="receipt-info-value"><?php echo htmlspecialchars($order['payment_account_name']); ?></div>
                </div>
                <div class="receipt-info-row">
                    <div class="receipt-info-label"><i class="fas fa-hashtag"></i> Account Number</div>
                    <div class="receipt-info-value"><code class="receipt-account-code"><?php echo htmlspecialchars($order['payment_account_number']); ?></code></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Items Section -->
    <div class="receipt-items-section">
        <div class="receipt-section-header">
            <div class="receipt-section-icon">
                <i class="fas fa-shopping-bag"></i>
            </div>
            <h3 class="receipt-section-title">Order Items</h3>
        </div>
        <table class="receipt-items-table">
            <thead>
                <tr>
                    <th>PRODUCT</th>
                    <th>CATEGORY</th>
                    <th style="text-align: center;">VARIANT</th>
                    <th>PRICE</th>
                    <th>QUANTITY</th>
                    <th>TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($order_items as $item): ?>
                    <tr>
                        <td>
                            <div class="receipt-product-display">
                                <img src="<?php echo $item['display_image']; ?>" 
                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                     class="receipt-product-image">
                                <div class="receipt-product-info">
                                    <div class="receipt-product-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                        <td style="text-align: center;">
                            <?php if (!empty($item['combination_details'])): ?>
                                <!-- Combination Variant Display -->
                                <div style="display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap;">
                                    <span class="receipt-combination-badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; display: inline-block;">
                                        <i class="fas fa-layer-group"></i> Combination
                                    </span>
                                    <span style="color: #4b5563; font-size: 0.85rem;">
                                        <?php 
                                        $combination_parts = [];
                                        foreach ($item['combination_details'] as $attr => $value) {
                                            $combination_parts[] = '<strong>' . htmlspecialchars($attr) . ':</strong> ' . htmlspecialchars($value);
                                        }
                                        echo implode(' | ', $combination_parts);
                                        ?>
                                    </span>
                                </div>
                            <?php elseif (!empty($item['all_variants'])): ?>
                                <!-- Multiple Simple Variants Display -->
                                <?php foreach ($item['all_variants'] as $variant): ?>
                                    <span class="receipt-variant-badge"><?php echo ucfirst($variant['variant_type']) . ': ' . htmlspecialchars($variant['variant_name']); ?></span><br>
                                <?php endforeach; ?>
                            <?php elseif ($item['variant_name']): ?>
                                <!-- Single Simple Variant Display -->
                                <?php
                                // For legacy orders, try to get variant type from variant_id
                                if (!empty($item['variant_id'])) {
                                    $vstmt = $db->prepare("SELECT variant_type FROM product_variants WHERE id = ?");
                                    $vstmt->execute([$item['variant_id']]);
                                    $variant_type = $vstmt->fetchColumn();
                                    if ($variant_type) {
                                        echo '<span class="receipt-variant-badge">' . ucfirst($variant_type) . ': ' . htmlspecialchars($item['variant_name']) . '</span>';
                                    } else {
                                        echo '<span class="receipt-variant-badge">' . htmlspecialchars($item['variant_name']) . '</span>';
                                    }
                                } else {
                                    echo '<span class="receipt-variant-badge">' . htmlspecialchars($item['variant_name']) . '</span>';
                                }
                                ?>
                            <?php else: ?>
                                <span style="color: #9ca3af;">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="receipt-price"><?php echo formatPrice($item['price']); ?></td>
                        <td>
                            <span class="receipt-quantity-badge"><?php echo $item['quantity']; ?></span>
                        </td>
                        <td class="receipt-total"><?php echo formatPrice($item['price'] * $item['quantity']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Order Summary -->
    <div class="receipt-summary-section">
        <div class="receipt-summary-card">
            <h3 class="receipt-summary-title">
                <i class="fas fa-calculator"></i>
                Order Summary
            </h3>
            <div class="receipt-summary-row">
                <span class="receipt-summary-label">Subtotal</span>
                <span class="receipt-summary-value"><?php echo formatPrice($order['subtotal']); ?></span>
            </div>
            <div class="receipt-summary-row">
                <span class="receipt-summary-label">Delivery Charges</span>
                <span class="receipt-summary-value"><?php echo formatPrice($order['delivery_charges']); ?></span>
            </div>
            <?php if ($order['discount_amount'] > 0): ?>
            <div class="receipt-summary-row receipt-discount-row">
                <span class="receipt-summary-label">
                    <i class="fas fa-tag me-2"></i>Discount
                </span>
                <span class="receipt-summary-value">-<?php echo formatPrice($order['discount_amount']); ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-divider"></div>
            <div class="receipt-summary-row receipt-total-row">
                <span class="receipt-summary-label">Total Amount</span>
                <span class="receipt-summary-value"><?php echo formatPrice($order['total_amount']); ?></span>
            </div>
        </div>
    </div>
</div>
