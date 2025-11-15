<?php
// Process form submission BEFORE any output
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/progress_helper.php';

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

// Check if user is logged in
if (!isLoggedIn()) {
    // Show access denied message
    $page_title = "Affiliate Program";
    require_once 'includes/header.php';
    ?>
    <div class="affiliate-access-denied-fullpage">
        <div class="access-denied-content">
            <div class="access-denied-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h2 class="access-denied-title">Access Denied</h2>
            <p class="access-denied-text">Please create an account or sign in to access our affiliate program.</p>
            <a href="auth.php" class="btn-access-auth">Sign In / Sign Up</a>
        </div>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

// Check if user is already an affiliate
$stmt = $db->prepare("SELECT * FROM affiliates WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

$is_affiliate = !empty($affiliate);

// Check Module 1 completion status (for partner ID visibility)
$module1_progress = checkModule1Completion($db, $_SESSION['user_id']);
$can_see_partner_id = $module1_progress['completed'];

// Handle affiliate signup
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_affiliate'])) {
    if ($is_affiliate) {
        $error = "You are already an affiliate member.";
    } else {
        $full_name = sanitizeInput($_POST['full_name']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $phone_raw = sanitizeInput($_POST['phone']);
        // Clean and format phone number (already has +92 prefix from input)
        $phone_clean = preg_replace('/[^0-9]/', '', $phone_raw);
        $phone = !empty($phone_clean) ? '+92' . substr($phone_clean, -10) : '';
        $address = sanitizeInput($_POST['address']);
        
        if (empty($full_name) || empty($email) || empty($password) || empty($phone_raw) || empty($address)) {
            $error = "All fields are required.";
        } else {
            // Verify user password
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($password, $user_data['password'])) {
                $error = "Incorrect password.";
            } else {
                // Generate unique sequential partner ID
                $partner_id = generatePartnerID($db);
                
                try {
                    $db->beginTransaction();
                    
                    // Update user profile
                    $stmt = $db->prepare("UPDATE users SET phone = ?, address = ? WHERE id = ?");
                    $stmt->execute([$phone, $address, $_SESSION['user_id']]);
                    
                    // Create affiliate record
                    $stmt = $db->prepare("INSERT INTO affiliates (user_id, partner_id) VALUES (?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $partner_id]);
                    
                    $db->commit();
                    $success = "affiliate_created";
                    
                    // Refresh affiliate data
                    $stmt = $db->prepare("SELECT * FROM affiliates WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
                    $is_affiliate = true;
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Error creating affiliate account. Please try again.";
                }
            }
        }
    }
}

// Handle affiliate signin
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['affiliate_signin'])) {
    $email = sanitizeInput($_POST['signin_email']);
    $password = $_POST['signin_password'];
    
    if (empty($email) || empty($password)) {
        $error = "All fields are required.";
    } else {
        $stmt = $db->prepare("SELECT u.*, a.* FROM users u JOIN affiliates a ON u.id = a.user_id WHERE u.email = ?");
        $stmt->execute([$email]);
        $user_affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_affiliate && password_verify($password, $user_affiliate['password'])) {
            $_SESSION['user_id'] = $user_affiliate['id'];
            $_SESSION['user_name'] = $user_affiliate['full_name'];
            $_SESSION['user_email'] = $user_affiliate['email'];
            
            $success = 'affiliate_login_successful';
        } else {
            $error = "not_affiliate";
        }
    }
}

// Get user data for form pre-filling
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// NOW load the header after form processing
if (!$is_affiliate) {
    $page_title = "Become an Affiliate";
} else {
    $page_title = "Affiliate Dashboard";
}
require_once 'includes/header.php';
?>

<?php if (!$is_affiliate): ?>
<!-- Affiliate Auth Section (Similar to auth.php) -->
<style>
    body {
        background: #f0f2f5
    }

    .auth-section {
        min-height: calc(100vh - 120px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px
    }

    .auth-wrapper {
        width: 100%;
        max-width: 950px;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 15px 40px rgba(0, 0, 0, .1);
        overflow: hidden;
        display: grid;
        grid-template-columns: 1fr 1fr;
        min-height: 600px
    }

    .auth-info-panel {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        padding: 60px 40px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden
    }

    .auth-info-panel::before {
        content: '';
        position: absolute;
        bottom: -50px;
        left: -50px;
        width: 200px;
        height: 200px;
        background: url("data:image/svg+xml,%3Csvg width='100' height='100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M 10 90 Q 50 10 90 90 T 170 90' fill='none' stroke='rgba(255,255,255,0.1)' stroke-width='10'/%3E%3C/svg%3E");
        opacity: .5
    }

    .auth-info-panel::after {
        content: '';
        position: absolute;
        top: -60px;
        right: -80px;
        width: 250px;
        height: 250px;
        background: url("data:image/svg+xml,%3Csvg width='100' height='100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M 10 10 Q 50 90 90 10 T 170 10' fill='none' stroke='rgba(255,255,255,0.1)' stroke-width='10'/%3E%3C/svg%3E");
        opacity: .5
    }

    .auth-info-content {
        position: relative;
        z-index: 1;
        color: #fff;
        text-align: center
    }

    .auth-info-content h2 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 1rem;
        line-height: 1.2
    }

    .auth-info-content p {
        font-size: 1.1rem;
        opacity: .9;
        line-height: 1.6
    }

    .auth-form-panel {
        padding: 60px 50px;
        display: flex;
        flex-direction: column;
        justify-content: center
    }

    .auth-form h3 {
        font-size: 2rem;
        color: #2c3e50;
        font-weight: 700;
        margin-bottom: 30px
    }

    .form-group {
        position: relative;
        margin-bottom: 20px
    }

    .form-group i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #95a5a6;
        font-size: 1rem;
        z-index: 1
    }

    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 14px 15px 14px 45px;
        border: 1px solid #ddd;
        border-radius: 10px;
        font-size: 1rem;
        transition: all .3s ease
    }

    .form-group textarea {
        resize: vertical;
        min-height: 80px;
        padding-top: 14px
    }

    .form-group input:focus,
    .form-group textarea:focus {
        outline: 0;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, .1)
    }

    .form-group input:focus~i,
    .form-group textarea:focus~i {
        color: #1e3a8a
    }

    .form-group input:read-only {
        background-color: #f8f9fa;
        cursor: not-allowed
    }

    .phone-input-wrapper {
        position: relative;
        width: 100%;
    }

    .phone-prefix {
        position: absolute;
        left: 45px;
        top: 50%;
        transform: translateY(-50%);
        color: #1e3a8a;
        font-weight: 600;
        font-size: 1rem;
        z-index: 2;
        pointer-events: none;
    }

    .auth-button {
        width: 100%;
        padding: 15px;
        background: #1e3a8a;
        color: #fff;
        border: none;
        border-radius: 50px;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
        transition: all .3s ease;
        margin-top: 10px
    }

    .auth-button:hover {
        background: #1e40af;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(30, 58, 138, .3)
    }

    .auth-footer {
        text-align: center;
        margin-top: 25px;
        color: #666;
        font-size: .95rem
    }

    .switch-link {
        color: #1e3a8a;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer
    }

    .switch-link:hover {
        text-decoration: underline
    }

    .hidden {
        display: none !important
    }

    .alert {
        padding: 15px 20px;
        border-radius: 50px;
        margin-bottom: 20px;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
        animation: slideIn 0.3s ease-out
    }

    .alert-danger {
        background: #ef4444;
        color: #fff;
        border: none
    }

    .alert-success {
        background: #10b981;
        color: #fff;
        border: none
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
        flex-shrink: 0
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px)
        }
        to {
            opacity: 1;
            transform: translateY(0)
        }
    }

    .phone-input-group {
        display: flex;
        align-items: center
    }

    .phone-prefix {
        position: absolute;
        left: 45px;
        top: 50%;
        transform: translateY(-50%);
        color: #2c3e50;
        font-weight: 500;
        z-index: 2;
        background: white;
        padding-right: 5px
    }

    .form-group.phone-group input {
        padding-left: 85px
    }

    @media (max-width:992px) {
        .auth-wrapper {
            grid-template-columns: 1fr
        }

        .auth-info-panel {
            padding: 50px 30px
        }

        .auth-form-panel {
            padding: 50px 30px
        }
    }

    @media (max-width:768px) {
        .auth-section {
            padding: 20px 15px
        }

        .auth-wrapper {
            max-width: 450px
        }

        .auth-info-panel {
            display: none
        }

        .auth-form-panel {
            padding: 40px 25px
        }

        .auth-form h3 {
            font-size: 1.8rem
        }
    }

    @media (max-width:480px) {
        .auth-wrapper {
            border-radius: 0;
            min-height: 100vh
        }

        .auth-form-panel {
            padding: 30px 20px
        }
    }
</style>

<section class="auth-section">
    <div class="auth-wrapper">
        <div class="auth-info-panel">
            <div class="auth-info-content">
                <div id="signup-info">
                    <h2>Join Our Affiliate Team</h2>
                    <p>Start earning by promoting our products. It's fast and free to join!</p>
                </div>
                <div id="signin-info" class="hidden">
                    <h2>Welcome Back, Affiliate!</h2>
                    <p>Sign in to your affiliate dashboard.</p>
                </div>
            </div>
        </div>
        <div class="auth-form-panel">
            <?php if ($error): ?>
                <?php if ($error === 'not_affiliate'): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Invalid credentials or you are not an affiliate member.</span>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($success === 'affiliate_login_successful'): ?>
                <div class="alert alert-success" id="login-success-alert">
                    <i class="fas fa-check-circle"></i>
                    <span>Login Successful! Loading dashboard...</span>
                </div>
            <?php elseif ($success === 'affiliate_created'): ?>
                <div class="alert alert-success" id="signup-success-alert">
                    <i class="fas fa-check-circle"></i>
                    <span>Affiliate account created! Loading dashboard...</span>
                </div>
            <?php endif; ?>
            
            <!-- Affiliate Signup Form -->
            <form id="signup-form" class="auth-form<?php echo ($error === 'not_affiliate' || $success === 'affiliate_login_successful') ? ' hidden' : ''; ?>" method="POST">
                <h3>Become an Affiliate</h3>
                <div class="form-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="full_name" placeholder="Full Name" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly required>
                </div>
                <div class="form-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly required>
                </div>
                <div class="form-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" placeholder="Confirm Your Password" required>
                </div>
                <div class="form-group">
                    <i class="fas fa-phone"></i>
                    <input type="tel" class="form-control phone-input-clean" name="phone" id="phone" placeholder="+923001234567" maxlength="13" inputmode="numeric" required>
                </div>
                <div class="form-group">
                    <i class="fas fa-map-marker-alt" style="top: 28px;"></i>
                    <textarea name="address" placeholder="Full Address" required><?php echo htmlspecialchars($user['address'] ?: ''); ?></textarea>
                </div>
                <button type="submit" name="create_affiliate" class="auth-button">Create Affiliate Account</button>
            </form>
            
            <!-- Affiliate Signin Form -->
            <form id="signin-form" class="auth-form<?php echo ($error === 'not_affiliate' || $success === 'affiliate_login_successful') ? '' : ' hidden'; ?>" method="POST">
                <h3>Affiliate Sign In</h3>
                <div class="form-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="signin_email" placeholder="Email" required>
                </div>
                <div class="form-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="signin_password" placeholder="Password" required>
                </div>
                <button type="submit" name="affiliate_signin" class="auth-button">Sign In</button>
            </form>
            
            <div class="auth-footer">
                <p id="switch-text"><?php echo ($error === 'not_affiliate' || $success === 'affiliate_login_successful') ? 'New here? <a class="switch-link" id="switch-btn">Become an Affiliate</a>' : 'Already an Affiliate? <a class="switch-link" id="switch-btn">Sign In</a>'; ?></p>
            </div>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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

        const switchBtn = document.getElementById('switch-btn');
        const signupForm = document.getElementById('signup-form');
        const signinForm = document.getElementById('signin-form');
        const signupInfo = document.getElementById('signup-info');
        const signinInfo = document.getElementById('signin-info');
        const switchText = document.getElementById('switch-text');

        // Handle success redirects with delay
        const loginSuccessAlert = document.getElementById('login-success-alert');
        const signupSuccessAlert = document.getElementById('signup-success-alert');
        
        if (loginSuccessAlert || signupSuccessAlert) {
            setTimeout(function() {
                window.location.href = 'affiliate.php';
            }, 2000);
        }

        // Ensure correct form is visible on page load based on PHP state
        <?php if ($error === 'not_affiliate' || $success === 'affiliate_login_successful'): ?>
        if (!signinInfo.classList.contains('hidden')) {
            signupInfo.classList.add('hidden');
        }
        signinInfo.classList.remove('hidden');
        <?php endif; ?>

        switchBtn.addEventListener('click', function(e) {
            e.preventDefault();

            // Toggle forms
            signupForm.classList.toggle('hidden');
            signinForm.classList.toggle('hidden');

            // Toggle info panels
            signupInfo.classList.toggle('hidden');
            signinInfo.classList.toggle('hidden');

            // Update switch text
            if (signupForm.classList.contains('hidden')) {
                switchText.innerHTML = 'New here? <a class="switch-link" id="switch-btn">Become an Affiliate</a>';
            } else {
                switchText.innerHTML = 'Already an Affiliate? <a class="switch-link" id="switch-btn">Sign In</a>';
            }

            // Re-attach event listener to the new switch button
            document.getElementById('switch-btn').addEventListener('click', arguments.callee);
        });
    });
