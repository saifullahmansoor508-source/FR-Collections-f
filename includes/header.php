<?php
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check for auto-login (persistent authentication via cookies)
checkAutoLogin($db);

// Check if logged-in user is blocked (force logout if blocked)
checkUserBlockStatus($db);

// Get site logo and favicon (check if table exists first)
$logo = '';
$favicon = '';
try {
    $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_logo'");
    $stmt->execute();
    $logo = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_favicon'");
    $stmt->execute();
    $favicon = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Table doesn't exist yet (during installation)
    $logo = '';
    $favicon = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' . SITE_NAME : SITE_NAME; ?></title>
    
    <!-- Favicon and Icons -->
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/logo.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/logo.png">
    
    <!-- Additional meta tags for better SEO -->
    <meta name="theme-color" content="#1e3a8a">
    <meta name="msapplication-TileColor" content="#1e3a8a">
    <meta name="msapplication-TileImage" content="assets/images/logo.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Slick Carousel CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css"/>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick-theme.css"/>
    <link href="assets/css/user.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="assets/css/reviews.css?v=<?php echo time(); ?>" rel="stylesheet">

    <!-- jQuery MUST load first - required by other scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <!-- Custom Notifications System -->
    <script src="assets/js/custom-notifications.js"></script>
    <style>
        /* Force blue overlay on headers - immediate effect */
        .hero-overlay {
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.35), rgba(59, 130, 246, 0.25)) !important;
        }
        .shop-hero-overlay {
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.35), rgba(59, 130, 246, 0.25)) !important;
        }
    </style>
