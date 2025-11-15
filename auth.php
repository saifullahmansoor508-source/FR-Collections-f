<?php
// Process form submission BEFORE any output
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

// Check for block message from forced logout
if (isset($_SESSION['block_message'])) {
    $error = $_SESSION['block_message'];
    unset($_SESSION['block_message']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['signup'])) {
        $full_name = sanitizeInput($_POST['full_name']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
            $error = "All fields are required.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } else {
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->rowCount() > 0) {
                // Email already registered
                $error = "email_exists";
            } else {
                // Create new account
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)");

                if ($stmt->execute([$full_name, $email, $hashed_password])) {
                    // Get the newly created user ID
                    $user_id = $db->lastInsertId();
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['user_name'] = $full_name;
                    $_SESSION['user_email'] = $email;
                    
                    // Automatically set remember me cookie for persistent login
                    setRememberMeCookie($email);
                    
                    // Set success message and redirect
                    $_SESSION['auth_success'] = 'account_created';
                    header('Location: index.php');
                    exit();
                } else {
                    $error = "Error creating account.";
                }
            }
        }
    } elseif (isset($_POST['signin'])) {
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            $error = "All fields are required.";
        } else {
            // Check if user exists with this email
            $stmt = $db->prepare("SELECT id, full_name, email, password, is_blocked FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // Email and password are correct
                if ($user['is_blocked']) {
                    $error = "Your account has been blocked.";
                } else {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];

                    // Automatically set remember me cookie for persistent login
                    setRememberMeCookie($email);
                    
                    // Set success flag for JavaScript to handle redirect with delay
                    $success = 'login_successful';
                }
            } else {
                // Invalid email or password
                $error = "invalid_credentials";
            }
        }
    }
}

// NOW load the header after form processing
$page_title = "Sign In / Sign Up";
require_once 'includes/header.php';
?>

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
        max-width: 900px;
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
        font-size: 1rem
    }

    .form-group input {
        width: 100%;
        padding: 14px 15px 14px 45px;
        border: 1px solid #ddd;
        border-radius: 10px;
        font-size: 1rem;
        transition: all .3s ease
    }

    .form-group input:focus {
        outline: 0;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, .1)
    }

    .form-group input:focus~i {
        color: #1e3a8a
    }

    .form-options {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px
    }

    .checkbox-wrapper {
        display: flex;
        align-items: center;
        gap: 8px
    }

    .checkbox-wrapper input[type=checkbox] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #1e3a8a
    }

    .checkbox-wrapper label {
        font-size: .9rem;
        color: #666;
        cursor: pointer;
        margin: 0
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
                    <h2>Create Account</h2>
                    <p>Join our community! It's quick and easy.</p>
                </div>
                <div id="signin-info" class="hidden">
                    <h2>Welcome Back!</h2>
                    <p>You can sign in to access your existing account.</p>
                </div>
            </div>
        </div>
        <div class="auth-form-panel">
            <?php if ($error): ?>
                <?php if ($error === 'email_exists'): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>This email is already registered.</span>
                    </div>
                <?php elseif ($error === 'invalid_credentials'): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Invalid email or password.</span>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($success === 'login_successful'): ?>
                <div class="alert alert-success" id="login-success-alert">
                    <i class="fas fa-check-circle"></i>
                    <span>Login Successful! Redirecting...</span>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            <form id="signup-form" class="auth-form<?php echo ($error === 'invalid_credentials' || $success === 'login_successful') ? ' hidden' : ''; ?>" method="POST">
                <h3>Sign Up</h3>
                <div class="form-group"><i class="fas fa-user"></i><input type="text" name="full_name" placeholder="Full Name" required></div>
                <div class="form-group"><i class="fas fa-envelope"></i><input type="email" name="email" placeholder="Email" required></div>
                <div class="form-group"><i class="fas fa-lock"></i><input type="password" id="signup-password" name="password" placeholder="Password" required></div>
                <div class="form-group"><i class="fas fa-check-circle"></i><input type="password" id="confirm-password" name="confirm_password" placeholder="Confirm Password" required></div>
                <button type="submit" name="signup" class="auth-button">Sign Up</button>
            </form>
            <form id="signin-form" class="auth-form<?php echo ($error === 'invalid_credentials' || $success === 'login_successful') ? '' : ' hidden'; ?>" method="POST">
                <h3>Sign In</h3>
                <div class="form-group"><i class="fas fa-envelope"></i><input type="email" name="email" placeholder="Email" value="<?php echo isset($_COOKIE['remember_user']) ? htmlspecialchars($_COOKIE['remember_user']) : ''; ?>" required></div>
                <div class="form-group"><i class="fas fa-lock"></i><input type="password" name="password" placeholder="Password" required></div>
                <button type="submit" name="signin" class="auth-button">Sign In</button>
            </form>
            <div class="auth-footer">
                <p id="switch-text"><?php echo ($error === 'invalid_credentials' || $success === 'login_successful') ? 'New here? <a class="switch-link" id="switch-btn">Create an Account</a>' : 'Already a member? <a class="switch-link" id="switch-btn">Sign In</a>'; ?></p>
            </div>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const switchBtn = document.getElementById('switch-btn');
        const signupForm = document.getElementById('signup-form');
        const signinForm = document.getElementById('signin-form');
        const signupInfo = document.getElementById('signup-info');
        const signinInfo = document.getElementById('signin-info');
        const switchText = document.getElementById('switch-text');

        // Handle login success redirect with delay
        const loginSuccessAlert = document.getElementById('login-success-alert');
        if (loginSuccessAlert) {
            setTimeout(function() {
                window.location.href = 'index.php';
            }, 2000); // 2 second delay
        }

        // Ensure correct form is visible on page load based on PHP state
        <?php if ($error === 'invalid_credentials' || $success === 'login_successful'): ?>
        // Show sign in info, hide sign up info
        signupInfo.classList.add('hidden');
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
                switchText.innerHTML = 'New here? <a class="switch-link" id="switch-btn">Create an Account</a>';
            } else {
                switchText.innerHTML = 'Already a member? <a class="switch-link" id="switch-btn">Sign In</a>';
            }

            // Re-attach event listener to the new switch button
            document.getElementById('switch-btn').addEventListener('click', arguments.callee);
        });

        // Password confirmation validation
        const confirmPassword = document.getElementById('confirm-password');
        if (confirmPassword) {
            confirmPassword.addEventListener('keyup', function() {
                const password = document.getElementById('signup-password').value;
                const confirmPasswordValue = this.value;

                if (password && confirmPasswordValue) {
                    if (password !== confirmPasswordValue) {
                        this.style.borderColor = '#e74c3c';
                    } else {
                        this.style.borderColor = '#27ae60';
                    }
                }
            });
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>