</script>

<?php else: ?>
<!-- Affiliate Dashboard -->
<div class="container-fluid" style="padding: 0; margin: 0;">
    <?php if ($is_affiliate): ?>
        <?php
        // Calculate commission DYNAMICALLY from database for ALL orders with this partner_id
        // This reads actual commission_amount from order_items which was calculated during checkout
        // Works for confirmed orders only (Confirmed, On The Way, Delivered)
        
        // STEP 1: Get all confirmed orders with this partner_id
        $stmt = $db->prepare("
            SELECT DISTINCT o.id, o.order_number
            FROM orders o
            WHERE o.partner_id = ? 
            AND o.status IN ('Confirmed', 'On The Way', 'Delivered')
        ");
        $stmt->execute([$affiliate['partner_id']]);
        $confirmed_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $confirmed_orders_count = count($confirmed_orders);
        
        // STEP 2: For each order, sum up the commission from order_items
        $available_balance = 0;
        
        foreach ($confirmed_orders as $order) {
            $stmt = $db->prepare("
                SELECT SUM(commission_amount) as order_commission
                FROM order_items
                WHERE order_id = ?
                AND commission_amount > 0
            ");
            $stmt->execute([$order['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['order_commission']) {
                $available_balance += floatval($result['order_commission']);
            }
        }
        
        // Store the total commission earned (this is the Total Earnings)
        $total_earnings = $available_balance;
        
        // Calculate ALL withdrawal requests (Pending, Completed, Rejected - all statuses)
        $stmt = $db->prepare("
            SELECT SUM(amount) as total_withdrawals 
            FROM withdrawals 
            WHERE user_id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $withdrawals_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_withdrawals = $withdrawals_data['total_withdrawals'] ? floatval($withdrawals_data['total_withdrawals']) : 0;
        
        // Available balance = Total Earnings - ALL withdrawal requests
        $available_balance = $total_earnings - $total_withdrawals;
        ?>
        
        <!-- Earnings Overview Section -->
        <div class="affiliate-earnings-section" style="overflow: hidden; position: relative; margin: 0 -15px; width: 100%;">
            <!-- Animated Background -->
            <div class="earnings-animated-bg" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, #0a1128 0%, #1e3a8a 50%, #2563eb 100%); z-index: 1;">
                <div class="bg-orb" style="position: absolute; width: 300px; height: 300px; background: radial-gradient(circle, rgba(59, 130, 246, 0.4) 0%, transparent 70%); border-radius: 50%; top: -150px; right: -150px; animation: float 8s ease-in-out infinite;"></div>
                <div class="bg-orb" style="position: absolute; width: 250px; height: 250px; background: radial-gradient(circle, rgba(16, 185, 129, 0.3) 0%, transparent 70%); border-radius: 50%; bottom: -125px; left: -125px; animation: float 10s ease-in-out infinite reverse;"></div>
                <div class="bg-orb" style="position: absolute; width: 200px; height: 200px; background: radial-gradient(circle, rgba(139, 92, 246, 0.3) 0%, transparent 70%); border-radius: 50%; top: 50%; left: 50%; transform: translate(-50%, -50%); animation: pulse 6s ease-in-out infinite;"></div>
                <!-- Animated particles -->
                <div class="particles" style="position: absolute; width: 100%; height: 100%; overflow: hidden;">
                    <div class="particle" style="position: absolute; width: 4px; height: 4px; background: rgba(255, 255, 255, 0.6); border-radius: 50%; top: 20%; left: 10%; animation: twinkle 3s ease-in-out infinite;"></div>
                    <div class="particle" style="position: absolute; width: 3px; height: 3px; background: rgba(255, 255, 255, 0.5); border-radius: 50%; top: 60%; left: 80%; animation: twinkle 4s ease-in-out infinite 1s;"></div>
                    <div class="particle" style="position: absolute; width: 5px; height: 5px; background: rgba(255, 255, 255, 0.4); border-radius: 50%; top: 40%; left: 50%; animation: twinkle 5s ease-in-out infinite 2s;"></div>
                    <div class="particle" style="position: absolute; width: 3px; height: 3px; background: rgba(255, 255, 255, 0.7); border-radius: 50%; top: 80%; left: 30%; animation: twinkle 3.5s ease-in-out infinite 0.5s;"></div>
                    <div class="particle" style="position: absolute; width: 4px; height: 4px; background: rgba(255, 255, 255, 0.5); border-radius: 50%; top: 25%; left: 70%; animation: twinkle 4.5s ease-in-out infinite 1.5s;"></div>
                </div>
            </div>
            
            <div style="position: relative; z-index: 2; padding: 45px 40px 40px; background: transparent; max-width: 1400px; margin: 0 auto;">
                        <div class="d-flex justify-content-between align-items-center mb-4" style="display: none !important;">
                        <?php if ($available_balance >= 100): ?>
                            <button class="btn" onclick="openWithdrawalModal()"
                                    style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 12px 30px; border-radius: 50px; font-weight: 700; font-size: 1rem; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);"
                                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(16, 185, 129, 0.4)'"
                                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(16, 185, 129, 0.3)'">
                                <i class="fas fa-money-bill-wave me-2"></i>Request Withdrawal
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <!-- Partner ID Card -->
                        <div class="col-lg-3 col-md-6 mb-4">
                            <?php if ($can_see_partner_id): ?>
                                <!-- Show Partner ID -->
                                <div class="earning-card modern animated-card" style="background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 50%, #6d28d9 100%); color: white; padding: 40px 32px; border-radius: 28px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 20px 50px rgba(91, 33, 182, 0.7), 0 0 40px rgba(124, 58, 237, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.2); position: relative; overflow: hidden; transition: all 0.4s ease; animation: cardPulse 4s ease-in-out infinite; border: none; height: 280px;"
                                     onmouseover="this.style.transform='translateY(-12px) scale(1.03)'; this.style.boxShadow='0 25px 55px rgba(30, 64, 175, 0.6), 0 0 40px rgba(59, 130, 246, 0.5)'; this.style.animation='none'"
                                     onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.boxShadow='0 15px 40px rgba(30, 64, 175, 0.5), 0 0 30px rgba(59, 130, 246, 0.3)'; this.style.animation='cardPulse 4s ease-in-out infinite'">
                                    <div style="position: absolute; top: -30%; right: -15%; width: 200px; height: 200px; background: rgba(255,255,255,0.2); border-radius: 50%; animation: rotate 20s linear infinite;"></div>
                                    <div style="position: absolute; bottom: -40%; left: -25%; width: 180px; height: 180px; background: rgba(255,255,255,0.15); border-radius: 50%; animation: rotate 15s linear infinite reverse;"></div>
                                    <div style="position: absolute; top: 20%; left: 10%; width: 80px; height: 80px; background: rgba(255,255,255,0.12); border-radius: 50%;"></div>
                                    <i class="fas fa-id-card" style="position: relative; z-index: 2; color: white; font-size: 3.5rem; margin-bottom: 20px; filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.4)); animation: iconFloat 3s ease-in-out infinite;"></i>
                                    <h5 class="mb-3" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 800; font-size: 0.85rem; color: rgba(255, 255, 255, 0.95); text-shadow: 0 2px 12px rgba(0, 0, 0, 0.6); letter-spacing: 3px; text-transform: uppercase;">PARTNER ID</h5>
                                    <h2 class="mb-0" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 900; font-size: 2.8rem; color: white; text-shadow: 0 4px 24px rgba(0, 0, 0, 0.7), 0 2px 8px rgba(0, 0, 0, 0.4); letter-spacing: 2px; -webkit-font-smoothing: antialiased;"><?php echo htmlspecialchars($affiliate['partner_id']); ?></h2>
                                </div>
                            <?php else: ?>
                                <!-- Show Locked State -->
                                <div class="earning-card modern animated-card" style="background: linear-gradient(135deg, #64748b 0%, #475569 50%, #334155 100%); color: white; padding: 40px 32px; border-radius: 28px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 20px 50px rgba(100, 116, 139, 0.7); position: relative; overflow: hidden; transition: all 0.4s ease; border: none; height: 280px;">
                                    <i class="fas fa-lock" style="position: relative; z-index: 2; color: white; font-size: 3.5rem; margin-bottom: 20px; filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.4)); opacity: 0.8;"></i>
                                    <h5 class="mb-3" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 800; font-size: 0.85rem; color: rgba(255, 255, 255, 0.95); letter-spacing: 3px; text-transform: uppercase;">PARTNER ID</h5>
                                    <h2 class="mb-2" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 900; font-size: 2rem; color: white; letter-spacing: 2px;">LOCKED</h2>
                                    <p style="position: relative; z-index: 2; font-size: 0.75rem; color: rgba(255,255,255,0.8); margin: 0;">Complete Module 1 to unlock</p>
                                    <?php if (!empty($module1_progress['missing_topics']) || !empty($module1_progress['missing_quizzes'])): ?>
                                        <div style="position: relative; z-index: 2; margin-top: 15px; font-size: 0.7rem; color: rgba(255,255,255,0.7);">
                                            <?php if (count($module1_progress['missing_topics']) > 0): ?>
                                                <div>📚 <?php echo count($module1_progress['missing_topics']); ?> lecture(s) remaining</div>
                                            <?php endif; ?>
                                            <?php if (count($module1_progress['missing_quizzes']) > 0): ?>
                                                <div>📝 <?php echo count($module1_progress['missing_quizzes']); ?> quiz(zes) to pass</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Available Balance Card -->
                        <div class="col-lg-3 col-md-6 mb-4">
                            <div class="earning-card modern animated-card" style="background: linear-gradient(135deg, #0e7490 0%, #0891b2 50%, #06b6d4 100%); color: white; padding: 40px 32px; border-radius: 28px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 20px 50px rgba(14, 116, 144, 0.7), 0 0 40px rgba(8, 145, 178, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.2); position: relative; overflow: hidden; transition: all 0.4s ease; animation: cardPulse 4s ease-in-out infinite 1s; border: none; height: 280px;"
                                 onmouseover="this.style.transform='translateY(-12px) scale(1.03)'; this.style.boxShadow='0 25px 55px rgba(5, 150, 105, 0.6), 0 0 40px rgba(16, 185, 129, 0.5)'; this.style.animation='none'"
                                 onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.boxShadow='0 15px 40px rgba(5, 150, 105, 0.5), 0 0 30px rgba(16, 185, 129, 0.3)'; this.style.animation='cardPulse 4s ease-in-out infinite 1s'">
                                <div style="position: absolute; top: -30%; right: -15%; width: 200px; height: 200px; background: rgba(255,255,255,0.2); border-radius: 50%; animation: rotate 20s linear infinite;"></div>
                                <div style="position: absolute; bottom: -40%; left: -25%; width: 180px; height: 180px; background: rgba(255,255,255,0.15); border-radius: 50%; animation: rotate 15s linear infinite reverse;"></div>
                                <div style="position: absolute; top: 20%; left: 10%; width: 80px; height: 80px; background: rgba(255,255,255,0.12); border-radius: 50%;"></div>
                                <i class="fas fa-coins" style="position: relative; z-index: 2; color: white; font-size: 3.5rem; margin-bottom: 20px; filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.4)); animation: iconFloat 3s ease-in-out infinite 0.5s;"></i>
                                <h5 class="mb-3" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 800; font-size: 0.85rem; color: rgba(255, 255, 255, 0.95); text-shadow: 0 2px 12px rgba(0, 0, 0, 0.6); letter-spacing: 3px; text-transform: uppercase;">AVAILABLE BALANCE</h5>
                                <h2 class="mb-0" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 900; font-size: 2.8rem; color: white; text-shadow: 0 4px 24px rgba(0, 0, 0, 0.7), 0 2px 8px rgba(0, 0, 0, 0.4); -webkit-font-smoothing: antialiased;"><?php echo formatPrice($available_balance); ?></h2>
                            </div>
                        </div>

                        <!-- Total Earnings Card -->
                        <div class="col-lg-3 col-md-6 mb-4">
                            <div class="earning-card modern animated-card" style="background: linear-gradient(135deg, #047857 0%, #059669 50%, #10b981 100%); color: white; padding: 40px 32px; border-radius: 28px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 20px 50px rgba(4, 120, 87, 0.7), 0 0 40px rgba(5, 150, 105, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.2); position: relative; overflow: hidden; transition: all 0.4s ease; animation: cardPulse 4s ease-in-out infinite 2s; border: none; height: 280px;"
                                 onmouseover="this.style.transform='translateY(-12px) scale(1.03)'; this.style.boxShadow='0 25px 55px rgba(124, 58, 237, 0.6), 0 0 40px rgba(139, 92, 246, 0.5)'; this.style.animation='none'"
                                 onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.boxShadow='0 15px 40px rgba(124, 58, 237, 0.5), 0 0 30px rgba(139, 92, 246, 0.3)'; this.style.animation='cardPulse 4s ease-in-out infinite 2s'">
                                <div style="position: absolute; top: -30%; right: -15%; width: 200px; height: 200px; background: rgba(255,255,255,0.2); border-radius: 50%; animation: rotate 20s linear infinite;"></div>
                                <div style="position: absolute; bottom: -40%; left: -25%; width: 180px; height: 180px; background: rgba(255,255,255,0.15); border-radius: 50%; animation: rotate 15s linear infinite reverse;"></div>
                                <div style="position: absolute; top: 20%; left: 10%; width: 80px; height: 80px; background: rgba(255,255,255,0.12); border-radius: 50%;"></div>
                                <i class="fas fa-trophy" style="position: relative; z-index: 2; color: white; font-size: 3.5rem; margin-bottom: 20px; filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.4)); animation: iconFloat 3s ease-in-out infinite 1s;"></i>
                                <h5 class="mb-3" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 800; font-size: 0.85rem; color: rgba(255, 255, 255, 0.95); text-shadow: 0 2px 12px rgba(0, 0, 0, 0.6); letter-spacing: 3px; text-transform: uppercase;">TOTAL EARNINGS</h5>
                                <h2 class="mb-0" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 900; font-size: 2.8rem; color: white; text-shadow: 0 4px 24px rgba(0, 0, 0, 0.7), 0 2px 8px rgba(0, 0, 0, 0.4); -webkit-font-smoothing: antialiased;"><?php echo formatPrice($total_earnings); ?></h2>
                            </div>
                        </div>

                        <!-- Confirmed Sales Card -->
                        <div class="col-lg-3 col-md-6 mb-4">
                            <div class="earning-card modern animated-card" style="background: linear-gradient(135deg, #be185d 0%, #db2777 50%, #ec4899 100%); color: white; padding: 40px 32px; border-radius: 28px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 20px 50px rgba(190, 24, 93, 0.7), 0 0 40px rgba(219, 39, 119, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.2); position: relative; overflow: hidden; transition: all 0.4s ease; animation: cardPulse 4s ease-in-out infinite 3s; border: none; height: 280px;"
                                 onmouseover="this.style.transform='translateY(-12px) scale(1.03)'; this.style.boxShadow='0 25px 55px rgba(220, 38, 38, 0.6), 0 0 40px rgba(245, 158, 11, 0.5)'; this.style.animation='none'"
                                 onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.boxShadow='0 15px 40px rgba(220, 38, 38, 0.5), 0 0 30px rgba(245, 158, 11, 0.3)'; this.style.animation='cardPulse 4s ease-in-out infinite 3s'">
                                <div style="position: absolute; top: -30%; right: -15%; width: 200px; height: 200px; background: rgba(255,255,255,0.2); border-radius: 50%; animation: rotate 20s linear infinite;"></div>
                                <div style="position: absolute; bottom: -40%; left: -25%; width: 180px; height: 180px; background: rgba(255,255,255,0.15); border-radius: 50%; animation: rotate 15s linear infinite reverse;"></div>
                                <div style="position: absolute; top: 20%; left: 10%; width: 80px; height: 80px; background: rgba(255,255,255,0.12); border-radius: 50%;"></div>
                                <i class="fas fa-shopping-cart" style="position: relative; z-index: 2; color: white; font-size: 3.5rem; margin-bottom: 20px; filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.4)); animation: iconFloat 3s ease-in-out infinite 1.5s;"></i>
                                <h5 class="mb-3" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 800; font-size: 0.85rem; color: rgba(255, 255, 255, 0.95); text-shadow: 0 2px 12px rgba(0, 0, 0, 0.6); letter-spacing: 3px; text-transform: uppercase;">CONFIRMED SALES</h5>
                                <h2 class="mb-0" style="position: relative; z-index: 2; font-family: 'Poppins', sans-serif; font-weight: 900; font-size: 2.8rem; color: white; text-shadow: 0 4px 24px rgba(0, 0, 0, 0.7), 0 2px 8px rgba(0, 0, 0, 0.4); -webkit-font-smoothing: antialiased;"><?php echo number_format($confirmed_orders_count); ?></h2>
                            </div>
                        </div>

                    </div>

                        <?php if ($available_balance < 100): ?>
                            <div class="alert alert-warning" style="border-radius: 15px; border: none; background: linear-gradient(135deg, #fef3c7, #fde68a 100%); color: #92400e; padding: 20px; margin-top: 20px; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.2); backdrop-filter: blur(10px);">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Minimum withdrawal amount is PKR 100.</strong> Keep earning to reach the threshold!
                            </div>
                        <?php endif; ?>

                        <!-- Collect Partner Card Mini Button -->
                        <div class="collect-card-section" style="margin-top: 30px; text-align: center;">
                            <button class="collect-card-mini-btn" onclick="collectPartnerCard()" style="
                                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                color: white;
                                border: none;
                                padding: 15px 30px;
                                border-radius: 50px;
                                font-weight: 600;
                                font-size: 1rem;
                                cursor: pointer;
                                transition: all 0.3s ease;
                                box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
                                position: relative;
                                overflow: hidden;
                                display: inline-flex;
                                align-items: center;
                                gap: 10px;
                                text-transform: uppercase;
                                letter-spacing: 1px;
                                font-family: 'Poppins', sans-serif;
                            " 
                            onmouseover="this.style.transform='translateY(-3px) scale(1.05)'; this.style.boxShadow='0 12px 35px rgba(102, 126, 234, 0.6)';"
                            onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.boxShadow='0 8px 25px rgba(102, 126, 234, 0.4)';">
                                <i class="fas fa-credit-card" style="font-size: 1.2rem; animation: cardPulse 2s ease-in-out infinite;"></i>
                                <span>Collect Your Partner Card</span>
                                <div style="
                                    position: absolute;
                                    top: 0;
                                    left: -100%;
                                    width: 100%;
                                    height: 100%;
                                    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
                                    transition: left 0.5s ease;
                                " class="shimmer-effect"></div>
                            </button>
                            <p style="margin-top: 15px; color: #666; font-size: 0.9rem; font-style: italic;">
                                <i class="fas fa-info-circle" style="color: #667eea; margin-right: 5px;"></i>
                                Build your partner card collection and unlock exclusive rewards!
                            </p>
                        </div>
            </div>
        </div>
        
        <!-- Learn to Earn Section -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="bg-white rounded-lg shadow-lg" style="border-radius: 20px !important; overflow: visible;">
                    <div class="affiliate-section-header" onclick="toggleAffiliateSection('learntoearn')" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); padding: 20px 30px; cursor: pointer; transition: all 0.3s ease; border-radius: 20px 20px 0 0;">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0" style="color: white; font-weight: 700; font-size: 1.75rem;">
                                <i class="fas fa-graduation-cap me-2"></i>Learn to Earn
                            </h4>
                            <i class="fas fa-chevron-down section-toggle-icon" style="color: white; font-size: 1.2rem; transition: transform 0.3s ease;"></i>
                        </div>
                    </div>
                    <div class="affiliate-section-content collapsed" id="learntoearn-content" style="padding: 0 30px; max-height: 0; transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), padding 0.4s ease, opacity 0.4s ease; overflow: hidden; opacity: 0;">
                        <div class="section-inner-wrapper">
                        <!-- Module 01 -->
                        <div class="learn-module" style="margin-bottom: 20px; border-radius: 15px; overflow: hidden; border: 2px solid #e5e7eb; transition: all 0.3s ease;"
                             onmouseover="this.style.borderColor='#f59e0b'; this.style.boxShadow='0 4px 15px rgba(245, 158, 11, 0.15)'"
                             onmouseout="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'">
                            <div class="module-header" onclick="toggleModule('module01')" 
                                 style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 18px 25px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s ease;"
                                 onmouseover="this.style.background='linear-gradient(135deg, #fde68a 0%, #fcd34d 100%)'"
                                 onmouseout="this.style.background='linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)'">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 1.1rem; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);">
                                        01
                                    </div>
                                    <div>
                                        <h5 class="mb-0" style="color: #92400e; font-weight: 700; font-size: 1.1rem;">Module 01</h5>
                                        <p class="mb-0" style="color: #b45309; font-size: 0.85rem;">FR Collections Introduction</p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-down module-toggle-icon" id="module01-icon" style="color: #d97706; font-size: 1rem; transition: transform 0.3s ease; transform: rotate(0deg);"></i>
                            </div>
                            
                            <div class="module-content" id="module01-content" style="max-height: 0px; overflow: hidden; transition: all 0.4s ease; padding: 0 25px; background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);">
                                <div class="row" style="padding: 0; transition: padding 0.4s ease;">
                                    <!-- Card 1 -->
                                    <div class="col-md-6 mb-3">
                                        <div style="background: white; padding: 25px; border-radius: 15px; border-left: 4px solid #f59e0b; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); transition: all 0.3s ease; height: 100%;"
                                             onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 25px rgba(245, 158, 11, 0.2)'"
                                             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 0, 0, 0.08)'">
                                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px;">
                                                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-book-open" style="color: white; font-size: 1.1rem;"></i>
                                                </div>
                                                <h6 style="margin: 0; color: #1e293b; font-weight: 700; font-size: 1rem;">About This Module</h6>
                                            </div>
                                            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6; margin: 0;">
                                                Module 01 is about FR Collection's complete introduction and working details. Learn everything about our platform, products, and affiliate program.
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Card 2 -->
                                    <div class="col-md-6 mb-3">
                                        <div style="background: white; padding: 25px; border-radius: 15px; border-left: 4px solid #dc2626; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); transition: all 0.3s ease; height: 100%;"
                                             onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 25px rgba(220, 38, 38, 0.2)'"
                                             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 0, 0, 0.08)'">
                                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px;">
                                                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #dc2626, #b91c1c); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-exclamation-circle" style="color: white; font-size: 1.1rem;"></i>
                                                </div>
                                                <h6 style="margin: 0; color: #1e293b; font-weight: 700; font-size: 1rem;">Mandatory Requirement</h6>
                                            </div>
                                            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6; margin: 0;">
                                                It is mandatory to complete all lectures and quizzes of this module to become a partner and get your partner ID.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Start Course Button - Always Visible -->
                            <div class="text-center module-start-btn-wrapper" style="padding: 20px 25px; background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-top: 1px solid rgba(245, 158, 11, 0.2);">
                                <a href="module01.php" class="btn module-start-btn" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 12px 30px; border-radius: 25px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3); transition: all 0.3s ease; font-size: 0.95rem;"
                                     onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(245, 158, 11, 0.4)'"
                                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(245, 158, 11, 0.3)'">
                                    <i class="fas fa-play-circle"></i> Start Course
                                </a>
                            </div>
                        </div>
                        
                        <!-- Module 02 -->
                        <div class="learn-module" style="margin-bottom: 20px; border-radius: 15px; overflow: hidden; border: 2px solid #e5e7eb; transition: all 0.3s ease;"
                             onmouseover="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 4px 15px rgba(59, 130, 246, 0.15)'"
                             onmouseout="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'">
                            <div class="module-header" onclick="toggleModule('module02')" 
                                 style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); padding: 18px 25px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s ease;"
                                 onmouseover="this.style.background='linear-gradient(135deg, #bfdbfe 0%, #93c5fd 100%)'"
                                 onmouseout="this.style.background='linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%)'">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 1.1rem; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);">
                                        02
                                    </div>
                                    <div>
                                        <h5 class="mb-0" style="color: #1e3a8a; font-weight: 700; font-size: 1.1rem;">Module 02</h5>
                                        <p class="mb-0" style="color: #2563eb; font-size: 0.85rem;">Basic SMM Course</p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-down module-toggle-icon" id="module02-icon" style="color: #2563eb; font-size: 1rem; transition: transform 0.3s ease; transform: rotate(0deg);"></i>
                            </div>
                            
                            <div class="module-content" id="module02-content" style="max-height: 0px; overflow: hidden; transition: all 0.4s ease; padding: 0 25px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);">
                                <div class="row" style="padding: 0; transition: padding 0.4s ease;">
                                    <!-- Card 1 -->
                                    <div class="col-md-6 mb-3">
                                        <div style="background: white; padding: 25px; border-radius: 15px; border-left: 4px solid #3b82f6; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); transition: all 0.3s ease; height: 100%;"
                                             onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 25px rgba(59, 130, 246, 0.2)'"
                                             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 0, 0, 0.08)'">
                                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px;">
                                                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-thumbs-up" style="color: white; font-size: 1.1rem;"></i>
                                                </div>
                                                <h6 style="margin: 0; color: #1e293b; font-weight: 700; font-size: 1rem;">About This Module</h6>
                                            </div>
                                            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6; margin: 0;">
                                                Learn social media marketing from zero. Discover amazing features of each platform and master the basics of SMM to boost your affiliate success.
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Card 2 -->
                                    <div class="col-md-6 mb-3">
                                        <div style="background: white; padding: 25px; border-radius: 15px; border-left: 4px solid #8b5cf6; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); transition: all 0.3s ease; height: 100%;"
                                             onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 25px rgba(139, 92, 246, 0.2)'"
                                             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 0, 0, 0.08)'">
                                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px;">
                                                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-star" style="color: white; font-size: 1.1rem;"></i>
                                                </div>
                                                <h6 style="margin: 0; color: #1e293b; font-weight: 700; font-size: 1rem;">What You'll Learn</h6>
                                            </div>
                                            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6; margin: 0;">
                                                Master Instagram, Facebook, TikTok, and other platforms. Learn content creation, engagement strategies, and effective social media techniques.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Start Course Button - Always Visible -->
                            <div class="text-center module-start-btn-wrapper" style="padding: 20px 25px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-top: 1px solid rgba(59, 130, 246, 0.2);">
                                <a href="module02.php" class="btn module-start-btn" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; padding: 12px 30px; border-radius: 25px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); transition: all 0.3s ease; font-size: 0.95rem;"
                                     onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(59, 130, 246, 0.4)'"
                                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(59, 130, 246, 0.3)'">
                                    <i class="fas fa-play-circle"></i> Start Course
                                </a>
                            </div>
                        </div>
                        
                        <!-- Module 03 -->
                        <div class="learn-module" style="margin-bottom: 20px; border-radius: 15px; overflow: hidden; border: 2px solid #e5e7eb; transition: all 0.3s ease;"
                             onmouseover="this.style.borderColor='#10b981'; this.style.boxShadow='0 4px 15px rgba(16, 185, 129, 0.15)'"
                             onmouseout="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'">
                            <div class="module-header" onclick="toggleModule('module03')" 
                                 style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); padding: 18px 25px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s ease;"
                                 onmouseover="this.style.background='linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 100%)'"
                                 onmouseout="this.style.background='linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%)'">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 1.1rem; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);">
                                        03
                                    </div>
                                    <div>
                                        <h5 class="mb-0" style="color: #065f46; font-weight: 700; font-size: 1.1rem;">Module 03</h5>
                                        <p class="mb-0" style="color: #059669; font-size: 0.85rem;">Advance Digital Marketing Course</p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-down module-toggle-icon" id="module03-icon" style="color: #059669; font-size: 1rem; transition: transform 0.3s ease; transform: rotate(0deg);"></i>
                            </div>
                            
                            <div class="module-content" id="module03-content" style="max-height: 0px; overflow: hidden; transition: all 0.4s ease; padding: 0 25px; background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);">
                                <div class="row" style="padding: 0; transition: padding 0.4s ease;">
                                    <!-- Card 1 -->
                                    <div class="col-md-6 mb-3">
                                        <div style="background: white; padding: 25px; border-radius: 15px; border-left: 4px solid #10b981; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); transition: all 0.3s ease; height: 100%;"
                                             onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 25px rgba(16, 185, 129, 0.2)'"
                                             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 0, 0, 0.08)'">
                                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px;">
                                                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-chart-line" style="color: white; font-size: 1.1rem;"></i>
                                                </div>
                                                <h6 style="margin: 0; color: #1e293b; font-weight: 700; font-size: 1rem;">About This Module</h6>
                                            </div>
                                            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6; margin: 0;">
                                                Master advanced digital marketing strategies to skyrocket your affiliate sales. Learn proven techniques used by top marketers worldwide.
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Card 2 -->
                                    <div class="col-md-6 mb-3">
                                        <div style="background: white; padding: 25px; border-radius: 15px; border-left: 4px solid #f59e0b; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); transition: all 0.3s ease; height: 100%;"
                                             onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 25px rgba(245, 158, 11, 0.2)'"
                                             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 0, 0, 0.08)'">
                                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px;">
                                                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-rocket" style="color: white; font-size: 1.1rem;"></i>
                                                </div>
                                                <h6 style="margin: 0; color: #1e293b; font-weight: 700; font-size: 1rem;">What You'll Master</h6>
                                            </div>
                                            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6; margin: 0;">
                                                SEO, Email Marketing, Paid Ads, Conversion Optimization, Analytics, and advanced sales funnels to maximize your commission earnings.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Start Course Button - Always Visible -->
                            <div class="text-center module-start-btn-wrapper" style="padding: 20px 25px; background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-top: 1px solid rgba(16, 185, 129, 0.2);">
                                <a href="module03.php" class="btn module-start-btn" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 12px 30px; border-radius: 25px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); transition: all 0.3s ease; font-size: 0.95rem;"
                                     onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(16, 185, 129, 0.4)'"
                                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(16, 185, 129, 0.3)'">
                                    <i class="fas fa-play-circle"></i> Start Course
                                </a>
                            </div>
                        </div>
                        </div><!-- End section-inner-wrapper -->
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Withdrawal History Section -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="bg-white rounded-lg shadow-lg" style="border-radius: 20px !important; overflow: visible;">
                    <div class="affiliate-section-header" onclick="toggleAffiliateSection('history')" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 20px 30px; cursor: pointer; transition: all 0.3s ease; border-radius: 20px 20px 0 0;">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0" style="color: white; font-weight: 700; font-size: 1.75rem;">
                                <i class="fas fa-history me-2"></i>Withdrawal History
                            </h4>
                            <i class="fas fa-chevron-down section-toggle-icon" style="color: white; font-size: 1.2rem; transition: transform 0.3s ease;"></i>
                        </div>
                    </div>
                    <div class="affiliate-section-content collapsed" id="history-content" style="padding: 0 30px; max-height: 0; transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), padding 0.4s ease; overflow: hidden; opacity: 0;">
                        <?php
                        // Fetch withdrawal history for this user
                        $stmt = $db->prepare("
                            SELECT * FROM withdrawals 
                            WHERE user_id = ? 
                            ORDER BY created_at DESC
                        ");
                        $stmt->execute([$_SESSION['user_id']]);
                        $withdrawal_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (empty($withdrawal_history)): ?>
                            <div class="text-center py-5">
                                <div style="font-size: 4rem; color: #e5e7eb; margin-bottom: 20px;">
                                    <i class="fas fa-inbox"></i>
                                </div>
                                <h5 style="color: #6b7280; font-weight: 600; margin-bottom: 10px;">No Withdrawal History</h5>
                                <p style="color: #9ca3af; margin-bottom: 0;">You haven't made any withdrawal requests yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="affiliate-withdrawal-list">
                                <?php foreach ($withdrawal_history as $withdrawal): ?>
                                    <div class="affiliate-withdrawal-card" data-id="<?php echo $withdrawal['id']; ?>"
                                         style="background: white; border-radius: 15px; margin-bottom: 15px; border: 2px solid #e5e7eb; transition: all 0.3s ease; overflow: hidden;"
                                         onmouseover="this.style.borderColor='#10b981'; this.style.boxShadow='0 4px 12px rgba(16, 185, 129, 0.15)';"
                                         onmouseout="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none';">
                                        
                                        <!-- Main Card Header (Always Visible) -->
                                        <div class="affiliate-withdrawal-header" onclick="toggleAffiliateWithdrawal(<?php echo $withdrawal['id']; ?>)" 
                                             style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; cursor: pointer;">
                                            
                                            <!-- Avatar Circle -->
                                            <div style="width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #059669); 
                                                        display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2rem; 
                                                        font-weight: 700; flex-shrink: 0; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);">
                                                <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                                            </div>
                                            
                                            <!-- Info Section -->
                                            <div style="flex: 1; min-width: 0;">
                                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                                    <i class="fas fa-user" style="color: #6b7280; font-size: 0.85rem;"></i>
                                                    <span style="font-weight: 600; color: #1e293b; font-size: 0.95rem;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                                                </div>
                                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                                    <span style="background: linear-gradient(135deg, #10b981, #059669); color: white; 
                                                                 padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 700;">
                                                        <?php echo formatPrice($withdrawal['amount']); ?>
                                                    </span>
                                                    <span style="color: #64748b; font-size: 0.85rem;">
                                                        <?php echo date('d M Y', strtotime($withdrawal['created_at'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <!-- Right Section (Status + Expand) -->
                                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px; justify-content: center;">
                                                <?php if ($withdrawal['status'] === 'Completed'): ?>
                                                    <span style="background: linear-gradient(135deg, #10b981, #059669); color: white; 
                                                                 padding: 5px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; 
                                                                 display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                                                        <i class="fas fa-circle" style="font-size: 0.45rem;"></i> Completed
                                                    </span>
                                                <?php elseif ($withdrawal['status'] === 'Rejected'): ?>
                                                    <span style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; 
                                                                 padding: 5px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; 
                                                                 display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                                                        <i class="fas fa-circle" style="font-size: 0.45rem;"></i> Rejected
                                                    </span>
                                                <?php else: ?>
                                                    <span style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; 
                                                                 padding: 5px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; 
                                                                 display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                                                        <i class="fas fa-circle" style="font-size: 0.45rem;"></i> Pending
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <i class="fas fa-chevron-down affiliate-withdrawal-chevron" 
                                                   style="color: #10b981; font-size: 1rem; transition: transform 0.3s ease; margin-top: 2px;"></i>
                                            </div>
                                        </div>
                                        
                                        <!-- Collapsible Details -->
                                        <div class="affiliate-withdrawal-details" id="affiliate-withdrawal-<?php echo $withdrawal['id']; ?>" 
                                             style="max-height: 0; overflow: hidden; transition: max-height 0.4s ease, padding 0.4s ease;">
                                            <div style="padding: 20px; background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-top: 1px solid #e5e7eb;">
                                                
                                                <!-- Payment Method -->
                                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px; 
                                                            padding: 12px; background: white; border-radius: 10px; border-left: 3px solid #3b82f6;">
                                                    <div style="width: 35px; height: 35px; background: linear-gradient(135deg, #3b82f6, #2563eb); 
                                                                border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="fas fa-credit-card" style="color: white; font-size: 0.95rem;"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Method</div>
                                                        <div style="font-size: 0.9rem; color: #1e293b; font-weight: 700;"><?php echo htmlspecialchars($withdrawal['method']); ?></div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Account Number -->
                                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px; 
                                                            padding: 12px; background: white; border-radius: 10px; border-left: 3px solid #10b981;">
                                                    <div style="width: 35px; height: 35px; background: linear-gradient(135deg, #10b981, #059669); 
                                                                border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="fas fa-wallet" style="color: white; font-size: 0.95rem;"></i>
                                                    </div>
                                                    <div style="flex: 1; min-width: 0;">
                                                        <div style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Account</div>
                                                        <div style="font-size: 0.85rem; color: #1e293b; font-weight: 700; font-family: monospace; 
                                                                    word-break: break-all;"><?php echo htmlspecialchars($withdrawal['account_number']); ?></div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Date & Time -->
                                                <div style="display: flex; align-items: center; gap: 12px; 
                                                            padding: 12px; background: white; border-radius: 10px; border-left: 3px solid #f59e0b;">
                                                    <div style="width: 35px; height: 35px; background: linear-gradient(135deg, #f59e0b, #d97706); 
                                                                border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="fas fa-calendar" style="color: white; font-size: 0.95rem;"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Requested On</div>
                                                        <div style="font-size: 0.9rem; color: #1e293b; font-weight: 700;">
                                                            <?php echo date('M d, Y - h:i A', strtotime($withdrawal['created_at'])); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($withdrawal['status'] === 'Pending'): ?>
                                                    <div style="margin-top: 15px; padding: 12px; background: linear-gradient(135deg, #dbeafe, #bfdbfe); 
                                                                border-radius: 10px; border-left: 3px solid #3b82f6;">
                                                        <div style="display: flex; align-items: center; gap: 8px;">
                                                            <i class="fas fa-info-circle" style="color: #1e40af; font-size: 0.95rem;"></i>
                                                            <span style="color: #1e3a8a; font-size: 0.8rem; font-weight: 600;">
                                                                Your request is being processed. You'll receive payment within 24 hours after approval.
                                                            </span>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- How It Works Section (Moved to Bottom) -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="bg-white rounded-lg shadow-lg" style="border-radius: 20px !important; overflow: visible;">
                    <div class="affiliate-section-header" onclick="toggleAffiliateSection('howitworks')" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); padding: 20px 30px; cursor: pointer; transition: all 0.3s ease; border-radius: 20px 20px 0 0;">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0" style="color: white; font-weight: 700; font-size: 1.75rem;">
                                <i class="fas fa-lightbulb me-2"></i>How It Works
                            </h4>
                            <i class="fas fa-chevron-down section-toggle-icon" style="color: white; font-size: 1.2rem; transition: transform 0.3s ease;"></i>
                        </div>
                    </div>
                    <div class="affiliate-section-content collapsed" id="howitworks-content" style="padding: 0px 30px; max-height: 0; overflow: hidden; opacity: 0; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);">
                        <div style="padding: 30px 0;">
                            <div class="row">
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="how-it-works-card" style="background: white; padding: 20px; border-radius: 15px; text-align: center; border: 2px solid #e2e8f0; transition: all 0.3s ease;"
                                         onmouseover="this.style.borderColor='#2563eb'; this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 20px rgba(37, 99, 235, 0.15)'"
                                         onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                                        <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 1.5rem;">
                                            <i class="fas fa-share"></i>
                                        </div>
                                        <h6 style="color: #1e3a8a; font-weight: 700; margin-bottom: 10px;">Share Your ID</h6>
                                        <p class="small text-muted mb-0">Share your Partner ID with customers.</p>
                                    </div>
                                </div>
                                
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="how-it-works-card" style="background: white; padding: 20px; border-radius: 15px; text-align: center; border: 2px solid #e2e8f0; transition: all 0.3s ease;"
                                         onmouseover="this.style.borderColor='#10b981'; this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 20px rgba(16, 185, 129, 0.15)'"
                                         onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                                        <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 1.5rem;">
                                            <i class="fas fa-shopping-cart"></i>
                                        </div>
                                        <h6 style="color: #1e3a8a; font-weight: 700; margin-bottom: 10px;">Customer Orders</h6>
                                        <p class="small text-muted mb-0">When customers use your ID during checkout, you earn commission.</p>
                                    </div>
                                </div>
                                
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="how-it-works-card" style="background: white; padding: 20px; border-radius: 15px; text-align: center; border: 2px solid #e2e8f0; transition: all 0.3s ease;"
                                         onmouseover="this.style.borderColor='#f59e0b'; this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 20px rgba(245, 158, 11, 0.15)'"
                                         onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                                        <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 1.5rem;">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </div>
                                        <h6 style="color: #1e3a8a; font-weight: 700; margin-bottom: 10px;">Earn Commission</h6>
                                        <p class="small text-muted mb-0">Earn commission on confirmed orders only.</p>
                                    </div>
                                </div>
                                
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="how-it-works-card" style="background: white; padding: 20px; border-radius: 15px; text-align: center; border: 2px solid #e2e8f0; transition: all 0.3s ease;"
                                         onmouseover="this.style.borderColor='#8b5cf6'; this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 20px rgba(139, 92, 246, 0.15)'"
                                         onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                                        <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 1.5rem;">
                                            <i class="fas fa-wallet"></i>
                                        </div>
                                        <h6 style="color: #1e3a8a; font-weight: 700; margin-bottom: 10px;">Withdraw Earnings</h6>
                                        <p class="small text-muted mb-0">Request withdrawal when you have minimum PKR 100.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
        <!-- Affiliate Signup/Signin Forms -->
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="row bg-white rounded-lg shadow-lg overflow-hidden">
                    <!-- Left Side - Info -->
                    <div class="col-md-6 d-flex align-items-center justify-content-center p-5" style="background: linear-gradient(135deg, <?php echo PRIMARY_COLOR; ?>, <?php echo ACCENT_COLOR; ?>);">
                        <div class="text-center text-white">
                            <div id="affiliateInfo">
                                <div id="signupInfo">
                                    <h3 class="mb-4">Join Our Affiliate Team</h3>
                                    <p class="mb-0">Start earning by promoting our products. It's fast and free to join!</p>
                                </div>
                                <div id="signinInfo" style="display: none;">
                                    <h3 class="mb-4">Welcome Back, Affiliate!</h3>
                                    <p class="mb-0">Sign in to your affiliate dashboard.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Side - Forms -->
                    <div class="col-md-6 p-5">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <!-- Affiliate Signup Form -->
                        <form id="affiliateSignupForm" method="POST">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="Enter your current password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text" style="background-color: #f8f9fa; font-weight: 600;">+92</span>
                                    <input type="tel" class="form-control phone-input-field" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars(str_replace('+92', '', $user['phone'] ?: '')); ?>" 
                                           placeholder="3001234567" maxlength="10" 
                                           style="border-left: none;" required>
                                </div>
                                <small class="form-text text-muted">10 digits only</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3" required><?php echo htmlspecialchars($user['address']); ?></textarea>
                            </div>
                            
                            <button type="submit" name="create_affiliate" class="btn btn-primary w-100 mb-3">
                                Create Affiliate Account
                            </button>
                            
                            <p class="text-center">
                                <a href="#" id="showAffiliateSignin" class="text-decoration-none">Already an Affiliate? Sign In</a>
                            </p>
                        </form>
                        
                        <!-- Affiliate Signin Form -->
                        <form id="affiliateSigninForm" method="POST" style="display: none;">
                            <div class="mb-3">
                                <label for="signin_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="signin_email" name="signin_email" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="signin_password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="signin_password" name="signin_password" required>
                            </div>
                            
                            <button type="submit" name="affiliate_signin" class="btn btn-primary w-100 mb-3">
                                Sign In
                            </button>
                            
                            <p class="text-center">
                                <a href="#" id="showAffiliateSignup" class="text-decoration-none">New here? Become an Affiliate</a>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>


</style>

<script>
// New Withdrawal System - Clean and Modern Implementation
let withdrawalData = {
    method: '',
    amount: 0,
    account: ''
};

// Initialize withdrawal system
function initWithdrawalSystem() {
    console.log('🎯 Withdrawal system initialized');
}

// Open withdrawal modal
function openWithdrawalModal() {
    console.log('📱 Opening withdrawal modal');

    // Reset form data
    withdrawalData = { method: '', amount: 0, account: '' };

    // Create and show modal
    createWithdrawalModal();
}

// Create the withdrawal modal dynamically
function createWithdrawalModal() {
    // Remove existing modal if any
    const existingModal = document.getElementById('affiliate-withdrawal-modal');
    if (existingModal) {
        existingModal.remove();
    }

    const modalHTML = `
    <div class="modal fade" id="affiliate-withdrawal-modal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.15); overflow: hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: white; padding: 30px; border: none; position: relative;">
                    <div style="text-align: center; width: 100%;">
                        <i class="fas fa-wallet fa-2x mb-3" style="color: rgba(255,255,255,0.9);"></i>
                        <h4 class="modal-title mb-2" style="font-weight: 700; font-size: 1.8rem; color: white;">Request Withdrawal</h4>
                        <p style="color: rgba(255,255,255,0.8); margin: 0; font-size: 1rem;">Choose your payment method and amount</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" onclick="closeWithdrawalModal()" style="position: absolute; top: 20px; right: 20px;"></button>
                </div>

                <div class="modal-body" style="padding: 40px;">
                    <!-- Progress Indicator -->
                    <div class="withdrawal-progress mb-4">
                        <div class="progress-steps">
                            <div class="step active" id="step1-indicator">
                                <div class="step-number">1</div>
                                <div class="step-label">Method</div>
                            </div>
                            <div class="step-connector"></div>
                            <div class="step" id="step2-indicator">
                                <div class="step-number">2</div>
                                <div class="step-label">Amount</div>
                            </div>
                            <div class="step-connector"></div>
                            <div class="step" id="step3-indicator">
                                <div class="step-number">3</div>
                                <div class="step-label">Confirm</div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 1: Payment Method Selection -->
                    <div id="withdrawal-step1" class="withdrawal-step active">
                        <h5 class="step-title" style="color: #1e3a8a; font-weight: 600; margin-bottom: 25px; text-align: center;">
                            <i class="fas fa-credit-card me-2"></i>Select Payment Method
                        </h5>

                        <div class="payment-methods-grid">
                            <div class="payment-method-option" data-method="JazzCash" onclick="selectPaymentMethod('JazzCash')">
                                <div class="method-icon" style="background: linear-gradient(135deg, #ff6b00 0%, #ff8533 100%);">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <div class="method-details">
                                    <h6 class="method-name">JazzCash</h6>
                                    <small class="method-desc">Mobile wallet</small>
                                </div>
                                <div class="method-radio">
                                    <div class="radio-indicator"></div>
                                </div>
                            </div>

                            <div class="payment-method-option" data-method="Easypaisa" onclick="selectPaymentMethod('Easypaisa')">
                                <div class="method-icon" style="background: linear-gradient(135deg, #00a859 0%, #00c96b 100%);">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="method-details">
                                    <h6 class="method-name">Easypaisa</h6>
                                    <small class="method-desc">Digital payment</small>
                                </div>
                                <div class="method-radio">
                                    <div class="radio-indicator"></div>
                                </div>
                            </div>

                            <div class="payment-method-option" data-method="Upaisa" onclick="selectPaymentMethod('Upaisa')">
                                <div class="method-icon" style="background: linear-gradient(135deg, #e74c3c 0%, #ec7063 100%);">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <div class="method-details">
                                    <h6 class="method-name">Upaisa</h6>
                                    <small class="method-desc">Banking app</small>
                                </div>
                                <div class="method-radio">
                                    <div class="radio-indicator"></div>
                                </div>
                            </div>
                        </div>

                        <div class="step-navigation mt-4">
                            <button type="button" class="btn btn-step-next" onclick="nextStep(1)" disabled id="step1-next">
                                Continue <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Amount Selection -->
                    <div id="withdrawal-step2" class="withdrawal-step">
                        <h5 class="step-title" style="color: #1e3a8a; font-weight: 600; margin-bottom: 25px; text-align: center;">
                            <i class="fas fa-coins me-2"></i>Choose Withdrawal Amount
                        </h5>

                        <div class="selected-method-display mb-4">
                            <div class="method-summary" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 15px; border-radius: 12px; text-align: center;">
                                <i class="fas fa-check-circle" style="color: #10b981; margin-right: 8px;"></i>
                                <span id="selected-method-text" style="font-weight: 600; color: #1e3a8a;">No method selected</span>
                            </div>
                        </div>

                        <div class="amount-selection">
                            <label style="font-weight: 600; color: #2c3e50; margin-bottom: 15px; display: block;">Quick Select:</label>
                            <div class="amount-options-grid">
                                <button type="button" class="amount-btn" onclick="selectWithdrawalAmount(100)">PKR 100</button>
                                <button type="button" class="amount-btn" onclick="selectWithdrawalAmount(200)">PKR 200</button>
                                <button type="button" class="amount-btn" onclick="selectWithdrawalAmount(300)">PKR 300</button>
                                <button type="button" class="amount-btn" onclick="selectWithdrawalAmount(500)">PKR 500</button>
                                <button type="button" class="amount-btn" onclick="selectWithdrawalAmount(1000)">PKR 1,000</button>
                                <button type="button" class="amount-btn full-balance" onclick="selectFullBalance()">
                                    <i class="fas fa-wallet me-2"></i>Full Balance
                                </button>
                            </div>

                            <div class="custom-amount-section mt-4">
                                <label style="font-weight: 600; color: #2c3e50; margin-bottom: 10px; display: block;">Or Enter Custom Amount:</label>
                                <div class="input-group">
                                    <span class="input-group-text" style="background: #f8f9fa; border: 2px solid #e9ecef; border-right: none; border-radius: 10px 0 0 10px;">PKR</span>
                                    <input type="number" class="form-control custom-amount-input" id="custom-withdrawal-amount"
                                           placeholder="Enter amount (Min: 100)" min="100" style="border-left: none; border-radius: 0 10px 10px 0; padding: 12px; font-size: 1.1rem;">
                                </div>
                                <small class="text-muted mt-2" style="display: block;">
                                    Available Balance: <strong><?php echo formatPrice($available_balance); ?></strong>
                                </small>
                            </div>
                        </div>

                        <div class="step-navigation mt-4" style="display: flex; justify-content: space-between;">
                            <button type="button" class="btn btn-step-back" onclick="prevStep(2)">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </button>
                            <button type="button" class="btn btn-step-next" onclick="nextStep(2)" disabled id="step2-next">
                                Continue <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Confirmation -->
                    <div id="withdrawal-step3" class="withdrawal-step">
                        <h5 class="step-title" style="color: #1e3a8a; font-weight: 600; margin-bottom: 25px; text-align: center;">
                            <i class="fas fa-check-circle me-2"></i>Confirm Withdrawal
                        </h5>

                        <div class="confirmation-summary" style="background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 15px; padding: 30px; border: 2px solid #e9ecef;">
                            <div class="summary-row mb-3" style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="color: #6b7280; font-weight: 500;">Payment Method:</span>
                                <span id="confirm-method" style="font-weight: 600; color: #1e3a8a;">--</span>
                            </div>
                            <div class="summary-row mb-3" style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="color: #6b7280; font-weight: 500;">Withdrawal Amount:</span>
                                <span id="confirm-amount" style="font-weight: 700; color: #10b981; font-size: 1.2rem;">--</span>
                            </div>
                            <div class="summary-row" style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="color: #6b7280; font-weight: 500;">Account Number:</span>
                                <input type="text" id="confirm-account" class="form-control" style="width: 200px; border-radius: 8px; border: 2px solid #e9ecef; padding: 8px 12px;" placeholder="+92XXXXXXXXXX">
                            </div>
                        </div>

                        <div class="processing-notice mt-4" style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border-radius: 12px; padding: 20px; border-left: 4px solid #2196f3;">
                            <i class="fas fa-info-circle me-2" style="color: #2196f3;"></i>
                            <strong>Processing Time:</strong> Your withdrawal request will be processed within 24 working hours after approval.
                        </div>

                        <div class="step-navigation mt-4" style="display: flex; justify-content: space-between;">
                            <button type="button" class="btn btn-step-back" onclick="prevStep(3)">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </button>
                            <button type="button" class="btn btn-submit-withdrawal" onclick="submitWithdrawalRequest()" disabled id="submit-withdrawal-btn">
                                <i class="fas fa-paper-plane me-2"></i>Submit Request
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="withdrawal-success-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
                <div class="modal-body text-center" style="padding: 50px 40px;">
                    <div class="success-animation mb-4">
                        <div style="width: 100px; height: 100px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; animation: successPulse 2s infinite;">
                            <i class="fas fa-check-circle fa-3x" style="color: white;"></i>
                        </div>
                    </div>
                    <h3 style="color: #1e3a8a; font-weight: 700; margin-bottom: 15px;">
                        <i class="fas fa-check-circle me-2" style="color: #10b981;"></i>Withdrawal Requested!
                    </h3>
                    <p style="font-size: 1.1rem; color: #555; margin-bottom: 20px;">
                        Your withdrawal request has been submitted successfully and is now pending approval.
                    </p>
                    <div class="alert alert-info" style="background: #e3f2fd; border: none; border-radius: 15px; padding: 20px; margin: 25px 0;">
                        <i class="fas fa-clock me-2" style="color: #2196f3;"></i>
                        <strong>You will receive your payment within 24 working hours</strong> after approval.
                    </div>
                    <button class="btn btn-success" onclick="closeAllModals()" style="padding: 12px 40px; border-radius: 50px; font-weight: 600; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none;">
                        <i class="fas fa-check me-2"></i>Got it!
                    </button>
                </div>
            </div>
        </div>
    </div>
    `;

    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('affiliate-withdrawal-modal'));
    modal.show();

    // Initialize event listeners
    initWithdrawalEventListeners();
}

// Initialize event listeners for the withdrawal modal
function initWithdrawalEventListeners() {
    // Custom amount input listener
    const customAmountInput = document.getElementById('custom-withdrawal-amount');
    if (customAmountInput) {
        customAmountInput.addEventListener('input', function() {
            const value = parseInt(this.value) || 0;
            if (value >= 100 && value <= <?php echo $available_balance; ?>) {
                withdrawalData.amount = value;
                updateStepNavigation();
                // Clear selected amount buttons
                document.querySelectorAll('.amount-btn').forEach(btn => btn.classList.remove('active'));
            }
        });
    }

    // Account number input listener
    const accountInput = document.getElementById('confirm-account');
    if (accountInput) {
        accountInput.addEventListener('input', function() {
            withdrawalData.account = this.value.trim();
            updateStepNavigation();
            formatAccountNumber(this);
        });

        accountInput.addEventListener('focus', function() {
            if (!this.value || this.value === '') {
                this.value = '+92';
            }
        });
    }
}

// Select payment method
function selectPaymentMethod(method) {
    withdrawalData.method = method;

    // Update UI
    document.querySelectorAll('.payment-method-option').forEach(option => {
        option.classList.remove('selected');
    });

    document.querySelector(`[data-method="${method}"]`).classList.add('selected');
    document.getElementById('selected-method-text').textContent = method;
    document.getElementById('confirm-method').textContent = method;

    updateStepNavigation();
}

// Select withdrawal amount
function selectWithdrawalAmount(amount) {
    withdrawalData.amount = amount;

    // Update UI
    document.querySelectorAll('.amount-btn').forEach(btn => {
        btn.classList.remove('active');
        if (parseInt(btn.textContent.replace(/[^\d]/g, '')) === amount || btn.textContent.includes(amount.toString())) {
            btn.classList.add('active');
        }
    });

    // Clear custom amount
    const customInput = document.getElementById('custom-withdrawal-amount');
    if (customInput) customInput.value = '';

    updateStepNavigation();
}

// Select full balance
function selectFullBalance() {
    const fullAmount = <?php echo $available_balance; ?>;
    selectWithdrawalAmount(fullAmount);
}

// Format account number
function formatAccountNumber(input) {
    let value = input.value;
    if (!value.startsWith('+92')) {
        value = '+92' + value.replace(/[^0-9]/g, '');
    }
    let numbers = value.substring(3).replace(/[^0-9]/g, '');
    if (numbers.length > 10) {
        numbers = numbers.substring(0, 10);
    }
    input.value = '+92' + numbers;
}

// Navigate between steps
function nextStep(currentStep) {
    if (currentStep === 1 && withdrawalData.method) {
        showStep(2);
    } else if (currentStep === 2 && withdrawalData.amount >= 100) {
        showStep(3);
        updateConfirmationSummary();
    }
}

function prevStep(currentStep) {
    if (currentStep === 2) {
        showStep(1);
    } else if (currentStep === 3) {
        showStep(2);
    }
}

function showStep(stepNumber) {
    // Hide all steps
    document.querySelectorAll('.withdrawal-step').forEach(step => {
        step.classList.remove('active');
    });

    // Update progress indicators
    document.querySelectorAll('.step').forEach(step => {
        step.classList.remove('active', 'completed');
    });

    // Show target step and update progress
    document.getElementById('withdrawal-step' + stepNumber).classList.add('active');

    for (let i = 1; i <= stepNumber; i++) {
        const indicator = document.getElementById('step' + i + '-indicator');
        if (indicator) {
            if (i < stepNumber) {
                indicator.classList.add('completed');
            }
            indicator.classList.add('active');
        }
    }
}

// Update confirmation summary
function updateConfirmationSummary() {
    document.getElementById('confirm-method').textContent = withdrawalData.method;
    document.getElementById('confirm-amount').textContent = 'PKR ' + withdrawalData.amount.toLocaleString();
}

// Update step navigation buttons
function updateStepNavigation() {
    const step1Next = document.getElementById('step1-next');
    const step2Next = document.getElementById('step2-next');
    const submitBtn = document.getElementById('submit-withdrawal-btn');

    if (step1Next) step1Next.disabled = !withdrawalData.method;
    if (step2Next) step2Next.disabled = !(withdrawalData.amount >= 100);
    if (submitBtn) submitBtn.disabled = !(withdrawalData.method && withdrawalData.amount >= 100 && withdrawalData.account && /^\+92\d{10}$/.test(withdrawalData.account));
}

// Submit withdrawal request
function submitWithdrawalRequest() {
    if (!validateFinalSubmission()) {
        return;
    }

    const submitBtn = document.getElementById('submit-withdrawal-btn');
    const originalText = submitBtn.innerHTML;

    // Show loading state
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
    submitBtn.disabled = true;

    // Prepare data
    const requestData = new URLSearchParams({
        amount: withdrawalData.amount,
        method: withdrawalData.method,
        account_number: withdrawalData.account
    });

    // Submit request
    fetch('ajax/submit_withdrawal.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: requestData
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        if (data.success) {
            showSuccessModal();
        } else {
            showAlert(data.message || 'Error submitting withdrawal request', 'error');
        }
    })
    .catch(error => {
        console.error('Submission error:', error);
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        showAlert('Error processing request. Please try again.', 'error');
    });
}

