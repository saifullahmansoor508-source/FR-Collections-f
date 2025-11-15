<?php
$admin_emails = ADMIN_EMAILS;
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], $admin_emails)) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Admin Panel - <?php echo SITE_NAME; ?></title>
    
    <!-- Static Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/logo.png">
    <link rel="apple-touch-icon" href="../assets/images/logo.png">
    
    <!-- jQuery (required for AJAX) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap JS Bundle (includes Popper for dropdowns) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <!-- Admin Panel Styles -->
    <link href="css/admin.css" rel="stylesheet">
    <!-- SheetJS for Excel/CSV parsing -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js" defer></script>
    <!-- Custom Notifications System - Deferred to load after DOM -->
    <script src="../assets/js/custom-notifications.js?v=2.0" defer></script>
    <style>
        /* ========================================
           ADMIN LAYOUT - Main Container
           ======================================== */
        .admin-layout {
            min-height: 100vh;
            position: relative;
        }
        
        /* ========================================
           SIDEBAR - Collapsible Navigation
           ======================================== */
        .admin-sidebar {
            background-color: var(--primary-color);
            min-height: 100vh;
            max-height: 100vh;
            color: white;
            position: fixed;
            width: 250px;
            z-index: 1050;
            /* Smooth slide transition */
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), 
                        box-shadow 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
            left: 0;
            top: 0;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        
        /* Custom scrollbar for sidebar */
        .admin-sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .admin-sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .admin-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }
        
        .admin-sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }
        
        /* ========================================
           SIDEBAR HEADER - Logo & Close Button
           ======================================== */
        .sidebar-header {
            position: relative;
            padding: 1rem;
        }
        
        /* Close button inside sidebar (✖️) */
        .sidebar-close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            opacity: 0;
            visibility: hidden;
        }
        
        .sidebar-close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }
        
        .sidebar-close-btn:active {
            transform: rotate(90deg) scale(0.9);
        }
        
        /* ========================================
           MAIN CONTENT AREA
           ======================================== */
        .admin-content {
            margin-left: 250px;
            padding: 20px;
            /* Smooth transition when sidebar toggles */
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
            width: calc(100% - 250px);
            max-width: calc(100% - 250px);
            overflow-x: hidden;
        }
        
        /* ========================================
           SIDEBAR NAVIGATION LINKS
           ======================================== */
        .admin-sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .admin-sidebar .nav-link:hover,
        .admin-sidebar .nav-link.active {
            background-color: var(--accent-color);
            color: #1e3a8a !important;
            font-weight: 600;
            padding-left: 25px;
        }
        
        .admin-sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
            text-align: center;
        }
        
        /* ========================================
           ADMIN HEADER - Top Bar
           ======================================== */
        .admin-header {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 15px 0;
            margin-bottom: 20px;
        }
        
        .admin-header .container-fluid {
            max-width: 100%;
        }
        
        .admin-header h4 {
            font-size: 1.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Responsive header buttons */
        .admin-header .btn {
            white-space: nowrap;
        }
        
        /* Keep buttons inline */
        .admin-header .d-flex.align-items-center.gap-2 {
            flex-wrap: nowrap !important;
            gap: 8px !important;
            min-width: 0;
        }
        
        /* Ensure header content doesn't wrap */
        .admin-header .d-flex.justify-content-between {
            flex-wrap: nowrap !important;
            gap: 12px;
        }
        
        .admin-header .d-flex.justify-content-between > div {
            flex-shrink: 0;
        }
        
        /* Allow title to shrink if needed */
        .admin-header h4 {
            flex-shrink: 1;
            min-width: 0;
        }
        
        @media (max-width: 992px) {
            .admin-header h4 {
                font-size: 1.1rem;
            }
            
            .admin-header .btn {
                padding: 8px 12px;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 768px) {
            /* COMPLETE FIX: Proper mobile layout */
            .admin-header {
                padding: 10px 0 !important;
                position: relative !important;
                margin-top: 15px !important;
            }
            
            .admin-header .container-fluid {
                padding: 0 !important;
            }
            
            .admin-header .px-3 {
                padding-left: 10px !important;
                padding-right: 10px !important;
                display: flex !important;
                align-items: center !important;
            }
            
            /* Main flex container */
            .admin-header .d-flex.justify-content-between {
                width: 100% !important;
                display: grid !important;
                grid-template-columns: auto 1fr auto !important;
                align-items: center !important;
                gap: 8px !important;
            }
            
            /* Sidebar toggle - first column */
            .sidebar-toggle {
                width: 36px !important;
                height: 36px !important;
                min-width: 36px !important;
                padding: 0 !important;
                margin: 0 !important;
                font-size: 1rem !important;
                flex-shrink: 0 !important;
                grid-column: 1 !important;
            }
            
            /* Title container - second column */
            .admin-header .d-flex.align-items-center:first-child {
                grid-column: 1 / 3 !important;
                display: grid !important;
                grid-template-columns: 36px 1fr !important;
                gap: 8px !important;
                align-items: center !important;
                min-width: 0 !important;
            }
            
            .admin-header h4 {
                font-size: 0.85rem !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                margin: 0 !important;
                line-height: 1.3 !important;
                grid-column: 2 !important;
            }
            
            /* Buttons container - third column */
            .admin-header .d-flex.align-items-center.gap-2 {
                grid-column: 3 !important;
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                gap: 6px !important;
                flex-shrink: 0 !important;
            }
            
            /* Button styles */
            .btn-gradient-view-site,
            .btn-gradient-admin {
                width: 36px !important;
                height: 36px !important;
                min-width: 36px !important;
                padding: 0 !important;
                font-size: 0.9rem !important;
                flex-shrink: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            
            .btn-gradient-view-site span,
            .btn-gradient-admin span {
                display: none !important;
            }
            
            .btn-gradient-view-site i,
            .btn-gradient-admin i {
                margin: 0 !important;
            }
            
            .btn-gradient-admin .fa-chevron-down {
                display: none !important;
            }
        }
        
        @media (max-width: 576px) {
            .admin-header h4 {
                font-size: 0.95rem;
            }
            
            .admin-header .d-flex {
                gap: 8px;
            }
        }
        
        /* ========================================
           HAMBURGER MENU BUTTON (☰)
           ======================================== */
        .sidebar-toggle {
            background: var(--primary-color);
            border: none;
            color: white;
            width: 45px;
            height: 45px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex; /* Always show toggle button */
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-toggle:hover {
            background: var(--accent-color);
            color: var(--primary-color);
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .sidebar-toggle:active {
            transform: scale(0.95);
        }
        
        /* Hamburger icon animation */
        .sidebar-toggle i {
            transition: transform 0.3s ease;
        }
        
        /* ========================================
           OVERLAY - Dark background when sidebar open on mobile
           ======================================== */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .sidebar-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        /* ========================================
           DESKTOP VIEW (> 768px)
           ======================================== */
        @media (min-width: 769px) {
            /* Sidebar always visible on desktop by default */
            .admin-sidebar {
                transform: translateX(0);
            }
            
            /* Hide close button on desktop */
            .sidebar-close-btn {
                display: none !important;
            }
            
            /* Collapsed state on desktop */
            .admin-sidebar.collapsed {
                transform: translateX(-250px);
            }
            
            .admin-content.expanded {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
            }
            
            /* Hamburger button always visible on desktop */
            .sidebar-toggle {
                display: flex !important;
            }
        }
        
        /* Responsive content adjustments */
        @media (max-width: 1400px) {
            .admin-content {
                padding: 16px;
            }
        }
        
        @media (max-width: 1200px) {
            .admin-content {
                padding: 14px;
            }
        }
        
        @media (max-width: 992px) {
            .admin-content {
                padding: 12px;
            }
        }
        
        /* ========================================
           MOBILE VIEW (<= 768px)
           ======================================== */
        @media (max-width: 768px) {
            /* Hide sidebar by default on mobile */
            .admin-sidebar {
                transform: translateX(-100%);
            }
            
            /* Show sidebar when toggled */
            .admin-sidebar.show {
                transform: translateX(0);
                box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
            }
            
            /* Show close button when sidebar is open */
            .admin-sidebar.show .sidebar-close-btn {
                opacity: 1;
                visibility: visible;
            }
            
            /* Content takes full width on mobile */
            .admin-content {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
                padding: 12px;
            }
            
            .admin-header {
                margin: -12px -12px 12px -12px;
            }
        }
        
        /* ========================================
           ADMIN STATS CARDS
           ======================================== */
        .admin-stats {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .admin-stats h3 {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .admin-stats::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        /* ========================================
           RESPONSIVE CONTAINERS & ELEMENTS
           ======================================== */
        /* Prevent any element from exceeding container width */
        .admin-content * {
            max-width: 100%;
            box-sizing: border-box;
        }
        
        /* Ensure Bootstrap containers fit properly */
        .admin-content .container,
        .admin-content .container-fluid {
            padding-left: 15px;
            padding-right: 15px;
            max-width: 100%;
        }
        
        /* Responsive rows */
        .admin-content .row {
            margin-left: -15px;
            margin-right: -15px;
            max-width: calc(100% + 30px);
        }
        
        /* Responsive columns */
        .admin-content [class*="col-"] {
            padding-left: 15px;
            padding-right: 15px;
        }
        
        /* Cards and panels */
        .admin-content .card,
        .admin-content .panel {
            max-width: 100%;
            overflow: hidden;
        }
        
        /* Tables responsive */
        .admin-content table {
            max-width: 100%;
            overflow-x: auto;
            display: block;
        }
        
        @media (min-width: 768px) {
            .admin-content table {
                display: table;
            }
        }
        
        /* Images responsive */
        .admin-content img {
            max-width: 100%;
            height: auto;
        }
        
        /* Buttons responsive */
        .admin-content .btn-group {
            flex-wrap: wrap;
        }
        
        /* ========================================
           RESPONSIVE STATS CARDS
           ======================================== */
        @media (max-width: 1400px) {
            .admin-stats h3 {
                font-size: 1.75rem;
            }
            
            .admin-stats {
                padding: 18px;
            }
        }
        
        @media (max-width: 1200px) {
            .admin-stats h3 {
                font-size: 1.5rem;
            }
            
            .admin-stats {
                padding: 16px;
            }
        }
        
        @media (max-width: 992px) {
            .admin-stats h3 {
                font-size: 1.4rem;
            }
            
            .admin-stats {
                padding: 14px;
            }
        }
        
        @media (max-width: 768px) {
            .admin-stats h3 {
                font-size: 1.3rem;
            }
            
            .admin-stats {
                padding: 12px;
            }
        }
        
        @media (max-width: 576px) {
            .admin-stats h3 {
                font-size: 1.2rem;
            }
            
            .admin-stats {
                padding: 10px;
                margin-bottom: 10px;
            }
            
            .admin-stats p {
                font-size: 0.85rem;
            }
        }
        
        /* ========================================
           GRADIENT NAVBAR BUTTONS - STYLISH & INTERACTIVE
           ======================================== */
        .btn-gradient-view-site,
        .btn-gradient-admin {
            position: relative;
            padding: 10px 20px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        /* View Site Button - Blue to Cyan Gradient */
        .btn-gradient-view-site {
            background: linear-gradient(135deg, #0058A3 0%, #00B4D8 100%);
            color: white;
        }
        
        .btn-gradient-view-site::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #00B4D8 0%, #0058A3 100%);
            transition: left 0.4s ease;
            z-index: 0;
        }
        
        .btn-gradient-view-site:hover::before {
            left: 0;
        }
        
        .btn-gradient-view-site:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 88, 163, 0.3);
            color: white;
            text-decoration: none;
        }
        
        .btn-gradient-view-site:active {
            transform: translateY(0);
            box-shadow: 0 4px 15px rgba(0, 88, 163, 0.2);
        }
        
        /* Admin Button - Orange to Red Gradient with Shine */
        .btn-gradient-admin {
            background: linear-gradient(135deg, #FF6B00 0%, #FF8C42 100%);
            color: white;
            position: relative;
            cursor: pointer;
            white-space: nowrap;
        }
        
        .btn-gradient-admin:focus,
        .btn-gradient-admin:active {
            color: white !important;
        }
        
        /* Dropdown container positioning */
        #adminDropdownContainer {
            position: relative;
        }
        
        #adminDropdownMenu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            z-index: 9999;
        }
        
        .btn-gradient-admin::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(255, 255, 255, 0.3) 50%,
                transparent 70%
            );
            animation: shine 3s infinite;
            z-index: 0;
        }
        
        @keyframes shine {
            0% {
                transform: translateX(-100%) translateY(-100%) rotate(45deg);
            }
            100% {
                transform: translateX(100%) translateY(100%) rotate(45deg);
            }
        }
        
        .btn-gradient-admin:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 25px rgba(255, 107, 0, 0.4);
            background: linear-gradient(135deg, #FF8C42 0%, #FF6B00 100%);
        }
        
        .btn-gradient-admin:active {
            transform: translateY(0) scale(1);
            box-shadow: 0 4px 15px rgba(255, 107, 0, 0.3);
        }
        
        /* Ensure icons and text are above the shine effect */
        .btn-gradient-view-site i,
        .btn-gradient-view-site span,
        .btn-gradient-admin i,
        .btn-gradient-admin span {
            position: relative;
            z-index: 1;
        }
        
        /* Modern Dropdown Menu */
        .dropdown-menu-modern {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            padding: 8px;
            margin-top: 8px;
            animation: dropdownSlide 0.3s ease;
        }
        
        @keyframes dropdownSlide {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .dropdown-menu-modern .dropdown-item {
            border-radius: 8px;
            padding: 10px 16px;
            transition: all 0.2s ease;
            font-weight: 500;
        }
        
        .dropdown-menu-modern .dropdown-item:hover {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            transform: translateX(4px);
        }
        
        .dropdown-menu-modern .dropdown-item.text-danger:hover {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #dc2626 !important;
        }
        
        .dropdown-menu-modern .dropdown-divider {
            margin: 8px 0;
            opacity: 0.1;
        }
        
        /* Responsive adjustments for gradient buttons */
        @media (max-width: 768px) {
            .btn-gradient-view-site span,
            .btn-gradient-admin span {
                display: none;
            }
            
            .btn-gradient-view-site,
            .btn-gradient-admin {
                padding: 10px 14px;
                border-radius: 10px;
            }
            
            .btn-gradient-view-site i,
            .btn-gradient-admin i {
                margin: 0 !important;
            }
        }
        
        @media (max-width: 576px) {
            .btn-gradient-view-site,
            .btn-gradient-admin {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
        }
    </style>
    
    <!-- Admin Mobile Responsive Styles -->
    <link rel="stylesheet" href="css/admin-mobile-responsive.css">
</head>
<body>
    <div class="admin-layout d-flex">
        <!-- Sidebar Overlay (Dark background on mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
        
        <!-- Sidebar -->
        <nav class="admin-sidebar" id="adminSidebar">
            <!-- Sidebar Header with Close Button -->
            <div class="sidebar-header p-3 border-bottom border-light border-opacity-25">
                <!-- Close button (✖️) - visible only on mobile when sidebar is open -->
                <button class="sidebar-close-btn" id="sidebarCloseBtn" onclick="closeSidebar()" title="Close Menu">
                    <i class="fas fa-times"></i>
                </button>
                
                <h5 class="mb-0">
                    <i class="fas fa-cog me-2"></i>
                    Admin Panel
                </h5>
                <small class="text-light opacity-75"><?php echo SITE_NAME; ?></small>
            </div>
            
            <ul class="nav nav-pills flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>" href="index.php">
                        <i class="fas fa-tachometer-alt"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'active' : ''; ?>" href="orders.php">
                        <i class="fas fa-shopping-cart"></i>Orders
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : ''; ?>" href="users.php">
                        <i class="fas fa-users"></i>Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'records.php' ? 'active' : ''; ?>" href="records.php">
                        <i class="fas fa-chart-line"></i>Records
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'affiliates.php' ? 'active' : ''; ?>" href="affiliates.php">
                        <i class="fas fa-handshake"></i>Affiliates
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'categories.php' ? 'active' : ''; ?>" href="categories.php">
                        <i class="fas fa-tags"></i>Categories
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'active' : ''; ?>" href="products.php">
                        <i class="fas fa-box"></i>Products
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'coupons.php' ? 'active' : ''; ?>" href="coupons.php">
                        <i class="fas fa-ticket-alt"></i>Coupon Codes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'reviews.php' ? 'active' : ''; ?>" href="reviews.php">
                        <i class="fas fa-star"></i>Reviews
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'withdrawals.php' ? 'active' : ''; ?>" href="withdrawals.php">
                        <i class="fas fa-money-bill-wave"></i>Withdrawals
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'contact.php' ? 'active' : ''; ?>" href="contact.php">
                        <i class="fas fa-envelope"></i>Contact Messages
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'product-requests.php' ? 'active' : ''; ?>" href="product-requests.php">
                        <i class="fas fa-plus-circle"></i>Product Requests
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'card-records.php' ? 'active' : ''; ?>" href="card-records.php">
                        <i class="fas fa-credit-card"></i>Cards Records
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'blog-posts.php' ? 'active' : ''; ?>" href="blog-posts.php">
                        <i class="fas fa-newspaper"></i>Blog Posts
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'import-blogs.php' ? 'active' : ''; ?>" href="import-blogs.php">
                        <i class="fas fa-cloud-upload-alt"></i>Import Blogs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'learning_zone.php' ? 'active' : ''; ?>" href="learning_zone.php">
                        <i class="fas fa-graduation-cap"></i>Learning Zone
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'add_video.php' ? 'active' : ''; ?>" href="add_video.php">
                        <i class="fas fa-video"></i>Add Video
                    </a>
                </li>
            </ul>
            
            <div class="mt-auto p-3 border-top border-light border-opacity-25">
                <div class="d-flex align-items-center mb-2">
                    <div class="user-avatar me-2" style="width: 30px; height: 30px; font-size: 0.8rem;">
                        <?php echo strtoupper(substr($_SESSION['admin_email'], 0, 1)); ?>
                    </div>
                    <small class="text-light opacity-75"><?php echo $_SESSION['admin_email']; ?></small>
                </div>
                <a href="logout.php" class="btn btn-outline-light btn-sm w-100">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="admin-content flex-grow-1">
            <!-- Header -->
            <div class="admin-header px-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <!-- Hamburger Menu Button (☰) -->
                        <button class="sidebar-toggle me-3" id="sidebarToggle" onclick="toggleSidebar()" title="Toggle Menu">
                            <i class="fas fa-bars"></i>
                        </button>
                        <h4 class="mb-0 text-primary-custom"><?php echo $page_title ?? 'Admin Panel'; ?></h4>
                    </div>
                    
                    <div class="d-flex align-items-center gap-2">
                        <a href="../index.php" class="btn-gradient-view-site" target="_blank">
                            <i class="fas fa-external-link-alt me-1"></i><span>View Site</span>
                        </a>
                        <div class="dropdown" id="adminDropdownContainer">
                            <button class="btn-gradient-admin" type="button" id="adminDropdown" onclick="toggleAdminDropdown(event)">
                                <i class="fas fa-user-shield me-1"></i><span>Admin</span>
                                <i class="fas fa-chevron-down ms-1" style="font-size: 0.8rem;"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern" id="adminDropdownMenu">
                                <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Page Content -->
