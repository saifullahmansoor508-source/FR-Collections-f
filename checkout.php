<?php
require_once 'config/config.php';

if (!isLoggedIn()) {
    redirectTo('auth.php');
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Include variant helpers for combination string parsing
require_once 'config/variant_helpers.php';

// Clear any existing coupon data on fresh page load (when not submitting form and not coming from same session)
// Only clear if there's no coupon_applied flag or if user came from a different page
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_SESSION['coupon_applied'])) {
    unset($_SESSION['coupon_code']);
    unset($_SESSION['coupon_discount']);
}

// Check if this is a buy-now checkout, selected items checkout, or regular cart checkout
$is_buy_now = isset($_GET['buy_now']) && isset($_SESSION['buy_now_item']);
$is_selected = isset($_GET['selected']) && isset($_SESSION['selected_cart_items']);
$cart_items = [];

if ($is_buy_now) {
    // Get buy-now item details
    $buy_now = $_SESSION['buy_now_item'];
    $stmt = $db->prepare("
        SELECT p.id as product_id, NULL as variant_id, ? as quantity,
               p.name as product_name, p.original_price, p.discounted_price, p.status, p.commission_rate, p.delivery_charges,
               pi.image_path, cat.name as category_name,
               pv.variant_name, pv.variant_price, pv.variant_image,
               pvc.price as combination_price, pvc.sku as combination_sku, pvc.image_path as combination_image
        FROM products p
        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
        LEFT JOIN categories cat ON p.category_id = cat.id
        LEFT JOIN product_variants pv ON pv.id = ?
        LEFT JOIN product_variant_combinations pvc ON pvc.id = ?
        WHERE p.id = ?
    ");
    $combination_id = isset($buy_now['variant_combination_id']) ? $buy_now['variant_combination_id'] : null;
    $stmt->execute([$buy_now['quantity'], $buy_now['variant_id'], $combination_id, $buy_now['product_id']]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($item) {
        $item['variant_id'] = $buy_now['variant_id'];
        $item['variant_combination_id'] = $combination_id;
        $item['quantity'] = $buy_now['quantity'];
        $item['variant_selections'] = isset($buy_now['variant_selections']) ? $buy_now['variant_selections'] : null;
        
        // Fetch all variant details if variant_selections exists
        if (!empty($item['variant_selections'])) {
            $variant_ids = json_decode($item['variant_selections'], true);
            if (is_array($variant_ids) && !empty($variant_ids)) {
                $placeholders = implode(',', array_fill(0, count($variant_ids), '?'));
                $stmt = $db->prepare("SELECT id, variant_type, variant_name FROM product_variants WHERE id IN ($placeholders)");
                $stmt->execute(array_values($variant_ids));
                $item['all_variants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        // Fetch combination details if combination_id exists
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
        
        $cart_items[] = $item;
    }
} elseif ($is_selected) {
    // Get selected cart items
    $selected_ids = $_SESSION['selected_cart_items'];
    if (!empty($selected_ids) && is_array($selected_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $params = array_merge($selected_ids, [$_SESSION['user_id']]);
        
        $stmt = $db->prepare("
            SELECT c.*, p.name as product_name, p.original_price, p.discounted_price, p.status, p.commission_rate, p.delivery_charges,
                   pi.image_path, cat.name as category_name,
                   pv.variant_name, pv.variant_price, pv.variant_image,
                   pvc.price as combination_price, pvc.sku as combination_sku, pvc.image_path as combination_image
            FROM cart c
            JOIN products p ON c.product_id = p.id
            LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
            LEFT JOIN categories cat ON p.category_id = cat.id
            LEFT JOIN product_variants pv ON c.variant_id = pv.id
            LEFT JOIN product_variant_combinations pvc ON c.variant_combination_id = pvc.id
            WHERE c.id IN ($placeholders) AND c.user_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute($params);
        $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch all variant details for items with variant_selections and combination details
        foreach ($cart_items as &$item) {
            // Handle simple variants (old format)
            if (!empty($item['variant_selections'])) {
                $variant_ids = json_decode($item['variant_selections'], true);
                if (is_array($variant_ids) && !empty($variant_ids)) {
                    $placeholders_v = implode(',', array_fill(0, count($variant_ids), '?'));
                    $stmt = $db->prepare("SELECT id, variant_type, variant_name FROM product_variants WHERE id IN ($placeholders_v)");
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
    }
} else {
    // Get cart items
    $stmt = $db->prepare("
        SELECT c.*, p.name as product_name, p.original_price, p.discounted_price, p.status, p.commission_rate, p.delivery_charges,
               pi.image_path, cat.name as category_name,
               pv.variant_name, pv.variant_price, pv.variant_image,
               pvc.price as combination_price, pvc.sku as combination_sku, pvc.image_path as combination_image
        FROM cart c
        JOIN products p ON c.product_id = p.id
        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
        LEFT JOIN categories cat ON p.category_id = cat.id
        LEFT JOIN product_variants pv ON c.variant_id = pv.id
        LEFT JOIN product_variant_combinations pvc ON c.variant_combination_id = pvc.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all variant details for items with variant_selections and combination details
    foreach ($cart_items as &$item) {
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
            // Get combination details
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
}

if (empty($cart_items)) {
    // If buy-now session exists but item not found, clear it and redirect
    if ($is_buy_now) {
        unset($_SESSION['buy_now_item']);
    }
    // If selected items session exists but no items found, clear it
    if ($is_selected) {
        unset($_SESSION['selected_cart_items']);
    }
    redirectTo('cart.php');
}

$page_title = "Checkout";
require_once 'includes/header.php';

// Get user details
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate totals
$subtotal = 0;
$delivery_charges = 0;
$products_with_delivery = []; // Track unique products for delivery charges
foreach ($cart_items as $item) {
    // Priority: combination_price > variant_price > discounted_price > original_price
    $price = $item['combination_price'] ?: ($item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price']));
    $subtotal += $price * $item['quantity'];
    
    // Add delivery charges only once per unique product (not per variant or quantity)
    $product_id = $item['product_id'];
    if (!in_array($product_id, $products_with_delivery)) {
        $delivery_charges += floatval($item['delivery_charges'] ?: 0);
        $products_with_delivery[] = $product_id;
    }
}

// Get payment details
$stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('payment_account_name', 'payment_account_number')");
$stmt->execute();
$payment_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$discount_amount = isset($_SESSION['coupon_discount']) ? $_SESSION['coupon_discount'] : 0;
$total = $subtotal + $delivery_charges - $discount_amount;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = sanitizeInput($_POST['full_name']);
    $email = sanitizeInput($_POST['email']);
    $phone_raw = sanitizeInput($_POST['phone']);
    // Clean and format phone number (already has +92 prefix from input)
    $phone_clean = preg_replace('/[^0-9]/', '', $phone_raw);
    $phone = !empty($phone_clean) ? '+92' . substr($phone_clean, -10) : '';
    $address = sanitizeInput($_POST['address']);
    $city = sanitizeInput($_POST['city']);
    $payment_made = isset($_POST['payment_made']);
    $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
    $payment_account_name = sanitizeInput($_POST['payment_account_name'] ?? '');
    $payment_account_number_raw = sanitizeInput($_POST['payment_account_number'] ?? '');
    // Clean and format payment account number (already has +92 prefix from input)
    $payment_clean = preg_replace('/[^0-9]/', '', $payment_account_number_raw);
    $payment_account_number = !empty($payment_clean) ? '+92' . substr($payment_clean, -10) : '';
    // Add FR prefix to partner ID if provided
    $partner_id_input = sanitizeInput($_POST['partner_id'] ?? '');
    $partner_id = !empty($partner_id_input) ? 'FR' . $partner_id_input : '';
    
    // Validate phone numbers (should be 13 chars: +92XXXXXXXXXX)
    $phone_digits_only = preg_replace('/[^0-9]/', '', $phone);
    $payment_digits_only = preg_replace('/[^0-9]/', '', $payment_account_number);
    
    if (empty($full_name) || empty($email) || empty($phone_raw) || empty($address) || empty($city)) {
        $error = "All billing fields are required.";
    } elseif (strlen($phone_digits_only) != 12) {
        $error = "Phone number must be exactly 10 digits after +92.";
    } elseif (empty($payment_method)) {
        $error = "Please select a payment method.";
    } elseif (!$payment_made) {
        $error = "Please confirm that you have made the payment.";
    } elseif (empty($payment_account_name) || empty($payment_account_number_raw)) {
        $error = "Please provide your payment account details.";
    } elseif (strlen($payment_digits_only) != 12) {
        $error = "Payment account number must be exactly 10 digits after +92.";
    } else {
        // Generate order number
        $order_number = 'FR' . date('Ymd') . rand(1000, 9999);
        
        try {
            $db->beginTransaction();
            
            // Create order
            $stmt = $db->prepare("
                INSERT INTO orders (user_id, order_number, full_name, email, phone, address, city, 
                                  subtotal, delivery_charges, discount_amount, total_amount, partner_id, 
                                  payment_method, payment_account_name, payment_account_number) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'], $order_number, $full_name, $email, $phone, $address, $city,
                $subtotal, $delivery_charges, $discount_amount, $total, $partner_id,
                $payment_method, $payment_account_name, $payment_account_number
            ]);
            
            $order_id = $db->lastInsertId();
            
            // Add order items
            $total_commission = 0;
            foreach ($cart_items as $item) {
                // Priority: combination_price > variant_price > discounted_price > original_price
                $price = $item['combination_price'] ?: ($item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price']));
                
                // Calculate commission for this item based on product's commission rate
                $item_commission = 0;
                if ($partner_id && $partner_id !== 'FR') {
                    $product_commission_rate = floatval($item['commission_rate']);
                    
                    // Smart commission calculation:
                    // If commission_rate >= 100, treat it as FIXED RUPEE AMOUNT per item
                    // If commission_rate < 100, treat it as PERCENTAGE of item price
                    // If commission_rate = 0, use default 10% percentage
                    
                    if ($product_commission_rate >= 100) {
                        // Fixed amount: Rs X per item (e.g., Rs 999 per item)
                        $item_commission = $product_commission_rate * $item['quantity'];
                    } else if ($product_commission_rate > 0 && $product_commission_rate < 100) {
                        // Percentage-based: X% of item total
                        $item_total = $price * $item['quantity'];
                        $item_commission = ($item_total * $product_commission_rate) / 100;
                    } else {
                        // Default: 10% of item total
                        $item_total = $price * $item['quantity'];
                        $item_commission = ($item_total * 10.00) / 100;
                    }
                    
                    $total_commission += $item_commission;
                }
                
                $stmt = $db->prepare("
                    INSERT INTO order_items (order_id, product_id, variant_id, variant_combination_id, variant_selections, quantity, price, commission_amount) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $variant_selections = isset($item['variant_selections']) ? $item['variant_selections'] : null;
                $variant_combination_id = isset($item['variant_combination_id']) ? $item['variant_combination_id'] : null;
                $stmt->execute([$order_id, $item['product_id'], $item['variant_id'], $variant_combination_id, $variant_selections, $item['quantity'], $price, $item_commission]);
                
                // Update product sales count
                $stmt = $db->prepare("UPDATE products SET sales_count = sales_count + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            // Update affiliate stats if partner ID provided (even if commission is 0)
            if ($partner_id && $partner_id !== 'FR') {
                $stmt = $db->prepare("SELECT id FROM affiliates WHERE partner_id = ?");
                $stmt->execute([$partner_id]);
                $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($affiliate) {
                    // Always increment total_sales counter
                    // Update revenue, balance and earnings only if commission > 0
                    if ($total_commission > 0) {
                        $stmt = $db->prepare("
                            UPDATE affiliates 
                            SET total_sales = total_sales + 1, 
                                total_revenue = total_revenue + ?, 
                                balance = balance + ?,
                                total_earnings = total_earnings + ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$total_commission, $total_commission, $total_commission, $affiliate['id']]);
                    } else {
                        // Just increment sales count
                        $stmt = $db->prepare("
                            UPDATE affiliates 
                            SET total_sales = total_sales + 1
                            WHERE id = ?
                        ");
                        $stmt->execute([$affiliate['id']]);
                    }
                    
                    // Create affiliate earnings records for each order item with commission
                    $stmt = $db->prepare("
                        SELECT id, commission_amount, product_id 
                        FROM order_items 
                        WHERE order_id = ?
                    ");
                    $stmt->execute([$order_id]);
                    $order_items_with_commission = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($order_items_with_commission as $oi) {
                        // Only create earnings record if commission amount > 0
                        if ($oi['commission_amount'] > 0) {
                            $stmt = $db->prepare("
                                INSERT INTO affiliate_earnings 
                                (affiliate_id, order_id, order_item_id, product_id, commission_amount, status) 
                                VALUES (?, ?, ?, ?, ?, 'Pending')
                            ");
                            $stmt->execute([
                                $affiliate['id'], 
                                $order_id, 
                                $oi['id'], 
                                $oi['product_id'], 
                                $oi['commission_amount']
                            ]);
                        }
                    }
                }
            }
            
            // Clear cart or buy-now session
            if ($is_buy_now) {
                unset($_SESSION['buy_now_item']);
            } elseif ($is_selected) {
                // Delete only selected items from cart
                $selected_ids = $_SESSION['selected_cart_items'];
                if (!empty($selected_ids) && is_array($selected_ids)) {
                    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                    $params = array_merge($selected_ids, [$_SESSION['user_id']]);
                    $stmt = $db->prepare("DELETE FROM cart WHERE id IN ($placeholders) AND user_id = ?");
                    $stmt->execute($params);
                }
                unset($_SESSION['selected_cart_items']);
            } else {
                $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            }
            
            // Clear coupon session
            unset($_SESSION['coupon_code']);
            unset($_SESSION['coupon_discount']);
            unset($_SESSION['coupon_applied']);
            
            $db->commit();
            
            // Set success session and use JavaScript redirect to avoid header error
            $_SESSION['order_placed'] = $order_number;
            $success = 'order_placed';
            
        } catch (Exception $e) {
            $db->rollBack();
            // Log the actual error for debugging
            error_log("Order processing error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            
            // Check for specific error types to provide better user feedback
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $error = "Order number already exists. Please try again.";
            } elseif (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
                $error = "Database configuration error. Please contact support.";
            } elseif (strpos($e->getMessage(), 'Data too long') !== false) {
                $error = "One of the provided values is too long. Please check your input.";
            } elseif (strpos($e->getMessage(), 'cannot be null') !== false) {
                $error = "Required information is missing. Please fill all fields.";
            } else {
                $error = "Error processing order: " . $e->getMessage() . ". Please try again or contact support.";
            }
        }
    }
}
?>

<style>
.checkout-hero {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    padding: 80px 0 60px;
    position: relative;
    overflow: hidden;
    margin-bottom: 0;
}

.checkout-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.checkout-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -5%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    border-radius: 50%;
}

.checkout-hero-content {
    position: relative;
    z-index: 1;
    color: white;
}

.checkout-hero h1 {
    font-size: 3.5rem;
    font-weight: 800;
    margin-bottom: 20px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.checkout-hero p {
    font-size: 1.3rem;
    opacity: 0.95;
    margin-bottom: 0;
}

@media (max-width: 768px) {
    .checkout-hero h1 {
        font-size: 2.5rem;
    }
    
    .checkout-hero p {
        font-size: 1.1rem;
    }
}
</style>

<!-- Hero Section -->
<div class="checkout-hero">
    <div class="container">
        <div class="checkout-hero-content text-center">
            <h1>
                <i class="fas fa-shopping-cart me-3"></i>Secure Checkout
            </h1>
            <p>Complete your purchase securely and safely</p>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="container my-4">
        <div class="alert alert-danger"><?php echo $error; ?></div>
    </div>
<?php endif; ?>

<div class="container my-5">

    <form method="POST" id="checkoutForm">
        <div class="row">
            <!-- Main Checkout Content -->
            <div class="col-lg-8">
                <!-- Billing Information -->
                <div class="checkout-card mb-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-user-circle me-2"></i>
                            Billing Information
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name"
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">WhatsApp Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control phone-input-clean" id="phone" name="phone"
                                       value="<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', str_replace('+92', '', $user['phone'] ?: ''))); ?>" 
                                       placeholder="+923001234567" maxlength="13" inputmode="numeric" required>
                                <small class="form-text text-muted">Enter your 10-digit number (e.g., 3001234567)</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="city" class="form-label">City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="city" name="city"
                                       value="<?php echo htmlspecialchars($user['city']); ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="address" class="form-label">Delivery Address <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="address" name="address" rows="3" required><?php echo htmlspecialchars($user['address']); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Payment Information -->
                <div class="checkout-card mb-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-credit-card me-2"></i>
                            Payment Information
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- Payment Details -->
                        <div class="payment-info-box mb-4">
                            <h6 class="payment-info-title">Our Payment Details</h6>
                            <div class="payment-details">
                                <div class="payment-detail">
                                    <span class="detail-label">Account Name:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($payment_settings['payment_account_name'] ?? 'Shameem Mansoor'); ?></span>
                                </div>
                                <div class="payment-detail">
                                    <span class="detail-label">Account Number:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($payment_settings['payment_account_number'] ?? '03455836944'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Methods -->
                        <div class="mb-4">
                            <h6 class="payment-methods-title">Select Payment Method <span class="text-danger">*</span></h6>
                            <div class="payment-methods-grid">
                                <div class="payment-method-item" data-method="JazzCash" onclick="selectPaymentMethod('JazzCash', this)">
                                    <img src="assets/images/jazzcash.png" alt="JazzCash" class="payment-logo" onerror="this.style.display='none'">
                                    <span>JazzCash</span>
                                    <i class="fas fa-check-circle payment-check-icon"></i>
                                </div>
                                <div class="payment-method-item" data-method="Easypaisa" onclick="selectPaymentMethod('Easypaisa', this)">
                                    <img src="assets/images/easypaisa.png" alt="Easypaisa" class="payment-logo" onerror="this.style.display='none'">
                                    <span>Easypaisa</span>
                                    <i class="fas fa-check-circle payment-check-icon"></i>
                                </div>
                                <div class="payment-method-item" data-method="Upaisa" onclick="selectPaymentMethod('Upaisa', this)">
                                    <img src="assets/images/upaisa.png" alt="Upaisa" class="payment-logo" onerror="this.style.display='none'">
                                    <span>Upaisa</span>
                                    <i class="fas fa-check-circle payment-check-icon"></i>
                                </div>
                            </div>
                            <input type="hidden" id="payment_method" name="payment_method" required>
                            <div class="invalid-feedback" id="paymentMethodError" style="display: none;">
                                Please select a payment method
                            </div>
                        </div>

                        <!-- Payment Confirmation -->
                        <div class="payment-confirmation">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="payment_made" name="payment_made" required>
                                <label class="form-check-label" for="payment_made">
                                    I confirm that I have made the payment using one of the methods above <span class="text-danger">*</span>
                                </label>
                            </div>

                            <div id="paymentDetails" class="payment-details-form" style="display: none;">
                                <div class="mb-3">
                                    <label for="payment_account_name" class="form-label">Your Account Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="payment_account_name" name="payment_account_name">
                                </div>

                                <div class="mb-3">
                                    <label for="payment_account_number" class="form-label">Your Account Number <span class="text-danger">*</span></label>
                                    <input type="tel" class="form-control phone-input-clean" id="payment_account_number" name="payment_account_number" placeholder="+923001234567" maxlength="13" inputmode="numeric">
                                    <small class="form-text text-muted">Enter your 10-digit number</small>
                                </div>

                                <div class="mb-3">
                                    <label for="partner_id" class="form-label">Partner ID (Optional)</label>
                                    <div class="input-group">
                                        <span class="input-group-text" style="background-color: #f8f9fa; border-right: 2px solid #dee2e6; font-weight: 600;">FR</span>
                                        <input type="text" class="form-control" id="partner_id" name="partner_id" placeholder="8110" maxlength="4" pattern="[0-9]{4}" style="border-left: none;">
                                    </div>
                                    <small class="form-text text-muted">4 digits only</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Summary Sidebar -->
            <div class="col-lg-4">
                <div class="order-summary-card">
                    <div class="summary-header">
                        <h4 class="summary-title">Order Summary</h4>
                        <span class="summary-subtitle"><?php echo count($cart_items); ?> items</span>
                    </div>

                    <!-- Order Items -->
                    <div class="order-items-list">
                        <?php foreach ($cart_items as $item): ?>
                            <?php 
                            // Priority: combination_price > variant_price > discounted_price > original_price
                            $price = $item['combination_price'] ?: ($item['variant_price'] ?: ($item['discounted_price'] ?: $item['original_price'])); 
                            ?>
                            <div class="order-item">
                                <div class="item-image">
                                    <img src="<?php echo $item['display_image']; ?>"
                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                </div>
                                <div class="item-details">
                                    <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    
                                    <?php if (!empty($item['combination_details'])): ?>
                                        <!-- Combination Variant Display -->
                                        <div class="item-variant">
                                            <span class="badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; margin-right: 6px;">
                                                <i class="fas fa-layer-group"></i> Combination
                                            </span>
                                            <?php 
                                            $combination_parts = [];
                                            foreach ($item['combination_details'] as $attr => $value) {
                                                $combination_parts[] = '<strong>' . htmlspecialchars($attr) . ':</strong> ' . htmlspecialchars($value);
                                            }
                                            echo implode(' | ', $combination_parts);
                                            ?>
                                        </div>
                                    <?php elseif (!empty($item['all_variants'])): ?>
                                        <!-- Multiple Simple Variants Display -->
                                        <div class="item-variant">
                                            <?php 
                                            $variant_display = [];
                                            foreach ($item['all_variants'] as $variant) {
                                                $variant_display[] = '<strong>' . ucfirst($variant['variant_type']) . ':</strong> ' . htmlspecialchars($variant['variant_name']);
                                            }
                                            echo implode(', ', $variant_display);
                                            ?>
                                        </div>
                                    <?php elseif ($item['variant_name']): ?>
                                        <!-- Single Simple Variant Display -->
                                        <div class="item-variant">
                                            <?php
                                            // Try to get variant type for better display
                                            if (!empty($item['variant_id'])) {
                                                $vstmt = $db->prepare("SELECT variant_type FROM product_variants WHERE id = ?");
                                                $vstmt->execute([$item['variant_id']]);
                                                $variant_type = $vstmt->fetchColumn();
                                                if ($variant_type) {
                                                    echo '<strong>' . ucfirst($variant_type) . ':</strong> ' . htmlspecialchars($item['variant_name']);
                                                } else {
                                                    echo htmlspecialchars($item['variant_name']);
                                                }
                                            } else {
                                                echo htmlspecialchars($item['variant_name']);
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="item-quantity">Qty: <?php echo $item['quantity']; ?></div>
                                </div>
                                <div class="item-price">
                                    <?php echo formatPrice($price * $item['quantity']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Coupon Code Section -->
                    <div class="coupon-section">
                        <?php if ($discount_amount == 0): ?>
                            <div class="coupon-input-group">
                                <input type="text" class="form-control" id="couponCode" placeholder="Enter coupon code" style="border-radius: 8px 0 0 8px; border-right: none; text-transform: uppercase;">
                                <button type="button" class="btn btn-outline-primary" onclick="applyCoupon()" style="border-radius: 0 8px 8px 0; border-left: none;">
                                    Apply
                                </button>
                            </div>
                            <div id="couponMessage" class="coupon-message"></div>
                        <?php else: ?>
                            <div class="applied-coupon">
                                <div class="coupon-applied-info">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <span>Coupon applied! You saved <?php echo formatPrice($discount_amount); ?></span>
                                    <button type="button" class="btn-remove-coupon" onclick="removeCoupon()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Order Totals -->
                    <div class="order-totals">
                        <div class="total-row">
                            <span class="total-label">Subtotal:</span>
                            <span class="total-value"><?php echo formatPrice($subtotal); ?></span>
                        </div>

                        <div class="total-row">
                            <span class="total-label">Delivery Charges:</span>
                            <span class="total-value"><?php echo formatPrice($delivery_charges); ?></span>
                        </div>

                        <?php if ($discount_amount > 0): ?>
                            <div class="total-row discount-row">
                                <span class="total-label">Discount:</span>
                                <span class="total-value text-success">−<?php echo formatPrice($discount_amount); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="total-row discount-row" style="display: none;">
                                <span class="total-label">Discount:</span>
                                <span class="total-value text-success">−<?php echo formatPrice(0); ?></span>
                            </div>
                        <?php endif; ?>

                        <hr class="total-divider">

                        <div class="total-row final-total">
                            <span class="total-label">Total Amount:</span>
                            <span class="total-value final-value"><?php echo formatPrice($total); ?></span>
                        </div>
                    </div>

                    <!-- Place Order Button Removed - Moved to bottom of form -->
                </div>
            </div>
        </div>
        
        <!-- Place Order Button - Form Width Only -->
        <div class="row mt-4">
            <div class="col-12 col-lg-8">
                <button type="submit" id="placeOrderBtn" class="btn btn-primary btn-lg btn-place-order w-100" style="padding: 18px !important; font-size: 1.2rem !important; border-radius: 12px !important; background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%) !important; display: block !important; visibility: visible !important; opacity: 1 !important; position: relative !important; z-index: 100 !important;">
                    <i class="fas fa-lock me-2"></i>
                    Place Secure Order - <?php echo formatPrice($total); ?>
                </button>
                <div class="text-center mt-3">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt text-success me-1"></i>
                        Secure SSL Encrypted Payment
                    </small>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// Select payment method function
function selectPaymentMethod(method, element) {
    // Remove active class from all payment method items
    document.querySelectorAll('.payment-method-item').forEach(item => {
        item.classList.remove('active');
    });
    
    // Add active class to selected item
    element.classList.add('active');
    
    // Set hidden input value
    document.getElementById('payment_method').value = method;
    
    // Hide error message
    document.getElementById('paymentMethodError').style.display = 'none';
}

// Phone number validation - only allow numeric input and limit to 10 digits
document.addEventListener('DOMContentLoaded', function() {
    const paymentCheckbox = document.getElementById('payment_made');
    const paymentDetails = document.getElementById('paymentDetails');
    const accountName = document.getElementById('payment_account_name');
    const accountNumber = document.getElementById('payment_account_number');
    
    if (paymentCheckbox && paymentDetails) {
        paymentCheckbox.addEventListener('change', function() {
            if (this.checked) {
                paymentDetails.style.display = 'block';
                if (accountName) accountName.required = true;
                if (accountNumber) accountNumber.required = true;
            } else {
                paymentDetails.style.display = 'none';
                if (accountName) accountName.required = false;
                if (accountNumber) accountNumber.required = false;
            }
        });
    }
    
    // Clean phone input handler - NEW IMPLEMENTATION
    const phoneInputsClean = document.querySelectorAll('.phone-input-clean');
    
    phoneInputsClean.forEach(function(input) {
        // Initialize with +92 prefix if needed
        if (input.value && !input.value.startsWith('+92')) {
            input.value = '+92' + input.value.replace(/\D/g, '').slice(0, 10);
        } else if (!input.value) {
            input.value = '+92';
        }
        
        // Focus event - ensure +92 is present
        input.addEventListener('focus', function() {
            if (this.value === '' || !this.value.startsWith('+92')) {
                this.value = '+92';
            }
            // Move cursor after +92
            setTimeout(() => {
                this.setSelectionRange(3, 3);
            }, 0);
        });
        
        // Click event - prevent clicking before +92
        input.addEventListener('click', function(e) {
            if (this.selectionStart < 3) {
                this.setSelectionRange(3, 3);
            }
        });
        
        // Keydown event - prevent deleting +92
        input.addEventListener('keydown', function(e) {
            const cursorPos = this.selectionStart;
            
            // Prevent backspace/delete if it would affect +92
            if ((e.key === 'Backspace' && cursorPos <= 3) || 
                (e.key === 'Delete' && cursorPos < 3)) {
                e.preventDefault();
                this.setSelectionRange(3, 3);
                return false;
            }
            
            // Prevent left arrow before +92
            if (e.key === 'ArrowLeft' && cursorPos <= 3) {
                e.preventDefault();
                return false;
            }
            
            // Only allow numbers after +92
            if (e.key.length === 1 && !/^\d$/.test(e.key) && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                return false;
            }
        });
        
        // Input event - maintain +92 and limit to 10 digits
        input.addEventListener('input', function(e) {
            let value = this.value;
            
            // Always ensure it starts with +92
            if (!value.startsWith('+92')) {
                value = '+92' + value.replace(/[^\d]/g, '');
            }
            
            // Extract just the numbers after +92
            const prefix = '+92';
            const numbers = value.slice(3).replace(/\D/g, '');
            
            // Limit to 10 digits after +92
            const limitedNumbers = numbers.slice(0, 10);
            
            // Set the value
            this.value = prefix + limitedNumbers;
            
            // Maintain cursor position
            const cursorPos = this.selectionStart;
            if (cursorPos < 3) {
                this.setSelectionRange(3, 3);
            }
        });
        
        // Paste event - handle pasted content
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const numbers = pastedText.replace(/\D/g, '');
            
            // If pasted text starts with 92, remove it
            const cleanNumbers = numbers.startsWith('92') ? numbers.slice(2) : numbers;
            
            // Limit to 10 digits
            const limitedNumbers = cleanNumbers.slice(0, 10);
            
            this.value = '+92' + limitedNumbers;
            this.setSelectionRange(this.value.length, this.value.length);
        });
        
        // Blur event - ensure valid format
        input.addEventListener('blur', function() {
            if (this.value === '+92' || this.value === '') {
                this.value = '';
            }
        });
    });
});

// jQuery version as backup
$(document).ready(function() {
    // Show/hide payment details when checkbox is checked
    $('#payment_made').change(function() {
        if ($(this).is(':checked')) {
            $('#paymentDetails').slideDown();
            $('#payment_account_name, #payment_account_number').attr('required', true);
        } else {
            $('#paymentDetails').slideUp();
            $('#payment_account_name, #payment_account_number').removeAttr('required');
        }
    });

    // Only allow numbers in partner ID and limit to 4 digits
    $('#partner_id').on('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value.length > 4) {
            this.value = this.value.slice(0, 4);
        }
    });

    // Apply coupon code
    window.applyCoupon = function() {
        const couponCode = $('#couponCode').val().trim();

        if (!couponCode) {
            showCouponMessage('Please enter a coupon code', 'error');
            return;
        }

        // Show loading state
        const applyBtn = $('.coupon-input-group .btn');
        const originalText = applyBtn.text();
        applyBtn.text('Applying...').prop('disabled', true);

        $.post('ajax/apply_coupon.php', {
            coupon_code: couponCode
        }, function(data) {
            if (data.success) {
                // Reload the page to show the updated discount
                location.reload();
            } else {
                showCouponMessage(data.message, 'error');
                applyBtn.text(originalText).prop('disabled', false);
            }
        }, 'json').fail(function() {
            showCouponMessage('Error applying coupon. Please try again.', 'error');
            applyBtn.text(originalText).prop('disabled', false);
        });
    };

    // Add remove coupon button
    function addRemoveCouponButton(discountAmount) {
        const couponSection = $('.coupon-section');
        couponSection.html(`
            <div class="applied-coupon">
                <div class="coupon-applied-info">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span>Coupon applied successfully!</span>
                    <button type="button" class="btn-remove-coupon" onclick="removeCoupon()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `);
        couponSection.slideDown();
    }

    // Remove coupon
    window.removeCoupon = async function() {
        if (await showConfirm('Are you sure you want to remove this coupon?', 'Remove Coupon', {confirmText: 'Yes, Remove', cancelText: 'Cancel', type: 'warning'})) {
            $.post('ajax/remove_coupon.php', function(data) {
                if (data.success) {
                    // Reload the page to show updated totals without discount
                    location.reload();
                } else {
                    showAlert('Error removing coupon. Please try again.', 'error');
                }
            }, 'json').fail(function() {
                showAlert('Error removing coupon. Please try again.', 'error');
            });
        }
    };

    // Show coupon message
    function showCouponMessage(message, type) {
        const messageDiv = $('#couponMessage');
        messageDiv.removeClass('success error').addClass(type);
        messageDiv.text(message).slideDown();

        setTimeout(() => {
            messageDiv.slideUp();
        }, 5000);
    }

    // Update order totals display
    function updateOrderTotals(discountAmount) {
        const subtotalElement = $('.total-row').first().find('.total-value');
        const subtotal = parseFloat(subtotalElement.text().replace('PKR ', '').replace(',', ''));

        if (discountAmount > 0) {
            // Update final total
            const finalTotalElement = $('.final-total .total-value');
            const currentTotal = subtotal + 150 - discountAmount; // 150 is delivery charges
            finalTotalElement.text(formatPrice(currentTotal));

            // Update the order total in the button
            $('.order-total').text(formatPrice(currentTotal));
        }
    }

    // Format price for display (match PHP formatPrice function)
    function formatPrice(price) {
        return 'PKR ' + Math.round(price).toLocaleString('en-US');
    }
    
    // Phone inputs are now handled by vanilla JS above
    // No jQuery manipulation needed
    
    // Limit partner ID to 4 digits
    $('#partner_id').on('input', function() {
        let value = $(this).val().replace(/[^0-9]/g, '');
        if (value.length > 4) {
            value = value.substring(0, 4);
        }
        $(this).val(value);
    });
    
    // Show processing screen on form submit
    $('#checkoutForm').on('submit', function(e) {
        // Check if payment method is selected
        const paymentMethod = $('#payment_method').val();
        if (!paymentMethod) {
            e.preventDefault();
            $('#paymentMethodError').show();
            $('html, body').animate({
                scrollTop: $('#payment_method').offset().top - 100
            }, 500);
            return false;
        }
        
        // Check if all required fields are filled
        const form = this;
        if (!form.checkValidity()) {
            return true; // Let browser show validation errors
        }
        
        // Check if payment checkbox is checked
        if (!$('#payment_made').is(':checked')) {
            return true; // Let form submit to show error
        }
        
        // Show processing overlay
        const overlay = $('<div>').attr('id', 'processing-overlay').css({
            'position': 'fixed',
            'top': '0',
            'left': '0',
            'width': '100%',
            'height': '100%',
            'background': 'rgba(255, 255, 255, 0.98)',
            'z-index': '99999',
            'display': 'flex',
            'flex-direction': 'column',
            'align-items': 'center',
            'justify-content': 'center'
        }).html(`
            <div style="text-align: center;">
                <div class="processing-spinner" style="
                    width: 80px;
                    height: 80px;
                    border: 8px solid #f3f3f3;
                    border-top: 8px solid #2563eb;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                    margin: 0 auto 30px;
                "></div>
                <h2 style="color: #1e3a8a; font-size: 2rem; margin-bottom: 15px;">Processing Your Order...</h2>
                <p style="color: #64748b; font-size: 1.1rem;">Please wait while we confirm your order</p>
            </div>
        `);
        
        // Add spinner animation
        if (!$('#spinner-animation').length) {
            $('<style id="spinner-animation">').text(`
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `).appendTo('head');
        }
        
        $('body').append(overlay);
        
        // Disable the submit button to prevent double submission
        $('#placeOrderBtn').prop('disabled', true);
    });
});
</script>

<?php if (isset($success) && $success === 'order_placed'): ?>
<script>
// Show processing screen immediately - don't wait for DOMContentLoaded
(function() {
    // Add spinner animation first
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);
    
    // Create processing overlay
    const overlay = document.createElement('div');
    overlay.id = 'processing-overlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.98);
        z-index: 99999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    `;
    
    overlay.innerHTML = `
        <div style="text-align: center;">
            <div class="processing-spinner" style="
                width: 80px;
                height: 80px;
                border: 8px solid #f3f3f3;
                border-top: 8px solid #2563eb;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto 30px;
            "></div>
            <h2 style="color: #1e3a8a; font-size: 2rem; margin-bottom: 15px;">Processing Your Order...</h2>
            <p style="color: #64748b; font-size: 1.1rem;">Please wait while we confirm your order</p>
        </div>
    `;
    
    document.body.appendChild(overlay);
    
    // Redirect after 2 seconds
    setTimeout(function() {
        window.location.href = 'order-pending.php?order=<?php echo $_SESSION['order_placed']; ?>';
    }, 2000);
})();
</script>
<?php endif; ?>

<style>
/* Modern Checkout Page Styling */
.checkout-progress {
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    padding: 20px;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 40px;
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    position: relative;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.progress-step.active .step-number {
    background: #1e3a8a;
    color: white;
}

.step-title {
    font-size: 0.875rem;
    color: #64748b;
    font-weight: 500;
}

.progress-step.active .step-title {
    color: #1e3a8a;
    font-weight: 600;
}

.progress-line {
    width: 80px;
    height: 2px;
    background: #e2e8f0;
    margin: 0 15px;
    transition: all 0.3s ease;
}

.progress-line.active {
    background: #1e3a8a;
}

/* Checkout Cards */
.checkout-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    overflow: hidden;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.checkout-card:hover {
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}

.card-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: white;
    padding: 20px 25px;
    border-bottom: none;
}

.card-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

.card-body {
    padding: 25px;
}

/* Payment Information Box */
.payment-info-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
}

.payment-info-title {
    color: #1e3a8a;
    font-weight: 600;
    margin-bottom: 15px;
    font-size: 1rem;
}

.payment-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.payment-detail {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #e2e8f0;
}

.payment-detail:last-child {
    border-bottom: none;
}

.detail-label {
    font-weight: 500;
    color: #64748b;
}

.detail-value {
    font-weight: 600;
    color: #1e3a8a;
    font-family: monospace;
}

/* Payment Methods */
.payment-methods-title {
    color: #1e3a8a;
    font-weight: 600;
    margin-bottom: 15px;
    font-size: 1rem;
}

.payment-methods-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
}

.payment-method-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 15px;
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
}

.payment-method-item:hover {
    border-color: #1e3a8a;
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.15);
    transform: translateY(-2px);
}

.payment-method-item.active {
    border-color: #1e3a8a;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    box-shadow: 0 4px 15px rgba(30, 58, 138, 0.25);
}

.payment-check-icon {
    position: absolute;
    top: 8px;
    right: 8px;
    color: #10b981;
    font-size: 1.2rem;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.payment-method-item.active .payment-check-icon {
    opacity: 1;
}

.invalid-feedback {
    color: #dc3545;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

.payment-logo {
    height: 30px;
    width: auto;
    object-fit: contain;
}

.payment-method-item span {
    font-size: 0.875rem;
    font-weight: 500;
    color: #64748b;
}

/* Payment Confirmation */
.payment-confirmation {
    background: #fffbeb;
    border: 1px solid #fbbf24;
    border-radius: 12px;
    padding: 20px;
    margin-top: 20px;
}

.form-check-input:checked {
    background-color: #1e3a8a;
    border-color: #1e3a8a;
}

.form-check-label {
    font-weight: 500;
    color: #1e293b;
    cursor: pointer;
}

.payment-details-form {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-top: 20px;
    display: none;
}

/* Order Summary Card */
.order-summary-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    overflow: hidden;
    border: 1px solid #e2e8f0;
    position: sticky;
    top: 20px;
    height: fit-content;
}

.summary-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: white;
    padding: 20px 25px;
    text-align: center;
}

.summary-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0 0 5px 0;
}

.summary-subtitle {
    font-size: 0.875rem;
    opacity: 0.9;
    margin: 0;
}

/* Order Items */
.order-items-list {
    padding: 20px 25px;
    max-height: 400px;
    overflow-y: auto;
}

.order-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 1px solid #f1f5f9;
}

.order-item:last-child {
    border-bottom: none;
}

.item-image {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    overflow: hidden;
    flex-shrink: 0;
}

.item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.item-details {
    flex: 1;
    min-width: 0;
}

.item-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.95rem;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.item-variant {
    font-size: 0.8rem;
    color: #64748b;
    margin-bottom: 2px;
}

.item-quantity {
    font-size: 0.8rem;
    color: #94a3b8;
}

.item-price {
    font-weight: 600;
    color: #1e3a8a;
    font-size: 0.95rem;
    text-align: right;
}

/* Coupon Section */
.coupon-section {
    padding: 20px 25px;
    border-top: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
}

.coupon-input-group {
    display: flex;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.coupon-input-group input {
    flex: 1;
    border: none;
    padding: 12px 15px;
    font-size: 0.95rem;
}

.coupon-input-group input:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(30, 58, 138, 0.1);
}

.coupon-input-group .btn {
    border-radius: 0;
    font-weight: 600;
    padding: 12px 20px;
}

.coupon-message {
    margin-top: 10px;
    font-size: 0.875rem;
    font-weight: 500;
    text-align: center;
    padding: 8px;
    border-radius: 6px;
    display: none;
}

.coupon-message.success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.coupon-message.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* Applied Coupon Styles */
.applied-coupon {
    background: #d1fae5;
    border: 1px solid #a7f3d0;
    border-radius: 8px;
    padding: 15px;
    text-align: center;
}

.coupon-applied-info {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-weight: 600;
    color: #065f46;
}

.btn-remove-coupon {
    background: none;
    border: none;
    color: #059669;
    font-size: 1.1rem;
    cursor: pointer;
    padding: 5px;
    border-radius: 50%;
    transition: all 0.3s ease;
}

.btn-remove-coupon:hover {
    background: rgba(5, 150, 105, 0.1);
    color: #047857;
    transform: scale(1.1);
}

/* Order Totals */
.order-totals {
    padding: 20px 25px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    font-size: 0.95rem;
}

.total-label {
    color: #64748b;
    font-weight: 500;
}

.total-value {
    color: #1e293b;
    font-weight: 600;
}

.discount-row .total-value {
    color: #059669 !important;
}

.total-divider {
    border: none;
    border-top: 2px solid #f1f5f9;
    margin: 15px 0;
}

.final-total {
    font-size: 1.1rem;
    margin-bottom: 0;
}

.final-value {
    color: #1e3a8a !important;
    font-weight: 700;
    font-size: 1.2rem;
}

/* Place Order Button */
.order-button-container {
    padding: 25px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
}

.btn-place-order {
    width: 100%;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: white;
    border: none;
    padding: 18px 25px;
    border-radius: 12px;
    font-size: 1.1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 15px;
    box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
}

.btn-place-order:hover {
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(30, 58, 138, 0.4);
    color: white;
}

.order-total {
    background: rgba(255, 255, 255, 0.2);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
}

.security-notice {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.875rem;
    color: #059669;
    font-weight: 500;
}

/* Form Styling */
.form-label {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 8px;
    font-size: 0.95rem;
}

.form-control {
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 15px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
}

.form-control:focus {
    border-color: #1e3a8a;
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    outline: none;
}

.form-check-input {
    width: 20px;
    height: 20px;
    border: 2px solid #e2e8f0;
    border-radius: 4px;
    cursor: pointer;
}

.form-check-input:focus {
    border-color: #1e3a8a;
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* Alert Messages */
.alert {
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    border: none;
    animation: slideIn 0.3s ease-out;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
}

.alert i {
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    flex-shrink: 0;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Mobile Responsive */
@media (max-width: 991px) {
    .checkout-progress {
        padding: 15px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .progress-step {
        flex: 0 0 auto;
    }

    .progress-line {
        width: 40px;
        margin: 0 8px;
    }

    .order-summary-card {
        position: static;
        margin-top: 30px;
    }

    .payment-methods-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .payment-method-item {
        padding: 10px;
    }

    .payment-logo {
        height: 25px;
    }
}

@media (max-width: 768px) {
    .container {
        padding-left: 15px;
        padding-right: 15px;
    }

    .checkout-card {
        margin-bottom: 20px;
    }

    .card-header {
        padding: 15px 20px;
    }

    .card-body {
        padding: 20px;
    }

    .payment-methods-grid {
        grid-template-columns: 1fr;
    }

    .order-items-list {
        padding: 15px 20px;
    }

    .order-item {
        gap: 10px;
    }

    .item-image {
        width: 40px;
        height: 40px;
    }

    .item-name {
        font-size: 0.875rem;
    }

    .coupon-section, .order-totals, .order-button-container {
        padding: 15px 20px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>