// Validate final submission
function validateFinalSubmission() {
    if (!withdrawalData.method) {
        showAlert('Please select a payment method', 'warning');
        showStep(1);
        return false;
    }

    if (withdrawalData.amount < 100) {
        showAlert('Minimum withdrawal amount is PKR 100', 'warning');
        showStep(2);
        return false;
    }

    if (withdrawalData.amount > <?php echo $available_balance; ?>) {
        showAlert('Insufficient balance', 'error', 'Insufficient Balance');
        showStep(2);
        return false;
    }

    if (!withdrawalData.account || !/^\+92\d{10}$/.test(withdrawalData.account)) {
        showAlert('Please enter a valid account number (+92 followed by 10 digits)', 'warning', 'Invalid Account Number');
        document.getElementById('confirm-account').focus();
        return false;
    }

    return true;
}

// Show success modal
function showSuccessModal() {
    closeWithdrawalModal();
    setTimeout(() => {
        const successModal = new bootstrap.Modal(document.getElementById('withdrawal-success-modal'));
        successModal.show();
    }, 300);
}

// Close withdrawal modal
function closeWithdrawalModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('affiliate-withdrawal-modal'));
    if (modal) modal.hide();
}

// Close all modals and reload
function closeAllModals() {
    const withdrawalModal = bootstrap.Modal.getInstance(document.getElementById('affiliate-withdrawal-modal'));
    const successModal = bootstrap.Modal.getInstance(document.getElementById('withdrawal-success-modal'));

    if (withdrawalModal) withdrawalModal.hide();
    if (successModal) successModal.hide();

    setTimeout(() => {
        location.reload();
    }, 300);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initWithdrawalSystem();
});
</script>

