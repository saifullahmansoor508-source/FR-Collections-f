<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    http_response_code(403);
    exit('Access denied');
}

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    exit('Invalid order ID');
}

$database = new Database();
$db = $database->getConnection();

// Get order details
$stmt = $db->prepare("
    SELECT o.*, u.full_name as user_name, u.email as user_email
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.id = ?
");
$stmt->execute([$order_id]);
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
require_once '../../config/variant_helpers.php';

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
        $display_image = '../uploads/products/' . $item['combination_image'];
    } elseif (!empty($item['variant_image'])) {
        // Use simple variant image if available
        $display_image = '../uploads/products/' . $item['variant_image'];
    } elseif (!empty($item['image_path'])) {
        // Use primary product image
        $display_image = '../uploads/products/' . $item['image_path'];
    } else {
        // Use default no-image
        $display_image = '../assets/images/no-image.jpg';
    }
    $item['display_image'] = $display_image;
}
unset($item);
?>

<style>
.variant-badge {
    display: inline-block;
    margin-bottom: 6px;
}

.admin-product-display {
    display: flex;
    align-items: center;
    gap: 12px;
}

.admin-product-image {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 6px;
    border: 2px solid #e5e7eb;
    flex-shrink: 0;
}

.admin-product-info {
    flex: 1;
}

.admin-product-name {
    font-weight: 600;
    color: #1f2937;
    font-size: 0.9rem;
    line-height: 1.3;
}
</style>

