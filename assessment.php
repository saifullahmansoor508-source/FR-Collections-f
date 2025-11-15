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
$module = isset($_GET['module']) ? $_GET['module'] : 'module01';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Create tables if they don't exist
try {
    // Create assessment_questions table
    $db->exec("CREATE TABLE IF NOT EXISTS `assessment_questions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `module` varchar(50) NOT NULL,
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
        KEY `module` (`module`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create assessment_results table
    $db->exec("CREATE TABLE IF NOT EXISTS `assessment_results` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `module` varchar(50) NOT NULL,
        `score` int(11) NOT NULL,
        `total_questions` int(11) NOT NULL DEFAULT 30,
        `percentage` decimal(5,2) NOT NULL,
        `passed` tinyint(1) NOT NULL DEFAULT 0,
        `certificate_generated` tinyint(1) NOT NULL DEFAULT 0,
        `certificate_code` varchar(50) DEFAULT NULL,
        `answers` text COMMENT 'JSON array of answers',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `module` (`module`),
        KEY `certificate_code` (`certificate_code`)
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
    
    // Insert default modules if not exist
    $db->exec("INSERT IGNORE INTO `course_modules` (`module_key`, `module_name`, `color`, `gradient`, `icon`, `sort_order`) VALUES
        ('module01', 'Introduction to FR Collections', '#f59e0b', 'linear-gradient(135deg, #f59e0b, #d97706)', 'fa-graduation-cap', 1),
        ('module02', 'Basic SMM Course', '#3b82f6', 'linear-gradient(135deg, #3b82f6, #2563eb)', 'fa-thumbs-up', 2),
        ('module03', 'Advanced Digital Marketing Course', '#10b981', 'linear-gradient(135deg, #10b981, #059669)', 'fa-chart-line', 3)");
} catch (PDOException $e) {
    // Tables might already exist, continue
}

// Check if user has already passed this assessment
$existing_result = null;
try {
    $stmt = $db->prepare("SELECT * FROM assessment_results WHERE user_id = ? AND module = ? AND passed = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id, $module]);
    $existing_result = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Fetch module info
$module_info = null;
try {
    $stmt = $db->prepare("SELECT * FROM course_modules WHERE module_key = ?");
    $stmt->execute([$module]);
    $module_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Use default info
    $module_info = [
        'module_name' => 'Module Assessment',
        'module_key' => $module
    ];
}

// Fetch assessment questions
$questions = [];
try {
    $stmt = $db->prepare("SELECT * FROM assessment_questions WHERE module = ? ORDER BY sort_order");
    $stmt->execute([$module]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Handle assessment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
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
    $passed = $percentage >= 70; // 70% to pass
    
    // Generate certificate code if passed
    $certificate_code = null;
    if ($passed) {
        $certificate_code = 'FR-' . strtoupper($module) . '-' . $user_id . '-' . time();
    }
    
    // Save result
    $stmt = $db->prepare("INSERT INTO assessment_results (user_id, module, score, total_questions, percentage, passed, certificate_code, answers) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $user_id,
        $module,
        $score,
        $total_questions,
        $percentage,
        $passed ? 1 : 0,
        $certificate_code,
        json_encode($answers)
    ]);
    
    // Redirect to results
    header('Location: assessment_result.php?id=' . $db->lastInsertId());
    exit();
}

require_once 'includes/header.php';
?>

<style>
body {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    min-height: 100vh;
}

.assessment-container {
    max-width: 900px;
    margin: 30px auto;
    padding: 0 15px;
}

.assessment-header {
    background: white;
    border-radius: 15px;
    padding: 40px;
    margin-bottom: 25px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    text-align: center;
}

.assessment-header .icon-box {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #f093fb, #f5576c);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.assessment-header .icon-box i {
    font-size: 2.5rem;
    color: white;
}

.assessment-header h1 {
    font-size: 2.2rem;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 10px 0;
}

.assessment-header p {
    color: #64748b;
    font-size: 1.1rem;
    margin: 0;
}

.assessment-card {
    background: white;
    border-radius: 15px;
    padding: 35px;
    margin-bottom: 25px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
}

.already-passed {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    border-left: 5px solid #10b981;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 25px;
    text-align: center;
}

.already-passed h2 {
    color: #065f46;
    margin: 0 0 15px 0;
}

.already-passed p {
    color: #047857;
    font-size: 1.1rem;
    margin: 10px 0;
}

.certificate-code {
    background: white;
    padding: 15px;
    border-radius: 10px;
    font-family: 'Courier New', monospace;
    font-size: 1.2rem;
    font-weight: 700;
    color: #059669;
    margin: 15px 0;
}

.question-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
}

.question-card:hover {
    border-color: #f093fb;
    box-shadow: 0 4px 15px rgba(240, 147, 251, 0.1);
}

.question-number {
    display: inline-block;
    background: linear-gradient(135deg, #f093fb, #f5576c);
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
    border-color: #f093fb;
    background: #fdf2f8;
}

.option-item input[type="radio"] {
    width: 20px;
    height: 20px;
    margin-right: 12px;
    cursor: pointer;
    accent-color: #f093fb;
}

.option-item input[type="radio"]:checked + span {
    color: #f093fb;
    font-weight: 700;
}

.submit-btn {
    width: 100%;
    padding: 20px;
    background: linear-gradient(135deg, #f093fb, #f5576c);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 1.3rem;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 6px 20px rgba(240, 147, 251, 0.3);
}

.submit-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(240, 147, 251, 0.4);
}

.assessment-instructions {
    background: linear-gradient(135deg, #fff7ed, #fed7aa);
    border-left: 4px solid #f59e0b;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 25px;
}

.assessment-instructions h3 {
    color: #92400e;
    font-weight: 700;
    margin: 0 0 15px 0;
}

.assessment-instructions ul {
    margin: 10px 0 0 20px;
    color: #78350f;
}

.assessment-instructions ul li {
    margin-bottom: 10px;
    font-weight: 600;
}

.progress-indicator {
    background: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.progress-indicator h3 {
    margin: 0 0 15px 0;
    color: #1e293b;
    font-size: 1.2rem;
}

.progress-bar {
    width: 100%;
    height: 15px;
    background: #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(135deg, #f093fb, #f5576c);
    border-radius: 20px;
    transition: width 0.5s ease;
}

@media (max-width: 768px) {
    .assessment-header h1 {
        font-size: 1.7rem;
    }
}
</style>

<div class="assessment-container">
    <!-- Header -->
    <div class="assessment-header">
        <div class="icon-box">
            <i class="fas fa-certificate"></i>
        </div>
        <h1>Final Assessment</h1>
        <p><?php echo $module_info ? htmlspecialchars($module_info['module_name']) : 'Module Assessment'; ?></p>
        <p style="font-size: 0.95rem; margin-top: 10px;">
            <i class="fas fa-clock"></i> 30 Questions | 
            <i class="fas fa-check-circle"></i> 70% Required to Pass
        </p>
    </div>

    <?php if ($existing_result): ?>
        <!-- Already Passed Message -->
        <div class="already-passed">
            <h2><i class="fas fa-trophy"></i> Congratulations!</h2>
            <p>You have already passed this assessment with a score of <strong><?php echo round($existing_result['percentage']); ?>%</strong></p>
            <p>Your Certificate Code:</p>
            <div class="certificate-code"><?php echo htmlspecialchars($existing_result['certificate_code']); ?></div>
            <p style="margin-top: 20px;">
                <a href="certificate.php?code=<?php echo urlencode($existing_result['certificate_code']); ?>" 
                   style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 12px 30px; border-radius: 8px; text-decoration: none; font-weight: 700; display: inline-block;">
                    <i class="fas fa-download"></i> Download Certificate
                </a>
            </p>
        </div>
    <?php elseif (empty($questions)): ?>
        <div class="assessment-card">
            <div class="assessment-instructions">
                <h3><i class="fas fa-info-circle"></i> Assessment Not Available</h3>
                <p>This assessment hasn't been set up yet. Please contact the administrator.</p>
            </div>
        </div>
    <?php else: ?>
        <!-- Instructions -->
        <div class="assessment-card">
            <div class="assessment-instructions">
                <h3><i class="fas fa-exclamation-triangle"></i> Important Instructions</h3>
                <ul>
                    <li>This is the <strong>final assessment</strong> for this module</li>
                    <li>Contains <strong>30 multiple-choice questions</strong></li>
                    <li>You need to score at least <strong>70% (21/30 correct)</strong> to pass</li>
                    <li>A <strong>certificate will be generated</strong> upon passing</li>
                    <li>Read each question carefully before answering</li>
                    <li>You can only submit once, so review your answers before submitting</li>
                </ul>
            </div>
        </div>

        <!-- Assessment Form -->
        <form method="POST" id="assessmentForm">
            <input type="hidden" name="submit_assessment" value="1">
            
            <!-- Questions -->
            <div class="assessment-card">
                <h2 style="margin: 0 0 25px 0; color: #1e293b;">
                    <i class="fas fa-question-circle"></i> Assessment Questions
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
            <div class="assessment-card">
                <button type="submit" class="submit-btn" onclick="return confirm('Are you sure you want to submit your assessment? You cannot change your answers after submission.');">
                    <i class="fas fa-paper-plane"></i> Submit Assessment
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
// Track answered questions
let answeredCount = 0;
const totalQuestions = <?php echo count($questions); ?>;

document.querySelectorAll('input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', function() {
        // Count unique answered questions
        const answered = new Set();
        document.querySelectorAll('input[type="radio"]:checked').forEach(r => {
            answered.add(r.name);
        });
        answeredCount = answered.size;
        
        // Update progress if element exists
        if (document.querySelector('.progress-fill')) {
            const percentage = (answeredCount / totalQuestions) * 100;
            document.querySelector('.progress-fill').style.width = percentage + '%';
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