<!-- Google Fonts for Round/Cursive Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Nunito:wght@600;700;800&family=Quicksand:wght@600;700&family=Comfortaa:wght@600;700&display=swap" rel="stylesheet">

<style>
/* Prevent horizontal scroll globally */
body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
}

html {
    overflow-x: hidden !important;
}

.container-fluid {
    overflow-x: hidden !important;
}

/* Section Wrapper Styles */
.section-inner-wrapper {
    width: 100%;
}

.affiliate-section-content {
    position: relative;
}

.affiliate-section-content.collapsed {
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    opacity: 0;
}

/* Withdrawal Modal Styles */
.withdrawal-progress {
    margin-bottom: 30px;
}

.progress-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    opacity: 0.5;
    transition: all 0.3s ease;
}

.step.active {
    opacity: 1;
}

.step.completed {
    opacity: 1;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e9ecef;
    color: #6b7280;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
    border: 3px solid #e9ecef;
    transition: all 0.3s ease;
}

.step.active .step-number {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: white;
    border-color: #1e3a8a;
}

.step.completed .step-number {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border-color: #10b981;
}

.step-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #6b7280;
    text-align: center;
}

.step.active .step-label {
    color: #1e3a8a;
}

.step.completed .step-label {
    color: #10b981;
}

.step-connector {
    width: 60px;
    height: 2px;
    background: #e9ecef;
    margin: 0 10px;
    transition: all 0.3s ease;
}

