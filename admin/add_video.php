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
    // Create course_modules table first
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
        UNIQUE KEY `module_key` (`module_key`)
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
        KEY `module_id` (`module_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create quiz_questions table
    $db->exec("CREATE TABLE IF NOT EXISTS `quiz_questions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `module` varchar(50) NOT NULL,
        `topic_id` int(11) NOT NULL,
        `question` text NOT NULL,
        `option_a` varchar(255) NOT NULL,
        `option_b` varchar(255) NOT NULL,
        `option_c` varchar(255) NOT NULL,
        `option_d` varchar(255) NOT NULL,
        `correct_answer` enum('A','B','C','D') NOT NULL,
        `sort_order` int(11) DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `module` (`module`),
        KEY `topic_id` (`topic_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Check if modules exist and insert defaults if needed
    $count = $db->query("SELECT COUNT(*) FROM course_modules")->fetchColumn();
    if ($count == 0) {
        $db->exec("INSERT INTO `course_modules` (`module_key`, `module_name`, `color`, `gradient`, `icon`, `sort_order`) VALUES
            ('module01', 'Introduction to FR Collections', '#f59e0b', 'linear-gradient(135deg, #f59e0b, #d97706)', 'fa-graduation-cap', 1),
            ('module02', 'Basic SMM Course', '#3b82f6', 'linear-gradient(135deg, #3b82f6, #2563eb)', 'fa-thumbs-up', 2),
            ('module03', 'Advanced Digital Marketing Course', '#10b981', 'linear-gradient(135deg, #10b981, #059669)', 'fa-chart-line', 3)");
    }
} catch (PDOException $e) {
    $error = "Failed to create tables: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'update_videos') {
                $stmt = $db->prepare("UPDATE course_topics SET video_en = ?, video_ur = ?, duration = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['video_en'],
                    $_POST['video_ur'],
                    $_POST['duration'],
                    $_POST['topic_id']
                ]);
                $success = "Videos updated successfully!";
            } elseif ($_POST['action'] === 'add_quiz_question') {
                // Check for duplicate questions first
                $checkStmt = $db->prepare("SELECT COUNT(*) FROM quiz_questions 
                                           WHERE module = ? AND topic_id = ? AND question = ?");
                $checkStmt->execute([$_POST['module'], $_POST['topic_id'], $_POST['question']]);
                $exists = $checkStmt->fetchColumn();
                
                if ($exists > 0) {
                    $error = "This question already exists for this module and topic!";
                } else {
                    $stmt = $db->prepare("INSERT INTO quiz_questions (module, topic_id, question, option_a, option_b, option_c, option_d, correct_answer, sort_order) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['module'],
                        $_POST['topic_id'],
                        $_POST['question'],
                        $_POST['option_a'],
                        $_POST['option_b'],
                        $_POST['option_c'],
                        $_POST['option_d'],
                        $_POST['correct_answer'],
                        $_POST['sort_order']
                    ]);
                    $success = "Quiz question added successfully!";
                }
            } elseif ($_POST['action'] === 'delete_quiz_question') {
                $stmt = $db->prepare("DELETE FROM quiz_questions WHERE id = ?");
                $stmt->execute([$_POST['question_id']]);
                $success = "Question deleted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage() . ". Please make sure tables are created.";
        }
    }
}

// Fetch modules
$modules = [];
try {
    $modules = $db->query("SELECT * FROM course_modules ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Fetch topics with module info
$topics = [];
try {
    $topics = $db->query("SELECT ct.*, cm.module_key, cm.module_name 
                           FROM course_topics ct 
                           JOIN course_modules cm ON ct.module_id = cm.id 
                           ORDER BY cm.sort_order, ct.sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tables might not exist yet
}

// Fetch quiz questions
$quiz_questions = [];
try {
    $quiz_questions = $db->query("SELECT qq.*, cm.module_name, ct.title as topic_title 
                                   FROM quiz_questions qq 
                                   LEFT JOIN course_modules cm ON qq.module = cm.module_key
                                   LEFT JOIN course_topics ct ON qq.topic_id = ct.topic_number AND cm.id = ct.module_id
                                   ORDER BY qq.module, qq.topic_id, qq.sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tables might not exist yet
}

$page_title = "Add Video";
require_once 'includes/header.php';
?>

<style>
/* ========================================
   DESKTOP & MOBILE RESPONSIVE DESIGN
   ======================================== */
.add-video-container {
    max-width: 1400px;
    margin: 30px auto;
    padding: 20px;
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

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 10px;
    font-weight: 700;
    color: #475569;
    font-size: 1.05rem;
}

.form-control {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    transform: translateY(-1px);
}

/* ========================================
   SHINY ANIMATED BUTTONS
   ======================================== */
.submit-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    padding: 16px 32px;
    border-radius: 12px;
    cursor: pointer;
    font-weight: 700;
    font-size: 1.05rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}

.submit-btn::before {
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

.submit-btn::after {
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
    animation: submitBtnShine 3s infinite;
    pointer-events: none;
}

@keyframes submitBtnShine {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

.submit-btn > * {
    position: relative;
    z-index: 1;
}

.submit-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
}

.submit-btn:active {
    transform: translateY(-1px);
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

.video-preview {
    background: #f1f5f9;
    padding: 15px;
    border-radius: 8px;
    margin-top: 10px;
    display: none;
}

.video-preview.active {
    display: block;
}

.video-preview iframe {
    width: 100%;
    max-width: 400px;
    aspect-ratio: 9/16;
    border-radius: 8px;
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

/* Desktop action buttons */
.action-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    margin-right: 8px;
    transition: all 0.3s ease;
}

.delete-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

/* ========================================
   MOBILE RESPONSIVE - MATCHING LEARNING ZONE
   ======================================== */
@media (max-width: 768px) {
    .add-video-container {
        padding: 12px;
        margin: 15px auto;
    }
    
    .section-card {
        padding: 20px !important;
        margin-bottom: 20px !important;
        border-radius: 12px !important;
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
    }
    
    .submit-btn {
        width: 100%;
        padding: 14px 24px !important;
        font-size: 1rem !important;
    }
    
    .form-row {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }
    
    .form-group {
        margin-bottom: 20px !important;
    }
    
    .form-group label {
        font-size: 1rem !important;
    }
    
    .form-control {
        padding: 12px 14px !important;
        font-size: 0.95rem !important;
    }
    
    .video-preview iframe {
        max-width: 100% !important;
    }
    
    /* Quiz Card Specific Styles */
    .quiz-card {
        border-left-color: #8b5cf6 !important;
    }
    
    /* Expand/Collapse Icon */
    .mobile-action-icon.expand {
        background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
    }
    
    .mobile-action-icon.expand.active i {
        transform: rotate(180deg);
    }
    
    /* Quiz Details Section */
    .quiz-details {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 2px solid #f1f5f9;
        animation: slideDown 0.3s ease;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .quiz-detail-section {
        margin-bottom: 16px;
    }
    
    .quiz-detail-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
    }
    
    .quiz-detail-value {
        font-size: 0.95rem;
        color: #1e293b;
        line-height: 1.5;
        font-weight: 500;
    }
    
    /* Quiz Options Grid */
    .quiz-options-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }
    
    .quiz-option {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        background: #f8fafc;
        border-radius: 10px;
        border: 2px solid #e2e8f0;
        transition: all 0.3s ease;
    }
    
    .quiz-option.correct {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        border-color: #10b981;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
    }
    
    .option-label {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        flex-shrink: 0;
        box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
    }
    
    .quiz-option.correct .option-label {
        background: linear-gradient(135deg, #10b981, #059669);
        box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
    }
    
    .option-text {
        flex: 1;
        font-size: 0.9rem;
        color: #1e293b;
        line-height: 1.4;
    }
    
    .quiz-correct-answer {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px;
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        border-radius: 10px;
        color: #065f46;
        font-size: 0.9rem;
        font-weight: 600;
        border: 2px solid #10b981;
    }
    
    .quiz-correct-answer i {
        color: #10b981;
        font-size: 1.1rem;
    }
}

/* Desktop - Hide mobile quiz cards */
@media (min-width: 769px) {
    .quiz-card {
        display: none;
    }
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
    max-width: 700px;
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

.badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

.badge-correct {
    background: #d1fae5;
    color: #065f46;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="add-video-container">
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
            <i class="fas fa-file-import"></i> Bulk Import Topics & Quizzes
        </a>
    </div>

    <!-- Add/Update Videos Section -->
    <div class="section-card">
        <div class="section-title">
            <i class="fas fa-video"></i>
            Add/Update Course Videos
            <a href="learning_zone.php" class="add-btn" style="margin-left: auto; text-decoration: none;">
                <i class="fas fa-plus"></i> Add New Topic
            </a>
        </div>
        
        <div style="background: #dbeafe; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #3b82f6;">
            <i class="fas fa-info-circle" style="color: #3b82f6;"></i>
            <strong>Note:</strong> To add a new topic or video, click <strong>"Add New Topic"</strong> above or go to 
            <a href="learning_zone.php" style="color: #3b82f6; font-weight: 600;">Learning Zone</a>.
            This form only updates videos for existing topics.
        </div>

        <form method="POST" id="videoForm">
            <input type="hidden" name="action" value="update_videos">
            
            <div class="form-group">
                <label>Select Topic</label>
                <select name="topic_id" id="topicSelect" class="form-control" required onchange="loadTopicData()">
                    <option value="">-- Select Topic --</option>
                    <?php foreach ($topics as $topic): ?>
                        <option value="<?php echo $topic['id']; ?>" 
                                data-video-en="<?php echo htmlspecialchars($topic['video_en']); ?>"
                                data-video-ur="<?php echo htmlspecialchars($topic['video_ur']); ?>"
                                data-duration="<?php echo $topic['duration']; ?>">
                            <?php echo htmlspecialchars($topic['module_name']); ?> - 
                            Topic <?php echo $topic['topic_number']; ?>: <?php echo htmlspecialchars($topic['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>English Video ID</label>
                    <input type="text" name="video_en" id="video_en" class="form-control" 
                           placeholder="YouTube Video ID (e.g., dQw4w9WgXcQ)" onchange="previewVideo('en')">
                    <div id="preview_en" class="video-preview"></div>
                </div>

                <div class="form-group">
                    <label>Urdu Video ID</label>
                    <input type="text" name="video_ur" id="video_ur" class="form-control" 
                           placeholder="YouTube Video ID (e.g., dQw4w9WgXcQ)" onchange="previewVideo('ur')">
                    <div id="preview_ur" class="video-preview"></div>
                </div>
            </div>

            <div class="form-group">
                <label>Video Duration (in seconds)</label>
                <input type="number" name="duration" id="duration" class="form-control" placeholder="e.g., 180 for 3 minutes">
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-save"></i> Update Videos
            </button>
        </form>
    </div>

    <!-- Quiz Questions Section -->
    <div class="section-card">
        <div class="section-title">
            <i class="fas fa-question-circle"></i>
            Quiz Questions (7 MCQs per Topic)
            <button class="add-btn" onclick="showAddQuestionModal()" style="margin-left: auto;">
                <i class="fas fa-plus"></i> Add Question
            </button>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Topic</th>
                    <th>Question</th>
                    <th>Correct Answer</th>
                    <th>Order</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quiz_questions as $q): ?>
                <tr>
                    <td><?php echo htmlspecialchars($q['module_name']); ?></td>
                    <td><?php echo htmlspecialchars($q['topic_title']); ?></td>
                    <td><?php echo substr(htmlspecialchars($q['question']), 0, 50) . '...'; ?></td>
                    <td><span class="badge badge-correct">Option <?php echo $q['correct_answer']; ?></span></td>
                    <td><?php echo $q['sort_order']; ?></td>
                    <td>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this question?');">
                            <input type="hidden" name="action" value="delete_quiz_question">
                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                            <button type="submit" class="action-btn delete-btn">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Mobile Cards for Quiz Questions -->
        <div class="mobile-cards-container" style="display: none;">
            <?php foreach ($quiz_questions as $q): ?>
            <div class="mobile-data-card quiz-card" style="--card-color: #8b5cf6">
                <div class="mobile-card-header">
                    <div style="flex: 1;">
                        <div class="mobile-card-title">
                            <i class="fas fa-question-circle me-2"></i>
                            <?php echo substr(htmlspecialchars($q['question']), 0, 40) . '...'; ?>
                        </div>
                        <div class="mobile-card-subtitle">
                            <span><?php echo htmlspecialchars($q['module_name']); ?></span>
                            <span>•</span>
                            <span><?php echo htmlspecialchars($q['topic_title']); ?></span>
                            <span>•</span>
                            <span>Order: <?php echo $q['sort_order']; ?></span>
                        </div>
                    </div>
                    <div class="mobile-card-actions">
                        <button class="mobile-action-icon expand" onclick="toggleQuizDetails(this)" title="View Details">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this question?');">
                            <input type="hidden" name="action" value="delete_quiz_question">
                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                            <button type="submit" class="mobile-action-icon delete" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Collapsible Details -->
                <div class="quiz-details" style="display: none;">
                    <div class="quiz-detail-section">
                        <div class="quiz-detail-label">Question:</div>
                        <div class="quiz-detail-value"><?php echo htmlspecialchars($q['question']); ?></div>
                    </div>
                    
                    <div class="quiz-options-grid">
                        <div class="quiz-option <?php echo $q['correct_answer'] == 'A' ? 'correct' : ''; ?>">
                            <span class="option-label">A</span>
                            <span class="option-text"><?php echo htmlspecialchars($q['option_a']); ?></span>
                        </div>
                        <div class="quiz-option <?php echo $q['correct_answer'] == 'B' ? 'correct' : ''; ?>">
                            <span class="option-label">B</span>
                            <span class="option-text"><?php echo htmlspecialchars($q['option_b']); ?></span>
                        </div>
                        <div class="quiz-option <?php echo $q['correct_answer'] == 'C' ? 'correct' : ''; ?>">
                            <span class="option-label">C</span>
                            <span class="option-text"><?php echo htmlspecialchars($q['option_c']); ?></span>
                        </div>
                        <div class="quiz-option <?php echo $q['correct_answer'] == 'D' ? 'correct' : ''; ?>">
                            <span class="option-label">D</span>
                            <span class="option-text"><?php echo htmlspecialchars($q['option_d']); ?></span>
                        </div>
                    </div>
                    
                    <div class="quiz-correct-answer">
                        <i class="fas fa-check-circle"></i>
                        Correct Answer: <strong>Option <?php echo $q['correct_answer']; ?></strong>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Add Quiz Question Modal -->
<div id="questionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add Quiz Question</h2>
            <span class="close" onclick="closeModal('questionModal')">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_quiz_question">
            
            <div class="form-group">
                <label>Module</label>
                <select name="module" id="quiz_module" class="form-control" required onchange="filterTopicsByModule()">
                    <option value="">-- Select Module --</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?php echo $module['module_key']; ?>">
                            <?php echo htmlspecialchars($module['module_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Topic (Topic Number)</label>
                <select name="topic_id" id="quiz_topic" class="form-control" required>
                    <option value="">-- Select Topic --</option>
                    <?php foreach ($topics as $topic): ?>
                        <option value="<?php echo $topic['topic_number']; ?>" data-module="<?php echo $topic['module_key']; ?>">
                            Topic <?php echo $topic['topic_number']; ?>: <?php echo htmlspecialchars($topic['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #64748b; font-size: 0.85rem; margin-top: 5px; display: block;">
                    ℹ️ This will save the topic number (1, 2, 3...), not the database ID
                </small>
            </div>

            <div class="form-group">
                <label>Question</label>
                <textarea name="question" class="form-control" rows="3" required></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Option A</label>
                    <input type="text" name="option_a" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Option B</label>
                    <input type="text" name="option_b" class="form-control" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Option C</label>
                    <input type="text" name="option_c" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Option D</label>
                    <input type="text" name="option_d" class="form-control" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Correct Answer</label>
                    <select name="correct_answer" class="form-control" required>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="1" required>
                </div>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-save"></i> Add Question
            </button>
        </form>
    </div>
</div>

<script>
function loadTopicData() {
    const select = document.getElementById('topicSelect');
    const option = select.options[select.selectedIndex];
    
    if (option.value) {
        document.getElementById('video_en').value = option.dataset.videoEn || '';
        document.getElementById('video_ur').value = option.dataset.videoUr || '';
        document.getElementById('duration').value = option.dataset.duration || '';
        
        if (option.dataset.videoEn) previewVideo('en');
        if (option.dataset.videoUr) previewVideo('ur');
    }
}

function previewVideo(lang) {
    const videoId = document.getElementById('video_' + lang).value;
    const previewDiv = document.getElementById('preview_' + lang);
    
    if (videoId && videoId.length > 5) {
        previewDiv.innerHTML = '<iframe src="https://www.youtube.com/embed/' + videoId + '" frameborder="0" allowfullscreen></iframe>';
        previewDiv.classList.add('active');
    } else {
        previewDiv.classList.remove('active');
    }
}

function showAddQuestionModal() {
    document.getElementById('questionModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function filterTopicsByModule() {
    const selectedModule = document.getElementById('quiz_module').value;
    const topicSelect = document.getElementById('quiz_topic');
    const options = topicSelect.options;
    
    for (let i = 1; i < options.length; i++) {
        const option = options[i];
        if (selectedModule === '' || option.dataset.module === selectedModule) {
            option.style.display = '';
        } else {
            option.style.display = 'none';
        }
    }
    
    topicSelect.selectedIndex = 0;
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Toggle quiz details dropdown
function toggleQuizDetails(button) {
    const card = button.closest('.quiz-card');
    const details = card.querySelector('.quiz-details');
    const icon = button.querySelector('i');
    
    if (details.style.display === 'none' || details.style.display === '') {
        details.style.display = 'block';
        button.classList.add('active');
        icon.style.transform = 'rotate(180deg)';
    } else {
        details.style.display = 'none';
        button.classList.remove('active');
        icon.style.transform = 'rotate(0deg)';
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
