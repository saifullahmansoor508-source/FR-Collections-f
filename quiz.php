<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$module = isset($_GET['module']) ? $_GET['module'] : 'module01';
$topic_id = isset($_GET['topic']) ? intval($_GET['topic']) : 1;

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Create tables if they don't exist
try {
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
    
    // Create quiz_results table
    $db->exec("CREATE TABLE IF NOT EXISTS `quiz_results` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `module` varchar(50) NOT NULL,
        `topic_id` int(11) NOT NULL,
        `full_name` varchar(255) NOT NULL,
        `city` varchar(255) NOT NULL,
        `score` int(11) NOT NULL,
        `total_questions` int(11) NOT NULL DEFAULT 7,
        `percentage` decimal(5,2) NOT NULL,
        `passed` tinyint(1) NOT NULL DEFAULT 0,
        `answers` text COMMENT 'JSON array of answers',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `module` (`module`),
        KEY `topic_id` (`topic_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create course_topics table for topic info
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
    
    // Create course_modules table
    $db->exec("CREATE TABLE IF NOT EXISTS `course_modules` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `module_key` varchar(50) NOT NULL UNIQUE,
        `module_name` varchar(255) NOT NULL,
        `color` varchar(50) DEFAULT '#3b82f6',
        `gradient` varchar(255) DEFAULT 'linear-gradient(135deg, #3b82f6, #2563eb)',
        `icon` varchar(50) DEFAULT 'fa-graduation-cap',
        `sort_order` int(11) DEFAULT 0,
        `status` enum('active','inactive') DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `module_key` (`module_key`),
        KEY `sort_order` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    // Tables might already exist, continue
}

// Fetch quiz questions
$questions = [];
$debug_info = [];
try {
    $debug_info['module'] = $module;
    $debug_info['topic_id'] = $topic_id;
    
    $stmt = $db->prepare("SELECT * FROM quiz_questions WHERE module = ? AND topic_id = ? ORDER BY sort_order");
    $stmt->execute([$module, $topic_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug_info['questions_found'] = count($questions);
    
    // Also try to fetch all questions to see what's in DB
    $all_stmt = $db->prepare("SELECT DISTINCT module, topic_id FROM quiz_questions ORDER BY module, topic_id");
    $all_stmt->execute();
    $debug_info['all_module_topics'] = $all_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $debug_info['error'] = $e->getMessage();
}

// Fetch topic info
$topic_info = null;
try {
    $stmt = $db->prepare("SELECT ct.*, cm.module_name FROM course_topics ct 
                          LEFT JOIN course_modules cm ON ct.module_id = cm.id 
                          WHERE cm.module_key = ? AND ct.topic_number = ?");
    $stmt->execute([$module, $topic_id]);
    $topic_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tables might not exist yet, use defaults
    $topic_info = [
        'module_name' => 'Module ' . substr($module, -2),
        'title' => 'Topic ' . $topic_id
    ];
}

// Handle quiz submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $full_name = $_POST['full_name'];
    $city = $_POST['city'];
    $answers = [];
    $score = 0;
    
    foreach ($questions as $q) {
        $user_answer = isset($_POST['question_' . $q['id']]) ? $_POST['question_' . $q['id']] : '';
        $answers[$q['id']] = $user_answer;
        
        if ($user_answer === $q['correct_answer']) {
            $score++;
        }
    }
    
    $total_questions = count($questions);
    $percentage = $total_questions > 0 ? ($score / $total_questions) * 100 : 0;
    $passed = $percentage >= 85; // 85% to pass
    
    // Save result
    $stmt = $db->prepare("INSERT INTO quiz_results (user_id, module, topic_id, full_name, city, score, total_questions, percentage, passed, answers) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $user_id,
        $module,
        $topic_id,
        $full_name,
        $city,
        $score,
        $total_questions,
        $percentage,
        $passed ? 1 : 0,
        json_encode($answers)
    ]);
    
    // Redirect to results
    header('Location: quiz_result.php?id=' . $db->lastInsertId());
    exit();
}

require_once 'includes/header.php';
?>

<style>
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #667eea 100%);
    background-size: 400% 400%;
    animation: gradientShift 15s ease infinite;
    min-height: 100vh;
}

@keyframes gradientShift {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}

.quiz-container {
    max-width: 800px;
    margin: 30px auto;
    padding: 0 15px;
    animation: fadeInScale 0.6s ease-out;
}

@keyframes fadeInScale {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.quiz-header {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 20px;
    padding: 40px;
    margin-bottom: 30px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
    text-align: center;
    position: relative;
    overflow: hidden;
    animation: slideInDown 0.6s ease-out;
}

.quiz-header::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent, rgba(102, 126, 234, 0.1), transparent);
    transform: rotate(45deg);
    animation: shine 3s linear infinite;
}

@keyframes shine {
    0% { left: -50%; }
    100% { left: 150%; }
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.quiz-header h1 {
    font-size: 2.2rem;
    font-weight: 900;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0 0 10px 0;
    position: relative;
    z-index: 1;
}

.quiz-header p {
    color: #64748b;
    font-size: 1.1rem;
    margin: 0;
    position: relative;
    z-index: 1;
}

.quiz-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 20px;
    padding: 35px;
    margin-bottom: 30px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    border: 2px solid rgba(102, 126, 234, 0.1);
    transition: all 0.4s ease;
    animation: slideInUp 0.6s ease-out;
}

.quiz-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 50px rgba(102, 126, 234, 0.25);
    border-color: rgba(102, 126, 234, 0.3);
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.student-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 700;
    color: #475569;
    font-size: 1rem;
}

.form-control {
    width: 100%;
    padding: 12px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.question-card {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 25px;
    border: 2px solid #e2e8f0;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    animation: fadeInUp 0.5s ease-out backwards;
}

.question-card:nth-child(1) { animation-delay: 0.1s; }
.question-card:nth-child(2) { animation-delay: 0.2s; }
.question-card:nth-child(3) { animation-delay: 0.3s; }
.question-card:nth-child(4) { animation-delay: 0.4s; }
.question-card:nth-child(5) { animation-delay: 0.5s; }
.question-card:nth-child(6) { animation-delay: 0.6s; }
.question-card:nth-child(7) { animation-delay: 0.7s; }

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

.question-card:hover {
    border-color: #667eea;
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
    transform: translateY(-3px);
}

.question-number {
    display: inline-block;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 6px 15px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 0.9rem;
    margin-bottom: 15px;
}

.question-text {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 20px;
    line-height: 1.6;
}

.options-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.option-item {
    margin-bottom: 12px;
}

.option-item label {
    display: flex;
    align-items: center;
    padding: 15px;
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 500;
    color: #475569;
}

.option-item label:hover {
    border-color: #667eea;
    background: #f8fafc;
}

.option-item input[type="radio"] {
    width: 20px;
    height: 20px;
    margin-right: 12px;
    cursor: pointer;
    accent-color: #667eea;
}

.option-item input[type="radio"]:checked + span {
    color: #667eea;
    font-weight: 700;
}

.submit-btn {
    width: 100%;
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #667eea 100%);
    background-size: 200% auto;
    color: white;
    border: none;
    border-radius: 15px;
    font-size: 1.3rem;
    font-weight: 900;
    cursor: pointer;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    position: relative;
    overflow: hidden;
    animation: pulse 2s ease-in-out infinite;
}

.submit-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transform: rotate(45deg);
    animation: shine 3s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% {
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    }
    50% {
        box-shadow: 0 12px 35px rgba(102, 126, 234, 0.6);
    }
}

.submit-btn:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 12px 35px rgba(102, 126, 234, 0.5);
    background-position: right center;
}

.submit-btn:active {
    transform: translateY(-1px) scale(0.98);
}

.quiz-instructions {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border-left: 4px solid #3b82f6;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 25px;
}

.quiz-instructions h3 {
    color: #1e40af;
    font-weight: 700;
    margin: 0 0 10px 0;
}

.quiz-instructions ul {
    margin: 10px 0 0 20px;
    color: #1e3a8a;
}

.quiz-instructions ul li {
    margin-bottom: 8px;
}

@media (max-width: 768px) {
    .student-info {
        grid-template-columns: 1fr;
    }
    
    .quiz-header h1 {
        font-size: 1.5rem;
    }
}
</style>

<div class="quiz-container">
    <!-- Header -->
    <div class="quiz-header">
        <h1><i class="fas fa-clipboard-check"></i> Topic Quiz</h1>
        <p><?php echo $topic_info ? htmlspecialchars($topic_info['module_name']) : 'Module'; ?> - 
           Topic <?php echo $topic_id; ?>: <?php echo $topic_info ? htmlspecialchars($topic_info['title']) : 'Quiz'; ?></p>
    </div>

    <?php if (empty($questions)): ?>
        <div class="quiz-card">
            <div class="quiz-instructions">
                <h3><i class="fas fa-info-circle"></i> No Questions Available</h3>
                <p>This quiz hasn't been set up yet. Please contact the administrator.</p>
            </div>
            
            <a href="lecture.php?module=<?php echo $module; ?>&topic=<?php echo $topic_id; ?>" class="submit-btn" style="margin-top: 20px;">
                <i class="fas fa-arrow-left"></i> Back to Lecture
            </a>
        </div>
    <?php else: ?>
        <!-- Instructions -->
        <div class="quiz-card">
            <div class="quiz-instructions">
                <h3><i class="fas fa-info-circle"></i> Instructions</h3>
                <ul>
                    <li>This quiz contains <strong><?php echo count($questions); ?> multiple-choice questions</strong></li>
                    <li>You need to score at least <strong>50%</strong> to pass</li>
                    <li>Select the best answer for each question</li>
                    <li>Click "Submit Quiz" when you're done</li>
                </ul>
            </div>
        </div>

        <!-- Quiz Form -->
        <form method="POST" id="quizForm">
            <input type="hidden" name="submit_quiz" value="1">
            
            <!-- Student Information -->
            <div class="quiz-card">
                <h2 style="margin: 0 0 20px 0; color: #1e293b;">
                    <i class="fas fa-user"></i> Student Information
                </h2>
                <div class="student-info">
                    <div class="form-group">
                        <label>Full Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="full_name" class="form-control" required placeholder="Enter your full name">
                    </div>
                    <div class="form-group">
                        <label>City Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="city" class="form-control" required placeholder="Enter your city">
                    </div>
                </div>
            </div>

            <!-- Questions -->
            <div class="quiz-card">
                <h2 style="margin: 0 0 25px 0; color: #1e293b;">
                    <i class="fas fa-question-circle"></i> Questions
                </h2>
                
                <?php foreach ($questions as $index => $question): ?>
                    <div class="question-card">
                        <span class="question-number">Question <?php echo ($index + 1); ?> of <?php echo count($questions); ?></span>
                        <div class="question-text">
                            <?php echo htmlspecialchars($question['question']); ?>
                        </div>
                        
                        <ul class="options-list">
                            <li class="option-item">
                                <label>
                                    <input type="radio" name="question_<?php echo $question['id']; ?>" value="A" required>
                                    <span>A. <?php echo htmlspecialchars($question['option_a']); ?></span>
                                </label>
                            </li>
                            <li class="option-item">
                                <label>
                                    <input type="radio" name="question_<?php echo $question['id']; ?>" value="B" required>
                                    <span>B. <?php echo htmlspecialchars($question['option_b']); ?></span>
                                </label>
                            </li>
                            <li class="option-item">
                                <label>
                                    <input type="radio" name="question_<?php echo $question['id']; ?>" value="C" required>
                                    <span>C. <?php echo htmlspecialchars($question['option_c']); ?></span>
                                </label>
                            </li>
                            <li class="option-item">
                                <label>
                                    <input type="radio" name="question_<?php echo $question['id']; ?>" value="D" required>
                                    <span>D. <?php echo htmlspecialchars($question['option_d']); ?></span>
                                </label>
                            </li>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Submit Button -->
            <div class="quiz-card">
                <button type="button" class="submit-btn" id="submitQuizBtn">
                    <i class="fas fa-paper-plane"></i> Submit Quiz
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script src="js/custom-dialog.js"></script>
<script>
document.getElementById('submitQuizBtn')?.addEventListener('click', async function() {
    const confirmed = await CustomDialog.confirm(
        'Submit Quiz',
        'Are you sure you want to submit your answers? You cannot change them after submission.',
        {
            icon: 'fa-paper-plane',
            iconColor: '#8b5cf6',
            confirmText: 'Yes, Submit',
            confirmColor: 'linear-gradient(135deg, #667eea, #764ba2)'
        }
    );
    
    if (confirmed) {
        document.getElementById('quizForm').submit();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