.step.active + .step-connector {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
}

.withdrawal-step {
    display: none;
    animation: fadeInUp 0.4s ease;
}

.withdrawal-step.active {
    display: block;
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

.payment-methods-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.payment-method-option {
    display: flex;
    align-items: center;
    padding: 20px;
    border: 2px solid #e9ecef;
    border-radius: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.payment-method-option:hover {
    border-color: #1e3a8a;
    box-shadow: 0 8px 25px rgba(30, 58, 138, 0.1);
    transform: translateY(-2px);
}

.payment-method-option.selected {
    border-color: #1e3a8a;
    background: linear-gradient(135deg, #f0f7ff 0%, #e6f0ff 100%);
    box-shadow: 0 8px 25px rgba(30, 58, 138, 0.15);
}

.method-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    margin-right: 15px;
    flex-shrink: 0;
}

.method-details {
    flex: 1;
}

.method-name {
    margin: 0;
    font-weight: 700;
    color: #1e3a8a;
    font-size: 1.1rem;
}

.method-desc {
    color: #6b7280;
    font-size: 0.9rem;
    margin: 0;
}

.method-radio {
    width: 20px;
    height: 20px;
    border: 2px solid #e9ecef;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.payment-method-option.selected .method-radio {
    border-color: #1e3a8a;
}

.radio-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #1e3a8a;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.payment-method-option.selected .radio-indicator {
    opacity: 1;
}

.amount-options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.amount-btn {
    padding: 12px 16px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    background: white;
    color: #1e3a8a;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-align: center;
}

.amount-btn:hover {
    border-color: #1e3a8a;
    background: #f8f9fa;
    transform: translateY(-1px);
}

.amount-btn.active {
    border-color: #10b981;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.amount-btn.full-balance {
    grid-column: 1 / -1;
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border-color: #d97706;
}

.amount-btn.full-balance:hover {
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    border-color: #b45309;
}

.custom-amount-input {
    border: 2px solid #e9ecef !important;
}

.custom-amount-input:focus {
    border-color: #1e3a8a !important;
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1) !important;
}