</head>
<body class="<?php echo isset($bodyClass) ? $bodyClass : 'no-gap'; ?>">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light" style="background-color: #1e3a8a; margin-bottom: 0;">
        <div class="container">
            <!-- Logo -->
            <a class="navbar-brand text-white fw-bold d-flex align-items-center" href="index.php">
                <?php if(file_exists('assets/images/logo.png')): ?>
                    <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" height="40" class="me-2">
                <?php else: ?>
                    <div class="logo-circle me-2">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                <?php endif; ?>
                <span class="logo-text"><?php echo SITE_NAME; ?></span>
            </a>

            <!-- Desktop Navigation - Center -->
            <div class="d-none d-lg-flex justify-content-center">
                <ul class="navbar-nav">
                    <?php 
                    $current_page = basename($_SERVER['PHP_SELF']);
                    $desktop_nav_items = [
                        ['url' => 'index.php', 'name' => 'Home'],
                        ['url' => 'about.php', 'name' => 'About'],
                        ['url' => 'shop.php', 'name' => 'Shop'],
                        ['url' => 'affiliate.php', 'name' => 'Affiliate'],
                        ['url' => 'contact.php', 'name' => 'Contact'],
                        ['url' => 'blog.php', 'name' => 'Blog']
                    ];
                    
                    foreach ($desktop_nav_items as $item):
                        $is_active = ($current_page === $item['url']);
                    ?>
                        <li class="nav-item">
                            <a class="nav-link px-3 <?php echo $is_active ? 'active' : ''; ?>" href="<?php echo $item['url']; ?>">
                                <?php echo $item['name']; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Desktop Right side - Sign In & Cart -->
            <div class="d-none d-lg-flex align-items-center">
                <?php if(isLoggedIn()): ?>
                    <a href="profile.php" class="text-decoration-none me-3">
                        <div class="user-avatar-circle">
                            <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                        </div>
                    </a>
                <?php else: ?>
                    <a href="auth.php" class="text-white text-decoration-none me-4">Sign In</a>
                <?php endif; ?>
                
                <!-- Cart Icon -->
                <a href="cart.php" class="text-white position-relative">
                    <i class="fas fa-shopping-cart fs-5"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-success" id="cart-count" style="font-size: 0.7rem;">
                        <?php 
                        if (isLoggedIn()) {
                            $stmt = $db->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo $result['total'] ?: 0;
                        } else {
                            echo '0';
                        }
                        ?>
                    </span>
                </a>
            </div>

            <!-- Mobile Right side - Profile, Cart & Hamburger -->
            <div class="d-lg-none d-flex align-items-center">
                <?php if(isLoggedIn()): ?>
                    <a href="profile.php" class="text-decoration-none me-3">
                        <div class="user-avatar-circle">
                            <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                        </div>
                    </a>
                <?php else: ?>
                    <a href="auth.php" class="text-white text-decoration-none me-3">Sign In</a>
                <?php endif; ?>
                
                <!-- Mobile Cart Icon -->
                <a href="cart.php" class="text-white position-relative me-3">
                    <i class="fas fa-shopping-cart fs-5"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-success" id="mobile-cart-count" style="font-size: 0.65rem;">
                        <?php 
                        if (isLoggedIn()) {
                            $stmt = $db->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo $result['total'] ?: 0;
                        } else {
                            echo '0';
                        }
                        ?>
                    </span>
                </a>
                
                <!-- Mobile toggle -->
                <button class="navbar-toggler border-0 p-0" type="button" onclick="openMobileMenu()">
                    <span class="navbar-toggler-icon-custom">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </div>

            <!-- Mobile Navigation Menu - Slide from Left -->
            <div class="mobile-menu-overlay d-lg-none" id="mobileMenuOverlay">
                <div class="mobile-menu-sidebar" id="mobileMenuSidebar">
                    <!-- Header with Cart and Close Button -->
                    <div class="mobile-menu-header">
                        <!-- Icons Container (left side) -->
                        <div class="mobile-menu-icons-left">
                            <!-- Favorites Icon - Golden Star -->
                            <a href="profile.php?tab=favorites#favorites" class="mobile-menu-favorites-icon" title="Favorites">
                                <i class="fas fa-star"></i>
                                <span class="mobile-menu-cart-badge" id="drawer-favorites-count">
                                    <?php 
                                    if (isLoggedIn()) {
                                        $stmt = $db->prepare("SELECT COUNT(*) as total FROM favorites WHERE user_id = ?");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                        echo $result['total'] ?: 0;
                                    } else {
                                        echo '0';
                                    }
                                    ?>
                                </span>
                            </a>
                            
                            <!-- Wishlist Icon - Red Heart -->
                            <a href="profile.php#wishlist" class="mobile-menu-wishlist-icon" title="Wishlist">
                                <i class="fas fa-heart"></i>
                                <span class="mobile-menu-cart-badge" id="drawer-wishlist-count">
                                    <?php 
                                    if (isLoggedIn()) {
                                        $stmt = $db->prepare("SELECT COUNT(*) as total FROM wishlist WHERE user_id = ?");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                        echo $result['total'] ?: 0;
                                    } else {
                                        echo '0';
                                    }
                                    ?>
                                </span>
                            </a>
                            
                            <!-- Cart Icon - Blue -->
                            <a href="cart.php" class="mobile-menu-cart-icon" title="Cart">
                                <i class="fas fa-shopping-cart"></i>
                                <span class="mobile-menu-cart-badge" id="drawer-cart-count">
                                    <?php 
                                    if (isLoggedIn()) {
                                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = ?");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                        echo $result['total'] ?: 0;
                                    } else {
                                        echo '0';
                                    }
                                    ?>
                                </span>
                            </a>
                            
                        </div>
                        
                        <!-- Close Button (right side) -->
                        <button class="mobile-menu-close" onclick="closeMobileMenu()" title="Close Menu">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- Navigation Links -->
                    <div class="mobile-menu-content">
                        <ul class="mobile-nav-list">
                            <?php 
                            $current_page = basename($_SERVER['PHP_SELF']);
                            $nav_items = [
                                ['url' => 'index.php', 'name' => 'Home'],
                                ['url' => 'about.php', 'name' => 'About'],
                                ['url' => 'shop.php', 'name' => 'Shop'],
                                ['url' => 'profile.php', 'name' => 'Profile'],
                                ['url' => 'affiliate.php', 'name' => 'Affiliate'],
                                ['url' => 'contact.php', 'name' => 'Contact'],
                                ['url' => 'blog.php', 'name' => 'Blog']
                            ];
                            
                            foreach ($nav_items as $item):
                                $is_active = ($current_page === $item['url']);
                            ?>
                                <li class="mobile-nav-item <?php echo $is_active ? 'active' : ''; ?>">
                                    <a href="<?php echo $item['url']; ?>" class="mobile-nav-link">
                                        <?php echo $item['name']; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <!-- My Orders Button -->
                        <a href="profile.php?tab=orders" class="mobile-orders-btn">
                            <i class="fas fa-shopping-bag me-2"></i> My Orders
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main>
