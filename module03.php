<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Create course_progress table if it doesn't exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `course_progress` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `module` varchar(50) NOT NULL,
        `topic_id` int(11) NOT NULL,
        `completed` tinyint(1) DEFAULT 0,
        `completed_at` timestamp NULL DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_progress` (`user_id`, `module`, `topic_id`),
        KEY `user_id` (`user_id`),
        KEY `module` (`module`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Fetch module info from database
$module_key = 'module03';
try {
    $stmt = $db->prepare("SELECT * FROM course_modules WHERE module_key = ?");
    $stmt->execute([$module_key]);
    $module_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$module_info) {
        // Fallback module info
        $module_info = [
            'id' => 3,
            'module_name' => 'Advanced Digital Marketing Course',
            'color' => '#10b981',
            'gradient' => 'linear-gradient(135deg, #10b981, #059669)'
        ];
    }
    
    // Fetch topics from database
    $stmt = $db->prepare("SELECT * FROM course_topics WHERE module_id = ? AND status = 'active' ORDER BY sort_order ASC");
    $stmt->execute([$module_info['id']]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Fallback to empty topics if error
    $topics = [];
}

// Fetch user's progress for this module from completed_topics table (syncs with admin)
$stmt = $db->prepare("SELECT topic_id FROM completed_topics WHERE user_id = ? AND module_id = ?");
$stmt->execute([$user_id, $module_info['id']]);
$progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create progress array for easy access (using topic_number as key)
$completed_topics = [];
foreach ($progress as $p) {
    $completed_topics[$p['topic_id']] = true;
}

// Fetch quiz results for this module
$stmt = $db->prepare("SELECT topic_id, passed FROM quiz_results WHERE user_id = ? AND module = 'module03' ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$quiz_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create quiz passed array (only latest result per topic)
$quiz_passed = [];
foreach ($quiz_results as $result) {
    if (!isset($quiz_passed[$result['topic_id']])) {
        $quiz_passed[$result['topic_id']] = (bool)$result['passed'];
    }
}

require_once 'includes/header.php';
?>

<style>
body {
    background: linear-gradient(135deg, #047857 0%, #10b981 50%, #34d399 100%);
    min-height: 100vh;
}

.module-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 30px 15px;
}

.module-hero {
    text-align: center;
    margin-bottom: 40px;
    padding: 40px 20px;
}

.module-icon {
    width: 80px;
    height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    backdrop-filter: blur(10px);
    border: 3px solid rgba(255, 255, 255, 0.3);
}

.module-icon i {
    font-size: 2.5rem;
    color: white;
}

.module-hero h1 {
    color: white;
    font-size: 2rem;
    font-weight: 800;
    margin-bottom: 10px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.module-hero p {
    color: rgba(255, 255, 255, 0.9);
    font-size: 1rem;
    margin: 0;
}

.module-card {
    background: white;
    border-radius: 20px;
    padding: 25px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    margin-bottom: 25px;
}

.module-header {
    display: flex;
    align-items: center;
    gap: 15px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 20px;
}

.module-number {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #10b981, #059669);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 800;
    font-size: 1.3rem;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.module-info h2 {
    margin: 0;
    font-size: 1.3rem;
    color: #1e293b;
    font-weight: 700;
}

.module-info p {
    margin: 5px 0 0;
    font-size: 0.9rem;
    color: #64748b;
}

.topic-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px;
    background: white;
    border-radius: 15px;
    margin-bottom: 12px;
    border: 2px solid #e5e7eb;
    transition: all 0.3s ease;
}

.topic-item.completed-partial {
    border-color: #3b82f6;
    background: linear-gradient(to right, rgba(59, 130, 246, 0.05), white);
}

.topic-item.completed-full {
    border-color: #10b981;
    background: linear-gradient(to right, rgba(16, 185, 129, 0.05), white);
}

.topic-item:hover {
    border-color: #10b981;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
    transform: translateX(5px);
}

.topic-item.completed {
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
    border-color: #10b981;
}

.topic-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.topic-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.topic-icon.incomplete {
    background: #e5e7eb;
    color: #9ca3af;
}

.topic-icon.completed-partial {
    background: #3b82f6;
    color: white;
}

.topic-icon.completed-full {
    background: #10b981;
    color: white;
}

/* Legacy support */
.topic-icon.completed {
    background: #10b981;
    color: white;
}

.topic-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.topic-actions {
    display: flex;
    gap: 8px;
}

.btn-topic {
    padding: 6px 16px;
    border: none;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-view {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.btn-view:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    transform: translateY(-2px);
}

.btn-quiz {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-quiz:hover {
    background: linear-gradient(135deg, #059669, #047857);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    transform: translateY(-2px);
}

.btn-topic i {
    font-size: 0.75rem;
}

.progress-bar-container {
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px solid #e5e7eb;
}

.progress-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
}

.progress-label span {
    font-size: 0.9rem;
    font-weight: 600;
    color: #64748b;
}

.progress-bar {
    width: 100%;
    height: 12px;
    background: #e5e7eb;
    border-radius: 20px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(135deg, #10b981, #059669);
    border-radius: 20px;
    transition: width 0.5s ease;
}

@media (max-width: 768px) {
    .module-hero h1 {
        font-size: 1.5rem;
    }
    
    .topic-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .topic-actions {
        width: 100%;
    }
    
    .btn-topic {
        flex: 1;
        justify-content: center;
    }
}
</style>

<div class="module-container">
    <!-- Hero Section -->
    <div class="module-hero">
        <div class="module-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <h1>Course Curriculum</h1>
        <p>Advanced techniques to skyrocket your affiliate sales</p>
    </div>

    <!-- Module Content -->
    <div class="module-card">
        <div class="module-header">
            <div class="module-number">03</div>
            <div class="module-info">
                <h2><?php echo htmlspecialchars($module_info['module_name']); ?></h2>
                <p>Master advanced marketing strategies</p>
            </div>
        </div>

        <div class="topics-list">
            <?php if (empty($topics)): ?>
                <p style="text-align: center; color: #64748b; padding: 40px;">No topics available yet. Please contact admin.</p>
            <?php else: ?>
            <?php foreach ($topics as $topic): ?>
                <?php 
                // Check completion by topic_number (syncs with admin records)
                $is_completed = isset($completed_topics[$topic['topic_number']]) && $completed_topics[$topic['topic_number']] === true;
                $is_quiz_passed = isset($quiz_passed[$topic['topic_number']]) && $quiz_passed[$topic['topic_number']] === true;
                
                // Blue if only video completed, green if both video and quiz completed
                $completion_class = '';
                if ($is_completed && $is_quiz_passed) {
                    $completion_class = 'completed-full'; // Green - both done
                } elseif ($is_completed) {
                    $completion_class = 'completed-partial'; // Blue - only video
                }
                ?>
                <div class="topic-item <?php echo $completion_class; ?>">
                    <div class="topic-left">
                        <div class="topic-icon <?php echo $completion_class ? $completion_class : 'incomplete'; ?>">
                            <?php if ($is_completed): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <i class="fas fa-circle"></i>
                            <?php endif; ?>
                        </div>
                        <span class="topic-title">Topic <?php echo $topic['topic_number']; ?>: <?php echo htmlspecialchars($topic['title']); ?></span>
                    </div>
                    <div class="topic-actions">
                        <?php 
                        // Default to 'video' if type is not set - ALWAYS show buttons
                        $topic_type = isset($topic['type']) && !empty($topic['type']) ? $topic['type'] : 'video';
                        ?>
                        <!-- View Button: Always show for video type -->
                        <a href="lecture.php?module=module03&topic=<?php echo $topic['topic_number']; ?>" class="btn-topic btn-view">
                            <i class="fas fa-play"></i> View
                        </a>
                        <!-- Quiz Button: Always show -->
                        <a href="quiz.php?module=module03&topic=<?php echo $topic['topic_number']; ?>" class="btn-topic btn-quiz">
                            <i class="fas fa-clipboard-check"></i> Quiz
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Progress Bar -->
        <div class="progress-bar-container">
            <?php
            $total_topics = count($topics);
            $completed_count = count($completed_topics);
            $progress_percentage = $total_topics > 0 ? round(($completed_count / $total_topics) * 100) : 0;
            ?>
            <div class="progress-label">
                <span>Course Progress</span>
                <span><?php echo $completed_count; ?> / <?php echo $total_topics; ?> Topics</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