.step-navigation {
    display: flex;
    justify-content: center;
    gap: 15px;
}

.btn-step-next, .btn-step-back, .btn-submit-withdrawal {
    padding: 12px 30px;
    border-radius: 50px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 1rem;
}

.btn-step-next, .btn-submit-withdrawal {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: white;
}

.btn-step-next:hover, .btn-submit-withdrawal:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(30, 58, 138, 0.3);
}

.btn-step-back {
    background: #f8f9fa;
    color: #6b7280;
    border: 2px solid #e9ecef;
}

.btn-step-back:hover {
    background: #e9ecef;
    border-color: #dee2e6;
}

.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
}

@keyframes successPulse {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
    }
    50% {
        transform: scale(1.05);
        box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
    }
}

/* Affiliate Section Collapsible Styles */
.affiliate-section-header:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.affiliate-section-content.collapsed {
    max-height: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    opacity: 0;
}

.affiliate-section-header.collapsed .section-toggle-icon {
    transform: rotate(-90deg);
}

/* Mobile Responsive Styles for Affiliate Page */
@media (max-width: 768px) {
    /* Full width navy section on mobile */
    .affiliate-earnings-section {
        margin-left: calc(-50vw + 50%) !important;
        margin-right: calc(-50vw + 50%) !important;
        width: 100vw !important;
        max-width: 100vw !important;
        overflow-x: hidden !important;
    }
    
    /* Reduce padding on mobile for hero section inner content */
    .affiliate-earnings-section > div[style*="max-width: 1400px"] {
        padding: 30px 20px 25px !important;
    }
    
    /* Floating withdrawal button */
    .withdrawal-btn-floating {
        top: 15px !important;
        right: 15px !important;
        padding: 10px 20px !important;
        font-size: 0.85rem !important;
    }
    
    .withdrawal-btn-floating .btn-text-desktop {
        display: none;
    }
    
    /* Stats Cards - 2x2 Grid */
    .row .col-lg-3 {
        flex: 0 0 50%;
        max-width: 50%;
    }
    
    .earning-card.modern {
        padding: 25px 18px !important;
        margin-bottom: 15px;
        height: 220px !important;
        border-radius: 22px !important;
    }
    
    .earning-card.modern i {
        font-size: 2.6rem !important;
        margin-bottom: 12px !important;
    }
    
    .earning-card.modern h5 {
        font-size: 0.65rem !important;
        margin-bottom: 12px !important;
        letter-spacing: 2px !important;
        font-weight: 800 !important;
    }
    
    .earning-card.modern h2 {
        font-size: 1.9rem !important;
        font-weight: 900 !important;
        text-shadow: 0 4px 20px rgba(0, 0, 0, 0.7), 0 2px 8px rgba(0, 0, 0, 0.4) !important;
        line-height: 1.2 !important;
    }
    
    /* Locked card compact text */
    .earning-card.modern p {
        font-size: 0.68rem !important;
        line-height: 1.4 !important;
        margin-top: 8px !important;
    }
    
    /* Locked card progress indicators */
    .earning-card.modern div > div {
        margin-top: 10px !important;
        font-size: 0.65rem !important;
    }
    
    /* Compact section headers */
    .affiliate-section-header {
        padding: 15px 20px !important;
    }
    
    .affiliate-section-header h4 {
        font-size: 1.3rem !important;
    }
    
    .section-toggle-icon {
        font-size: 1rem !important;
    }
    
    /* Hide withdrawal button text on mobile */
    .withdrawal-btn-mobile .btn-text-desktop {
        display: none;
    }
    
    .withdrawal-btn-mobile {
        padding: 8px 16px !important;
        font-size: 0.85rem !important;
    }
    
    /* Module Start Button - Mobile Styles */
    .module-start-btn-wrapper {
        padding: 15px 15px !important;
    }
    
    .module-start-btn {
        width: 100% !important;
        max-width: 280px !important;
        padding: 14px 25px !important;
        font-size: 0.9rem !important;
        justify-content: center !important;
        display: flex !important;
    }
    
    /* Compact content padding */
    .affiliate-section-content {
        padding: 20px 15px !important;
    }
    
    /* How it works cards */
    .how-it-works-card {
        padding: 15px !important;
    }
    
    .how-it-works-card h6 {
        font-size: 0.9rem !important;
    }
    
    .how-it-works-card div[style*="width: 60px"] {
        width: 50px !important;
        height: 50px !important;
    }
    
    /* Withdrawal history grid */
    .withdrawal-history-grid {
        grid-template-columns: 1fr !important;
    }
    
    .withdrawal-history-card {
        padding: 15px !important;
    }
    
    /* Affiliate withdrawal cards mobile */
    .affiliate-withdrawal-header {
        padding: 12px 15px !important;
        gap: 12px !important;
    }
    
    .affiliate-withdrawal-header > div:first-of-type {
        width: 45px !important;
        height: 45px !important;
        font-size: 1.1rem !important;
    }
    
    .affiliate-withdrawal-header .withdrawal-checkbox {
        width: 18px !important;
        height: 18px !important;
    }
}

