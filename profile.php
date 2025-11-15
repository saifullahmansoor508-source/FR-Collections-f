<?php
require_once 'config/config.php';

if (!isLoggedIn()) {
    redirectTo('auth.php');
}

$page_title = "Profile";
require_once 'includes/header.php';

// Get user details
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if user is affiliate
$stmt = $db->prepare("SELECT * FROM affiliates WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
$is_affiliate = !empty($affiliate);

// Function to check if card is already collected for an order
function isCardCollected($order_id, $card_type, $user_id, $db) {
    $stmt = $db->prepare("SELECT id FROM user_card_collections WHERE user_id = ? AND order_id = ? AND card_type = ? AND is_collected = TRUE");
    $stmt->execute([$user_id, $order_id, $card_type]);
    return $stmt->fetch() !== false;
}

// Get active tab
$active_tab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'personal';

// DIRECT DATABASE CLEANUP when viewing orders tab
if ($active_tab === 'orders') {
    try {
        // Clean up user_order cards that don't have valid orders
        $stmt = $db->prepare("
            DELETE ucc FROM user_card_collections ucc 
            LEFT JOIN orders o ON ucc.order_id = o.id AND o.user_id = ? AND o.status IN ('Confirmed', 'On The Way', 'Delivered')
            WHERE ucc.user_id = ? AND ucc.card_type = 'user_order' AND o.id IS NULL
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        
        // Clear and rebuild phase progress for user cards
        $stmt = $db->prepare("DELETE FROM user_phase_progress WHERE user_id = ? AND card_type = 'user_order'");
        $stmt->execute([$_SESSION['user_id']]);
        
        // Rebuild user card phases
        $stmt = $db->prepare("SELECT * FROM user_card_collections WHERE user_id = ? AND card_type = 'user_order' AND is_collected = TRUE ORDER BY collected_at ASC");
        $stmt->execute([$_SESSION['user_id']]);
        $user_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $user_phases = [];
        foreach ($user_cards as $index => $card) {
            $new_phase = floor($index / 10) + 1;
            $new_position = ($index % 10) + 1;
            
            $stmt = $db->prepare("UPDATE user_card_collections SET phase_number = ?, card_position = ? WHERE id = ?");
            $stmt->execute([$new_phase, $new_position, $card['id']]);
            
            if (!isset($user_phases[$new_phase])) {
                $user_phases[$new_phase] = 0;
            }
            $user_phases[$new_phase]++;
        }
        
        // Create phase progress records
        foreach ($user_phases as $phase_number => $cards_collected) {
            $is_completed = ($cards_collected >= 10);
            $stmt = $db->prepare("
                INSERT INTO user_phase_progress (user_id, phase_number, card_type, cards_collected, is_unlocked, is_phase_completed, unlocked_at, phase_completed_at) 
                VALUES (?, ?, 'user_order', ?, TRUE, ?, NOW(), ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                $phase_number, 
                $cards_collected, 
                $is_completed,
                $is_completed ? date('Y-m-d H:i:s') : null
            ]);
        }
        
        // Ensure Phase 1 exists
        if (empty($user_phases)) {
            $stmt = $db->prepare("
                INSERT INTO user_phase_progress (user_id, phase_number, card_type, cards_collected, is_unlocked, unlocked_at) 
                VALUES (?, 1, 'user_order', 0, TRUE, NOW())
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
        
    } catch (Exception $e) {
        error_log("Profile cleanup error: " . $e->getMessage());
    }
}


$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = sanitizeInput($_POST['full_name']);
        $email = sanitizeInput($_POST['email']);
        $phone_raw = sanitizeInput($_POST['phone']);
        // Clean and format phone number (already has +92 prefix from input)
        $phone_clean = preg_replace('/[^0-9]/', '', $phone_raw);
        $phone = !empty($phone_clean) ? '+92' . substr($phone_clean, -10) : '';
        $address = sanitizeInput($_POST['address']);
        
        if (empty($full_name) || empty($email)) {
            $error = "Name and email are required.";
        } else {
            $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
            if ($stmt->execute([$full_name, $email, $phone, $address, $_SESSION['user_id']])) {
                $_SESSION['user_name'] = $full_name;
                $_SESSION['user_email'] = $email;
                $success = "Profile updated successfully!";
                
                // Refresh user data
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = "Error updating profile.";
            }
        }
    } elseif (isset($_POST['request_product'])) {
        $product_name = sanitizeInput($_POST['product_name']);
        $category = sanitizeInput($_POST['category']);
        $image_path = '';
        
        // Handle image upload
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['product_image']['name'];
            $filetype = pathinfo($filename, PATHINFO_EXTENSION);
            
            if (in_array(strtolower($filetype), $allowed) && $_FILES['product_image']['size'] <= 2097152) {
                $new_filename = uniqid() . '.' . $filetype;
                $upload_path = 'uploads/product_requests/' . $new_filename;
                
                if (!file_exists('uploads/product_requests/')) {
                    mkdir('uploads/product_requests/', 0777, true);
                }
                
                if (move_uploaded_file($_FILES['product_image']['tmp_name'], $upload_path)) {
                    $image_path = $new_filename;
                }
            }
        }
        
        if (empty($product_name) || empty($category)) {
            $error = "Product name and category are required.";
        } else {
            $stmt = $db->prepare("INSERT INTO product_requests (user_id, product_name, category, image_path) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$_SESSION['user_id'], $product_name, $category, $image_path])) {
                $success = "Product request submitted successfully!";
            } else {
                $error = "Error submitting product request.";
            }
        }
    }
}
?>

<style>
    /* Profile Page Styles */
    .profile-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        padding: 60px 0;
        margin-bottom: 40px;
    }

    .profile-header h1 {
        color: white;
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 10px;
    }

    .profile-header p {
        color: rgba(255, 255, 255, 0.9);
        font-size: 1.1rem;
    }

    .profile-avatar-large {
        width: 100px;
        height: 100px;
        background: #10b981;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 48px;
        font-weight: 700;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        margin: 0 auto;
    }

    /* Alert Styles - Match auth.php */
    .alert {
        padding: 15px 20px;
        border-radius: 50px;
        margin-bottom: 20px;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
        animation: slideIn 0.3s ease-out;
        border: none;
    }

    .alert-danger {
        background: #ef4444;
        color: #fff;
    }

    .alert-success {
        background: #10b981;
        color: #fff;
    }

    .alert-info {
        background: #3b82f6;
        color: #fff;
    }

    .alert-warning {
        background: #f59e0b;
        color: #fff;
    }

    .alert-light {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
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

    .alert-light i {
        background: rgba(71, 85, 105, 0.1);
        color: #475569;
    }

    .alert .btn-close {
        background: transparent url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3e%3cpath d='M.293.293a1 1 0 011.414 0L8 6.586 14.293.293a1 1 0 111.414 1.414L9.414 8l6.293 6.293a1 1 0 01-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 01-1.414-1.414L6.586 8 .293 1.707a1 1 0 010-1.414z'/%3e%3c/svg%3e") center/1em auto no-repeat;
        opacity: 1;
        padding: 0;
        width: 20px;
        height: 20px;
    }

    .alert-light .btn-close {
        background: transparent url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23475569'%3e%3cpath d='M.293.293a1 1 0 011.414 0L8 6.586 14.293.293a1 1 0 111.414 1.414L9.414 8l6.293 6.293a1 1 0 01-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 01-1.414-1.414L6.586 8 .293 1.707a1 1 0 010-1.414z'/%3e%3c/svg%3e") center/1em auto no-repeat;
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

    .profile-sidebar {
        background: white;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        overflow: hidden;
    }

    .sidebar-header {
        padding: 30px 20px;
        text-align: center;
        border-bottom: 1px solid #e5e7eb;
    }

    .sidebar-avatar {
        width: 80px;
        height: 80px;
        background: #10b981;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 36px;
        font-weight: 700;
        margin: 0 auto 15px;
    }

    .sidebar-name {
        font-size: 1.25rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 5px;
    }

    .sidebar-email {
        font-size: 0.875rem;
        color: #64748b;
    }

    /* Hide mobile toggle on desktop */
    .mobile-menu-toggle {
        display: none;
    }

    .sidebar-nav {
        padding: 15px 0;
    }

    .sidebar-nav-item {
        display: flex;
        align-items: center;
        padding: 12px 25px;
        color: #475569;
        text-decoration: none;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
    }

    .sidebar-nav-item:hover {
        background: #f8fafc;
        color: #1e3a8a;
    }

    .sidebar-nav-item.active {
        background: #eff6ff;
        color: #1e3a8a;
        border-left-color: #1e3a8a;
        font-weight: 600;
    }

    .sidebar-nav-item i {
        width: 24px;
        margin-right: 12px;
        font-size: 1.1rem;
    }

    .sidebar-nav-item.logout-btn {
        color: #dc2626;
    }

    .sidebar-nav-item.logout-btn:hover {
        background: #fef2f2;
    }

    .profile-content {
        background: white;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        padding: 40px;
    }

    .content-title {
        color: #1e3a8a;
        font-size: 1.75rem;
        font-weight: 700;
        margin-bottom: 30px;
    }

    .info-row {
        display: flex;
        padding: 20px 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .info-row:last-child {
        border-bottom: none;
    }

    .info-label {
        font-weight: 600;
        color: #1e293b;
        width: 200px;
        flex-shrink: 0;
    }

    .info-value {
        color: #64748b;
        flex: 1;
    }

    .btn-edit-profile {
        background: #1e3a8a;
        color: white;
        padding: 12px 30px;
        border-radius: 50px;
        border: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-edit-profile:hover {
        background: #1e40af;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
        color: white;
    }

    .edit-mode .info-row {
        display: block;
        padding: 15px 0;
    }

    .edit-mode .form-label {
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 8px;
    }

    .edit-mode .form-control {
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 10px 15px;
    }

    .edit-mode .form-control:focus {
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    }

    /* Mobile Styles */
    @media (max-width: 991px) {
        .profile-header {
            background: linear-gradient(180deg, #1e3a8a 0%, #334155 100%);
            padding: 40px 0 80px 0;
            margin-bottom: 0;
            border-radius: 0 0 30px 30px;
            position: relative;
        }

        .profile-header h1 {
            font-size: 1.75rem;
            text-align: center;
            margin-top: 20px;
        }

        .profile-header p {
            font-size: 0.95rem;
            text-align: center;
        }

        .profile-avatar-large {
            width: 80px;
            height: 80px;
            font-size: 36px;
            margin: 0 auto;
        }

        .profile-header .d-flex {
            flex-direction: column;
            text-align: center;
        }

        .profile-header .d-flex .me-4 {
            margin-right: 0 !important;
            margin-bottom: 20px;
        }

        .profile-sidebar {
            margin-top: -60px;
            position: relative;
            z-index: 2;
        }

        .sidebar-header {
            padding: 40px 20px 30px;
        }

        .sidebar-avatar {
            width: 100px;
            height: 100px;
            font-size: 42px;
            margin: 0 auto 20px;
        }

        .sidebar-name {
            font-size: 1.35rem;
            font-weight: 700;
        }

        .sidebar-email {
            font-size: 0.9rem;
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 18px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            border-radius: 0;
            box-shadow: 0 2px 8px rgba(30, 58, 138, 0.2);
            user-select: none;
        }

        .mobile-menu-toggle:active {
            transform: scale(0.98);
        }

        .mobile-menu-toggle i {
            transition: transform 0.4s ease;
            font-size: 1.2rem;
        }

        .mobile-menu-toggle.open i {
            transform: rotate(180deg);
        }

        .sidebar-nav {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
        }

        .sidebar-nav.open {
            max-height: 800px;
            padding: 0;
        }

        .sidebar-nav:not(.open) {
            padding: 0;
        }

        .sidebar-nav-item {
            padding: 16px 25px;
            font-size: 1.05rem;
            border-left: none;
            border-radius: 0;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateX(-10px);
        }

        .sidebar-nav.open .sidebar-nav-item {
            opacity: 1;
            transform: translateX(0);
        }

        .sidebar-nav.open .sidebar-nav-item:nth-child(1) { transition-delay: 0.05s; }
        .sidebar-nav.open .sidebar-nav-item:nth-child(2) { transition-delay: 0.1s; }
        .sidebar-nav.open .sidebar-nav-item:nth-child(3) { transition-delay: 0.15s; }
        .sidebar-nav.open .sidebar-nav-item:nth-child(4) { transition-delay: 0.2s; }
        .sidebar-nav.open .sidebar-nav-item:nth-child(5) { transition-delay: 0.25s; }
        .sidebar-nav.open .sidebar-nav-item:nth-child(6) { transition-delay: 0.3s; }
        .sidebar-nav.open .sidebar-nav-item:nth-child(7) { transition-delay: 0.35s; }

        .sidebar-nav-item:last-child {
            border-bottom: none;
        }

        .sidebar-nav-item i {
            width: 30px;
            margin-right: 15px;
            font-size: 1.2rem;
        }

        .sidebar-nav-item.active {
            background: linear-gradient(90deg, #eff6ff 0%, #dbeafe 100%);
            color: #1e3a8a;
            font-weight: 600;
            border-left: 4px solid #1e3a8a;
            box-shadow: inset 0 0 10px rgba(30, 58, 138, 0.05);
        }

        .profile-content {
            padding: 30px 20px;
            margin-top: 20px;
        }

        .content-title {
            font-size: 1.5rem;
            margin-bottom: 25px;
        }

        .info-row {
            flex-direction: column;
            padding: 15px 0;
        }

        .info-label {
            width: 100%;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .info-value {
            font-size: 1rem;
            color: #1e293b;
        }

        .btn-edit-profile {
            width: 100%;
            padding: 14px 30px;
            font-size: 1.05rem;
        }
    }

    /* Custom Confirmation Modal */
    .custom-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease;
    }

    .custom-modal-overlay.show {
        display: flex;
    }

    .custom-modal {
        background: white;
        border-radius: 20px;
        padding: 40px;
        max-width: 450px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: slideUp 0.3s ease;
        text-align: center;
    }

    .modal-icon {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, #ef4444, #dc2626);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 2.5rem;
        color: white;
    }

    .modal-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 15px;
    }

    .modal-message {
        font-size: 1.1rem;
        color: #64748b;
        margin-bottom: 30px;
        line-height: 1.6;
    }

    .modal-buttons {
        display: flex;
        gap: 15px;
        justify-content: center;
    }

    .modal-btn {
        padding: 12px 40px;
        border-radius: 50px;
        font-size: 1rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        min-width: 120px;
    }

    .modal-btn-cancel {
        background: #e2e8f0;
        color: #475569;
    }

    .modal-btn-cancel:hover {
        background: #cbd5e1;
        transform: translateY(-2px);
    }

    .modal-btn-confirm {
        background: #ef4444;
        color: white;
    }

    .modal-btn-confirm:hover {
        background: #dc2626;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (max-width: 576px) {
        .custom-modal {
            padding: 30px 20px;
        }

        .modal-title {
            font-size: 1.5rem;
        }

        .modal-message {
            font-size: 1rem;
        }

        .modal-buttons {
            flex-direction: column;
        }

        .modal-btn {
            width: 100%;
        }
    }

    /* Order Section Styles */
    .section-title {
        font-size: 1.15rem;
        font-weight: 700;
        margin-bottom: 20px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .section-title i {
        font-size: 1rem;
    }

    .section-title.pending {
        color: #d97706;
    }

    .section-title.confirmed {
        color: #059669;
    }

    .section-title.ontheway {
        color: #2563eb;
    }

    .section-title.delivered {
        color: #7c3aed;
    }

    .section-title.canceled {
        color: #dc2626;
    }

    /* Wishlist Product Card Styles */
    .wishlist-card {
        background: white;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        height: 100%;
        display: flex;
        flex-direction: column;
    }

    .wishlist-card:hover {
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        transform: translateY(-5px);
    }

    .wishlist-image-container {
        position: relative;
        width: 100%;
        padding-top: 100%;
        overflow: hidden;
        background: #f8f9fa;
    }

    .wishlist-image {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .wishlist-card:hover .wishlist-image {
        transform: scale(1.05);
    }

    .wishlist-badge {
        position: absolute;
        top: 15px;
        right: 15px;
        background: rgba(239, 68, 68, 0.95);
        color: white;
        padding: 6px 15px;
        border-radius: 50px;
        font-size: 0.85rem;
        font-weight: 600;
        backdrop-filter: blur(10px);
    }

    .wishlist-heart {
        position: absolute;
        top: 15px;
        left: 15px;
        width: 40px;
        height: 40px;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        backdrop-filter: blur(10px);
    }

    .wishlist-heart i {
        color: #ef4444;
        font-size: 1.1rem;
    }

    .wishlist-heart:hover {
        background: #ef4444;
        transform: scale(1.1);
    }

    .wishlist-heart:hover i {
        color: white;
    }

    .wishlist-body {
        padding: 20px;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .wishlist-category {
        color: #94a3b8;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
        font-weight: 500;
    }

    .wishlist-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 12px;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        min-height: 44px;
    }

    .wishlist-price {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e3a8a;
        margin-bottom: 15px;
    }

    .wishlist-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: auto;
    }

    .wishlist-btn {
        padding: 12px 20px;
        border-radius: 50px;
        font-size: 0.95rem;
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

    .wishlist-btn-primary {
        background: #1e3a8a;
        color: white;
    }

    .wishlist-btn-primary:hover {
        background: #1e40af;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
        color: white;
    }

    .wishlist-btn-secondary {
        background: #fef2f2;
        color: #dc2626;
    }

    .wishlist-btn-secondary:hover {
        background: #fee2e2;
        transform: translateY(-2px);
    }

    .wishlist-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none !important;
    }

    .wishlist-empty {
        text-align: center;
        padding: 80px 20px;
        background: white;
        border-radius: 20px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        position: relative;
    }

    .wishlist-empty i {
        font-size: 4rem;
        color: #cbd5e1;
        margin-bottom: 20px;
    }

    .wishlist-empty h5 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 10px;
    }

    .wishlist-empty p {
        color: #94a3b8;
        margin-bottom: 35px;
    }

    @media (max-width: 768px) {
        .wishlist-card {
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 1.1rem;
        }
    }

    /* Modern Order Cards - Matching Image Design */
    .order-section-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: #1e3a8a;
        margin-bottom: 20px;
    }

    .modern-order-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border-left: 4px solid #fbbf24;
        transition: all 0.3s ease;
    }

    .modern-order-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        transform: translateY(-2px);
    }

    /* Border colors based on status */
    .modern-order-card[data-status="pending"] {
        border-left-color: #fbbf24;
    }

    .modern-order-card[data-status="confirmed"] {
        border-left-color: #10b981;
    }

    .modern-order-card[data-status="ontheway"] {
        border-left-color: #3b82f6;
    }

    .modern-order-card[data-status="delivered"] {
        border-left-color: #22c55e;
    }

    .modern-order-card[data-status="canceled"] {
        border-left-color: #ef4444;
    }

    .order-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .order-amount {
        font-size: 1.4rem;
        font-weight: 700;
        color: #1e3a8a;
    }

    .order-date-right {
        font-size: 0.9rem;
        color: #64748b;
    }

    .order-card-body {
        margin-bottom: 15px;
    }

    .order-items-text {
        font-size: 0.95rem;
        color: #475569;
        line-height: 1.6;
    }

    .order-items-text strong {
        color: #1e293b;
    }

    .order-card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 15px;
        border-top: 1px solid #e2e8f0;
    }

    .order-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .order-status-badge i {
        font-size: 8px;
    }

    /* Status badge colors */
    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .status-confirmed {
        background: #d1fae5;
        color: #065f46;
    }

    .status-ontheway {
        background: #dbeafe;
        color: #1e40af;
    }

    .status-delivered {
        background: #dcfce7;
        color: #166534;
    }

    .status-canceled {
        background: #fee2e2;
        color: #991b1b;
    }

    .order-cancel-btn {
        background: #fee2e2;
        color: #dc2626;
        border: none;
        padding: 8px 18px;
        border-radius: 6px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .order-cancel-btn:hover {
        background: #fecaca;
        transform: scale(1.05);
    }

    .no-orders-message {
        text-align: center;
        padding: 40px;
        background: #f8fafc;
        border-radius: 8px;
        color: #64748b;
        font-size: 0.95rem;
    }

    @media (max-width: 768px) {
        .modern-order-card {
            padding: 15px;
        }

        .order-amount {
            font-size: 1.2rem;
        }

        .order-card-footer {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .order-cancel-btn {
            width: 100%;
        }
    }

    /* Beautiful Custom Confirmation Modal */
    .custom-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.75);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 99999;
        opacity: 0;
        transition: opacity 0.3s ease;
        backdrop-filter: blur(8px);
    }

    .custom-modal-overlay.show {
        display: flex;
        opacity: 1;
        pointer-events: auto;
    }

    .custom-modal-overlay.show .custom-modal-container {
        transform: scale(1) translateY(0);
        opacity: 1;
    }

    .custom-modal-container {
        transform: scale(0.9) translateY(20px);
        opacity: 0;
        transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .custom-modal-content {
        background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
        border-radius: 24px;
        width: 450px;
        max-width: 90%;
        padding: 40px 35px 35px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 
                    0 0 0 1px rgba(255, 255, 255, 0.1);
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .custom-modal-content::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
        background-size: 200% 100%;
        animation: gradientShift 3s ease infinite;
    }

    .custom-modal-icon {
        width: 80px;
        height: 80px;
        margin: 0 auto 25px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 36px;
        position: relative;
        animation: iconPulse 2s ease-in-out infinite;
    }

    .custom-modal-icon::before {
        content: '';
        position: absolute;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        opacity: 0.2;
        animation: ripple 2s ease-out infinite;
    }

    .custom-modal-icon.cancel-icon {
        background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        color: white;
        box-shadow: 0 10px 30px rgba(251, 191, 36, 0.3);
    }

    .custom-modal-icon.cancel-icon::before {
        background: #fbbf24;
    }

    .custom-modal-icon.delete-icon {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        box-shadow: 0 10px 30px rgba(239, 68, 68, 0.3);
    }

    .custom-modal-icon.delete-icon::before {
        background: #ef4444;
    }

    .custom-modal-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 15px 0;
        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .custom-modal-message {
        font-size: 1.05rem;
        color: #64748b;
        line-height: 1.7;
        margin: 0 0 30px 0;
        font-weight: 500;
    }

    .custom-modal-buttons {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .custom-modal-btn {
        padding: 14px 32px;
        border-radius: 12px;
        border: none;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        position: relative;
        overflow: hidden;
    }

    .custom-modal-btn::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.3);
        transform: translate(-50%, -50%);
        transition: width 0.6s, height 0.6s;
    }

    .custom-modal-btn:hover::before {
        width: 300px;
        height: 300px;
    }

    .custom-modal-btn.btn-danger {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
    }

    .custom-modal-btn.btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
    }

    .custom-modal-btn.btn-secondary {
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        color: #475569;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    }

    .custom-modal-btn.btn-secondary:hover {
        transform: translateY(-2px);
        background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }

    @keyframes gradientShift {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }

    @keyframes iconPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }

    @keyframes ripple {
        0% {
            transform: scale(0.8);
            opacity: 0.6;
        }
        100% {
            transform: scale(1.5);
            opacity: 0;
        }
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    @keyframes slideUp {
        from {
            transform: translateY(50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    /* Delete Canceled Orders Button */
    .delete-canceled-btn {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        transition: all 0.3s ease;
        margin-bottom: 20px;
    }

    .delete-canceled-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
    }

    /* Eye Icon for View Product */
    .order-view-icon {
        background: #eff6ff;
        color: #2563eb;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
    }

    .order-view-icon:hover {
        background: #dbeafe;
        transform: scale(1.1);
    }

    .order-card-footer-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    /* Inline Eye and Download Icons */
    .order-view-icon-inline,
    .order-download-icon-inline {
        background: white;
        border: 2px solid #e5e7eb;
        color: #dc2626;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-left: 8px;
        padding: 0;
        font-size: 0.85rem;
        position: relative;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .order-view-icon-inline:hover {
        background: #fef2f2;
        border-color: #dc2626;
        transform: scale(1.1);
        box-shadow: 0 4px 8px rgba(220, 38, 38, 0.2);
    }

    .order-download-icon-inline {
        color: #ea580c;
        border-color: #e5e7eb;
    }

    .order-download-icon-inline:hover {
        background: #fff7ed;
        border-color: #ea580c;
        transform: scale(1.1);
        box-shadow: 0 4px 8px rgba(234, 88, 12, 0.2);
    }

    /* Mobile Order Cards - Compact & Collapsible */
    @media (max-width: 767px) {
        .mobile-order-compact {
            background: white;
            border-radius: 12px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .mobile-order-compact.expanded {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }

        .mobile-order-header {
            display: flex;
            align-items: center;
            padding: 15px;
            cursor: pointer;
            gap: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .mobile-order-header:active {
            transform: scale(0.98);
        }

        .mobile-order-compact.expanded .mobile-order-header {
            border-bottom-color: #e2e8f0;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        }

        .order-number-circle {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 4px 12px rgba(5, 150, 105, 0.4);
            }
            50% {
                box-shadow: 0 4px 20px rgba(5, 150, 105, 0.6);
            }
        }

        .mobile-order-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .mobile-order-amount {
            font-size: 1.15rem;
            font-weight: 700;
            color: #1e3a8a;
        }

        .mobile-order-date {
            font-size: 0.8rem;
            color: #64748b;
        }

        /* Mobile right section - contains icons and status */
        .mobile-order-right-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }

        /* Mobile order actions - icons on the right */
        .mobile-order-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }

        .mobile-order-actions .order-view-icon-inline,
        .mobile-order-actions .order-download-icon-inline {
            width: 32px;
            height: 32px;
            font-size: 0.9rem;
            background: white;
            border: 2px solid #e2e8f0;
        }

        .mobile-order-actions .order-view-icon-inline:hover {
            background: #eff6ff;
            border-color: #2563eb;
        }

        .mobile-order-actions .order-download-icon-inline:hover {
            background: #f0fdf4;
            border-color: #16a34a;
        }

        /* Mobile meta - status and dropdown arrow inline */
        .mobile-order-meta {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Smaller status badge inline with arrow */
        .mobile-status-badge-small {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .mobile-status-badge-small i {
            font-size: 0.5rem;
        }

        .mobile-status-badge-small.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .mobile-status-badge-small.confirmed {
            background: #d1fae5;
            color: #065f46;
        }

        .mobile-status-badge-small.ontheway {
            background: #dbeafe;
            color: #1e3a8a;
        }

        .mobile-status-badge-small.delivered {
            background: #dcfce7;
            color: #14532d;
        }

        .mobile-status-badge-small.canceled {
            background: #fee2e2;
            color: #991b1b;
        }

        .mobile-status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            flex-shrink: 0;
        }

        .mobile-status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .mobile-status-badge.confirmed {
            background: #d1fae5;
            color: #065f46;
        }

        .mobile-status-badge.ontheway {
            background: #dbeafe;
            color: #1e40af;
        }

        .mobile-status-badge.delivered {
            background: #dcfce7;
            color: #166534;
        }

        .mobile-status-badge.canceled {
            background: #fee2e2;
            color: #991b1b;
        }

        .mobile-dropdown-arrow {
            color: #1e3a8a;
            font-size: 0.7rem;
            width: 28px;
            height: 28px;
            background: rgba(30, 58, 138, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .mobile-order-compact.expanded .mobile-dropdown-arrow {
            transform: rotate(180deg);
            background: rgba(30, 58, 138, 0.15);
        }

        .mobile-order-details {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .mobile-order-compact.expanded .mobile-order-details {
            max-height: 2000px;
        }

        .mobile-order-summary {
            padding: 20px 15px;
            background: white;
        }

        .mobile-summary-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 15px;
        }

        .mobile-summary-title {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .mobile-summary-items-count {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .mobile-summary-product {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .mobile-summary-product:last-child {
            border-bottom: none;
        }

        .mobile-summary-img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .mobile-summary-product-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .mobile-summary-product-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1e293b;
        }

        .mobile-summary-variant {
            font-size: 0.85rem;
            color: #475569;
            margin-top: 4px;
            line-height: 1.4;
        }

        .mobile-summary-variant .badge {
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 6px;
            margin-right: 6px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            font-weight: 600;
        }

        .mobile-summary-qty {
            font-size: 0.85rem;
            color: #64748b;
        }

        .mobile-summary-price {
            font-weight: 700;
            color: #1e3a8a;
            font-size: 1rem;
            text-align: right;
        }

        .mobile-summary-totals {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #e2e8f0;
        }

        .mobile-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 0.95rem;
        }

        .mobile-summary-row.total {
            font-weight: 700;
            font-size: 1.1rem;
            color: #1e3a8a;
            padding-top: 12px;
            border-top: 2px solid #e2e8f0;
        }

        .mobile-coupon-applied {
            background: #d1fae5;
            padding: 14px 16px;
            border-radius: 10px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
            font-weight: 500;
            color: #047857;
            border: 1px solid #a7f3d0;
            position: relative;
        }

        .mobile-coupon-applied .coupon-check {
            width: 24px;
            height: 24px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            flex-shrink: 0;
        }

        .mobile-coupon-applied .coupon-close {
            margin-left: auto;
            color: #059669;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            opacity: 0.7;
            transition: opacity 0.2s;
            flex-shrink: 0;
        }

        .mobile-coupon-applied .coupon-close:hover {
            opacity: 1;
        }

        /* Hide desktop view on mobile */
        @media (max-width: 767px) {
            .modern-order-card {
                display: none;
            }
        }
    }

    /* Show desktop view on larger screens */
    @media (min-width: 768px) {
        .mobile-order-compact {
            display: none !important;
        }
    }

    /* Desktop Order Card Styles */
    .desktop-order-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        margin-bottom: 20px;
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .desktop-order-card:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    }

    .desktop-order-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 24px;
        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        cursor: pointer;
        user-select: none;
    }

    .desktop-order-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .desktop-order-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .desktop-order-amount {
        font-size: 20px;
        font-weight: 700;
        color: white;
    }

    .desktop-order-date {
        font-size: 13px;
        color: rgba(255,255,255,0.8);
    }

    .desktop-order-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .desktop-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        background: white;
        color: #1e3a8a;
    }

    .desktop-status-badge.pending {
        background: #fef3c7;
        color: #92400e;
    }

    .desktop-status-badge.confirmed {
        background: #dbeafe;
        color: #1e40af;
    }

    .desktop-status-badge i {
        font-size: 8px;
    }

    .desktop-dropdown-arrow {
        color: white;
        font-size: 18px;
        transition: transform 0.3s ease;
    }

    .desktop-order-card.expanded .desktop-dropdown-arrow {
        transform: rotate(180deg);
    }

    .desktop-order-details {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.4s ease;
    }

    .desktop-order-card.expanded .desktop-order-details {
        max-height: 2000px;
    }

    .desktop-order-summary {
        padding: 24px;
    }

    .desktop-summary-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        background: #f8fafc;
        border-radius: 8px;
        margin-bottom: 12px;
    }

    .desktop-item-img {
        width: 80px;
        height: 80px;
        object-fit: cover;
        border-radius: 8px;
        border: 2px solid #e2e8f0;
    }

    .desktop-item-details {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .desktop-item-name {
        font-size: 16px;
        font-weight: 600;
        color: #1e293b;
    }

    .desktop-item-variant {
        font-size: 13px;
        color: #64748b;
    }

    .desktop-item-variant .badge {
        font-size: 11px;
        padding: 2px 8px;
    }

    .desktop-item-qty {
        font-size: 13px;
        color: #64748b;
    }

    .desktop-item-price {
        font-size: 18px;
        font-weight: 700;
        color: #1e3a8a;
    }

    .desktop-order-totals {
        margin-top: 24px;
        padding-top: 20px;
        border-top: 2px solid #e2e8f0;
    }

    .desktop-coupon-info {
        padding: 12px;
        background: #fef3c7;
        border-radius: 8px;
        color: #92400e;
        font-size: 14px;
        margin-bottom: 16px;
    }

    .desktop-coupon-info i {
        color: #f59e0b;
    }

    .desktop-total-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        font-size: 15px;
        color: #475569;
    }

    .desktop-total-row.total {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 2px solid #e2e8f0;
        font-size: 18px;
        font-weight: 700;
        color: #1e3a8a;
    }

    .desktop-order-actions {
        margin-top: 20px;
        display: flex;
        justify-content: flex-end;
    }

    .btn-cancel-order {
        padding: 10px 24px;
        background: #ef4444;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-cancel-order:hover {
        background: #dc2626;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .btn-cancel-order i {
        margin-right: 6px;
    }

    /* Hide modern-order-card on desktop */
    @media (min-width: 768px) {
        .modern-order-card {
            display: none;
        }
    }

    /* Hide desktop-order-card on mobile */
    @media (max-width: 767px) {
        .desktop-order-card {
            display: none;
        }
    }
</style>

<!-- Profile Header -->
<div class="profile-header">
    <div class="container">
        <div class="text-center">
            <div class="profile-avatar-large mx-auto mb-3">
                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
            </div>
            <h1>Welcome Back, <?php echo htmlspecialchars(explode(' ', $user['full_name'])[0]); ?>!</h1>
            <p class="mb-0">Manage your personal info, and customize your experience.</p>
        </div>
    </div>
</div>

<!-- Profile Content -->
<div class="container mb-5">
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="profile-sidebar">
                <div class="sidebar-header">
                    <div class="sidebar-avatar">
                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                    </div>
                    <div class="sidebar-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                    <div class="sidebar-email"><?php echo htmlspecialchars($user['email']); ?></div>
                </div>
                
                <!-- Mobile Dropdown Toggle -->
                <div class="mobile-menu-toggle d-lg-none" id="mobileMenuToggle" onclick="toggleMobileMenu()">
                    <span>My Info</span>
                    <i class="fas fa-chevron-down" id="menuToggleIcon"></i>
                </div>
                
                <nav class="sidebar-nav" id="sidebarNav">
                    <a href="javascript:void(0)" data-tab="personal" class="sidebar-nav-item tab-link <?php echo $active_tab === 'personal' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i>Personal Info
                    </a>
                    <a href="javascript:void(0)" data-tab="orders" class="sidebar-nav-item tab-link <?php echo $active_tab === 'orders' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-bag"></i>My Orders
                    </a>
                    <a href="javascript:void(0)" data-tab="wishlist" class="sidebar-nav-item tab-link <?php echo $active_tab === 'wishlist' ? 'active' : ''; ?>">
                        <i class="fas fa-heart"></i>My Wishlist
                    </a>
                    <a href="javascript:void(0)" data-tab="favorites" class="sidebar-nav-item tab-link <?php echo $active_tab === 'favorites' ? 'active' : ''; ?>">
                        <i class="fas fa-star"></i>Favorite Products
                    </a>
                    <a href="javascript:void(0)" data-tab="request" class="sidebar-nav-item tab-link <?php echo $active_tab === 'request' ? 'active' : ''; ?>">
                        <i class="fas fa-lightbulb"></i>Request Product
                    </a>
                    <a href="cart.php" class="sidebar-nav-item">
                        <i class="fas fa-shopping-cart"></i>Your Cart
                    </a>
                    <a href="#" onclick="showLogoutModal(); return false;" class="sidebar-nav-item logout-btn">
                        <i class="fas fa-sign-out-alt"></i>Logout
                    </a>
                </nav>
            </div>
        </div>
        
        <!-- Content -->
        <div class="col-lg-9">
            <div class="profile-content">
                <div id="tab-personal" class="tab-pane <?php echo $active_tab === 'personal' ? 'active' : ''; ?>">
                <?php if ($active_tab === 'personal' || true): ?>
                    <!-- Personal Info -->
                    <div id="view-mode">
                        <h2 class="content-title mb-4">Personal Information</h2>
                        
                        <div class="info-row">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['address'] ?: 'Not provided'); ?></div>
                        </div>
                        
                        <div class="mt-4">
                            <button class="btn btn-edit-profile" onclick="toggleEditMode()">
                                <i class="fas fa-edit me-2"></i>Edit Profile
                            </button>
                        </div>
                    </div>
                    
                    <div id="edit-mode" class="edit-mode" style="display: none;">
                        <h2 class="content-title">Edit Personal Information</h2>
                        
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="full_name" class="form-label">Full Name</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" 
                                           value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control phone-input-clean" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', str_replace('+92', '', $user['phone']))); ?>"
                                       placeholder="+923001234567" maxlength="13" inputmode="numeric">
                                <small class="form-text text-muted">Enter your 10-digit number (e.g., 3001234567)</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($user['address']); ?></textarea>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" name="update_profile" class="btn btn-edit-profile">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleEditMode()" style="border-radius: 50px; padding: 12px 30px;">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                </div>
                
                <div id="tab-orders" class="tab-pane <?php echo $active_tab === 'orders' ? 'active' : ''; ?>">
                <?php if ($active_tab === 'orders' || true): ?>
                    <!-- My Orders -->
                    <h2 class="content-title">My Orders</h2>
                    
                    <?php
                    // Get all orders for the user
                    $stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
                    $stmt->execute([$_SESSION['user_id']]);
                    $all_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Separate orders by status
                    $pending_orders = array_filter($all_orders, function($order) { return $order['status'] === 'Pending'; });
                    $confirmed_orders = array_filter($all_orders, function($order) { return $order['status'] === 'Confirmed'; });
                    $ontheway_orders = array_filter($all_orders, function($order) { return $order['status'] === 'On The Way'; });
                    $delivered_orders = array_filter($all_orders, function($order) { return $order['status'] === 'Delivered'; });
                    $canceled_orders = array_filter($all_orders, function($order) { return $order['status'] === 'Canceled'; });
                    ?>
                    
                    <!-- Pending Orders -->
                    <div class="mb-5">
                        <h4 class="order-section-title">Pending Orders</h4>
                        <?php if (!empty($pending_orders)): ?>
                            <?php 
                            $order_number = 1;
                            foreach ($pending_orders as $order): 
                                // Get order items with images and variant combination details
                                try {
                                    $stmt = $db->prepare("
                                        SELECT oi.*, 
                                               p.name, 
                                               p.id as product_id,
                                               pi.image_path,
                                               pv.variant_name, 
                                               pv.variant_image,
                                               pvc.image_path as combination_image,
                                               (SELECT GROUP_CONCAT(
                                                    CONCAT(va.attribute_name, ':', vav.value_name) 
                                                    ORDER BY va.attribute_name 
                                                    SEPARATOR ' | '
                                                )
                                                FROM combination_attribute_map cam
                                                INNER JOIN variant_attribute_values vav ON cam.attribute_value_id = vav.id
                                                INNER JOIN variant_attributes va ON vav.attribute_id = va.id
                                                WHERE cam.combination_id = oi.variant_combination_id
                                               ) as combination_string
                                        FROM order_items oi 
                                        JOIN products p ON oi.product_id = p.id 
                                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                                        LEFT JOIN product_variants pv ON oi.variant_id = pv.id
                                        LEFT JOIN product_variant_combinations pvc ON oi.variant_combination_id = pvc.id
                                        WHERE oi.order_id = ?
                                    ");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    // Fallback if variant tables don't exist yet
                                    $stmt = $db->prepare("
                                        SELECT oi.*, 
                                               p.name, 
                                               p.id as product_id,
                                               pi.image_path,
                                               NULL as variant_name,
                                               NULL as variant_image,
                                               NULL as combination_image,
                                               NULL as combination_string
                                        FROM order_items oi 
                                        JOIN products p ON oi.product_id = p.id 
                                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                                        WHERE oi.order_id = ?
                                    ");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                }
                                ?>
                                
                                <!-- Desktop View -->
                                <div class="desktop-order-card" id="desktopOrder<?php echo $order['id']; ?>">
                                    <div class="desktop-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="desktop-order-left">
                                            <div class="order-number-circle"><?php echo $order_number; ?></div>
                                            <div class="desktop-order-info">
                                                <div class="desktop-order-amount">Rs <?php echo number_format($order['total_amount']); ?></div>
                                                <div class="desktop-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?> • <?php echo count($items); ?> items</div>
                                            </div>
                                        </div>
                                        <div class="desktop-order-right">
                                            <div class="desktop-status-badge pending">
                                                <i class="fas fa-circle"></i> Pending
                                            </div>
                                            <div class="desktop-dropdown-arrow">
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="desktop-order-details">
                                        <div class="desktop-order-summary">
                                            <?php foreach ($items as $item): 
                                                // Determine which image to display
                                                $display_image = null;
                                                if (!empty($item['combination_image'])) {
                                                    $display_image = 'uploads/products/' . $item['combination_image'];
                                                } elseif (!empty($item['variant_image'])) {
                                                    $display_image = 'uploads/products/' . $item['variant_image'];
                                                } elseif (!empty($item['image_path'])) {
                                                    $display_image = 'uploads/products/' . $item['image_path'];
                                                } else {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="desktop-summary-item">
                                                <img src="<?php echo $display_image; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="desktop-item-img" onerror="this.src='assets/images/placeholder.svg';">
                                                <div class="desktop-item-details">
                                                    <div class="desktop-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['variant_name'])): ?>
                                                    <div class="desktop-item-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="desktop-item-qty">Quantity: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="desktop-item-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="desktop-order-totals">
                                                <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-coupon-info">
                                                    <i class="fas fa-tag"></i> Coupon Applied: <strong><?php echo htmlspecialchars($order['coupon_code']); ?></strong> - You saved Rs <?php echo number_format($order['discount_amount']); ?>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="desktop-total-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="desktop-total-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-total-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="desktop-total-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="desktop-order-actions" onclick="event.stopPropagation();">
                                                <button class="btn-cancel-order" onclick="event.stopPropagation(); cancelOrderCustom(<?php echo $order['id']; ?>, event);" style="cursor: pointer; position: relative; z-index: 10;">
                                                    <i class="fas fa-times-circle"></i> Cancel Order
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mobile View -->
                                <div class="mobile-order-compact" id="mobileOrder<?php echo $order['id']; ?>">
                                    <div class="mobile-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="order-number-circle"><?php echo $order_number; ?></div>
                                        <div class="mobile-order-info">
                                            <div class="mobile-order-amount">Rs <?php echo number_format($order['total_amount']); ?></div>
                                            <div class="mobile-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?></div>
                                        </div>
                                        <div class="mobile-status-badge pending">
                                            <i class="fas fa-circle"></i> Pending
                                        </div>
                                        <div class="mobile-dropdown-arrow">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    <div class="mobile-order-details">
                                        <div class="mobile-order-summary">
                                            <div class="mobile-summary-header">
                                                <div class="mobile-summary-title">Order Summary</div>
                                                <div class="mobile-summary-items-count"><?php echo count($items); ?> items</div>
                                            </div>
                                            <?php foreach ($items as $item): 
                                                // Determine which image to display (matching checkout.php logic)
                                                // Priority: combination_image > variant_image > primary image
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
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                
                                                // Check if file exists, if not use placeholder
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="mobile-summary-product">
                                                <img src="<?php echo $display_image; ?>" 
                                                     alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                                     class="mobile-summary-img"
                                                     onerror="this.src='assets/images/placeholder.svg'; this.onerror=null;">
                                                <div class="mobile-summary-product-info">
                                                    <div class="mobile-summary-product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['combination_string'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Combination</span>
                                                        <?php echo htmlspecialchars(str_replace(' | ', ' | ', $item['combination_string'])); ?>
                                                    </div>
                                                    <?php elseif (!empty($item['variant_name'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="mobile-summary-qty">Qty: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="mobile-summary-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                            <div class="mobile-coupon-applied">
                                                <div class="coupon-check">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <span>Coupon applied! You saved Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                <span class="coupon-close" onclick="this.parentElement.style.display='none'">&times;</span>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="mobile-summary-totals">
                                                <div class="mobile-summary-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="mobile-summary-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="mobile-summary-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="mobile-summary-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <!-- Mobile Cancel Button -->
                                            <div class="mobile-order-actions" style="margin-top: 20px; padding: 0 15px 15px;" onclick="event.stopPropagation();">
                                                <button class="order-cancel-btn" onclick="event.stopPropagation(); cancelOrderCustom(<?php echo $order['id']; ?>, event);" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; position: relative; z-index: 10;">
                                                    <i class="fas fa-times-circle"></i> Cancel Order
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php 
                                $order_number++;
                            endforeach; ?>
                        <?php else: ?>
                            <div class="no-orders-message">
                                No orders in this category.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Confirmed Orders -->
                    <div class="mb-5">
                        <h4 class="order-section-title">Confirmed Orders</h4>
                        <?php if (!empty($confirmed_orders)): ?>
                            <?php 
                            $order_number = 1;
                            foreach ($confirmed_orders as $order): 
                                // Get order items with images and variant combination details
                                try {
                                    $stmt = $db->prepare("
                                        SELECT oi.*, 
                                               p.name, 
                                               p.id as product_id,
                                               pi.image_path,
                                               pv.variant_name, 
                                               pv.variant_image,
                                               pvc.image_path as combination_image,
                                               (SELECT GROUP_CONCAT(
                                                    CONCAT(va.attribute_name, ':', vav.value_name) 
                                                    ORDER BY va.attribute_name 
                                                    SEPARATOR ' | '
                                                )
                                                FROM combination_attribute_map cam
                                                INNER JOIN variant_attribute_values vav ON cam.attribute_value_id = vav.id
                                                INNER JOIN variant_attributes va ON vav.attribute_id = va.id
                                                WHERE cam.combination_id = oi.variant_combination_id
                                               ) as combination_string
                                        FROM order_items oi 
                                        JOIN products p ON oi.product_id = p.id 
                                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                                        LEFT JOIN product_variants pv ON oi.variant_id = pv.id
                                        LEFT JOIN product_variant_combinations pvc ON oi.variant_combination_id = pvc.id
                                        WHERE oi.order_id = ?
                                    ");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    // Fallback if variant tables don't exist yet
                                    $stmt = $db->prepare("
                                        SELECT oi.*, 
                                               p.name, 
                                               p.id as product_id,
                                               pi.image_path,
                                               NULL as variant_name,
                                               NULL as variant_image,
                                               NULL as combination_image,
                                               NULL as combination_string
                                        FROM order_items oi 
                                        JOIN products p ON oi.product_id = p.id 
                                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                                        WHERE oi.order_id = ?
                                    ");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                }
                                ?>
                                <!-- Desktop View -->
                                <div class="desktop-order-card" id="desktopOrder<?php echo $order['id']; ?>">
                                    <div class="desktop-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="desktop-order-left">
                                            <div class="order-number-circle"><?php echo $order_number; ?></div>
                                            <div class="desktop-order-info">
                                                <div class="desktop-order-amount">
                                                    Rs <?php echo number_format($order['total_amount']); ?>
                                                    <button class="order-view-icon-inline" onclick="event.stopPropagation(); viewOrderDetails(<?php echo $order['id']; ?>)" title="View Receipt">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="order-download-icon-inline" onclick="event.stopPropagation(); downloadAsPNG(<?php echo $order['id']; ?>)" title="Download Receipt">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                </div>
                                                <div class="desktop-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?> • <?php echo count($items); ?> items</div>
                                            </div>
                                        </div>
                                        <div class="desktop-order-right">
                                            <div class="desktop-status-badge confirmed">
                                                <i class="fas fa-circle"></i> Confirmed
                                            </div>
                                            <div class="desktop-dropdown-arrow">
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="desktop-order-details">
                                        <div class="desktop-order-summary">
                                            <?php foreach ($items as $item): 
                                                // Determine which image to display
                                                $display_image = null;
                                                if (!empty($item['combination_image'])) {
                                                    $display_image = 'uploads/products/' . $item['combination_image'];
                                                } elseif (!empty($item['variant_image'])) {
                                                    $display_image = 'uploads/products/' . $item['variant_image'];
                                                } elseif (!empty($item['image_path'])) {
                                                    $display_image = 'uploads/products/' . $item['image_path'];
                                                } else {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="desktop-summary-item">
                                                <img src="<?php echo $display_image; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="desktop-item-img" onerror="this.src='assets/images/placeholder.svg';">
                                                <div class="desktop-item-details">
                                                    <div class="desktop-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['variant_name'])): ?>
                                                    <div class="desktop-item-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="desktop-item-qty">Quantity: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="desktop-item-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="desktop-order-totals">
                                                <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-coupon-info">
                                                    <i class="fas fa-tag"></i> Coupon Applied: <strong><?php echo htmlspecialchars($order['coupon_code']); ?></strong> - You saved Rs <?php echo number_format($order['discount_amount']); ?>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="desktop-total-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="desktop-total-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-total-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="desktop-total-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="desktop-order-actions" onclick="event.stopPropagation();">
                                                <?php 
                                                $is_collected = isCardCollected($order['id'], 'user_order', $_SESSION['user_id'], $db);
                                                if ($is_collected): ?>
                                                    <button class="btn-collected" style="background: linear-gradient(135deg, #10b981, #34d399); cursor: not-allowed; position: relative; z-index: 10; color: white; border: none; padding: 12px 24px; border-radius: 50px; font-weight: 600;" disabled>
                                                        <i class="fas fa-check"></i> Collected
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-collect-card" onclick="event.stopPropagation(); collectCard(<?php echo $order['id']; ?>, 'user_order', event);" style="cursor: pointer; position: relative; z-index: 10;">
                                                        <i class="fas fa-credit-card"></i> Collect Card
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mobile View -->
                                <div class="mobile-order-compact" id="mobileOrder<?php echo $order['id']; ?>">
                                    <div class="mobile-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="order-number-circle"><?php echo $order_number; ?></div>
                                        <div class="mobile-order-info">
                                            <div class="mobile-order-amount">Rs <?php echo number_format($order['total_amount']); ?></div>
                                            <div class="mobile-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?></div>
                                        </div>
                                        <div class="mobile-order-right-section">
                                            <div class="mobile-order-actions">
                                                <button class="order-view-icon-inline" onclick="event.stopPropagation(); viewOrderDetails(<?php echo $order['id']; ?>)" title="View Receipt">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="order-download-icon-inline" onclick="event.stopPropagation(); downloadAsPNG(<?php echo $order['id']; ?>)" title="Download Receipt">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </div>
                                            <div class="mobile-order-meta">
                                                <div class="mobile-status-badge-small confirmed">
                                                    <i class="fas fa-circle"></i> Confirmed
                                                </div>
                                                <div class="mobile-dropdown-arrow">
                                                    <i class="fas fa-chevron-down"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mobile-order-details">
                                        <div class="mobile-order-summary">
                                            <div class="mobile-summary-header">
                                                <div class="mobile-summary-title">Order Summary</div>
                                                <div class="mobile-summary-items-count"><?php echo count($items); ?> items</div>
                                            </div>
                                            <?php foreach ($items as $item): 
                                                // Determine which image to display (matching checkout.php logic)
                                                // Priority: combination_image > variant_image > primary image
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
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                
                                                // Check if file exists, if not use placeholder
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="mobile-summary-product">
                                                <img src="<?php echo $display_image; ?>" 
                                                     alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                                     class="mobile-summary-img"
                                                     onerror="this.src='assets/images/placeholder.svg'; this.onerror=null;">
                                                <div class="mobile-summary-product-info">
                                                    <div class="mobile-summary-product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['combination_string'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Combination</span>
                                                        <?php echo htmlspecialchars(str_replace(' | ', ' | ', $item['combination_string'])); ?>
                                                    </div>
                                                    <?php elseif (!empty($item['variant_name'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="mobile-summary-qty">Qty: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="mobile-summary-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                            <div class="mobile-coupon-applied">
                                                <div class="coupon-check">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <span>Coupon applied! You saved Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                <span class="coupon-close" onclick="this.parentElement.style.display='none'">&times;</span>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="mobile-summary-totals">
                                                <div class="mobile-summary-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="mobile-summary-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="mobile-summary-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="mobile-summary-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <!-- Mobile Collect Card Button -->
                                            <div class="mobile-order-actions" style="margin-top: 20px; padding: 0 15px 15px;" onclick="event.stopPropagation();">
                                                <?php 
                                                $is_collected = isCardCollected($order['id'], 'user_order', $_SESSION['user_id'], $db);
                                                if ($is_collected): ?>
                                                    <button class="btn-collected-mobile" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #10b981, #34d399); cursor: not-allowed; position: relative; z-index: 10; color: white; border: none;" disabled>
                                                        <i class="fas fa-check"></i> Collected
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-collect-card-mobile" onclick="event.stopPropagation(); collectCard(<?php echo $order['id']; ?>, 'user_order', event);" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; position: relative; z-index: 10;">
                                                        <i class="fas fa-credit-card"></i> Collect Card
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php 
                                $order_number++;
                            endforeach; ?>
                        <?php else: ?>
                            <div class="no-orders-message">
                                No orders in this category.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- On the Way -->
                    <div class="mb-5">
                        <h4 class="order-section-title">On the Way</h4>
                        <?php if (!empty($ontheway_orders)): ?>
                            <?php 
                            $order_number = 1;
                            foreach ($ontheway_orders as $order): 
                                // Get order items with images
                                try {
                                    $stmt = $db->prepare("
                                        SELECT oi.*, 
                                               p.name, 
                                               p.id as product_id,
                                               pi.image_path,
                                               pv.variant_name, 
                                               pv.variant_image,
                                               pvc.image_path as combination_image
                                        FROM order_items oi 
                                        JOIN products p ON oi.product_id = p.id 
                                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                                        LEFT JOIN product_variants pv ON oi.variant_id = pv.id
                                        LEFT JOIN product_variant_combinations pvc ON oi.variant_combination_id = pvc.id
                                        WHERE oi.order_id = ?
                                    ");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    $stmt = $db->prepare("SELECT oi.*, p.name, p.id as product_id, pi.image_path, NULL as variant_name, NULL as variant_image, NULL as combination_image FROM order_items oi JOIN products p ON oi.product_id = p.id LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1 WHERE oi.order_id = ?");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                }
                                ?>
                                <!-- Desktop View -->
                                <div class="desktop-order-card" id="desktopOrder<?php echo $order['id']; ?>">
                                    <div class="desktop-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="desktop-order-left">
                                            <div class="order-number-circle"><?php echo $order_number; ?></div>
                                            <div class="desktop-order-info">
                                                <div class="desktop-order-amount">
                                                    Rs <?php echo number_format($order['total_amount']); ?>
                                                    <button class="order-view-icon-inline" onclick="event.stopPropagation(); viewOrderDetails(<?php echo $order['id']; ?>)" title="View Receipt">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="order-download-icon-inline" onclick="event.stopPropagation(); downloadAsPNG(<?php echo $order['id']; ?>)" title="Download Receipt">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                </div>
                                                <div class="desktop-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?> • <?php echo count($items); ?> items</div>
                                            </div>
                                        </div>
                                        <div class="desktop-order-right">
                                            <div class="desktop-status-badge ontheway">
                                                <i class="fas fa-circle"></i> On the Way
                                            </div>
                                            <div class="desktop-dropdown-arrow">
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="desktop-order-details">
                                        <div class="desktop-order-summary">
                                            <?php foreach ($items as $item): 
                                                $display_image = null;
                                                if (!empty($item['combination_image'])) {
                                                    $display_image = 'uploads/products/' . $item['combination_image'];
                                                } elseif (!empty($item['variant_image'])) {
                                                    $display_image = 'uploads/products/' . $item['variant_image'];
                                                } elseif (!empty($item['image_path'])) {
                                                    $display_image = 'uploads/products/' . $item['image_path'];
                                                } else {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="desktop-summary-item">
                                                <img src="<?php echo $display_image; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="desktop-item-img" onerror="this.src='assets/images/placeholder.svg';">
                                                <div class="desktop-item-details">
                                                    <div class="desktop-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['variant_name'])): ?>
                                                    <div class="desktop-item-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="desktop-item-qty">Quantity: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="desktop-item-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="desktop-order-totals">
                                                <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-coupon-info">
                                                    <i class="fas fa-tag"></i> Coupon Applied: <strong><?php echo htmlspecialchars($order['coupon_code']); ?></strong> - You saved Rs <?php echo number_format($order['discount_amount']); ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="desktop-total-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="desktop-total-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-total-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="desktop-total-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="desktop-order-actions" onclick="event.stopPropagation();">
                                                <?php 
                                                $is_collected = isCardCollected($order['id'], 'user_order', $_SESSION['user_id'], $db);
                                                if ($is_collected): ?>
                                                    <button class="btn-collected" style="background: linear-gradient(135deg, #10b981, #34d399); cursor: not-allowed; position: relative; z-index: 10; color: white; border: none; padding: 12px 24px; border-radius: 50px; font-weight: 600;" disabled>
                                                        <i class="fas fa-check"></i> Collected
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-collect-card" onclick="event.stopPropagation(); collectCard(<?php echo $order['id']; ?>, 'user_order', event);" style="cursor: pointer; position: relative; z-index: 10;">
                                                        <i class="fas fa-credit-card"></i> Collect Card
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mobile View -->
                                <div class="mobile-order-compact" id="mobileOrder<?php echo $order['id']; ?>">
                                    <div class="mobile-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="order-number-circle"><?php echo $order_number; ?></div>
                                        <div class="mobile-order-info">
                                            <div class="mobile-order-amount">Rs <?php echo number_format($order['total_amount']); ?></div>
                                            <div class="mobile-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?></div>
                                        </div>
                                        <div class="mobile-order-right-section">
                                            <div class="mobile-order-actions">
                                                <button class="order-view-icon-inline" onclick="event.stopPropagation(); viewOrderDetails(<?php echo $order['id']; ?>)" title="View Receipt">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="order-download-icon-inline" onclick="event.stopPropagation(); downloadAsPNG(<?php echo $order['id']; ?>)" title="Download Receipt">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </div>
                                            <div class="mobile-order-meta">
                                                <div class="mobile-status-badge-small ontheway">
                                                    <i class="fas fa-circle"></i> On the Way
                                                </div>
                                                <div class="mobile-dropdown-arrow">
                                                    <i class="fas fa-chevron-down"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mobile-order-details">
                                        <div class="mobile-order-summary">
                                            <div class="mobile-summary-header">
                                                <div class="mobile-summary-title">Order Summary</div>
                                                <div class="mobile-summary-items-count"><?php echo count($items); ?> items</div>
                                            </div>
                                            <?php foreach ($items as $item): 
                                                $display_image = null;
                                                if (!empty($item['combination_image'])) {
                                                    $display_image = 'uploads/products/' . $item['combination_image'];
                                                } elseif (!empty($item['variant_image'])) {
                                                    $display_image = 'uploads/products/' . $item['variant_image'];
                                                } elseif (!empty($item['image_path'])) {
                                                    $display_image = 'uploads/products/' . $item['image_path'];
                                                } else {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="mobile-summary-product">
                                                <img src="<?php echo $display_image; ?>" 
                                                     alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                                     class="mobile-summary-img"
                                                     onerror="this.src='assets/images/placeholder.svg'; this.onerror=null;">
                                                <div class="mobile-summary-product-info">
                                                    <div class="mobile-summary-product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['combination_string'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Combination</span>
                                                        <?php echo htmlspecialchars(str_replace(' | ', ' | ', $item['combination_string'])); ?>
                                                    </div>
                                                    <?php elseif (!empty($item['variant_name'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="mobile-summary-qty">Qty: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="mobile-summary-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                            <div class="mobile-coupon-applied">
                                                <div class="coupon-check">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <span>Coupon applied! You saved Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                <span class="coupon-close" onclick="this.parentElement.style.display='none'">&times;</span>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="mobile-summary-totals">
                                                <div class="mobile-summary-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="mobile-summary-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="mobile-summary-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="mobile-summary-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <!-- Mobile Collect Card Button -->
                                            <div class="mobile-order-actions" style="margin-top: 20px; padding: 0 15px 15px;" onclick="event.stopPropagation();">
                                                <?php 
                                                $is_collected = isCardCollected($order['id'], 'user_order', $_SESSION['user_id'], $db);
                                                if ($is_collected): ?>
                                                    <button class="btn-collected-mobile" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #10b981, #34d399); cursor: not-allowed; position: relative; z-index: 10; color: white; border: none;" disabled>
                                                        <i class="fas fa-check"></i> Collected
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-collect-card-mobile" onclick="event.stopPropagation(); collectCard(<?php echo $order['id']; ?>, 'user_order', event);" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; position: relative; z-index: 10;">
                                                        <i class="fas fa-credit-card"></i> Collect Card
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php 
                                $order_number++;
                            endforeach; ?>
                        <?php else: ?>
                            <div class="no-orders-message">
                                No orders in this category.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Delivered Orders -->
                    <div class="mb-5">
                        <h4 class="order-section-title">Delivered Orders</h4>
                        <?php if (!empty($delivered_orders)): ?>
                            <?php 
                            $order_number = 1;
                            foreach ($delivered_orders as $order): 
                                // Get order items with images
                                try {
                                    $stmt = $db->prepare("
                                        SELECT oi.*, 
                                               p.name, 
                                               p.id as product_id,
                                               pi.image_path,
                                               pv.variant_name, 
                                               pv.variant_image,
                                               pvc.image_path as combination_image
                                        FROM order_items oi 
                                        JOIN products p ON oi.product_id = p.id 
                                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                                        LEFT JOIN product_variants pv ON oi.variant_id = pv.id
                                        LEFT JOIN product_variant_combinations pvc ON oi.variant_combination_id = pvc.id
                                        WHERE oi.order_id = ?
                                    ");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    $stmt = $db->prepare("SELECT oi.*, p.name, p.id as product_id, pi.image_path, NULL as variant_name, NULL as variant_image, NULL as combination_image FROM order_items oi JOIN products p ON oi.product_id = p.id LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1 WHERE oi.order_id = ?");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                }
                                ?>
                                <!-- Desktop View -->
                                <div class="desktop-order-card" id="desktopOrder<?php echo $order['id']; ?>">
                                    <div class="desktop-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="desktop-order-left">
                                            <div class="order-number-circle"><?php echo $order_number; ?></div>
                                            <div class="desktop-order-info">
                                                <div class="desktop-order-amount">
                                                    Rs <?php echo number_format($order['total_amount']); ?>
                                                    <button class="order-view-icon-inline" onclick="event.stopPropagation(); viewOrderDetails(<?php echo $order['id']; ?>)" title="View Receipt">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="order-download-icon-inline" onclick="event.stopPropagation(); downloadAsPNG(<?php echo $order['id']; ?>)" title="Download Receipt">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                </div>
                                                <div class="desktop-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?> • <?php echo count($items); ?> items</div>
                                            </div>
                                        </div>
                                        <div class="desktop-order-right">
                                            <div class="desktop-status-badge delivered">
                                                <i class="fas fa-circle"></i> Delivered
                                            </div>
                                            <div class="desktop-dropdown-arrow">
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="desktop-order-details">
                                        <div class="desktop-order-summary">
                                            <?php foreach ($items as $item): 
                                                $display_image = null;
                                                if (!empty($item['combination_image'])) {
                                                    $display_image = 'uploads/products/' . $item['combination_image'];
                                                } elseif (!empty($item['variant_image'])) {
                                                    $display_image = 'uploads/products/' . $item['variant_image'];
                                                } elseif (!empty($item['image_path'])) {
                                                    $display_image = 'uploads/products/' . $item['image_path'];
                                                } else {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="desktop-summary-item">
                                                <img src="<?php echo $display_image; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="desktop-item-img" onerror="this.src='assets/images/placeholder.svg';">
                                                <div class="desktop-item-details">
                                                    <div class="desktop-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['variant_name'])): ?>
                                                    <div class="desktop-item-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="desktop-item-qty">Quantity: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="desktop-item-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="desktop-order-totals">
                                                <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-coupon-info">
                                                    <i class="fas fa-tag"></i> Coupon Applied: <strong><?php echo htmlspecialchars($order['coupon_code']); ?></strong> - You saved Rs <?php echo number_format($order['discount_amount']); ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="desktop-total-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="desktop-total-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-total-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="desktop-total-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="desktop-order-actions" onclick="event.stopPropagation();">
                                                <?php 
                                                $is_collected = isCardCollected($order['id'], 'user_order', $_SESSION['user_id'], $db);
                                                if ($is_collected): ?>
                                                    <button class="btn-collected" style="background: linear-gradient(135deg, #10b981, #34d399); cursor: not-allowed; position: relative; z-index: 10; color: white; border: none; padding: 12px 24px; border-radius: 50px; font-weight: 600;" disabled>
                                                        <i class="fas fa-check"></i> Collected
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-collect-card" onclick="event.stopPropagation(); collectCard(<?php echo $order['id']; ?>, 'user_order', event);" style="cursor: pointer; position: relative; z-index: 10;">
                                                        <i class="fas fa-credit-card"></i> Collect Card
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mobile View -->
                                <div class="mobile-order-compact" id="mobileOrder<?php echo $order['id']; ?>">
                                    <div class="mobile-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="order-number-circle"><?php echo $order_number; ?></div>
                                        <div class="mobile-order-info">
                                            <div class="mobile-order-amount">Rs <?php echo number_format($order['total_amount']); ?></div>
                                            <div class="mobile-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?></div>
                                        </div>
                                        <div class="mobile-order-right-section">
                                            <div class="mobile-order-actions">
                                                <button class="order-view-icon-inline" onclick="event.stopPropagation(); viewOrderDetails(<?php echo $order['id']; ?>)" title="View Receipt">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="order-download-icon-inline" onclick="event.stopPropagation(); downloadAsPNG(<?php echo $order['id']; ?>)" title="Download Receipt">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </div>
                                            <div class="mobile-order-meta">
                                                <div class="mobile-status-badge-small delivered">
                                                    <i class="fas fa-circle"></i> Delivered
                                                </div>
                                                <div class="mobile-dropdown-arrow">
                                                    <i class="fas fa-chevron-down"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mobile-order-details">
                                        <div class="mobile-order-summary">
                                            <div class="mobile-summary-header">
                                                <div class="mobile-summary-title">Order Summary</div>
                                                <div class="mobile-summary-items-count"><?php echo count($items); ?> items</div>
                                            </div>
                                            <?php foreach ($items as $item): 
                                                $display_image = null;
                                                if (!empty($item['combination_image'])) {
                                                    $display_image = 'uploads/products/' . $item['combination_image'];
                                                } elseif (!empty($item['variant_image'])) {
                                                    $display_image = 'uploads/products/' . $item['variant_image'];
                                                } elseif (!empty($item['image_path'])) {
                                                    $display_image = 'uploads/products/' . $item['image_path'];
                                                } else {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="mobile-summary-product">
                                                <img src="<?php echo $display_image; ?>" 
                                                     alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                                     class="mobile-summary-img"
                                                     onerror="this.src='assets/images/placeholder.svg'; this.onerror=null;">
                                                <div class="mobile-summary-product-info">
                                                    <div class="mobile-summary-product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['combination_string'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Combination</span>
                                                        <?php echo htmlspecialchars(str_replace(' | ', ' | ', $item['combination_string'])); ?>
                                                    </div>
                                                    <?php elseif (!empty($item['variant_name'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="mobile-summary-qty">Qty: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="mobile-summary-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                            <div class="mobile-coupon-applied">
                                                <div class="coupon-check">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <span>Coupon applied! You saved Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                <span class="coupon-close" onclick="this.parentElement.style.display='none'">&times;</span>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="mobile-summary-totals">
                                                <div class="mobile-summary-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="mobile-summary-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="mobile-summary-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="mobile-summary-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <!-- Mobile Collect Card Button -->
                                            <div class="mobile-order-actions" style="margin-top: 20px; padding: 0 15px 15px;" onclick="event.stopPropagation();">
                                                <?php 
                                                $is_collected = isCardCollected($order['id'], 'user_order', $_SESSION['user_id'], $db);
                                                if ($is_collected): ?>
                                                    <button class="btn-collected-mobile" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #10b981, #34d399); cursor: not-allowed; position: relative; z-index: 10; color: white; border: none;" disabled>
                                                        <i class="fas fa-check"></i> Collected
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-collect-card-mobile" onclick="event.stopPropagation(); collectCard(<?php echo $order['id']; ?>, 'user_order', event);" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; position: relative; z-index: 10;">
                                                        <i class="fas fa-credit-card"></i> Collect Card
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php 
                                $order_number++;
                            endforeach; ?>
                        <?php else: ?>
                            <div class="no-orders-message">
                                No orders in this category.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Canceled Orders -->
                    <div class="mb-5">
                        <h4 class="order-section-title">Canceled Orders</h4>
                        <?php if (!empty($canceled_orders)): ?>
                            <button class="delete-canceled-btn" onclick="deleteCanceledOrders(event)">
                                <i class="fas fa-trash-alt"></i>
                                Delete All Canceled Orders
                            </button>
                            <?php 
                            $order_number = 1;
                            foreach ($canceled_orders as $order): 
                                // Get order items with images
                                try {
                                    $stmt = $db->prepare("
                                        SELECT oi.*, 
                                               p.name, 
                                               p.id as product_id,
                                               pi.image_path,
                                               pv.variant_name, 
                                               pv.variant_image,
                                               pvc.image_path as combination_image
                                        FROM order_items oi 
                                        JOIN products p ON oi.product_id = p.id 
                                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                                        LEFT JOIN product_variants pv ON oi.variant_id = pv.id
                                        LEFT JOIN product_variant_combinations pvc ON oi.variant_combination_id = pvc.id
                                        WHERE oi.order_id = ?
                                    ");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    $stmt = $db->prepare("SELECT oi.*, p.name, p.id as product_id, pi.image_path, NULL as variant_name, NULL as variant_image, NULL as combination_image FROM order_items oi JOIN products p ON oi.product_id = p.id LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1 WHERE oi.order_id = ?");
                                    $stmt->execute([$order['id']]);
                                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                }
                                ?>
                                <!-- Desktop View -->
                                <div class="desktop-order-card" id="desktopOrder<?php echo $order['id']; ?>">
                                    <div class="desktop-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="desktop-order-left">
                                            <div class="order-number-circle"><?php echo $order_number; ?></div>
                                            <div class="desktop-order-info">
                                                <div class="desktop-order-amount">Rs <?php echo number_format($order['total_amount']); ?></div>
                                                <div class="desktop-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?> • <?php echo count($items); ?> items</div>
                                            </div>
                                        </div>
                                        <div class="desktop-order-right">
                                            <div class="desktop-status-badge canceled">
                                                <i class="fas fa-circle"></i> Canceled
                                            </div>
                                            <div class="desktop-dropdown-arrow">
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="desktop-order-details">
                                        <div class="desktop-order-summary">
                                            <?php foreach ($items as $item): 
                                                $display_image = null;
                                                if (!empty($item['combination_image'])) {
                                                    $display_image = 'uploads/products/' . $item['combination_image'];
                                                } elseif (!empty($item['variant_image'])) {
                                                    $display_image = 'uploads/products/' . $item['variant_image'];
                                                } elseif (!empty($item['image_path'])) {
                                                    $display_image = 'uploads/products/' . $item['image_path'];
                                                } else {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="desktop-summary-item">
                                                <img src="<?php echo $display_image; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="desktop-item-img" onerror="this.src='assets/images/placeholder.svg';">
                                                <div class="desktop-item-details">
                                                    <div class="desktop-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['variant_name'])): ?>
                                                    <div class="desktop-item-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="desktop-item-qty">Quantity: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="desktop-item-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="desktop-order-totals">
                                                <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-coupon-info">
                                                    <i class="fas fa-tag"></i> Coupon Applied: <strong><?php echo htmlspecialchars($order['coupon_code']); ?></strong> - You saved Rs <?php echo number_format($order['discount_amount']); ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="desktop-total-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="desktop-total-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="desktop-total-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="desktop-total-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mobile View -->
                                <div class="mobile-order-compact" id="mobileOrder<?php echo $order['id']; ?>">
                                    <div class="mobile-order-header" onclick="toggleMobileOrder(<?php echo $order['id']; ?>)">
                                        <div class="order-number-circle"><?php echo $order_number; ?></div>
                                        <div class="mobile-order-info">
                                            <div class="mobile-order-amount">Rs <?php echo number_format($order['total_amount']); ?></div>
                                            <div class="mobile-order-date"><?php echo date('d M Y', strtotime($order['created_at'])); ?></div>
                                        </div>
                                        <div class="mobile-status-badge canceled">
                                            <i class="fas fa-circle"></i> Canceled
                                        </div>
                                        <div class="mobile-dropdown-arrow">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    <div class="mobile-order-details">
                                        <div class="mobile-order-summary">
                                            <div class="mobile-summary-header">
                                                <div class="mobile-summary-title">Order Summary</div>
                                                <div class="mobile-summary-items-count"><?php echo count($items); ?> items</div>
                                            </div>
                                            <?php foreach ($items as $item): 
                                                $display_image = null;
                                                if (!empty($item['combination_image'])) {
                                                    $display_image = 'uploads/products/' . $item['combination_image'];
                                                } elseif (!empty($item['variant_image'])) {
                                                    $display_image = 'uploads/products/' . $item['variant_image'];
                                                } elseif (!empty($item['image_path'])) {
                                                    $display_image = 'uploads/products/' . $item['image_path'];
                                                } else {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                                if (!file_exists($display_image)) {
                                                    $display_image = 'assets/images/placeholder.svg';
                                                }
                                            ?>
                                            <div class="mobile-summary-product">
                                                <img src="<?php echo $display_image; ?>" 
                                                     alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                                     class="mobile-summary-img"
                                                     onerror="this.src='assets/images/placeholder.svg'; this.onerror=null;">
                                                <div class="mobile-summary-product-info">
                                                    <div class="mobile-summary-product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['combination_string'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Combination</span>
                                                        <?php echo htmlspecialchars(str_replace(' | ', ' | ', $item['combination_string'])); ?>
                                                    </div>
                                                    <?php elseif (!empty($item['variant_name'])): ?>
                                                    <div class="mobile-summary-variant">
                                                        <span class="badge bg-primary">Variant</span> <?php echo htmlspecialchars($item['variant_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="mobile-summary-qty">Qty: <?php echo $item['quantity']; ?></div>
                                                </div>
                                                <div class="mobile-summary-price">Rs <?php echo number_format($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if (!empty($order['coupon_code']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                            <div class="mobile-coupon-applied">
                                                <div class="coupon-check">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <span>Coupon applied! You saved Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                <span class="coupon-close" onclick="this.parentElement.style.display='none'">&times;</span>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="mobile-summary-totals">
                                                <div class="mobile-summary-row">
                                                    <span>Subtotal:</span>
                                                    <span>Rs <?php echo number_format($order['subtotal'] ?? $order['total_amount']); ?></span>
                                                </div>
                                                <?php if (!empty($order['delivery_charges'])): ?>
                                                <div class="mobile-summary-row">
                                                    <span>Delivery Charges:</span>
                                                    <span>Rs <?php echo number_format($order['delivery_charges']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                                                <div class="mobile-summary-row" style="color: #059669;">
                                                    <span>Discount:</span>
                                                    <span>-Rs <?php echo number_format($order['discount_amount']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="mobile-summary-row total">
                                                    <span>Total Amount:</span>
                                                    <span>Rs <?php echo number_format($order['total_amount']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php 
                                $order_number++;
                            endforeach; ?>
                        <?php else: ?>
                            <div class="no-orders-message">
                                No orders in this category.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php elseif ($active_tab === 'partner' && $is_affiliate): ?>
                    <!-- Partner ID -->
                    <h4 class="text-primary-custom mb-4">My Partner ID</h4>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="bg-section p-4 rounded text-center">
                                <h2 class="text-primary-custom mb-3"><?php echo htmlspecialchars($affiliate['partner_id']); ?></h2>
                                <p class="text-muted mb-3">Your Unique Partner ID</p>
                                <button class="btn btn-outline-primary" onclick="copyToClipboard('<?php echo $affiliate['partner_id']; ?>')">
                                    <i class="fas fa-copy me-2"></i>Copy ID
                                </button>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="bg-section p-4 rounded">
                                <h6 class="text-primary-custom mb-3">Performance Stats</h6>
                                <p class="mb-2"><strong>Total Sales:</strong> <?php echo number_format($affiliate['total_sales']); ?></p>
                                <p class="mb-2"><strong>Total Revenue:</strong> <?php echo formatPrice($affiliate['total_revenue']); ?></p>
                                <p class="mb-0"><strong>Available Balance:</strong> <?php echo formatPrice($affiliate['balance']); ?></p>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($active_tab === 'rewards' && $is_affiliate): ?>
                    <!-- My Rewards -->
                    <h2 class="content-title">My Rewards</h2>
                    
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
                    $debug_info = [];
                    
                    foreach ($confirmed_orders as $order) {
                        $stmt = $db->prepare("
                            SELECT 
                                oi.id as item_id,
                                oi.product_id,
                                p.name as product_name,
                                oi.quantity,
                                oi.commission_amount,
                                p.commission_rate
                            FROM order_items oi
                            INNER JOIN products p ON oi.product_id = p.id
                            WHERE oi.order_id = ?
                            AND oi.commission_amount > 0
                        ");
                        $stmt->execute([$order['id']]);
                        $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $order_commission = 0;
                        foreach ($order_items as $item) {
                            // commission_amount already has quantity included from checkout.php
                            $order_commission += floatval($item['commission_amount']);
                            
                            $debug_info[] = "Order #{$order['order_number']} - {$item['product_name']} (x{$item['quantity']}) - Commission Rate: {$item['commission_rate']} - Stored Commission: Rs " . number_format($item['commission_amount'], 2);
                        }
                        
                        $available_balance += $order_commission;
                    }
                    
                    // Optional: Display debug info (remove in production)
                    // echo "<pre>"; print_r($debug_info); echo "</pre>";
                    
                    // Total earnings is same as available balance
                    $total_earnings = $available_balance;
                    ?>
                    
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <div class="bg-section p-4 rounded text-center">
                                <i class="fas fa-dollar-sign fa-3x text-success mb-3"></i>
                                <h4 class="text-success mb-2"><?php echo formatPrice($available_balance); ?></h4>
                                <p class="text-muted mb-0">Available Balance</p>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="bg-section p-4 rounded text-center">
                                <i class="fas fa-chart-line fa-3x text-primary mb-3"></i>
                                <h4 class="text-primary mb-2"><?php echo formatPrice($total_earnings); ?></h4>
                                <p class="text-muted mb-0">Total Earnings</p>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="bg-section p-4 rounded text-center">
                                <i class="fas fa-shopping-cart fa-3x text-info mb-3"></i>
                                <h4 class="text-info mb-2"><?php echo number_format($confirmed_orders_count); ?></h4>
                                <p class="text-muted mb-0">Confirmed Sales</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>How it works:</strong> Share your Partner ID with customers and earn commission on confirmed orders only (Confirmed, On The Way, Delivered)!
                    </div>
                <?php endif; ?>
                </div>
                
                <div id="tab-wishlist" class="tab-pane <?php echo $active_tab === 'wishlist' ? 'active' : ''; ?>">
                <?php if ($active_tab === 'wishlist' || true): ?>
                    <!-- Wishlist -->
                    <h2 class="content-title">My Wishlist</h2>
                    
                    <?php
                    $stmt = $db->prepare("
                        SELECT w.*, p.id as product_id, p.name as product_name, p.original_price, p.discounted_price, p.status, p.sales_count, p.stock_count,
                               pi.image_path, c.name as category_name
                        FROM wishlist w
                        JOIN products p ON w.product_id = p.id
                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                        LEFT JOIN categories c ON p.category_id = c.id
                        WHERE w.user_id = ?
                        ORDER BY w.created_at DESC
                    ");
                    $stmt->execute([$_SESSION['user_id']]);
                    $wishlist_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get cart product IDs for wishlist tab
                    $wishlist_cart_product_ids = [];
                    if (isset($_SESSION['user_id'])) {
                        $stmt = $db->prepare("SELECT DISTINCT product_id FROM cart WHERE user_id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                        $wishlist_cart_product_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                    ?>
                    
                    <?php if (!empty($wishlist_items)): ?>
                        <div class="row">
                            <?php foreach ($wishlist_items as $item): ?>
                                <div class="col-lg-4 col-md-6 col-6 mb-4">
                                    <div class="product-card-modern" onclick="window.location.href='product.php?id=<?php echo $item['product_id']; ?>'" style="cursor: pointer;">
                                        <div class="product-image">
                                            <img src="<?php echo $item['image_path'] ? PRODUCT_IMAGES_DIR . $item['image_path'] : 'assets/images/no-image.jpg'; ?>" 
                                                 alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                            
                                            <!-- Wishlist Heart Button -->
                                            <button class="favorite-btn" data-product-id="<?php echo $item['product_id']; ?>" onclick="event.stopPropagation(); toggleWishlist(<?php echo $item['product_id']; ?>); location.reload();" title="Remove from wishlist" style="background: #ef4444;">
                                                <i class="fas fa-heart" style="color: white;"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="product-info">
                                            <!-- Stock Status and Sold Stats inline -->
                                            <div class="product-meta-row">
                                                <span class="stock-text <?php echo $item['status'] === 'Out of Stock' ? 'out-of-stock' : ($item['status'] === 'Limited' ? 'limited' : 'in-stock'); ?>">
                                                    <?php echo $item['status'] === 'Out of Stock' ? 'Out of Stock' : ($item['status'] === 'Limited' ? 'Limited Stock' : 'In Stock'); ?>
                                                </span>
                                                <span class="sold-stat"><?php echo $item['sales_count'] ?? 0; ?> Sold</span>
                                            </div>
                                            
                                            <!-- Product Title -->
                                            <h6 class="product-title"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                            
                                            <!-- Price -->
                                            <div class="product-price">
                                                <span class="current-price">Rs.<?php echo number_format($item['discounted_price'] ?: $item['original_price'], 0); ?></span>
                                                <?php if ($item['discounted_price'] && $item['discounted_price'] < $item['original_price']): ?>
                                                    <span class="original-price">Rs.<?php echo number_format($item['original_price'], 0); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="product-actions">
                                                <?php if ($item['status'] === 'In Stock' || $item['status'] === 'Limited'): ?>
                                                    <?php 
                                                    $isInCart = in_array($item['product_id'], $wishlist_cart_product_ids);
                                                    $cartBtnClass = $isInCart ? 'btn btn-in-cart' : 'btn btn-cart';
                                                    $cartBtnText = $isInCart ? 'In Cart' : 'Cart';
                                                    ?>
                                                    <button class="<?php echo $cartBtnClass; ?>" onclick="event.stopPropagation(); <?php echo $isInCart ? 'window.location.href=\'cart.php\'' : 'addToCart(' . $item['product_id'] . ', null, 1, $(this))'; ?>">
                                                        <?php echo $cartBtnText; ?>
                                                    </button>
                                                    <a href="product.php?id=<?php echo $item['product_id']; ?>" class="btn btn-buy" onclick="event.stopPropagation();">
                                                        Buy
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-cart" disabled style="opacity: 0.5; cursor: not-allowed;">
                                                        Out of Stock
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="wishlist-empty">
                            <i class="fas fa-heart"></i>
                            <h5>Your wishlist is empty</h5>
                            <p>Save items you love for later!</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
                
                <div id="tab-favorites" class="tab-pane <?php echo $active_tab === 'favorites' ? 'active' : ''; ?>">
                <?php if ($active_tab === 'favorites' || true): ?>
                    <!-- Favorite Products -->
                    <h2 class="content-title">Favorite Products</h2>
                    
                    <?php
                    $stmt = $db->prepare("
                        SELECT f.*, p.id as product_id, p.name as product_name, p.original_price, p.discounted_price, p.status, p.sales_count, p.stock_count,
                               pi.image_path, c.name as category_name
                        FROM favorites f
                        JOIN products p ON f.product_id = p.id
                        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                        LEFT JOIN categories c ON p.category_id = c.id
                        WHERE f.user_id = ?
                        ORDER BY f.created_at DESC
                    ");
                    $stmt->execute([$_SESSION['user_id']]);
                    $favorite_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get cart product IDs for favorites tab
                    $favorites_cart_product_ids = [];
                    if (isset($_SESSION['user_id'])) {
                        $stmt = $db->prepare("SELECT DISTINCT product_id FROM cart WHERE user_id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                        $favorites_cart_product_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                    ?>
                    
                    <?php if (!empty($favorite_items)): ?>
                        <div class="row">
                            <?php foreach ($favorite_items as $item): ?>
                                <div class="col-lg-4 col-md-6 col-6 mb-4">
                                    <div class="product-card-modern" onclick="window.location.href='product.php?id=<?php echo $item['product_id']; ?>'" style="cursor: pointer;">
                                        <div class="product-image">
                                            <img src="<?php echo $item['image_path'] ? PRODUCT_IMAGES_DIR . $item['image_path'] : 'assets/images/no-image.jpg'; ?>" 
                                                 alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                            
                                            <!-- Favorite Star Button -->
                                            <button class="favorite-btn" data-product-id="<?php echo $item['product_id']; ?>" onclick="event.stopPropagation(); toggleFavorite(<?php echo $item['product_id']; ?>); location.reload();" title="Remove from favorites" style="background: #fbbf24;">
                                                <i class="fas fa-star" style="color: white;"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="product-info">
                                            <!-- Stock Status and Sold Stats inline -->
                                            <div class="product-meta-row">
                                                <span class="stock-text <?php echo $item['status'] === 'Out of Stock' ? 'out-of-stock' : ($item['status'] === 'Limited' ? 'limited' : 'in-stock'); ?>">
                                                    <?php echo $item['status'] === 'Out of Stock' ? 'Out of Stock' : ($item['status'] === 'Limited' ? 'Limited Stock' : 'In Stock'); ?>
                                                </span>
                                                <span class="sold-stat"><?php echo $item['sales_count'] ?? 0; ?> Sold</span>
                                            </div>
                                            
                                            <!-- Product Title -->
                                            <h6 class="product-title"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                            
                                            <!-- Price -->
                                            <div class="product-price">
                                                <span class="current-price">Rs.<?php echo number_format($item['discounted_price'] ?: $item['original_price'], 0); ?></span>
                                                <?php if ($item['discounted_price'] && $item['discounted_price'] < $item['original_price']): ?>>
                                                    <span class="original-price">Rs.<?php echo number_format($item['original_price'], 0); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="product-actions">
                                                <?php if ($item['status'] === 'In Stock' || $item['status'] === 'Limited'): ?>
                                                    <?php 
                                                    $isInCart = in_array($item['product_id'], $favorites_cart_product_ids);
                                                    $cartBtnClass = $isInCart ? 'btn btn-in-cart' : 'btn btn-cart';
                                                    $cartBtnText = $isInCart ? 'In Cart' : 'Cart';
                                                    ?>
                                                    <button class="<?php echo $cartBtnClass; ?>" onclick="event.stopPropagation(); <?php echo $isInCart ? 'window.location.href=\'cart.php\'' : 'addToCart(' . $item['product_id'] . ', null, 1, $(this))'; ?>">
                                                        <?php echo $cartBtnText; ?>
                                                    </button>
                                                    <a href="product.php?id=<?php echo $item['product_id']; ?>" class="btn btn-buy" onclick="event.stopPropagation();">
                                                        Buy
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-cart" disabled style="opacity: 0.5; cursor: not-allowed;">
                                                        Out of Stock
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="wishlist-empty">
                            <i class="fas fa-star"></i>
                            <h5>No favorite products yet</h5>
                            <p>Click the star icon on products to save them here!</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
                
                <div id="tab-request" class="tab-pane <?php echo $active_tab === 'request' ? 'active' : ''; ?>">
                <?php if ($active_tab === 'request' || true): ?>
                    <!-- Request Product -->
                    <h2 class="content-title" style="color: #1e3a8a; font-weight: 700;">Request a New Product</h2>
                    <p class="text-muted mb-4" style="font-size: 1rem;">Can't find what you're looking for? Let us know!</p>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label for="product_name" class="form-label" style="font-weight: 600; color: #1e293b; font-size: 0.95rem;">Product Name</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="product_name" 
                                   name="product_name" 
                                   placeholder="e.g., Men's Leather Wallet"
                                   style="padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.95rem;"
                                   required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="category" class="form-label" style="font-weight: 600; color: #1e293b; font-size: 0.95rem;">Category</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="category" 
                                   name="category" 
                                   placeholder="e.g., Accessories, Clothing"
                                   style="padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.95rem;"
                                   required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="product_image" class="form-label" style="font-weight: 600; color: #1e293b; font-size: 0.95rem;">Product Image</label>
                            <div class="upload-area" 
                                 id="uploadArea"
                                 style="border: 2px dashed #cbd5e1; 
                                        border-radius: 12px; 
                                        padding: 60px 20px; 
                                        text-align: center; 
                                        background: #f8fafc;
                                        cursor: pointer;
                                        transition: all 0.3s ease;">
                                <input type="file" 
                                       class="d-none" 
                                       id="product_image" 
                                       name="product_image" 
                                       accept=".png,.jpg,.jpeg,.gif"
                                       onchange="handleImageUpload(this)">
                                <div id="uploadPlaceholder">
                                    <div class="mb-3">
                                        <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: #1e3a8a;"></i>
                                    </div>
                                    <p class="mb-2" style="color: #64748b; font-weight: 500;">Click to browse or drag & drop</p>
                                    <p class="mb-0" style="color: #94a3b8; font-size: 0.875rem;">PNG, JPG, GIF up to 2MB</p>
                                </div>
                                <div id="imagePreviewContainer" style="display: none;">
                                    <img id="imagePreview" src="#" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">
                                    <p class="mt-2 mb-0" style="color: #64748b; font-size: 0.875rem;" id="fileName"></p>
                                    <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="removeImage()">
                                        <i class="fas fa-times me-1"></i>Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" 
                                name="request_product" 
                                class="btn" 
                                style="background: #1e3a8a; 
                                       color: white; 
                                       padding: 12px 40px; 
                                       border-radius: 50px; 
                                       border: none; 
                                       font-weight: 600;
                                       font-size: 1rem;
                                       transition: all 0.3s ease;"
                                onmouseover="this.style.background='#1e40af'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 15px rgba(30, 58, 138, 0.3)'"
                                onmouseout="this.style.background='#1e3a8a'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                            <i class="fas fa-paper-plane me-2"></i>Submit Request
                        </button>
                    </form>
                    
                    <style>
                        .upload-area:hover {
                            border-color: #1e3a8a;
                            background: #eff6ff;
                        }
                        
                        #product_name:focus,
                        #category:focus {
                            outline: none;
                            border-color: #1e3a8a;
                            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
                        }
                    </style>
                    
                    <script>
                        // Upload area click handler
                        document.getElementById('uploadArea').addEventListener('click', function(e) {
                            if (e.target.id !== 'product_image' && !e.target.closest('button')) {
                                document.getElementById('product_image').click();
                            }
                        });
                        
                        // Drag and drop handlers
                        const uploadArea = document.getElementById('uploadArea');
                        
                        uploadArea.addEventListener('dragover', function(e) {
                            e.preventDefault();
                            this.style.borderColor = '#1e3a8a';
                            this.style.background = '#eff6ff';
                        });
                        
                        uploadArea.addEventListener('dragleave', function(e) {
                            e.preventDefault();
                            this.style.borderColor = '#cbd5e1';
                            this.style.background = '#f8fafc';
                        });
                        
                        uploadArea.addEventListener('drop', function(e) {
                            e.preventDefault();
                            this.style.borderColor = '#cbd5e1';
                            this.style.background = '#f8fafc';
                            
                            const files = e.dataTransfer.files;
                            if (files.length > 0) {
                                document.getElementById('product_image').files = files;
                                handleImageUpload(document.getElementById('product_image'));
                            }
                        });
                        
                        function handleImageUpload(input) {
                            if (input.files && input.files[0]) {
                                const file = input.files[0];
                                const reader = new FileReader();
                                
                                reader.onload = function(e) {
                                    document.getElementById('imagePreview').src = e.target.result;
                                    document.getElementById('fileName').textContent = file.name;
                                    document.getElementById('uploadPlaceholder').style.display = 'none';
                                    document.getElementById('imagePreviewContainer').style.display = 'block';
                                };
                                
                                reader.readAsDataURL(file);
                            }
                        }
                        
                        function removeImage() {
                            document.getElementById('product_image').value = '';
                            document.getElementById('imagePreview').src = '#';
                            document.getElementById('uploadPlaceholder').style.display = 'block';
                            document.getElementById('imagePreviewContainer').style.display = 'none';
                        }
                    </script>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Tab panes - instant switching */
.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
// Instant tab switching without page reload
document.addEventListener('DOMContentLoaded', function() {
    const tabLinks = document.querySelectorAll('.tab-link');
    
    tabLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Stop event from bubbling to jQuery
            
            const tabName = this.getAttribute('data-tab');
            
            // Update active state on nav items
            document.querySelectorAll('.sidebar-nav-item').forEach(item => {
                item.classList.remove('active');
            });
            this.classList.add('active');
            
            // Switch tab content
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            const targetPane = document.getElementById('tab-' + tabName);
            if (targetPane) {
                targetPane.classList.add('active');
            }
            
            // Close mobile menu if open
            const sidebarNav = document.getElementById('sidebarNav');
            const menuToggle = document.getElementById('mobileMenuToggle');
            if (sidebarNav && menuToggle && window.innerWidth < 992) {
                sidebarNav.classList.remove('open');
                menuToggle.classList.remove('open');
                
                // On mobile, scroll to put the section at top of screen
                setTimeout(() => {
                    if (targetPane) {
                        // Get the first heading in the tab pane
                        const heading = targetPane.querySelector('h2, h4, .content-title');
                        const elementToScroll = heading || targetPane;
                        
                        // Calculate position to put element at top of viewport
                        const elementRect = elementToScroll.getBoundingClientRect();
                        const absoluteElementTop = elementRect.top + window.pageYOffset;
                        const headerOffset = 20; // Small offset from very top
                        
                        window.scrollTo({
                            top: absoluteElementTop - headerOffset,
                            behavior: 'smooth'
                        });
                    }
                }, 200);
            } else {
                // On desktop, no scrolling needed
            }
            
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        });
    });
});
</script>

<!-- Custom Logout Confirmation Modal -->
<div class="custom-modal-overlay" id="logoutModal">
    <div class="custom-modal">
        <div class="modal-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        <h3 class="modal-title">Confirm Logout</h3>
        <p class="modal-message">Are you sure you want to logout?</p>
        <div class="modal-buttons">
            <button class="modal-btn modal-btn-cancel" onclick="hideLogoutModal()">Cancel</button>
            <button class="modal-btn modal-btn-confirm" onclick="logout()">Yes, Logout</button>
        </div>
    </div>
</div>

<script>
// Mobile Menu Toggle - Define early to avoid hoisting issues
let menuWasClicked = false;

function toggleMobileMenu() {
    const sidebarNav = document.getElementById('sidebarNav');
    const menuToggle = document.getElementById('mobileMenuToggle');
    
    if (!sidebarNav || !menuToggle) return;
    
    sidebarNav.classList.toggle('open');
    menuToggle.classList.toggle('open');
    
    // Mark that user has interacted with the menu
    if (!sidebarNav.classList.contains('open')) {
        menuWasClicked = true;
    }
}

// Clean phone input handler - NEW IMPLEMENTATION
document.addEventListener('DOMContentLoaded', function() {
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

function showLogoutModal() {
    document.getElementById('logoutModal').classList.add('show');
}

function hideLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
}

function logout() {
    window.location.href = 'logout.php';
}

// Close modal when clicking outside
document.getElementById('logoutModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideLogoutModal();
    }
});

function toggleEditMode() {
    const viewMode = document.getElementById('view-mode');
    const editMode = document.getElementById('edit-mode');
    
    if (viewMode.style.display === 'none') {
        viewMode.style.display = 'block';
        editMode.style.display = 'none';
    } else {
        viewMode.style.display = 'none';
        editMode.style.display = 'block';
    }
}

async function confirmLogout() {
    if (await showConfirm('Are you sure you want to logout?', 'Logout Confirmation', {confirmText: 'Yes, Logout', cancelText: 'Cancel', type: 'warning'})) {
        window.location.href = 'logout.php';
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Show styled success message instead of alert
        const successDiv = document.createElement('div');
        successDiv.className = 'alert alert-success';
        successDiv.innerHTML = '<i class="fas fa-check-circle"></i><span>Partner ID copied to clipboard!</span>';
        successDiv.style.position = 'fixed';
        successDiv.style.top = '20px';
        successDiv.style.right = '20px';
        successDiv.style.zIndex = '10000';
        successDiv.style.minWidth = '300px';
        document.body.appendChild(successDiv);
        
        setTimeout(function() {
            successDiv.remove();
        }, 3000);
    });
}

function cancelOrderCustom(orderId, evt) {
    console.log('cancelOrderCustom called with orderId:', orderId);
    
    // Prevent any event propagation
    if (evt) {
        evt.stopPropagation();
        evt.preventDefault();
    }
    
    // Create beautiful animated confirmation modal
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'custom-modal-overlay';
    console.log('Modal created');
    modalOverlay.innerHTML = `
        <div class="custom-modal-container" onclick="event.stopPropagation()">
            <div class="custom-modal-content">
                <div class="custom-modal-icon cancel-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 class="custom-modal-title">Cancel Order?</h3>
                <p class="custom-modal-message">Are you sure you want to cancel this order? This action cannot be undone.</p>
                <div class="custom-modal-buttons">
                    <button class="custom-modal-btn btn-secondary" onclick="event.stopPropagation(); this.closest('.custom-modal-overlay').remove();">
                        <i class="fas fa-times"></i> No, Keep It
                    </button>
                    <button class="custom-modal-btn btn-danger" onclick="event.stopPropagation(); confirmCancelOrder(${orderId});">
                        <i class="fas fa-check"></i> Yes, Cancel
                    </button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modalOverlay);
    
    // Trigger animation after a brief delay to ensure DOM is ready
    setTimeout(() => {
        modalOverlay.classList.add('show');
    }, 50);
    
    // Close on overlay click (but not on modal content)
    modalOverlay.addEventListener('click', function(e) {
        if (e.target === modalOverlay) {
            modalOverlay.classList.remove('show');
            setTimeout(() => modalOverlay.remove(), 300);
        }
    });
}

// Actually cancel the order via AJAX
function confirmCancelOrder(orderId) {
    // Close the modal
    const modal = document.querySelector('.custom-modal-overlay');
    if (modal) {
        modal.classList.remove('show');
        setTimeout(() => modal.remove(), 300);
    }
    
    // Show loading notification
    showUserNotification('Canceling order...', 'info', false);
    
    // Send POST request to cancel order
    fetch('ajax/cancel_order.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'order_id=' + orderId
    })
    .then(response => response.json())
    .then(data => {
        // Remove loading notification
        const loadingToast = document.querySelector('.download-toast.toast-info');
        if (loadingToast) {
            loadingToast.classList.remove('show');
            setTimeout(() => loadingToast.remove(), 300);
        }
        
        if (data.success) {
            showUserNotification('Order canceled successfully!', 'success');
            // Reload page after short delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showUserNotification(data.message || 'Failed to cancel order', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showUserNotification('An error occurred. Please try again.', 'error');
    });
}

function deleteCanceledOrders(evt) {
    // Prevent any event propagation
    if (evt) {
        evt.stopPropagation();
        evt.preventDefault();
    }
    
    // Create beautiful animated confirmation modal
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'custom-modal-overlay';
    modalOverlay.innerHTML = `
        <div class="custom-modal-container" onclick="event.stopPropagation()">
            <div class="custom-modal-content">
                <div class="custom-modal-icon delete-icon">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <h3 class="custom-modal-title">Delete All Canceled Orders?</h3>
                <p class="custom-modal-message">This will permanently delete all your canceled orders. This action cannot be undone!</p>
                <div class="custom-modal-buttons">
                    <button class="custom-modal-btn btn-secondary" onclick="event.stopPropagation(); this.closest('.custom-modal-overlay').remove();">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button class="custom-modal-btn btn-danger" onclick="event.stopPropagation(); window.location.href='ajax/delete_canceled_orders.php';">
                        <i class="fas fa-trash"></i> Yes, Delete All
                    </button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modalOverlay);
    
    // Trigger animation after a brief delay to ensure DOM is ready
    setTimeout(() => {
        modalOverlay.classList.add('show');
    }, 50);
    
    // Close on overlay click (but not on modal content)
    modalOverlay.addEventListener('click', function(e) {
        if (e.target === modalOverlay) {
            modalOverlay.classList.remove('show');
            setTimeout(() => modalOverlay.remove(), 300);
        }
    });
}

function toggleFavorite(productId) {
    if (typeof $ === 'undefined') {
        console.error('jQuery is not loaded');
        showAlert('An error occurred. Please refresh the page.', 'error');
        return;
    }

    $.ajax({
        url: 'ajax/toggle_favorite.php',
        method: 'POST',
        data: { product_id: productId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Show success message
                const successDiv = document.createElement('div');
                successDiv.className = 'alert alert-success';
                successDiv.innerHTML = '<i class="fas fa-check-circle"></i><span>' + response.message + '</span>';
                successDiv.style.position = 'fixed';
                successDiv.style.top = '20px';
                successDiv.style.right = '20px';
                successDiv.style.zIndex = '10000';
                successDiv.style.minWidth = '300px';
                document.body.appendChild(successDiv);
                
                setTimeout(function() {
                    successDiv.remove();
                    // Reload if on favorites tab
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('tab') === 'favorites') {
                        location.reload();
                    }
                }, 2000);
            } else {
                showAlert(response.message || 'An error occurred', 'error');
            }
        },
        error: function() {
            showAlert('Error processing request', 'error');
        }
    });
}

// View order details - Opens professional receipt in same window
function viewOrderDetails(orderId) {
    // Navigate to the receipt page in the same window
    window.location.href = 'order-details.php?id=' + orderId;
}


// Toast notification helper
function showUserNotification(message, type = 'info', autoHide = true) {
    const toast = document.createElement('div');
    toast.className = `download-toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('show');
    }, 100);
    
    if (autoHide) {
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    return toast; // Return toast element for manual control
}

// Close order details modal
function closeOrderModal() {
    if (typeof $ === 'undefined' || typeof jQuery === 'undefined') {
        return;
    }
    $('#orderDetailsModal').fadeOut(300);
}

// Close modal when clicking outside (only if jQuery is loaded)
if (typeof $ !== 'undefined' && typeof jQuery !== 'undefined') {
    $(document).on('click', '#orderDetailsModal', function(e) {
        if (e.target === this) {
            closeOrderModal();
        }
    });
}

// Order Toggle Functionality (works for both desktop and mobile)
function toggleMobileOrder(orderId) {
    const mobileCard = document.getElementById('mobileOrder' + orderId);
    const desktopCard = document.getElementById('desktopOrder' + orderId);
    
    if (mobileCard) {
        mobileCard.classList.toggle('expanded');
    }
    if (desktopCard) {
        desktopCard.classList.toggle('expanded');
    }
}

// Download Receipt - Instantly downloads without opening window
function downloadAsPNG(orderId) {
    // Show loading notification (don't auto-hide)
    const loadingToast = showUserNotification('Preparing download...', 'info', false);
    
    // Create hidden iframe for silent download
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;';
    iframe.src = 'order-details.php?id=' + orderId + '&autodownload=1';
    document.body.appendChild(iframe);
    
    // Update notification after delay (increased time for html2canvas to load)
    setTimeout(() => {
        // Hide loading toast
        loadingToast.classList.remove('show');
        setTimeout(() => loadingToast.remove(), 300);
        
        // Show success notification
        showUserNotification('Receipt downloaded successfully!', 'success');
        
        // Remove iframe after download completes
        setTimeout(() => iframe.remove(), 5000);
    }, 4000);
}

// Initialize mobile menu
document.addEventListener('DOMContentLoaded', function() {
    const sidebarNav = document.getElementById('sidebarNav');
    const menuToggle = document.getElementById('mobileMenuToggle');
    
    if (!sidebarNav || !menuToggle) return;
    
    function checkAndOpenMenu() {
        if (window.innerWidth < 992) {
            // Always open menu by default on page load/refresh
            sidebarNav.classList.add('open');
            menuToggle.classList.add('open');
        }
    }
    
    // Open immediately
    checkAndOpenMenu();
    
    // Also check after a small delay to ensure styles are loaded
    setTimeout(checkAndOpenMenu, 50);
    
    // Close menu when any nav item is clicked (except logout which is a modal)
    const navItems = document.querySelectorAll('.sidebar-nav-item:not(.logout-btn)');
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            if (window.innerWidth < 992) {
                // Close menu after a brief delay to show the click animation
                setTimeout(() => {
                    sidebarNav.classList.remove('open');
                    menuToggle.classList.remove('open');
                    menuWasClicked = true;
                }, 200);
            }
        });
    });
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebarNav = document.getElementById('sidebarNav');
    const menuToggle = document.getElementById('mobileMenuToggle');
    
    if (window.innerWidth >= 992) {
        // Desktop view - remove mobile classes and styles
        sidebarNav.classList.remove('open');
        menuToggle.classList.remove('open');
        sidebarNav.style.maxHeight = '';
    } else if (!menuWasClicked) {
        // Mobile view and user hasn't interacted yet - keep open
        sidebarNav.classList.add('open');
        menuToggle.classList.add('open');
    }
});
</script>

<!-- Order Details Modal -->
<div id="orderDetailsModal" class="order-modal-overlay" style="display: none;">
    <div class="order-modal-container">
        <button class="order-modal-close" onclick="closeOrderModal()">
            <i class="fas fa-times"></i>
        </button>
        <div id="orderDetailsModalContent">
            <!-- Content will be loaded here via AJAX -->
        </div>
    </div>
</div>

<style>
/* Beautiful Download Toast Notification */
.download-toast {
    position: fixed;
    top: 24px;
    right: 24px;
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    padding: 18px 28px;
    border-radius: 16px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15), 
                0 0 0 1px rgba(255, 255, 255, 0.8);
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 1rem;
    font-weight: 600;
    z-index: 10001;
    opacity: 0;
    transform: translateX(400px) scale(0.9);
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    backdrop-filter: blur(10px);
    min-width: 280px;
}

.download-toast.show {
    opacity: 1;
    transform: translateX(0) scale(1);
}

.download-toast i {
    font-size: 1.5rem;
    flex-shrink: 0;
    animation: iconBounce 0.6s ease-out;
}

.toast-success {
    border-left: 5px solid #10b981;
    background: linear-gradient(145deg, #ecfdf5 0%, #d1fae5 100%);
    color: #047857;
}

.toast-success i {
    color: #10b981;
    filter: drop-shadow(0 2px 4px rgba(16, 185, 129, 0.3));
}

.toast-error {
    border-left: 5px solid #ef4444;
    background: linear-gradient(145deg, #fef2f2 0%, #fee2e2 100%);
    color: #dc2626;
}

.toast-error i {
    color: #ef4444;
    filter: drop-shadow(0 2px 4px rgba(239, 68, 68, 0.3));
}

.toast-info {
    border-left: 5px solid #3b82f6;
    background: linear-gradient(145deg, #eff6ff 0%, #dbeafe 100%);
    color: #1e40af;
}

.toast-info i {
    color: #3b82f6;
    animation: spin 1s linear infinite;
    filter: drop-shadow(0 2px 4px rgba(59, 130, 246, 0.3));
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@keyframes iconBounce {
    0%, 100% { transform: scale(1); }
    25% { transform: scale(1.2); }
    50% { transform: scale(0.9); }
    75% { transform: scale(1.1); }
}

/* Order Modal Styles */
.order-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    overflow-y: auto;
}

.order-modal-container {
    position: relative;
    background: white;
    border-radius: 20px;
    max-width: 1200px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.order-modal-close {
    position: sticky;
    top: 15px;
    right: 15px;
    float: right;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
    transition: all 0.3s ease;
}

.order-modal-close:hover {
    transform: rotate(90deg);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.6);
}

@media (max-width: 768px) {
    .order-modal-container {
        margin: 10px;
        max-height: 95vh;
        border-radius: 15px;
    }
    
    .order-modal-close {
        width: 35px;
        height: 35px;
        font-size: 1rem;
    }
}
</style>

<!-- Card Collection System Styles -->
<link rel="stylesheet" href="assets/css/card-collection.css">

<!-- Card Collection System JavaScript -->
<script src="assets/js/card-collection.js"></script>

<?php require_once 'includes/footer.php'; ?>
