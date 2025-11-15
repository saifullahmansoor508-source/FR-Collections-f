<?php
/**
 * FR Collections - Main Configuration File
 * 
 * This file contains all site-wide configuration settings, constants,
 * and helper functions used throughout the application.
 * 
 * @package    FR Collections
 * @version    1.0
 * @author     FR Collections Team
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// SITE CONFIGURATION
// ============================================

define('SITE_NAME', 'FR Collections');
define('SITE_URL', 'https://frcollections.pk/');
define('ADMIN_EMAIL', 'info@frcollections.pk');


// ============================================
// ADMIN CREDENTIALS
// ============================================

define('ADMIN_EMAILS', [
    'frcollectionspkofficial@gmail.com' => 'S@ifu||@h',
    'raimanoor123mianwali@gmail.com' => 'F@t!m@'
]);

// ============================================
// UPLOAD DIRECTORIES
// ============================================

define('UPLOAD_DIR', 'uploads/');
define('PRODUCT_IMAGES_DIR', UPLOAD_DIR . 'products/');
define('SLIDER_IMAGES_DIR', UPLOAD_DIR . 'slider/');
define('LOGO_DIR', UPLOAD_DIR . 'logo/');
define('FAVICON_DIR', UPLOAD_DIR . 'favicon/');
define('BLOG_IMAGES_DIR', UPLOAD_DIR . 'blog/');

// ============================================
// COLOR SCHEME
// ============================================

define('PRIMARY_COLOR', '#0058A3');
define('ACCENT_COLOR', '#FF6B00');
define('SECTION_BG', '#F4F4F4');
define('TEXT_COLOR', '#333333');
define('FOOTER_COLOR', '#1E1E1E');

// ============================================
// CATEGORIES
// ============================================

define('CATEGORIES', [
    'All Categories',
    'Dresses',
    'Shoes', 
    'Jewellery',
    'Appliances',
    'Electronics',
    'Stationery',
    'Home & Living',
    'Kids Hub',
    'Gents Collection',
    'Purses & Bags',
    'Gifts',
    'Apparel',
    'Digital Products'
]);

// ============================================
// AUTHENTICATION FUNCTIONS
// ============================================

/**
 * Check if user is logged in
 * 
 * @return bool True if user is logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if current user is an admin
 * 
 * @return bool True if user is admin, false otherwise
 */
function isAdmin() {
    return isset($_SESSION['admin_email']) && array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS);
}

/**
 * Auto-login function - checks if user has remember me cookie and logs them in automatically
 * 
 * @param PDO $db Database connection object
 * @return bool True if auto-login successful, false otherwise
 */