@media (max-width: 576px) {
    .affiliate-withdrawal-header {
        flex-wrap: wrap;
    }
    
    .affiliate-withdrawal-header > div:nth-child(4) {
        width: 100%;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding-top: 10px;
        border-top: 1px solid #f1f5f9;
        margin-top: 10px;
    }
}

@media (max-width: 480px) {
    /* Ensure full width on extra small devices */
    .affiliate-earnings-section {
        margin-left: calc(-50vw + 50%) !important;
        margin-right: calc(-50vw + 50%) !important;
        width: 100vw !important;
        max-width: 100vw !important;
        overflow-x: hidden !important;
    }
    
    /* Further reduce padding on extra small devices */
    .affiliate-earnings-section > div[style*="max-width: 1400px"] {
        padding: 25px 15px 20px !important;
    }
    
    .earning-card.modern {
        padding: 20px 12px !important;
        height: 190px !important;
        border-radius: 20px !important;
    }
    
    .earning-card.modern i {
        font-size: 2.3rem !important;
        margin-bottom: 10px !important;
    }
    
    .earning-card.modern h5 {
        font-size: 0.6rem !important;
        letter-spacing: 1.5px !important;
        margin-bottom: 10px !important;
    }
    
    .earning-card.modern h2 {
        font-size: 1.6rem !important;
        font-weight: 900 !important;
        line-height: 1.2 !important;
    }
    
    /* Locked card very compact */
    .earning-card.modern p {
        font-size: 0.62rem !important;
        line-height: 1.3 !important;
        margin-top: 6px !important;
    }
    
    /* Locked card progress indicators - extra small */
    .earning-card.modern div > div {
        margin-top: 8px !important;
        font-size: 0.6rem !important;
    }
    
    .affiliate-section-header {
        padding: 12px 15px !important;
    }
    
    .affiliate-section-header h4 {
        font-size: 1.1rem !important;
    }
    
    /* Module Start Button - Extra Small Mobile */
    .module-start-btn-wrapper {
        padding: 12px 10px !important;
    }
    
    .module-start-btn {
        max-width: 100% !important;
        padding: 12px 20px !important;
        font-size: 0.85rem !important;
    }
}

