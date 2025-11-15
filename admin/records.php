<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session_manager.php';

$database = new Database();
$db = $database->getConnection();

$message = '';
$message_type = '';

// Handle AJAX requests for updating status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_lecture_status') {
        $user_id = intval($_POST['user_id']);
        $module_key = $_POST['module_key'];
        $topic_number = intval($_POST['topic_number']);
        $status = $_POST['status']; // 'complete' or 'incomplete'
        
        try {
            // Get module_id
            $stmt = $db->prepare("SELECT id FROM course_modules WHERE module_key = ?");
            $stmt->execute([$module_key]);
            $module = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($status === 'complete') {
                // Add or update completion
                $stmt = $db->prepare("INSERT INTO completed_topics (user_id, module_id, topic_id, completed_at) 
                                      VALUES (?, ?, ?, NOW()) 
                                      ON DUPLICATE KEY UPDATE completed_at = NOW()");
                $stmt->execute([$user_id, $module['id'], $topic_number]);
            } else {
                // Remove completion
                $stmt = $db->prepare("DELETE FROM completed_topics WHERE user_id = ? AND module_id = ? AND topic_id = ?");
                $stmt->execute([$user_id, $module['id'], $topic_number]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Lecture status updated successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
        
    } elseif ($_POST['action'] === 'update_quiz_status') {
        $user_id = intval($_POST['user_id']);
        $module_key = $_POST['module_key'];
        $topic_number = intval($_POST['topic_number']);
        $status = $_POST['status']; // 'passed' or 'failed'
        
        try {
            $passed = ($status === 'passed') ? 1 : 0;
            
            // Check if quiz result exists
            $check_stmt = $db->prepare("SELECT id FROM quiz_results WHERE user_id = ? AND module = ? AND topic_id = ?");
            $check_stmt->execute([$user_id, $module_key, $topic_number]);
            
            if ($check_stmt->fetch()) {
                // Update existing result
                $stmt = $db->prepare("UPDATE quiz_results 
                                      SET passed = ? 
                                      WHERE user_id = ? AND module = ? AND topic_id = ? 
                                      ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$passed, $user_id, $module_key, $topic_number]);
            } else {
                // Insert new result (manual entry by admin)
                $stmt = $db->prepare("INSERT INTO quiz_results (user_id, module, topic_id, score, total_questions, percentage, passed, created_at) 
                                      VALUES (?, ?, ?, 0, 0, 0, ?, NOW())");
                $stmt->execute([$user_id, $module_key, $topic_number, $passed]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Quiz status updated successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Get only affiliate users with their progress
try {
    $stmt = $db->query("SELECT u.id, u.full_name, u.email, u.city, u.created_at, a.partner_id 
                        FROM users u 
                        INNER JOIN affiliates a ON u.id = a.user_id 
                        ORDER BY u.created_at DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Get all modules
try {
    $modules = $db->query("SELECT * FROM course_modules ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $modules = [];
}

// Function to get user's lecture progress
function getUserLectureProgress($db, $user_id, $module_key) {
    try {
        // Get module ID for completion lookup
        $stmt = $db->prepare("SELECT id FROM course_modules WHERE module_key = ?");
        $stmt->execute([$module_key]);
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$module) return [];
        
        $module_id = $module['id'];
        
        // Use LOWER() for case-insensitive comparison
        $stmt = $db->prepare("SELECT topic_number, title, type, module_id FROM course_topics 
                              WHERE module_id = ? AND LOWER(type) = 'video' 
                              ORDER BY sort_order");
        $stmt->execute([$module_id]);
        $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get completion status for each
        $progress = [];
        foreach ($topics as $index => $topic) {
            // Simplified - just add the topic without completion check for now
            $progress[] = [
                'topic_number' => $topic['topic_number'],
                'title' => $topic['title'],
                'completed' => false, // Default to not completed
                'completed_at' => null
            ];
        }
        
        // Now check completion status separately (avoid any PDO statement reuse issues)
        foreach ($progress as $idx => $item) {
            try {
                $comp_stmt = $db->prepare("SELECT completed_at FROM completed_topics 
                                           WHERE user_id = ? AND module_id = ? AND topic_id = ?");
                $comp_stmt->execute([$user_id, $module_id, $item['topic_number']]);
                $completion = $comp_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($completion) {
                    $progress[$idx]['completed'] = true;
                    $progress[$idx]['completed_at'] = $completion['completed_at'];
                }
            } catch (Exception $comp_error) {
                // Ignore completion check errors, just leave as not completed
            }
        }
        
        return $progress;
    } catch (Exception $e) {
        return [];
    }
}

// Function to get user's quiz progress
function getUserQuizProgress($db, $user_id, $module_key) {
    try {
        // Quizzes are NOT separate topics - they're tied to video topics
        // Fetch quiz results from quiz_results table only
        $stmt = $db->prepare("SELECT topic_id, score, total_questions, percentage, passed, created_at 
                              FROM quiz_results 
                              WHERE user_id = ? AND module = ? 
                              ORDER BY created_at DESC");
        $stmt->execute([$user_id, $module_key]);
        $all_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by topic_id, keeping only the latest result for each
        $progress = [];
        $seen_topics = [];
        
        foreach ($all_results as $result) {
            $topic_id = $result['topic_id'];
            
            // Only add if we haven't seen this topic yet (latest result due to ORDER BY)
            if (!isset($seen_topics[$topic_id])) {
                $progress[] = [
                    'topic_number' => $topic_id,
                    'title' => 'Quiz ' . $topic_id,
                    'attempted' => true,
                    'passed' => (bool)$result['passed'],
                    'score' => $result['score'],
                    'total' => $result['total_questions'],
                    'percentage' => $result['percentage'],
                    'attempted_at' => $result['created_at']
                ];
                $seen_topics[$topic_id] = true;
            }
        }
        
        return $progress;
    } catch (Exception $e) {
        return [];
    }
}

$page_title = 'Affiliate Progress Records';
include 'includes/header.php';
?>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.records-container {
    padding: 30px;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    background: white;
    padding: 30px;
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
    animation: slideInDown 0.5s ease-out;
}

.page-header h1 {
    font-size: 2rem;
    font-weight: 900;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin: 0 0 10px 0;
}

.page-header p {
    color: #64748b;
    margin: 0;
    font-size: 1rem;
}

.stats-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
}

.progress-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.progress-table th:nth-child(1),
.progress-table td:nth-child(1) {
    width: 40%;
}

.progress-table th:nth-child(2),
.progress-table td:nth-child(2) {
    width: 30%;
    text-align: center;
}

.progress-table th:nth-child(3),
.progress-table td:nth-child(3) {
    width: 30%;
    text-align: center;
}

.user-card {
    background: white;
    border-radius: 12px;
    margin-bottom: 15px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    animation: slideInUp 0.5s ease-out;
    transition: all 0.3s ease;
    border: 1px solid #e5e7eb;
}

.user-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.user-header {
    padding: 18px 20px;
    background: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer !important;
    transition: all 0.2s ease;
    gap: 15px;
    user-select: none;
}

.user-header:hover {
    background: #f8fafc;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.user-checkbox {
    width: 18px;
    height: 18px;
    border: 2px solid #d1d5db;
    border-radius: 4px;
    cursor: pointer;
}

.user-checkbox:checked {
    background: #667eea;
    border-color: #667eea;
}

.user-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
    font-weight: 700;
    flex-shrink: 0;
}

.user-details {
    flex: 1;
    min-width: 0;
}

.user-details h3 {
    font-size: 1.05rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.user-details p {
    font-size: 0.85rem;
    color: #94a3b8;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 5px;
}

.user-details p i {
    font-size: 0.75rem;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
}

.badge-affiliate {
    background: #fbbf24;
    color: white;
}

.badge-active {
    background: #10b981;
    color: white;
}

.badge-partner {
    background: linear-gradient(135deg, #e9d5ff, #d8b4fe);
    color: #6b21a8;
    font-size: 0.7rem;
}

.user-meta {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-right: 10px;
}

.stat-compact {
    text-align: center;
    min-width: 50px;
}

.stat-compact .num {
    font-size: 1.4rem;
    font-weight: 800;
    color: #667eea;
    display: block;
    line-height: 1;
}

.stat-compact .label {
    font-size: 0.7rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.toggle-icon {
    font-size: 1.2rem;
    color: #9ca3af;
    transition: transform 0.3s ease;
    flex-shrink: 0;
}

.toggle-icon.rotated {
    transform: rotate(180deg);
}

.user-details-section {
    display: none;
    padding: 30px;
    background: white;
}

.user-details-section.active {
    display: block;
    animation: slideInDown 0.3s ease-out;
}

.module-section {
    margin-bottom: 30px;
}

.module-header {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 18px 25px;
    border-radius: 16px;
    font-weight: 700;
    font-size: 1.15rem;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.module-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    transition: all 0.5s ease;
}

.module-header:hover {
    transform: translateX(5px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.5);
}

.module-header:hover::before {
    transform: scale(1.5);
}

.module-title {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    z-index: 2;
}

.module-toggle-icon {
    font-size: 1.3rem;
    transition: transform 0.3s ease;
    z-index: 2;
}

.module-toggle-icon.rotated {
    transform: rotate(180deg);
}

.module-content {
    display: none;
    padding: 20px;
    background: #f8fafc;
    border-radius: 12px;
    margin-bottom: 20px;
    animation: slideDown 0.3s ease-out;
}

.module-content.active {
    display: block;
}

.progress-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.progress-table thead {
    background: #f8fafc;
}

.progress-table th {
    padding: 12px 15px;
    text-align: left;
    font-weight: 700;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.progress-table td {
    padding: 15px;
    border-bottom: 1px solid #f1f5f9;
    color: #64748b;
}

.progress-table tr:hover {
    background: #f8fafc;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-block;
}

.status-badge.completed {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
}

.status-badge.pending {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
}

.status-badge.passed {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
}

.status-badge.failed {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
}

/* Topic Info Styles */
.topic-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.topic-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 8px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-weight: 700;
    border-radius: 8px;
    font-size: 0.9rem;
}

.topic-title {
    flex: 1;
    font-weight: 600;
    color: #1e293b;
}

.topic-date {
    font-size: 0.75rem;
    color: #94a3b8;
    display: block;
}

/* Status Button Styles */
.status-btn {
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    min-width: 36px;
    min-height: 36px;
}

.status-btn .btn-icon {
    font-size: 0.95rem;
}

.status-btn .btn-text {
    display: inline;
    font-size: 0.7rem;
}

.status-btn:hover {
    transform: scale(1.05);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
}

.status-btn:active {
    transform: scale(0.98);
}

/* Lecture Status Colors */
.status-pending {
    background: linear-gradient(135deg, #fb923c, #f97316);
    color: white;
}

.status-completed {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

/* Quiz Status Colors */
.status-fail {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.status-passed {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.no-quiz {
    color: #94a3b8;
    font-size: 0.9rem;
    display: block;
    text-align: center;
}

.action-toggle.add {
    background: linear-gradient(135deg, #10b981, #059669);
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: #94a3b8;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 15px;
    opacity: 0.5;
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
    }
    to {
        opacity: 1;
        max-height: 2000px;
    }
}

.section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    margin-bottom: 15px;
    font-weight: 700;
    font-size: 1rem;
}

.section-title i {
    color: white;
    font-size: 1.1rem;
}

.no-data {
    text-align: center;
    padding: 30px;
    color: #94a3b8;
    font-style: italic;
}

@media (max-width: 768px) {
    .records-container {
        padding: 15px;
    }
    
    .page-header {
        padding: 20px;
    }
    
    .page-header h1 {
        font-size: 1.5rem;
    }
    
    .user-header {
        padding: 15px;
        gap: 10px;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
    }
    
    .user-details h3 {
        font-size: 0.95rem;
    }
    
    .user-details p {
        font-size: 0.8rem;
    }
    
    .badge {
        font-size: 0.7rem;
        padding: 3px 8px;
    }
    
    .user-meta {
        flex-direction: column;
        gap: 8px;
        align-items: flex-end;
    }
    
    .stat-compact {
        min-width: 40px;
    }
    
    .stat-compact .num {
        font-size: 1.2rem;
    }
    
    .module-header {
        padding: 12px 15px;
        font-size: 0.95rem;
    }
    
    .progress-table {
        font-size: 0.8rem;
    }
    
    .progress-table th,
    .progress-table td {
        padding: 8px 6px;
    }
    
    /* Mobile: Compact layout */
    .topic-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    
    .topic-number {
        min-width: 28px;
        height: 28px;
        font-size: 0.8rem;
    }
    
    .topic-title {
        display: none;
    }
    
    .topic-date {
        font-size: 0.65rem;
        margin-left: 0;
    }
    
    /* Mobile: Mini icon buttons */
    .status-btn {
        padding: 6px;
        min-width: 32px;
        min-height: 32px;
    }
    
    .status-btn .btn-text {
        display: none;
    }
    
    .status-btn .btn-icon {
        display: inline;
        font-size: 1rem;
    }
    
    .progress-table th:first-child,
    .progress-table td:first-child {
        width: 35%;
    }
    
    /* Mobile: Narrow status columns */
    .progress-table th:nth-child(2),
    .progress-table td:nth-child(2) {
        width: 32.5%;
    }
    
    .progress-table th:nth-child(3),
    .progress-table td:nth-child(3) {
        width: 32.5%;
    }
}

.search-box {
    margin-bottom: 20px;
}

.search-box input {
    width: 100%;
    padding: 15px 20px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.search-box input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

/* Delete Selected Button */
.btn-delete-selected-affiliates {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
    position: relative;
    overflow: hidden;
}

.btn-delete-selected-affiliates::before {
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

.btn-delete-selected-affiliates::after {
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
    animation: deleteShine 3s infinite;
    pointer-events: none;
}

@keyframes deleteShine {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

.btn-delete-selected-affiliates > * {
    position: relative;
    z-index: 1;
}

.btn-delete-selected-affiliates:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
}

.btn-delete-selected-affiliates:active {
    transform: translateY(-1px);
}

@media (max-width: 768px) {
    .btn-delete-selected-affiliates {
        padding: 10px 20px;
        font-size: 0.9rem;
    }
}
</style>

<div class="records-container">
    <div class="page-header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1><i class="fas fa-chart-line"></i> Affiliate Progress Records</h1>
                <p>Track and manage affiliate user lecture completions and quiz results - Click modules to expand</p>
            </div>
            <button id="deleteSelectedBtn" class="btn-delete-selected-affiliates" onclick="deleteSelectedAffiliates()" style="display: none;">
                <i class="fas fa-trash-alt"></i>
                <span>Delete Selected</span>
            </button>
        </div>
    </div>

    <div class="search-box">
        <input type="text" id="searchUsers" placeholder="🔍 Search users by name, email, or city..." onkeyup="filterUsers()">
    </div>

    <?php if (empty($users)): ?>
        <div class="user-card">
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>No affiliate users found in the system.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($users as $user): ?>
            <?php
            // Calculate overall progress
            $total_completed = 0;
            $total_passed = 0;
            
            foreach ($modules as $module) {
                $lectures = getUserLectureProgress($db, $user['id'], $module['module_key']);
                $quizzes = getUserQuizProgress($db, $user['id'], $module['module_key']);
                
                foreach ($lectures as $lecture) {
                    if ($lecture['completed']) $total_completed++;
                }
                
                foreach ($quizzes as $quiz) {
                    if ($quiz['passed']) $total_passed++;
                }
            }
            ?>
            
            <div class="user-card" data-user-id="<?php echo $user['id']; ?>" data-user-name="<?php echo strtolower($user['full_name']); ?>" data-user-email="<?php echo strtolower($user['email']); ?>" data-user-city="<?php echo strtolower($user['city'] ?? ''); ?>">
                <div class="user-header" onclick="toggleUserDetails(<?php echo $user['id']; ?>)">
                    <div class="user-info">
                        <input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>" onclick="event.stopPropagation(); updateAffiliateSelection();">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <h3>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                                <span class="badge badge-affiliate">
                                    <i class="fas fa-handshake"></i> Affiliate
                                </span>
                                <span class="badge badge-active">
                                    <i class="fas fa-circle-check"></i> Active
                                </span>
                            </h3>
                            <p>
                                <i class="fas fa-calendar"></i>
                                <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                <span class="badge badge-partner" style="margin-left: 8px;">
                                    <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($user['partner_id']); ?>
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="user-meta">
                        <div class="stat-compact">
                            <span class="num"><?php echo $total_completed; ?></span>
                            <span class="label">Completed</span>
                        </div>
                        <div class="stat-compact">
                            <span class="num"><?php echo $total_passed; ?></span>
                            <span class="label">Passed</span>
                        </div>
                    </div>
                    <i class="fas fa-chevron-down toggle-icon" id="toggleIcon<?php echo $user['id']; ?>"></i>
                </div>
                
                <div class="user-details-section" id="userDetails<?php echo $user['id']; ?>">
                    <?php foreach ($modules as $module): ?>
                        <?php
                        $lectures = getUserLectureProgress($db, $user['id'], $module['module_key']);
                        $quizzes = getUserQuizProgress($db, $user['id'], $module['module_key']);
                        ?>
                        
                        <div class="module-section">
                            <div class="module-header" onclick="toggleModuleContent(<?php echo $user['id']; ?>, '<?php echo $module['module_key']; ?>')">
                                <div class="module-title">
                                    <i class="fas fa-book"></i>
                                    <span><?php echo htmlspecialchars($module['module_name']); ?></span>
                                    <span style="background: rgba(255,255,255,0.25); padding: 4px 10px; border-radius: 12px; font-size: 0.85rem; margin-left: 12px;">
                                        <?php echo count($lectures); ?> topics
                                    </span>
                                </div>
                                <i class="fas fa-chevron-down module-toggle-icon" id="moduleIcon<?php echo $user['id']  . '_' . $module['module_key']; ?>"></i>
                            </div>
                            
                            <div class="module-content" id="moduleContent<?php echo $user['id'] . '_' . $module['module_key']; ?>">
                                <?php if (empty($lectures) && empty($quizzes)): ?>
                                    <div style="text-align: center; padding: 30px 20px; color: #94a3b8; background: #f8fafc; border-radius: 12px;">
                                        <i class="fas fa-inbox" style="font-size: 2.5rem; margin-bottom: 12px; opacity: 0.4;"></i>
                                        <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">No topics in this module yet</p>
                                        <p style="margin: 5px 0 0 0; font-size: 0.8rem;">Add topics in Learning Zone to see them here</p>
                                    </div>
                                <?php else: ?>
                                    <!-- Lectures Table -->
                                    <?php if (!empty($lectures)): ?>
                                        <div class="section-title">
                                            <i class="fas fa-video"></i>
                                            <span>Lectures</span>
                                        </div>
                                    <table class="progress-table">
                                    <thead>
                                        <tr>
                                            <th>Topic</th>
                                            <th>Lecture Status</th>
                                            <th>Quiz Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Create quiz lookup by topic_id from quiz_results
                                        $quiz_by_topic = [];
                                        foreach ($quizzes as $quiz) {
                                            $quiz_by_topic[$quiz['topic_number']] = $quiz;
                                        }
                                        
                                        foreach ($lectures as $lecture): 
                                            // Check if there's a quiz result for this topic
                                            $quiz_result = isset($quiz_by_topic[$lecture['topic_number']]) ? $quiz_by_topic[$lecture['topic_number']] : null;
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="topic-info">
                                                        <span class="topic-number"><?php echo $lecture['topic_number']; ?></span>
                                                        <span class="topic-title"><?php echo htmlspecialchars($lecture['title']); ?></span>
                                                        <span class="topic-date"><?php echo $lecture['completed_at'] ? date('M d, Y', strtotime($lecture['completed_at'])) : ''; ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <button class="status-btn <?php echo $lecture['completed'] ? 'status-completed' : 'status-pending'; ?>" 
                                                            onclick="toggleLectureStatus(event, <?php echo $user['id']; ?>, '<?php echo $module['module_key']; ?>', <?php echo $lecture['topic_number']; ?>, '<?php echo $lecture['completed'] ? 'incomplete' : 'complete'; ?>')">
                                                        <span class="btn-text"><?php echo $lecture['completed'] ? 'Completed' : 'Mark as Complete'; ?></span>
                                                        <span class="btn-icon"><i class="fas fa-<?php echo $lecture['completed'] ? 'check-circle' : 'circle'; ?>"></i></span>
                                                    </button>
                                                </td>
                                                <td>
                                                    <?php if ($quiz_result): ?>
                                                        <button class="status-btn <?php echo $quiz_result['passed'] ? 'status-passed' : 'status-fail'; ?>" 
                                                                onclick="toggleQuizStatus(event, <?php echo $user['id']; ?>, '<?php echo $module['module_key']; ?>', <?php echo $lecture['topic_number']; ?>, '<?php echo $quiz_result['passed'] ? 'failed' : 'passed'; ?>')">
                                                            <span class="btn-text"><?php echo $quiz_result['passed'] ? 'Passed' : 'Pass'; ?></span>
                                                            <span class="btn-icon"><i class="fas fa-<?php echo $quiz_result['passed'] ? 'check-circle' : 'trophy'; ?>"></i></span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="status-btn status-fail" 
                                                                onclick="toggleQuizStatus(event, <?php echo $user['id']; ?>, '<?php echo $module['module_key']; ?>', <?php echo $lecture['topic_number']; ?>, 'passed')">
                                                            <span class="btn-text">Pass</span>
                                                            <span class="btn-icon"><i class="fas fa-trophy"></i></span>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                                <?php endif; ?>
                            </div><!-- Close module-content -->
                        </div><!-- Close module-section -->
                    <?php endforeach; ?>
                </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="../js/custom-dialog.js"></script>
<script>
function toggleUserDetails(userId) {
    const detailsSection = document.getElementById('userDetails' + userId);
    const toggleIcon = document.getElementById('toggleIcon' + userId);
    
    if (detailsSection) {
        detailsSection.classList.toggle('active');
    }
    if (toggleIcon) {
        toggleIcon.classList.toggle('rotated');
    }
}

function toggleModuleContent(userId, moduleKey) {
    const moduleContent = document.getElementById('moduleContent' + userId + '_' + moduleKey);
    const moduleIcon = document.getElementById('moduleIcon' + userId + '_' + moduleKey);
    
    moduleContent.classList.toggle('active');
    moduleIcon.classList.toggle('rotated');
}

async function toggleLectureStatus(event, userId, moduleKey, topicNumber, newStatus) {
    const confirmed = await CustomDialog.confirm(
        'Update Lecture Status',
        `Are you sure you want to mark this lecture as ${newStatus}?`,
        {
            icon: 'fa-video',
            iconColor: '#667eea',
            confirmText: 'Yes, Update',
            confirmColor: 'linear-gradient(135deg, #667eea, #764ba2)'
        }
    );
    
    if (!confirmed) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_lecture_status');
        formData.append('user_id', userId);
        formData.append('module_key', moduleKey);
        formData.append('topic_number', topicNumber);
        formData.append('status', newStatus);
        
        const response = await fetch('records.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Update button in real-time without page reload
            const button = event.target.closest('.status-btn');
            
            if (newStatus === 'complete') {
                button.className = 'status-btn status-completed';
                button.querySelector('.btn-text').textContent = 'Completed';
                button.querySelector('.btn-icon i').className = 'fas fa-check-circle';
                button.setAttribute('onclick', `toggleLectureStatus(event, ${userId}, '${moduleKey}', ${topicNumber}, 'incomplete')`);
            } else {
                button.className = 'status-btn status-pending';
                button.querySelector('.btn-text').textContent = 'Mark as Complete';
                button.querySelector('.btn-icon i').className = 'fas fa-circle';
                button.setAttribute('onclick', `toggleLectureStatus(event, ${userId}, '${moduleKey}', ${topicNumber}, 'complete')`);
            }
            
            await CustomDialog.success('Success!', result.message);
        } else {
            await CustomDialog.error('Error', result.message);
        }
    } catch (error) {
        await CustomDialog.error('Error', 'Failed to update lecture status');
    }
}

async function toggleQuizStatus(event, userId, moduleKey, topicNumber, newStatus) {
    const confirmed = await CustomDialog.confirm(
        'Update Quiz Status',
        `Are you sure you want to mark this quiz as ${newStatus}?`,
        {
            icon: 'fa-clipboard-check',
            iconColor: '#8b5cf6',
            confirmText: 'Yes, Update',
            confirmColor: 'linear-gradient(135deg, #8b5cf6, #7c3aed)'
        }
    );
    
    if (!confirmed) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_quiz_status');
        formData.append('user_id', userId);
        formData.append('module_key', moduleKey);
        formData.append('topic_number', topicNumber);
        formData.append('status', newStatus);
        
        const response = await fetch('records.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Update button in real-time without page reload
            const button = event.target.closest('.status-btn');
            
            if (newStatus === 'passed') {
                button.className = 'status-btn status-passed';
                button.querySelector('.btn-text').textContent = 'Passed';
                button.querySelector('.btn-icon i').className = 'fas fa-check-circle';
                button.setAttribute('onclick', `toggleQuizStatus(event, ${userId}, '${moduleKey}', ${topicNumber}, 'failed')`);
            } else {
                button.className = 'status-btn status-fail';
                button.querySelector('.btn-text').textContent = 'Pass';
                button.querySelector('.btn-icon i').className = 'fas fa-trophy';
                button.setAttribute('onclick', `toggleQuizStatus(event, ${userId}, '${moduleKey}', ${topicNumber}, 'passed')`);
            }
            
            await CustomDialog.success('Success!', result.message);
        } else {
            await CustomDialog.error('Error', result.message);
        }
    } catch (error) {
        await CustomDialog.error('Error', 'Failed to update quiz status');
    }
}

// Update affiliate selection and show/hide delete button
function updateAffiliateSelection() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    
    if (checkboxes.length > 0) {
        deleteBtn.style.display = 'flex';
        deleteBtn.querySelector('span').textContent = `Delete Selected (${checkboxes.length})`;
    } else {
        deleteBtn.style.display = 'none';
    }
}

// Delete selected affiliates
async function deleteSelectedAffiliates() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    const userIds = Array.from(checkboxes).map(cb => cb.value);
    
    if (userIds.length === 0) {
        await CustomDialog.error('No Selection', 'Please select at least one affiliate to delete.');
        return;
    }
    
    const confirmed = await CustomDialog.confirm(
        'Delete Affiliates',
        `Are you sure you want to permanently delete ${userIds.length} affiliate(s)? This will remove their affiliate status and all related data. This action cannot be undone.`,
        {
            icon: 'fa-trash-alt',
            iconColor: '#ef4444',
            confirmText: 'Yes, Delete',
            confirmColor: 'linear-gradient(135deg, #ef4444, #dc2626)'
        }
    );
    
    if (!confirmed) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_affiliates');
        formData.append('user_ids', JSON.stringify(userIds));
        
        const response = await fetch('ajax/delete_affiliates.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            await CustomDialog.success('Success!', result.message);
            location.reload();
        } else {
            await CustomDialog.error('Error', result.message);
        }
    } catch (error) {
        await CustomDialog.error('Error', 'Failed to delete affiliates. Please try again.');
    }
}

function filterUsers() {
    const searchValue = document.getElementById('searchUsers').value.toLowerCase();
    const userCards = document.querySelectorAll('.user-card[data-user-name]');
    
    userCards.forEach(card => {
        const name = card.getAttribute('data-user-name');
        const email = card.getAttribute('data-user-email');
        const city = card.getAttribute('data-user-city');
        
        if (name.includes(searchValue) || email.includes(searchValue) || city.includes(searchValue)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