function checkAutoLogin($db) {
    // If user is already logged in, no need to check
    if (isLoggedIn()) {
        return true;
    }
    
    // Check if remember me cookie exists
    if (isset($_COOKIE['remember_user']) && isset($_COOKIE['remember_token'])) {
        $user_email = $_COOKIE['remember_user'];
        $token = $_COOKIE['remember_token'];
        
        // Validate the token (simple hash check - in production, use more secure token storage)
        $expected_token = hash('sha256', $user_email . 'FR_COLLECTIONS_SECRET_KEY');
        
        if ($token === $expected_token) {
            // Fetch user from database
            try {
                $stmt = $db->prepare("SELECT id, full_name, email, is_blocked FROM users WHERE email = ?");
                $stmt->execute([$user_email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && !$user['is_blocked']) {
                    // Auto-login the user by setting session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['auto_logged_in'] = true;
                    
                    return true;
                }
            } catch (PDOException $e) {
                // Database error - fail silently
                return false;
            }
        }
    }
    
    return false;
}

/**
 * Set remember me cookie for persistent login
 * 
 * @param string $email User's email address
 * @return void
 */
function setRememberMeCookie($email) {
    $token = hash('sha256', $email . 'FR_COLLECTIONS_SECRET_KEY');
    // Cookie expires in 30 days
    setcookie('remember_user', $email, time() + (86400 * 30), "/", "", false, true);
    setcookie('remember_token', $token, time() + (86400 * 30), "/", "", false, true);
}

/**
 * Clear remember me cookies on logout
 * 
 * @return void
 */
function clearRememberMeCookie() {
    setcookie('remember_user', '', time() - 3600, "/");
    setcookie('remember_token', '', time() - 3600, "/");
}

/**
 * Check if currently logged-in user is blocked and force logout if blocked
 * 
 * @param PDO $db Database connection object
 * @return bool True if user is valid (not blocked), false if blocked and logged out
 */
function checkUserBlockStatus($db) {
    // Only check if user is logged in
    if (!isLoggedIn()) {
        return true;
    }
    
    // Check for logout flag first (for immediate logout when admin blocks user)
    require_once __DIR__ . '/session_manager.php';
    if (checkLogoutFlag($_SESSION['user_id'])) {
        // Clear session
        session_unset();
        session_destroy();
        
        // Clear remember me cookies
        clearRememberMeCookie();
        
        // Start new session for error message
        session_start();
        $_SESSION['block_message'] = 'Your account has been blocked by the administrator.';
        
        // Redirect to login page
        redirectTo('auth.php');
        return false;
    }
    
    try {
        $stmt = $db->prepare("SELECT is_blocked FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If user is blocked, force logout immediately
        if ($user && $user['is_blocked']) {
            // Clear session
            session_unset();
            session_destroy();
            
            // Clear remember me cookies
            clearRememberMeCookie();
            
            // Start new session for error message
            session_start();
            $_SESSION['block_message'] = 'Your account has been blocked by the administrator.';
            
            // Redirect to login page
            redirectTo('auth.php');
            return false;
        }
    } catch (PDOException $e) {
        // Database error - fail silently
        return true;
    }
    
    return true;
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

/**
 * Redirect to specified URL
 * 
 * @param string $url URL to redirect to
 * @return void
 */
function redirectTo($url) {
    header("Location: " . $url);
    exit();
}

/**
 * Sanitize user input to prevent XSS attacks
 * 
 * @param string $data Input data to sanitize
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Format price in Pakistani Rupees
 * 
 * @param float $price Price to format
 * @return string Formatted price string
 */
function formatPrice($price) {
    return 'PKR ' . number_format($price, 0);
}

// ============================================
// AFFILIATE FUNCTIONS
// ============================================

/**
 * Generate unique sequential Partner ID for affiliates
 * Uses database to ensure uniqueness and sequential numbering
 * 
 * @param PDO $db Database connection object
 * @return string Generated Partner ID (e.g., "FR8001")
 */
function generatePartnerID($db) {
    // Get the highest partner ID number
    $stmt = $db->prepare("SELECT partner_id FROM affiliates ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $lastPartner = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lastPartner && preg_match('/FR(\d+)/', $lastPartner['partner_id'], $matches)) {
        $lastNumber = intval($matches[1]);
        $newNumber = $lastNumber + 1;
    } else {
        // Start from FR8001 if no partners exist
        $newNumber = 8001;
    }
    
    return 'FR' . $newNumber;
}

/**
 * Generate random Partner ID for affiliates (Alternative method)
 * This is a simpler method that generates random 4-digit numbers
 * 
 * @return string Generated Partner ID (e.g., "FR1234")
 */
/**
 * Generate a random partner ID
 * 
 * @return string Generated Partner ID (e.g., "FR1234")
 */
function generateRandomPartnerID() {
    return 'FR' . rand(1000, 9999);
}

// ============================================
// WATERMARK FUNCTIONS
// ============================================

/**
 * Get watermarked image URL
 * 
 * @param string $imagePath Relative path to image (e.g., "uploads/products/image.jpg")
 * @return string URL to watermarked image
 */
function getWatermarkedImageUrl($imagePath) {
    if (empty($imagePath)) {
        return 'assets/images/no-image.jpg';
    }
    return 'watermarked-image.php?img=' . urlencode($imagePath);
}

/**
 * Get product image URL with watermark
 * 
 * @param string $imagePath Image filename or path
 * @return string Full URL to watermarked image
 */
function getProductImageUrl($imagePath) {
    if (empty($imagePath)) {
        return 'assets/images/no-image.jpg';
    }
    
    // If it's already a full path, use it
    if (strpos($imagePath, PRODUCT_IMAGES_DIR) === 0) {
        return getWatermarkedImageUrl($imagePath);
    }
    
    // Otherwise, prepend the product images directory
    return getWatermarkedImageUrl(PRODUCT_IMAGES_DIR . $imagePath);
}
?>