/* Animations for Earnings Overview Section */
@keyframes float {
    0%, 100% {
        transform: translateY(0) translateX(0);
    }
    33% {
        transform: translateY(-20px) translateX(10px);
    }
    66% {
        transform: translateY(10px) translateX(-10px);
    }
}

@keyframes pulse {
    0%, 100% {
        transform: translate(-50%, -50%) scale(1);
        opacity: 0.3;
    }
    50% {
        transform: translate(-50%, -50%) scale(1.2);
        opacity: 0.5;
    }
}

@keyframes twinkle {
    0%, 100% {
        opacity: 0.3;
        transform: scale(1);
    }
    50% {
        opacity: 1;
        transform: scale(1.5);
    }
}

@keyframes bounce {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}

@keyframes glow {
    0%, 100% {
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    }
    50% {
        box-shadow: 0 6px 30px rgba(16, 185, 129, 0.7), 0 0 20px rgba(16, 185, 129, 0.5);
    }
}

@keyframes rotate {
    0% {
        transform: rotate(0deg);
    }
    100% {
        transform: rotate(360deg);
    }
}

@keyframes cardPulse {
    0%, 100% {
        transform: translateY(0) scale(1);
    }
    50% {
        transform: translateY(-3px) scale(1.01);
    }
}

@keyframes iconFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}
</style>

<script>
// Toggle affiliate section collapse/expand
function toggleAffiliateSection(sectionId) {
    const content = document.getElementById(sectionId + '-content');
    const header = event.currentTarget;
    const icon = header.querySelector('.section-toggle-icon');
    
    if (content.classList.contains('collapsed')) {
        // Expand
        content.classList.remove('collapsed');
        // Use a very large max-height to accommodate any content
        content.style.maxHeight = '5000px';
        content.style.opacity = '1';
        content.style.paddingTop = '30px';
        content.style.paddingBottom = '30px';
        icon.style.transform = 'rotate(0deg)';
        
        // After expansion, set overflow to visible so modules can expand
        setTimeout(function() {
            if (!content.classList.contains('collapsed')) {
                content.style.overflow = 'visible';
            }
        }, 400);
    } else {
        // Collapse
        content.classList.add('collapsed');
        content.style.overflow = 'hidden';
        content.style.maxHeight = '0';
        content.style.opacity = '0';
        content.style.paddingTop = '0';
        content.style.paddingBottom = '0';
        icon.style.transform = 'rotate(-90deg)';
    }
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    // Start with "Learn to Earn", "Withdrawal History", and "How it Works" collapsed
    const learntoearnContent = document.getElementById('learntoearn-content');
    if (learntoearnContent) {
        learntoearnContent.classList.add('collapsed');
        learntoearnContent.style.maxHeight = '0';
        learntoearnContent.style.opacity = '0';
        learntoearnContent.style.paddingTop = '0';
        learntoearnContent.style.paddingBottom = '0';
        // Rotate chevron icon
        const learntoearnSection = learntoearnContent.closest('.col-12');
        if (learntoearnSection) {
            const chevron = learntoearnSection.querySelector('.section-toggle-icon');
            if (chevron) chevron.style.transform = 'rotate(-90deg)';
        }
    }
    
    const historyContent = document.getElementById('history-content');
    if (historyContent) {
        historyContent.classList.add('collapsed');
        historyContent.style.maxHeight = '0';
        historyContent.style.opacity = '0';
        historyContent.style.paddingTop = '0';
        historyContent.style.paddingBottom = '0';
        // Rotate chevron icon
        const historySection = historyContent.closest('.col-12');
        if (historySection) {
            const chevron = historySection.querySelector('.section-toggle-icon');
            if (chevron) chevron.style.transform = 'rotate(-90deg)';
        }
    }
    
    const howitworksContent = document.getElementById('howitworks-content');
    if (howitworksContent) {
        howitworksContent.classList.add('collapsed');
    }
});

// Toggle individual withdrawal card details
function toggleAffiliateWithdrawal(id) {
    const details = document.getElementById('affiliate-withdrawal-' + id);
    const card = document.querySelector(`[data-id="${id}"]`);
    const chevron = card.querySelector('.affiliate-withdrawal-chevron');
    
    if (details.style.maxHeight === '0px' || !details.style.maxHeight) {
        // Expand
        details.style.maxHeight = details.scrollHeight + 'px';
        chevron.style.transform = 'rotate(180deg)';
    } else {
        // Collapse
        details.style.maxHeight = '0px';
        chevron.style.transform = 'rotate(0deg)';
    }
}

// Toggle Learn to Earn module
function toggleModule(moduleId) {
    event.stopPropagation();
    const content = document.getElementById(moduleId + '-content');
    const icon = document.getElementById(moduleId + '-icon');
    const innerContent = content.querySelector('.row');
    
    if (content.style.maxHeight === '0px' || content.style.maxHeight === '') {
        // Expand
        // Calculate the actual height needed
        const actualHeight = innerContent.scrollHeight + 50; // Add 50px for padding
        content.style.maxHeight = actualHeight + 'px';
        innerContent.style.paddingTop = '25px';
        innerContent.style.paddingBottom = '25px';
        icon.style.transform = 'rotate(180deg)';
    } else {
        // Collapse
        content.style.maxHeight = '0px';
        innerContent.style.paddingTop = '0';
        innerContent.style.paddingBottom = '0';
        icon.style.transform = 'rotate(0deg)';
    }
}

// Collect Partner Card Function
function collectPartnerCard() {
    // Redirect to Your Cards page with partner tab
    window.location.href = 'your-cards.php?tab=partner';
}

// Add shimmer effect on hover
document.addEventListener('DOMContentLoaded', function() {
    const collectBtn = document.querySelector('.collect-card-mini-btn');
    if (collectBtn) {
        collectBtn.addEventListener('mouseenter', function() {
            const shimmer = this.querySelector('.shimmer-effect');
            if (shimmer) {
                shimmer.style.left = '100%';
            }
        });
        
        collectBtn.addEventListener('mouseleave', function() {
            const shimmer = this.querySelector('.shimmer-effect');
            if (shimmer) {
                setTimeout(() => {
                    shimmer.style.left = '-100%';
                }, 200);
            }
        });
    }
});
</script>

<!-- Spacing before footer -->
<div style="height: 80px;"></div>

<?php require_once 'includes/footer.php'; ?>
