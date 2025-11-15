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

// Fetch result
$result = null;
try {
    $stmt = $db->prepare("SELECT ar.*, cm.module_name 
                           FROM assessment_results ar
                           LEFT JOIN course_modules cm ON ar.module = cm.module_key
                           WHERE ar.id = ? AND ar.user_id = ?");
    $stmt->execute([$result_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Try without join if table doesn't exist
    try {
        $stmt = $db->prepare("SELECT * FROM assessment_results WHERE id = ? AND user_id = ?");
        $stmt->execute([$result_id, $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $result['module_name'] = 'Module Assessment';
        }
    } catch (PDOException $e) {
        // Table doesn't exist
    }
}

if (!$result) {
    header('Location: affiliate.php');
    exit();
}

require_once 'includes/header.php';
?>

<style>
body {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    min-height: 100vh;
}

.result-container {
    max-width: 800px;
    margin: 50px auto;
    padding: 0 15px;
}

.result-card {
    background: white;
    border-radius: 20px;
    padding: 50px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
    text-align: center;
}

.result-icon {
    width: 140px;
    height: 140px;
    margin: 0 auto 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 5rem;
}

.result-icon.passed {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    animation: celebrate 0.6s ease;
}

.result-icon.failed {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

@keyframes celebrate {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.result-title {
    font-size: 2.8rem;
    font-weight: 900;
    margin: 0 0 15px 0;
}

.result-title.passed {
    background: linear-gradient(135deg, #10b981, #059669);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.result-title.failed {
    color: #ef4444;
}

.result-subtitle {
    font-size: 1.3rem;
    color: #64748b;
    margin-bottom: 30px;
}

.score-display {
    font-size: 4.5rem;
    font-weight: 900;
    margin: 30px 0;
}

.score-display.passed {
    background: linear-gradient(135deg, #10b981, #059669);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.score-display.failed {
    color: #ef4444;
}

.result-info {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    padding: 30px;
    border-radius: 15px;
    margin: 30px 0;
}

.result-info p {
    margin: 12px 0;
    font-size: 1.15rem;
    color: #475569;
}

.result-info strong {
    color: #1e293b;
    font-weight: 800;
}

.certificate-section {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border: 3px dashed #f59e0b;
    padding: 30px;
    border-radius: 15px;
    margin: 30px 0;
}

.certificate-section h3 {
    color: #92400e;
    font-size: 1.5rem;
    margin: 0 0 15px 0;
}

.certificate-code {
    background: white;
    padding: 20px;
    border-radius: 12px;
    font-family: 'Courier New', monospace;
    font-size: 1.5rem;
    font-weight: 900;
    color: #f59e0b;
    letter-spacing: 2px;
    margin: 15px 0;
}

.download-certificate-btn {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    padding: 18px 40px;
    border-radius: 12px;
    font-size: 1.2rem;
    font-weight: 800;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-top: 15px;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
    transition: all 0.3s ease;
}

.download-certificate-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
}

.retry-message {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    border-left: 5px solid #ef4444;
    padding: 25px;
    border-radius: 12px;
    margin: 30px 0;
    text-align: left;
}

.retry-message h3 {
    color: #991b1b;
    margin: 0 0 15px 0;
}

.retry-message ul {
    color: #7f1d1d;
    margin: 15px 0 15px 25px;
}

.retry-message ul li {
    margin-bottom: 10px;
}

.action-buttons {
    display: flex;
    gap: 15px;
    margin-top: 40px;
}

.btn {
    flex: 1;
    padding: 18px;
    border: none;
    border-radius: 12px;
    font-weight: 800;
    font-size: 1.1rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.3s ease;
}

.btn-primary {
    background: linear-gradient(135deg, #f093fb, #f5576c);
    color: white;
}

.btn-secondary {
    background: #f1f5f9;
    color: #475569;
}

.btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
}

@media (max-width: 768px) {
    .result-card {
        padding: 30px 20px;
    }
    
    .result-title {
        font-size: 2rem;
    }
    
    .score-display {
        font-size: 3rem;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<div class="result-container">
    <div class="result-card">
        <div class="result-icon <?php echo $result['passed'] ? 'passed' : 'failed'; ?>">
            <i class="fas fa-<?php echo $result['passed'] ? 'trophy' : 'times-circle'; ?>"></i>
        </div>
        
        <h1 class="result-title <?php echo $result['passed'] ? 'passed' : 'failed'; ?>">
            <?php echo $result['passed'] ? 'Outstanding!' : 'Not Quite There'; ?>
        </h1>
        
        <p class="result-subtitle">
            <?php echo $result['passed'] ? 'You have successfully completed the assessment!' : 'You need 70% to pass this assessment.'; ?>
        </p>
        
        <div class="score-display <?php echo $result['passed'] ? 'passed' : 'failed'; ?>">
            <?php echo round($result['percentage']); ?>%
        </div>
        
        <div class="result-info">
            <p><strong>Module:</strong> <?php echo htmlspecialchars($result['module_name']); ?></p>
            <p><strong>Score:</strong> <?php echo $result['score']; ?> out of <?php echo $result['total_questions']; ?> correct</p>
            <p><strong>Passing Score:</strong> 70% (21/30 correct)</p>
            <p><strong>Your Score:</strong> <?php echo round($result['percentage']); ?>%</p>
            <p><strong>Status:</strong> 
                <span style="font-size: 1.3rem; color: <?php echo $result['passed'] ? '#10b981' : '#ef4444'; ?>; font-weight: 900;">
                    <?php echo $result['passed'] ? '✓ PASSED' : '✗ FAILED'; ?>
                </span>
            </p>
        </div>
        
        <?php if ($result['passed']): ?>
            <!-- Certificate Section -->
            <div class="certificate-section">
                <h3><i class="fas fa-certificate"></i> Your Certificate</h3>
                <p style="color: #78350f; font-weight: 600;">Congratulations! You've earned a certificate for completing this module.</p>
                <p style="color: #92400e; font-size: 0.9rem; margin-top: 10px;">Certificate Code:</p>
                <div class="certificate-code"><?php echo htmlspecialchars($result['certificate_code']); ?></div>
                <a href="certificate.php?code=<?php echo urlencode($result['certificate_code']); ?>" class="download-certificate-btn">
                    <i class="fas fa-download"></i> Download Certificate
                </a>
            </div>
        <?php else: ?>
            <!-- Retry Message -->
            <div class="retry-message">
                <h3><i class="fas fa-info-circle"></i> How to Improve</h3>
                <p style="font-weight: 600;">Don't give up! Here's what you can do:</p>
                <ul>
                    <li>Review the course materials and lectures</li>
                    <li>Take notes on key concepts</li>
                    <li>Practice with the topic quizzes</li>
                    <li>Study areas where you struggled</li>
                    <li>Retake the assessment when ready</li>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="action-buttons">
            <a href="<?php echo $result['module']; ?>.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Back to Course
            </a>
            <a href="affiliate.php" class="btn btn-secondary">
                <i class="fas fa-dashboard"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