<div class="order-details-modern" id="orderDetailsContent">
    <!-- Modern Header with Gradient -->
    <div class="order-header-modern">
        <div class="order-header-bg"></div>
        <div class="order-header-content">
            <div class="order-main-info">
                <div class="order-icon-badge">
                    <i class="fas fa-receipt"></i>
                </div>
                <div>
                    <h2 class="order-number-display"><?php echo htmlspecialchars($order['order_number']); ?></h2>
                    <p class="order-date-display">
                        <i class="far fa-calendar-alt me-2"></i>
                        <?php echo date('F d, Y g:i A', strtotime($order['created_at'])); ?>
                    </p>
                </div>
            </div>
            <div class="order-status-modern">
                <span class="status-badge-modern status-<?php echo strtolower(str_replace(' ', '-', $order['status'])); ?>">
                    <i class="fas fa-circle status-dot-icon"></i>
                    <?php echo htmlspecialchars($order['status']); ?>
                </span>
                <?php if ($order['partner_id']): ?>
                <span class="partner-badge-modern">
                    <i class="fas fa-handshake me-2"></i>
                    <?php echo htmlspecialchars($order['partner_id']); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Information Grid -->
    <div class="info-grid-modern">
        <!-- Customer Information Card -->
        <div class="info-card-modern">
            <div class="info-card-header">
                <div class="info-card-icon" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                    <i class="fas fa-user"></i>
                </div>
                <h3 class="info-card-title">Customer Information</h3>
            </div>
            <div class="info-card-body">
                <div class="info-item-modern">
                    <div class="info-label"><i class="fas fa-user-circle me-2"></i>Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($order['full_name']); ?></div>
                </div>
                <div class="info-item-modern">
                    <div class="info-label"><i class="fas fa-envelope me-2"></i>Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($order['email']); ?></div>
                </div>
                <div class="info-item-modern">
                    <div class="info-label"><i class="fas fa-phone me-2"></i>Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($order['phone']); ?></div>
                </div>
                <div class="info-item-modern">
                    <div class="info-label"><i class="fas fa-map-marker-alt me-2"></i>City</div>
                    <div class="info-value"><?php echo htmlspecialchars($order['city']); ?></div>
                </div>
            </div>
        </div>

        <!-- Delivery Address Card -->
        <div class="info-card-modern">
            <div class="info-card-header">
                <div class="info-card-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    <i class="fas fa-map-marked-alt"></i>
                </div>
                <h3 class="info-card-title">Delivery Address</h3>
            </div>
            <div class="info-card-body">
                <div class="address-box-modern">
                    <i class="fas fa-location-arrow address-icon"></i>
                    <p class="address-text"><?php echo nl2br(htmlspecialchars($order['address'])); ?></p>
                </div>
            </div>
        </div>

        <!-- Payment Information Card -->
        <div class="info-card-modern">
            <div class="info-card-header">
                <div class="info-card-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                    <i class="fas fa-credit-card"></i>
                </div>
                <h3 class="info-card-title">Payment Information</h3>
            </div>
            <div class="info-card-body">
                <?php if (!empty($order['payment_method'])): ?>
                <div class="info-item-modern">
                    <div class="info-label"><i class="fas fa-wallet me-2"></i>Payment Method</div>
                    <div class="info-value">
                        <span class="badge bg-primary" style="font-size: 0.95rem; padding: 6px 12px;">
                            <?php echo htmlspecialchars($order['payment_method']); ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
                <div class="info-item-modern">
                    <div class="info-label"><i class="fas fa-user-tag me-2"></i>Account Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($order['payment_account_name']); ?></div>
                </div>
                <div class="info-item-modern">
                    <div class="info-label"><i class="fas fa-hashtag me-2"></i>Account Number</div>
                    <div class="info-value"><code class="account-code"><?php echo htmlspecialchars($order['payment_account_number']); ?></code></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Items Section -->
    <div class="order-items-section-modern">
        <div class="section-header-modern">
            <div class="section-icon-modern" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                <i class="fas fa-shopping-bag"></i>
            </div>
            <h3 class="section-title-modern">Order Items</h3>
        </div>
        <div class="items-table-modern">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th style="text-align: center;">Variant</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order_items as $item): ?>
                        <tr>
                            <td class="product-name-cell">
                                <div class="admin-product-display">
                                    <img src="<?php echo $item['display_image']; ?>" 
                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                         class="admin-product-image">
                                    <div class="admin-product-info">
                                        <div class="admin-product-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                            <td style="text-align: center;">
                                <?php if (!empty($item['combination_details'])): ?>
                                    <!-- Combination Variant Display -->
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap;">
                                        <span class="badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; display: inline-block;">
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
                                    <?php foreach ($item['all_variants'] as $index => $variant): ?>
                                        <span class="variant-badge"><?php echo ucfirst($variant['variant_type']) . ': ' . htmlspecialchars($variant['variant_name']); ?></span><?php if ($index < count($item['all_variants']) - 1) echo '<br style="margin-bottom: 6px;">'; ?>
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
                                            echo '<span class="variant-badge">' . ucfirst($variant_type) . ': ' . htmlspecialchars($item['variant_name']) . '</span>';
                                        } else {
                                            echo '<span class="variant-badge">' . htmlspecialchars($item['variant_name']) . '</span>';
                                        }
                                    } else {
                                        echo '<span class="variant-badge">' . htmlspecialchars($item['variant_name']) . '</span>';
                                    }
                                    ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="price-cell"><?php echo formatPrice($item['price']); ?></td>
                            <td class="quantity-cell">
                                <span class="quantity-badge"><?php echo $item['quantity']; ?></span>
                            </td>
                            <td class="total-cell"><?php echo formatPrice($item['price'] * $item['quantity']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Order Summary -->
    <div class="order-summary-modern">
        <div class="summary-card">
            <h3 class="summary-title">
                <i class="fas fa-calculator me-2"></i>Order Summary
            </h3>
            <div class="summary-items">
                <div class="summary-row">
                    <span class="summary-label">Subtotal</span>
                    <span class="summary-value"><?php echo formatPrice($order['subtotal']); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Delivery Charges</span>
                    <span class="summary-value"><?php echo formatPrice($order['delivery_charges']); ?></span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                <div class="summary-row discount-row">
                    <span class="summary-label">
                        <i class="fas fa-tag me-2"></i>Discount
                    </span>
                    <span class="summary-value">-<?php echo formatPrice($order['discount_amount']); ?></span>
                </div>
                <?php endif; ?>
                <div class="summary-divider"></div>
                <div class="summary-row total-row">
                    <span class="summary-label">Total Amount</span>
                    <span class="summary-value"><?php echo formatPrice($order['total_amount']); ?></span>
                </div>
            </div>
        </div>
    </div>
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
