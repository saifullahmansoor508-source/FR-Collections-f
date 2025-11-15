<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    header('Location: affiliate.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$result_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Fetch result with comprehensive error handling
// NOTE: Using $quiz_result instead of $result to avoid conflicts with header.php
$quiz_result = null;
$debug_info = [];

// First, check if quiz_results table exists and has the record
try {
    $stmt = $db->prepare("SELECT * FROM quiz_results WHERE id = ? AND user_id = ?");
    $stmt->execute([$result_id, $user_id]);
    $quiz_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($quiz_result) {
        $debug_info['step1'] = 'Basic query successful - record found';
        
        // Now try to get module name
        try {
            $stmt2 = $db->prepare("SELECT module_name FROM course_modules WHERE module_key = ?");
            $stmt2->execute([$quiz_result['module']]);
            $module_data = $stmt2->fetch(PDO::FETCH_ASSOC);
            $quiz_result['module_name'] = $module_data ? $module_data['module_name'] : 'Unknown Module';
            $debug_info['step2'] = 'Module name fetched';
        } catch (PDOException $e) {
            $quiz_result['module_name'] = 'Module';
            $debug_info['step2_error'] = $e->getMessage();
        }
        
        // Now try to get topic title
        try {
            $stmt3 = $db->prepare("SELECT ct.title FROM course_topics ct 
                                   INNER JOIN course_modules cm ON ct.module_id = cm.id 
                                   WHERE ct.topic_number = ? AND cm.module_key = ?");
            $stmt3->execute([$quiz_result['topic_id'], $quiz_result['module']]);
            $topic_data = $stmt3->fetch(PDO::FETCH_ASSOC);
            $quiz_result['topic_title'] = $topic_data ? $topic_data['title'] : 'Quiz ' . $quiz_result['topic_id'];
            $debug_info['step3'] = 'Topic title fetched';
        } catch (PDOException $e) {
            $quiz_result['topic_title'] = 'Quiz ' . $quiz_result['topic_id'];
            $debug_info['step3_error'] = $e->getMessage();
        }
    } else {
        $debug_info['error'] = 'No record found for result_id=' . $result_id . ' and user_id=' . $user_id;
    }
} catch (PDOException $e) {
    $debug_info['fatal_error'] = $e->getMessage();
}

if (!$quiz_result) {
    header('Location: affiliate.php');
    exit();
}

// Ensure numeric fields are properly typed BEFORE any output
$quiz_result['passed'] = isset($quiz_result['passed']) ? (bool)$quiz_result['passed'] : false;
$quiz_result['score'] = isset($quiz_result['score']) ? (int)$quiz_result['score'] : 0;
$quiz_result['total_questions'] = isset($quiz_result['total_questions']) ? (int)$quiz_result['total_questions'] : 0;
$quiz_result['percentage'] = isset($quiz_result['percentage']) ? (float)$quiz_result['percentage'] : 0;

require_once 'includes/header.php';
?>

<style>
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
}

.result-container {
    max-width: 700px;
    margin: 50px auto;
    padding: 0 15px;
}

.result-card {
    background: white;
    border-radius: 20px;
    padding: 40px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
    text-align: center;
}

.result-icon {
    width: 120px;
    height: 120px;
    margin: 0 auto 25px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
}

.result-icon.passed {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.result-icon.failed {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.result-title {
    font-size: 2.5rem;
    font-weight: 800;
    margin: 0 0 15px 0;
}

.result-title.passed {
    color: #10b981;
}

.result-title.failed {
    color: #ef4444;
}

.result-info {
    background: #f8fafc;
    padding: 25px;
    border-radius: 15px;
    margin: 25px 0;
}

.result-info p {
    margin: 10px 0;
    font-size: 1.1rem;
    color: #475569;
}

.result-info strong {
    color: #1e293b;
    font-weight: 700;
}

.score-display {
    font-size: 3rem;
    font-weight: 900;
    margin: 20px 0;
}

.score-display.passed {
    color: #10b981;
}

.score-display.failed {
    color: #ef4444;
}

.action-buttons {
    display: flex;
    gap: 15px;
    margin-top: 30px;
}

.btn {
    flex: 1;
    padding: 15px;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.btn-secondary {
    background: #f1f5f9;
    color: #475569;
}

.btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}

@media (max-width: 768px) {
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<div class="result-container">
    <div class="result-card">
        <div class="result-icon <?php echo $quiz_result['passed'] ? 'passed' : 'failed'; ?>">
            <i class="fas fa-<?php echo $quiz_result['passed'] ? 'check-circle' : 'times-circle'; ?>"></i>
        </div>
        
        <h1 class="result-title <?php echo $quiz_result['passed'] ? 'passed' : 'failed'; ?>">
            <?php echo $quiz_result['passed'] ? 'Congratulations!' : 'Keep Trying!'; ?>
        </h1>
        
        <p style="font-size: 1.2rem; color: #64748b; margin-bottom: 10px;">
            <?php echo $quiz_result['passed'] ? 'You have successfully passed the quiz!' : 'You need to improve your score.'; ?>
        </p>
        
        <div class="score-display <?php echo $quiz_result['passed'] ? 'passed' : 'failed'; ?>">
            <?php echo round($quiz_result['percentage']); ?>%
        </div>
        
        <div class="result-info">
            <p><strong>Student:</strong> <?php echo htmlspecialchars($quiz_result['full_name']); ?></p>
            <p><strong>City:</strong> <?php echo htmlspecialchars($quiz_result['city']); ?></p>
            <p><strong>Module:</strong> <?php echo htmlspecialchars($quiz_result['module_name']); ?></p>
            <p><strong>Topic:</strong> <?php echo htmlspecialchars($quiz_result['topic_title']); ?></p>
            <p><strong>Score:</strong> <?php echo $quiz_result['score']; ?> out of <?php echo $quiz_result['total_questions']; ?> correct</p>
            <p><strong>Status:</strong> 
                <span style="color: <?php echo $quiz_result['passed'] ? '#10b981' : '#ef4444'; ?>; font-weight: 700;">
                    <?php echo $quiz_result['passed'] ? 'PASSED ✓' : 'FAILED ✗'; ?>
                </span>
            </p>
        </div>
        
        <div class="action-buttons">
            <a href="lecture.php?module=<?php echo $quiz_result['module']; ?>&topic=<?php echo $quiz_result['topic_id']; ?>" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Lecture
            </a>
            <a href="<?php echo $quiz_result['module']; ?>.php" class="btn btn-secondary">
                <i class="fas fa-home"></i> Course Home
            </a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
