<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

// Check admin authentication
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    header('Location: login.php');
    exit;
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Create tables if they don't exist - FORCE CREATE
try {
    // Create course_modules table
    $db->exec("CREATE TABLE IF NOT EXISTS `course_modules` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `module_key` varchar(50) NOT NULL,
        `module_name` varchar(255) NOT NULL,
        `color` varchar(50) DEFAULT '#3b82f6',
        `gradient` varchar(255) DEFAULT 'linear-gradient(135deg, #3b82f6, #2563eb)',
        `icon` varchar(50) DEFAULT 'fa-graduation-cap',
        `sort_order` int(11) DEFAULT 0,
        `status` enum('active','inactive') DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `module_key` (`module_key`),
        KEY `sort_order` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create course_topics table
    $db->exec("CREATE TABLE IF NOT EXISTS `course_topics` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `module_id` int(11) NOT NULL,
        `topic_number` int(11) NOT NULL,
        `title` varchar(255) NOT NULL,
        `video_en` varchar(255) DEFAULT NULL,
        `video_ur` varchar(255) DEFAULT NULL,
        `duration` int(11) DEFAULT 0,
        `type` enum('video','quiz','assessment') DEFAULT 'video',
        `sort_order` int(11) DEFAULT 0,
        `status` enum('active','inactive') DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `module_id` (`module_id`),
        KEY `topic_number` (`topic_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Check if modules already exist
    $count = $db->query("SELECT COUNT(*) FROM course_modules")->fetchColumn();
    
    // Insert default modules if table is empty
    if ($count == 0) {
        $db->exec("INSERT INTO `course_modules` (`module_key`, `module_name`, `color`, `gradient`, `icon`, `sort_order`) VALUES
            ('module01', 'Introduction to FR Collections', '#f59e0b', 'linear-gradient(135deg, #f59e0b, #d97706)', 'fa-graduation-cap', 1),
            ('module02', 'Basic SMM Course', '#3b82f6', 'linear-gradient(135deg, #3b82f6, #2563eb)', 'fa-thumbs-up', 2),
            ('module03', 'Advanced Digital Marketing Course', '#10b981', 'linear-gradient(135deg, #10b981, #059669)', 'fa-chart-line', 3)");
    }
} catch (PDOException $e) {
    $error = "Failed to create tables: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'add_module':
                    $stmt = $db->prepare("INSERT INTO course_modules (module_key, module_name, color, gradient, icon, sort_order) 
                                          VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['module_key'],
                        $_POST['module_name'],
                        $_POST['color'],
                        $_POST['gradient'],
                        $_POST['icon'],
                        $_POST['sort_order']
                    ]);
                    $success = "Module added successfully!";
                    break;
                    
                case 'edit_module':
                    $stmt = $db->prepare("UPDATE course_modules SET module_name = ?, color = ?, gradient = ?, icon = ?, sort_order = ?, status = ? 
                                          WHERE id = ?");
                    $stmt->execute([
                        $_POST['module_name'],
                        $_POST['color'],
                        $_POST['gradient'],
                        $_POST['icon'],
                        $_POST['sort_order'],
                        $_POST['status'],
                        $_POST['module_id']
                    ]);
                    $success = "Module updated successfully!";
                    break;
                    
                case 'delete_module':
                    $stmt = $db->prepare("DELETE FROM course_modules WHERE id = ?");
                    $stmt->execute([$_POST['module_id']]);
                    $success = "Module deleted successfully!";
                    break;
                    
                case 'add_topic':
                    // Check for duplicates first
                    $checkStmt = $db->prepare("SELECT COUNT(*) FROM course_topics 
                                               WHERE module_id = ? AND topic_number = ?");
                    $checkStmt->execute([$_POST['module_id'], $_POST['topic_number']]);
                    $exists = $checkStmt->fetchColumn();
                    
                    if ($exists > 0) {
                        $error = "A topic with this number already exists in this module!";
                    } else {
                        $stmt = $db->prepare("INSERT INTO course_topics (module_id, topic_number, title, video_en, video_ur, duration, type, sort_order) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $_POST['module_id'],
                            $_POST['topic_number'],
                            $_POST['title'],
                            $_POST['video_en'],
                            $_POST['video_ur'],
                            $_POST['duration'],
                            $_POST['type'],
                            $_POST['sort_order']
                        ]);
                        $success = "Topic added successfully!";
                    }
                    break;
                    
                case 'edit_topic':
                    $stmt = $db->prepare("UPDATE course_topics SET module_id = ?, topic_number = ?, title = ?, video_en = ?, video_ur = ?, 
                                          duration = ?, type = ?, sort_order = ?, status = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['module_id'],
                        $_POST['topic_number'],
                        $_POST['title'],
                        $_POST['video_en'],
                        $_POST['video_ur'],
                        $_POST['duration'],
                        $_POST['type'],
                        $_POST['sort_order'],
                        $_POST['status'],
                        $_POST['topic_id']
                    ]);
                    $success = "Topic updated successfully!";
                    break;
                    
                case 'delete_topic':
                    $stmt = $db->prepare("DELETE FROM course_topics WHERE id = ?");
                    $stmt->execute([$_POST['topic_id']]);
                    $success = "Topic deleted successfully!";
                    break;
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage() . ". Please make sure tables are created.";
        }
    }
}

// Fetch all modules
$modules = [];
try {
    $modules = $db->query("SELECT * FROM course_modules ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Fetch all topics with module info
$topics = [];
try {
    $topics = $db->query("SELECT ct.*, cm.module_name, cm.module_key 
                           FROM course_topics ct 
                           JOIN course_modules cm ON ct.module_id = cm.id 
                           ORDER BY cm.sort_order, ct.sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tables might not exist yet
}

$page_title = "Learning Zone";
require_once 'includes/header.php';
?>

<style>
.learning-zone-container {
    padding: 20px;
}

/* ========================================
   MOBILE RESPONSIVE - SHINY GLOSSY DESIGN
   ======================================== */
@media (max-width: 768px) {
    .learning-zone-container {
        padding: 12px;
    }
    
    .section-card {
        padding: 16px !important;
        margin-bottom: 16px !important;
    }
    
    .section-title {
        font-size: 1.1rem !important;
        flex-wrap: wrap;
        gap: 8px !important;
    }
    
    /* Hide desktop tables on mobile */
    .data-table {
        display: none !important;
    }
    
    /* Mobile card layout */
    .mobile-cards-container {
        display: block !important;
    }
    
    .mobile-data-card {
        background: white;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 12px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        border-left: 4px solid var(--card-color, #3b82f6);
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .mobile-data-card:active {
        transform: scale(0.98);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
    }
    
    /* Glossy overlay */
    .mobile-data-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 40%;
        background: linear-gradient(
            to bottom,
            rgba(255, 255, 255, 0.4) 0%,
            rgba(255, 255, 255, 0) 100%
        );
        pointer-events: none;
    }
    
    .mobile-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
    }
    
    .mobile-card-title {
        font-weight: 700;
        font-size: 1rem;
        color: #1e293b;
        margin-bottom: 4px;
    }
    
    .mobile-card-subtitle {
        font-size: 0.8rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .mobile-card-actions {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    
    /* Shiny gradient action icons */
    .mobile-action-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 0.9rem;
        text-decoration: none;
        position: relative;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        border: none;
    }
    
    /* Edit icon - Blue gradient */
    .mobile-action-icon.edit {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    }
    
    /* Delete icon - Red gradient */
    .mobile-action-icon.delete {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    }
    
    /* Glossy overlay on icons */
    .mobile-action-icon::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 50%;
        background: linear-gradient(
            to bottom,
            rgba(255, 255, 255, 0.3) 0%,
            rgba(255, 255, 255, 0) 100%
        );
        border-radius: 10px 10px 0 0;
        pointer-events: none;
    }
    
    /* Shine animation on icons */
    .mobile-action-icon::after {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: linear-gradient(
            45deg,
            transparent 30%,
            rgba(255, 255, 255, 0.4) 50%,
            transparent 70%
        );
        transform: rotate(45deg);
        animation: mobileIconShine 2.5s infinite;
        pointer-events: none;
    }
    
    @keyframes mobileIconShine {
        0% {
            transform: translateX(-100%) translateY(-100%) rotate(45deg);
        }
        100% {
            transform: translateX(100%) translateY(100%) rotate(45deg);
        }
    }
    
    .mobile-action-icon:active {
        transform: scale(0.9);
    }
    
    .mobile-action-icon i {
        position: relative;
        z-index: 1;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
    }
    
    /* Compact Add Button */
    .add-btn {
        padding: 8px 16px !important;
        font-size: 0.85rem !important;
        border-radius: 10px !important;
        position: relative !important;
        overflow: hidden !important;
    }
    
    /* Glossy effect on add button */
    .add-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 50%;
        background: linear-gradient(
            to bottom,
            rgba(255, 255, 255, 0.3) 0%,
            rgba(255, 255, 255, 0) 100%
        );
        border-radius: 10px 10px 0 0;
        pointer-events: none;
    }
    
    /* Shine animation on add button */
    .add-btn::after {
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
        transform: rotate(45deg);
        animation: buttonShine 3s infinite;
        pointer-events: none;
    }
    
    @keyframes buttonShine {
        0% {
            transform: translateX(-100%) translateY(-100%) rotate(45deg);
        }
        100% {
            transform: translateX(100%) translateY(100%) rotate(45deg);
        }
    }
    
    .add-btn i,
    .add-btn span {
        position: relative;
        z-index: 1;
    }
    
    .mobile-card-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .badge-active {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
    }
    
    .badge-inactive {
        background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
        color: white;
    }
}

.section-card {
    background: white;
    border-radius: 16px;
    padding: 35px;
    margin-bottom: 30px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.section-card:hover {
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
}

/* Glossy overlay on section cards */
.section-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 30%;
    background: linear-gradient(
        to bottom,
        rgba(255, 255, 255, 0.5) 0%,
        rgba(255, 255, 255, 0) 100%
    );
    pointer-events: none;
    z-index: 0;
}

.section-card > * {
    position: relative;
    z-index: 1;
}

.section-title {
    font-size: 1.6rem;
    font-weight: 700;
    margin-bottom: 25px;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 12px;
}

.add-btn {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 12px;
    cursor: pointer;
    font-weight: 700;
    font-size: 1.05rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
}

/* Glossy overlay on add button */
.add-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(
        to bottom,
        rgba(255, 255, 255, 0.3) 0%,
        rgba(255, 255, 255, 0) 100%
    );
    border-radius: 12px 12px 0 0;
    pointer-events: none;
}

/* Shine animation on add button */
.add-btn::after {
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
    transform: rotate(45deg);
    animation: addBtnShine 3s infinite;
    pointer-events: none;
}

@keyframes addBtnShine {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

.add-btn > * {
    position: relative;
    z-index: 1;
}

.add-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
}

.add-btn:active {
    transform: translateY(-1px);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.data-table th {
    background: #f1f5f9;
    padding: 12px;
    text-align: left;
    font-weight: 700;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
}

.data-table tr:hover {
    background: #f8fafc;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-active {
    background: #d1fae5;
    color: #065f46;
}

.status-inactive {
    background: #fee2e2;
    color: #991b1b;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    margin-right: 5px;
    transition: all 0.3s ease;
}

.edit-btn {
    background: #3b82f6;
    color: white;
}

.delete-btn {
    background: #ef4444;
    color: white;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
}

.modal-content {
    background: white;
    margin: 50px auto;
    padding: 30px;
    border-radius: 15px;
    max-width: 600px;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.close {
    font-size: 2rem;
    cursor: pointer;
    color: #64748b;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #475569;
}

.form-control {
    width: 100%;
    padding: 10px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
}

.submit-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 700;
    font-size: 1rem;
    width: 100%;
    transition: all 0.3s ease;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.3);
}

.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 600;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
}

.color-preview {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    display: inline-block;
    vertical-align: middle;
}

/* Beautiful Custom Confirmation Dialog */
.custom-dialog-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    z-index: 10000;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.custom-dialog-overlay.active {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.custom-dialog-box {
    background: white;
    border-radius: 24px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 480px;
    width: 100%;
    overflow: hidden;
    animation: slideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(50px) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.dialog-header {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    padding: 30px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.dialog-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(to bottom, rgba(255, 255, 255, 0.2), transparent);
}

.dialog-icon {
    width: 80px;
    height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    animation: pulse 2s infinite;
    position: relative;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.dialog-icon i {
    font-size: 2.5rem;
    color: white;
}

.dialog-title {
    color: white;
    font-size: 1.75rem;
    font-weight: 800;
    margin: 0;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    position: relative;
}

.dialog-body {
    padding: 30px;
    text-align: center;
}

.dialog-message {
    color: #475569;
    font-size: 1.1rem;
    line-height: 1.6;
    margin: 0;
}

.dialog-actions {
    display: flex;
    gap: 12px;
    padding: 0 30px 30px;
}

.dialog-btn {
    flex: 1;
    padding: 16px 24px;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.dialog-btn::before {
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

.dialog-btn:active::before {
    width: 300px;
    height: 300px;
}

.dialog-btn-cancel {
    background: #f1f5f9;
    color: #64748b;
}

.dialog-btn-cancel:hover {
    background: #e2e8f0;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.dialog-btn-confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}

.dialog-btn-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
}

.dialog-btn-confirm::after {
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
    transform: rotate(45deg);
    animation: shine 3s infinite;
}

@keyframes shine {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .custom-dialog-box {
        max-width: 95%;
        border-radius: 20px;
    }
    
    .dialog-header {
        padding: 25px 20px;
    }
    
    .dialog-icon {
        width: 70px;
        height: 70px;
    }
    
    .dialog-icon i {
        font-size: 2rem;
    }
    
    .dialog-title {
        font-size: 1.5rem;
    }
    
    .dialog-body {
        padding: 25px 20px;
    }
    
    .dialog-message {
        font-size: 1rem;
    }
    
    .dialog-actions {
        flex-direction: column-reverse;
        padding: 0 20px 25px;
    }
    
    .dialog-btn {
        padding: 14px 20px;
    }
}
</style>

<!-- Custom Confirmation Dialog -->
<div id="customDialogOverlay" class="custom-dialog-overlay">
    <div class="custom-dialog-box">
        <div class="dialog-header">
            <div class="dialog-icon">
                <i class="fas fa-trash-alt"></i>
            </div>
            <h2 class="dialog-title" id="dialogTitle">Delete Item</h2>
        </div>
        <div class="dialog-body">
            <p class="dialog-message" id="dialogMessage">Are you sure you want to delete this item?</p>
        </div>
        <div class="dialog-actions">
            <button class="dialog-btn dialog-btn-cancel" onclick="closeCustomDialog()">
                <span>Cancel</span>
            </button>
            <button class="dialog-btn dialog-btn-confirm" id="dialogConfirmBtn">
                <span>Yes, Delete</span>
            </button>
        </div>
    </div>
</div>

<div class="learning-zone-container">
    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div style="margin-bottom: 20px; text-align: right;">
        <a href="import-learning-content.php" class="add-btn" style="text-decoration: none; background: linear-gradient(135deg, #8b5cf6, #6d28d9);">
            <i class="fas fa-file-import"></i> Bulk Import from Google Sheets
        </a>
    </div>

    <!-- Modules Section -->
    <div class="section-card">
        <div class="section-title">
            <i class="fas fa-boxes"></i>
            Course Modules
            <button class="add-btn" onclick="showAddModuleModal()" style="margin-left: auto;">
                <i class="fas fa-plus"></i> Add Module
            </button>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Module Key</th>
                    <th>Module Name</th>
                    <th>Color</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($modules as $module): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($module['module_key']); ?></code></td>
                    <td><?php echo htmlspecialchars($module['module_name']); ?></td>
                    <td>
                        <span class="color-preview" style="background: <?php echo htmlspecialchars($module['color']); ?>"></span>
                        <?php echo htmlspecialchars($module['color']); ?>
                    </td>
                    <td><?php echo $module['sort_order']; ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $module['status']; ?>">
                            <?php echo ucfirst($module['status']); ?>
                        </span>
                    </td>
                    <td>
                        <button class="action-btn edit-btn" onclick='editModule(<?php echo json_encode($module); ?>)'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return handleDeleteConfirm(event, 'Delete Module', 'Are you sure you want to delete this module?<br><br><span style=\'color: #ef4444; font-weight: 600;\'>⚠️ This action cannot be undone.</span>');">
                            <input type="hidden" name="action" value="delete_module">
                            <input type="hidden" name="module_id" value="<?php echo $module['id']; ?>">
                            <button type="submit" class="action-btn delete-btn">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Mobile Cards for Modules -->
        <div class="mobile-cards-container" style="display: none;">
            <?php foreach ($modules as $module): ?>
            <div class="mobile-data-card" style="--card-color: <?php echo htmlspecialchars($module['color']); ?>">
                <div class="mobile-card-header">
                    <div>
                        <div class="mobile-card-title">
                            <i class="fas <?php echo htmlspecialchars($module['icon']); ?> me-2"></i>
                            <?php echo htmlspecialchars($module['module_name']); ?>
                        </div>
                        <div class="mobile-card-subtitle">
                            <code><?php echo htmlspecialchars($module['module_key']); ?></code>
                            <span>•</span>
                            <span>Order: <?php echo $module['sort_order']; ?></span>
                        </div>
                    </div>
                    <div class="mobile-card-actions">
                        <button class="mobile-action-icon edit" onclick='editModule(<?php echo json_encode($module); ?>)' title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this module?');">
                            <input type="hidden" name="action" value="delete_module">
                            <input type="hidden" name="module_id" value="<?php echo $module['id']; ?>">
                            <button type="submit" class="mobile-action-icon delete" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div>
                    <span class="mobile-card-badge badge-<?php echo $module['status']; ?>">
                        <?php echo ucfirst($module['status']); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Topics Section -->
    <div class="section-card">
        <div class="section-title">
            <i class="fas fa-list"></i>
            Course Topics
            <button class="add-btn" onclick="showAddTopicModal()" style="margin-left: auto;">
                <i class="fas fa-plus"></i> Add Topic
            </button>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Topic #</th>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topics as $topic): ?>
                <tr>
                    <td><?php echo htmlspecialchars($topic['module_name']); ?></td>
                    <td><?php echo $topic['topic_number']; ?></td>
                    <td><?php echo htmlspecialchars($topic['title']); ?></td>
                    <td><span class="status-badge status-active"><?php echo ucfirst($topic['type']); ?></span></td>
                    <td><?php echo gmdate("i:s", $topic['duration']); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $topic['status']; ?>">
                            <?php echo ucfirst($topic['status']); ?>
                        </span>
                    </td>
                    <td>
                        <button class="action-btn edit-btn" onclick='editTopic(<?php echo json_encode($topic); ?>)'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this topic?');">
                            <input type="hidden" name="action" value="delete_topic">
                            <input type="hidden" name="topic_id" value="<?php echo $topic['id']; ?>">
                            <button type="submit" class="action-btn delete-btn">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Mobile Cards for Topics -->
        <div class="mobile-cards-container" style="display: none;">
            <?php foreach ($topics as $topic): ?>
            <div class="mobile-data-card" style="--card-color: #3b82f6">
                <div class="mobile-card-header">
                    <div>
                        <div class="mobile-card-title">
                            <i class="fas fa-play-circle me-2"></i>
                            <?php echo htmlspecialchars($topic['title']); ?>
                        </div>
                        <div class="mobile-card-subtitle">
                            <span><?php echo htmlspecialchars($topic['module_name']); ?></span>
                            <span>•</span>
                            <span>Topic #<?php echo $topic['topic_number']; ?></span>
                            <span>•</span>
                            <span><?php echo gmdate("i:s", $topic['duration']); ?></span>
                        </div>
                    </div>
                    <div class="mobile-card-actions">
                        <button class="mobile-action-icon edit" onclick='editTopic(<?php echo json_encode($topic); ?>)' title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this topic?');">
                            <input type="hidden" name="action" value="delete_topic">
                            <input type="hidden" name="topic_id" value="<?php echo $topic['id']; ?>">
                            <button type="submit" class="mobile-action-icon delete" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div>
                    <span class="mobile-card-badge badge-<?php echo $topic['status']; ?>">
                        <?php echo ucfirst($topic['status']); ?>
                    </span>
                    <span class="mobile-card-badge" style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: white; margin-left: 6px;">
                        <?php echo ucfirst($topic['type']); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Module Modal -->
<div id="moduleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="moduleModalTitle">Add Module</h2>
            <span class="close" onclick="closeModal('moduleModal')">&times;</span>
        </div>
        <form method="POST" id="moduleForm">
            <input type="hidden" name="action" id="moduleAction" value="add_module">
            <input type="hidden" name="module_id" id="module_id">
            
            <div class="form-group">
                <label>Module Key (e.g., module01)</label>
                <input type="text" name="module_key" id="module_key" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Module Name</label>
                <input type="text" name="module_name" id="module_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Color</label>
                <input type="color" name="color" id="color" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Gradient</label>
                <input type="text" name="gradient" id="gradient" class="form-control" 
                       placeholder="linear-gradient(135deg, #3b82f6, #2563eb)" required>
            </div>
            
            <div class="form-group">
                <label>Icon (FontAwesome class)</label>
                <input type="text" name="icon" id="icon" class="form-control" placeholder="fa-graduation-cap" required>
            </div>
            
            <div class="form-group">
                <label>Sort Order</label>
                <input type="number" name="sort_order" id="module_sort_order" class="form-control" required>
            </div>
            
            <div class="form-group" id="moduleStatusGroup" style="display: none;">
                <label>Status</label>
                <select name="status" id="module_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <button type="submit" class="submit-btn">
                <i class="fas fa-save"></i> Save Module
            </button>
        </form>
    </div>
</div>

<!-- Add/Edit Topic Modal -->
<div id="topicModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="topicModalTitle">Add Topic</h2>
            <span class="close" onclick="closeModal('topicModal')">&times;</span>
        </div>
        <form method="POST" id="topicForm">
            <input type="hidden" name="action" id="topicAction" value="add_topic">
            <input type="hidden" name="topic_id" id="topic_id">
            
            <div class="form-group">
                <label>Module</label>
                <select name="module_id" id="topic_module_id" class="form-control" required>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?php echo $module['id']; ?>">
                            <?php echo htmlspecialchars($module['module_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Topic Number</label>
                <input type="number" name="topic_number" id="topic_number" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Title</label>
                <input type="text" name="title" id="topic_title" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>English Video ID</label>
                <input type="text" name="video_en" id="video_en" class="form-control" placeholder="YouTube Video ID">
            </div>
            
            <div class="form-group">
                <label>Urdu Video ID</label>
                <input type="text" name="video_ur" id="video_ur" class="form-control" placeholder="YouTube Video ID">
            </div>
            
            <div class="form-group">
                <label>Duration (seconds)</label>
                <input type="number" name="duration" id="duration" class="form-control" value="0">
            </div>
            
            <div class="form-group">
                <label>Type</label>
                <select name="type" id="topic_type" class="form-control">
                    <option value="video">Video</option>
                    <option value="quiz">Quiz</option>
                    <option value="assessment">Assessment</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Sort Order</label>
                <input type="number" name="sort_order" id="topic_sort_order" class="form-control" required>
            </div>
            
            <div class="form-group" id="topicStatusGroup" style="display: none;">
                <label>Status</label>
                <select name="status" id="topic_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <button type="submit" class="submit-btn">
                <i class="fas fa-save"></i> Save Topic
            </button>
        </form>
    </div>
</div>

<script>
function showAddModuleModal() {
    document.getElementById('moduleModalTitle').textContent = 'Add Module';
    document.getElementById('moduleAction').value = 'add_module';
    document.getElementById('moduleForm').reset();
    document.getElementById('moduleStatusGroup').style.display = 'none';
    document.getElementById('moduleModal').style.display = 'block';
}

function editModule(module) {
    document.getElementById('moduleModalTitle').textContent = 'Edit Module';
    document.getElementById('moduleAction').value = 'edit_module';
    document.getElementById('module_id').value = module.id;
    document.getElementById('module_key').value = module.module_key;
    document.getElementById('module_name').value = module.module_name;
    document.getElementById('color').value = module.color;
    document.getElementById('gradient').value = module.gradient;
    document.getElementById('icon').value = module.icon;
    document.getElementById('module_sort_order').value = module.sort_order;
    document.getElementById('module_status').value = module.status;
    document.getElementById('moduleStatusGroup').style.display = 'block';
    document.getElementById('moduleModal').style.display = 'block';
}

function showAddTopicModal() {
    document.getElementById('topicModalTitle').textContent = 'Add Topic';
    document.getElementById('topicAction').value = 'add_topic';
    document.getElementById('topicForm').reset();
    document.getElementById('topicStatusGroup').style.display = 'none';
    document.getElementById('topicModal').style.display = 'block';
}

function editTopic(topic) {
    document.getElementById('topicModalTitle').textContent = 'Edit Topic';
    document.getElementById('topicAction').value = 'edit_topic';
    document.getElementById('topic_id').value = topic.id;
    document.getElementById('topic_module_id').value = topic.module_id;
    document.getElementById('topic_number').value = topic.topic_number;
    document.getElementById('topic_title').value = topic.title;
    document.getElementById('video_en').value = topic.video_en || '';
    document.getElementById('video_ur').value = topic.video_ur || '';
    document.getElementById('duration').value = topic.duration;
    document.getElementById('topic_type').value = topic.type;
    document.getElementById('topic_sort_order').value = topic.sort_order;
    document.getElementById('topic_status').value = topic.status;
    document.getElementById('topicStatusGroup').style.display = 'block';
    document.getElementById('topicModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Custom Dialog Functions
function showCustomDialog(title, message, onConfirm) {
    const overlay = document.getElementById('customDialogOverlay');
    const titleEl = document.getElementById('dialogTitle');
    const messageEl = document.getElementById('dialogMessage');
    const confirmBtn = document.getElementById('dialogConfirmBtn');
    
    titleEl.textContent = title;
    messageEl.innerHTML = message;
    overlay.classList.add('active');
    
    // Remove old event listeners
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    // Add new event listener
    newConfirmBtn.addEventListener('click', function() {
        closeCustomDialog();
        if (onConfirm) onConfirm();
    });
    
    // Close on overlay click
    overlay.onclick = function(e) {
        if (e.target === overlay) {
            closeCustomDialog();
        }
    };
    
    // Close on ESC key
    document.addEventListener('keydown', function escHandler(e) {
        if (e.key === 'Escape') {
            closeCustomDialog();
            document.removeEventListener('keydown', escHandler);
        }
    });
}

function closeCustomDialog() {
    const overlay = document.getElementById('customDialogOverlay');
    overlay.classList.remove('active');
}

function handleDeleteConfirm(event, title, message) {
    event.preventDefault();
    const form = event.target;
    
    showCustomDialog(
        title,
        message,
        function() {
            form.submit();
        }
    );
    
    return false;
}
</script>

<?php require_once 'includes/footer.php'; ?>
