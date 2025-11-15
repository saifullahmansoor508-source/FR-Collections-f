<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

// Check admin authentication
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    header('Location: login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';
$preview_data = null;
$import_type = '';

// Fetch modules for dropdown
$modules = [];
try {
    $modules = $db->query("SELECT * FROM course_modules ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Could not fetch modules: " . $e->getMessage();
}

// Function to parse Google Sheets URL
function getCSVDataFromSheets($sheet_url) {
    if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $sheet_url, $matches)) {
        $sheet_id = $matches[1];
        $csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        $csv_data = @file_get_contents($csv_url, false, $context);
        return $csv_data;
    }
    return false;
}

// Handle Import Preview
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['preview'])) {
    $sheet_url = $_POST['sheet_url'] ?? '';
    $import_type = $_POST['import_type'] ?? '';
    
    if (empty($sheet_url) || empty($import_type)) {
        $error = "Please provide Google Sheets URL and select import type.";
    } else {
        $csv_data = getCSVDataFromSheets($sheet_url);
        
        if ($csv_data === false) {
            $error = "<strong>Could not fetch data from Google Sheets.</strong><br><br>
                      <strong>How to fix:</strong><br>
                      1. Open your Google Sheet<br>
                      2. Click Share button (top right)<br>
                      3. Change to 'Anyone with the link can view'<br>
                      4. Copy the URL and try again";
        } else {
            // Parse CSV
            $temp_file = tmpfile();
            fwrite($temp_file, $csv_data);
            rewind($temp_file);
            
            $all_rows = [];
            while (($data = fgetcsv($temp_file)) !== false) {
                $all_rows[] = $data;
            }
            fclose($temp_file);
            
            if (count($all_rows) > 1) {
                $preview_data = [
                    'headers' => $all_rows[0],
                    'rows' => array_slice($all_rows, 1, 10) // Preview first 10 rows
                ];
            } else {
                $error = "No data found in the sheet.";
            }
        }
    }
}

// Handle Actual Import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_import'])) {
    $sheet_url = $_POST['sheet_url'] ?? '';
    $import_type = $_POST['import_type'] ?? '';
    $module_id = $_POST['module_id'] ?? '';
    
    if (empty($sheet_url) || empty($import_type)) {
        $error = "Missing required information.";
    } else {
        $csv_data = getCSVDataFromSheets($sheet_url);
        
        if ($csv_data === false) {
            $error = "Could not fetch data from Google Sheets.";
        } else {
            // Parse CSV
            $temp_file = tmpfile();
            fwrite($temp_file, $csv_data);
            rewind($temp_file);
            
            $all_rows = [];
            while (($data = fgetcsv($temp_file)) !== false) {
                $all_rows[] = $data;
            }
            fclose($temp_file);
            
            // Remove header
            array_shift($all_rows);
            
            try {
                $db->beginTransaction();
                $imported_count = 0;
                
                if ($import_type == 'topics') {
                    // Import Topics
                    // Expected columns: Module, Topic Number, Title, Video EN ID, Video UR ID, Duration (seconds), Type, Sort Order, Status
                    
                    foreach ($all_rows as $row) {
                        if (empty($row) || count($row) < 6) continue;
                        
                        $mod_key = trim($row[0]);
                        $topic_num = intval(trim($row[1]));
                        $title = trim($row[2]);
                        $video_en = trim($row[3] ?? '');
                        $video_ur = trim($row[4] ?? '');
                        $duration = intval(trim($row[5] ?? 0));
                        $type = trim($row[6] ?? 'video');
                        $sort_order = intval(trim($row[7] ?? 0));
                        $status = trim($row[8] ?? 'active');
                        
                        if (empty($title) || $topic_num <= 0) continue;
                        
                        // Get module ID from key
                        $stmt = $db->prepare("SELECT id FROM course_modules WHERE module_key = ?");
                        $stmt->execute([$mod_key]);
                        $mod = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$mod) continue;
                        
                        $mod_id = $mod['id'];
                        
                        // Check for duplicates
                        $checkStmt = $db->prepare("SELECT COUNT(*) FROM course_topics 
                                                   WHERE module_id = ? AND topic_number = ?");
                        $checkStmt->execute([$mod_id, $topic_num]);
                        $exists = $checkStmt->fetchColumn();
                        
                        if ($exists > 0) continue; // Skip duplicates
                        
                        // Insert topic
                        $stmt = $db->prepare("INSERT INTO course_topics 
                                             (module_id, topic_number, title, video_en, video_ur, duration, type, sort_order, status) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$mod_id, $topic_num, $title, $video_en, $video_ur, $duration, $type, $sort_order, $status]);
                        $imported_count++;
                    }
                    
                } elseif ($import_type == 'quizzes') {
                    // Import Quiz Questions
                    // Expected columns: Module, Topic ID, Question, Option A, Option B, Option C, Option D, Correct Answer, Sort Order
                    
                    foreach ($all_rows as $row) {
                        if (empty($row) || count($row) < 9) continue;
                        
                        $module = trim($row[0]);
                        $topic_id = intval(trim($row[1]));
                        $question = trim($row[2]);
                        $option_a = trim($row[3]);
                        $option_b = trim($row[4]);
                        $option_c = trim($row[5]);
                        $option_d = trim($row[6]);
                        $correct = strtoupper(trim($row[7]));
                        $sort_order = intval(trim($row[8] ?? 0));
                        
                        if (empty($question) || empty($option_a) || !in_array($correct, ['A', 'B', 'C', 'D'])) continue;
                        
                        // Check for duplicates
                        $checkStmt = $db->prepare("SELECT COUNT(*) FROM quiz_questions 
                                                   WHERE module = ? AND topic_id = ? AND question = ?");
                        $checkStmt->execute([$module, $topic_id, $question]);
                        $exists = $checkStmt->fetchColumn();
                        
                        if ($exists > 0) continue; // Skip duplicates
                        
                        // Insert quiz question
                        $stmt = $db->prepare("INSERT INTO quiz_questions 
                                             (module, topic_id, question, option_a, option_b, option_c, option_d, correct_answer, sort_order) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$module, $topic_id, $question, $option_a, $option_b, $option_c, $option_d, $correct, $sort_order]);
                        $imported_count++;
                    }
                }
                
                $db->commit();
                $success = "Successfully imported {$imported_count} " . ($import_type == 'topics' ? 'topics' : 'quiz questions') . "!";
                
            } catch (PDOException $e) {
                $db->rollBack();
                $error = "Import failed: " . $e->getMessage();
            }
        }
    }
}

$page_title = "Import Learning Content";
require_once 'includes/header.php';
?>

<style>
/* ========================================
   DESKTOP & MOBILE RESPONSIVE DESIGN
   ======================================== */
.import-container {
    max-width: 1400px;
    margin: 30px auto;
    padding: 20px;
}

.import-card {
    background: white;
    border-radius: 16px;
    padding: 35px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
    margin-bottom: 30px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.import-card:hover {
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
}

/* Glossy overlay on cards */
.import-card::before {
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

.import-card > * {
    position: relative;
    z-index: 1;
}

.import-header {
    text-align: center;
    margin-bottom: 40px;
    padding: 30px 20px;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}

.import-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(
        to bottom,
        rgba(255, 255, 255, 0.6) 0%,
        rgba(255, 255, 255, 0) 100%
    );
    pointer-events: none;
}

.import-header > * {
    position: relative;
    z-index: 1;
}

.import-header h1 {
    font-size: 2.2rem;
    font-weight: 800;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 12px;
    text-shadow: 0 2px 10px rgba(59, 130, 246, 0.1);
}

.import-header p {
    font-size: 1.1rem;
    color: #64748b;
    margin-bottom: 20px;
}

.import-type-selector {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.type-card {
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    border: 3px solid #e2e8f0;
    border-radius: 16px;
    padding: 30px 25px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.type-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 40%;
    background: linear-gradient(
        to bottom,
        rgba(255, 255, 255, 0.6) 0%,
        rgba(255, 255, 255, 0) 100%
    );
    pointer-events: none;
}

.type-card > * {
    position: relative;
    z-index: 1;
}

.type-card:hover {
    border-color: #3b82f6;
    box-shadow: 0 8px 30px rgba(59, 130, 246, 0.2);
    transform: translateY(-5px);
}

.type-card.active {
    border-color: #3b82f6;
    background: linear-gradient(135deg, #dbeafe, #eff6ff);
    box-shadow: 0 8px 30px rgba(59, 130, 246, 0.25);
}

.type-card i {
    font-size: 3.5rem;
    margin-bottom: 18px;
    filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
}

.type-card h3 {
    font-size: 1.3rem;
    font-weight: 700;
    margin: 0;
    color: #1e293b;
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
   SHINY ANIMATED ACTION BUTTONS
   ======================================== */
.action-buttons-container {
    display: flex;
    gap: 15px;
    margin-top: 30px;
    flex-wrap: wrap;
}

.btn {
    padding: 16px 32px;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1.05rem;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: inline-flex;
    align-items: center;
    gap: 12px;
    position: relative;
    overflow: hidden;
}

/* Glossy overlay on buttons */
.btn::before {
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

/* Shine animation on buttons */
.btn::after {
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
    animation: btnShineEffect 3s infinite;
    pointer-events: none;
}

@keyframes btnShineEffect {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

.btn > * {
    position: relative;
    z-index: 1;
}

.btn-primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}

.btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
}

.btn-primary:active {
    transform: translateY(-1px);
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
}

.btn-success:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
}

.btn-success:active {
    transform: translateY(-1px);
}

/* Template Guide Link Button */
.template-guide-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
    padding: 14px 28px;
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: white;
    text-decoration: none;
    border-radius: 12px;
    font-weight: 700;
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.template-guide-btn::before {
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
    pointer-events: none;
}

.template-guide-btn::after {
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
    animation: btnShineEffect 3s infinite;
    pointer-events: none;
}

.template-guide-btn > * {
    position: relative;
    z-index: 1;
}

.template-guide-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
}

.alert {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 600;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #ef4444;
}

.preview-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.preview-table th,
.preview-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.preview-table th {
    background: #f1f5f9;
    font-weight: 700;
    color: #475569;
}

.preview-table tr:hover {
    background: #f8fafc;
}

.template-info {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-radius: 14px;
    padding: 25px;
    margin-bottom: 30px;
    border-left: 5px solid #f59e0b;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.15);
}

.template-info::before {
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

.template-info > * {
    position: relative;
    z-index: 1;
}

.template-info h3 {
    color: #92400e;
    margin: 0 0 15px 0;
    font-size: 1.2rem;
}

.template-info code {
    background: white;
    padding: 4px 10px;
    border-radius: 6px;
    font-family: monospace;
    color: #dc2626;
    font-weight: 600;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.template-info ol {
    line-height: 1.8;
}

/* ========================================
   MOBILE RESPONSIVE DESIGN
   ======================================== */
@media (max-width: 768px) {
    .import-container {
        padding: 12px;
        margin: 15px auto;
    }
    
    .import-card {
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 12px;
    }
    
    .import-header {
        padding: 20px 15px;
        margin-bottom: 25px;
    }
    
    .import-header h1 {
        font-size: 1.6rem;
    }
    
    .import-header p {
        font-size: 0.95rem;
    }
    
    .template-guide-btn {
        padding: 12px 20px;
        font-size: 0.9rem;
        width: 100%;
        justify-content: center;
    }
    
    .import-type-selector {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .type-card {
        padding: 25px 20px;
    }
    
    .type-card i {
        font-size: 2.8rem;
    }
    
    .type-card h3 {
        font-size: 1.1rem;
    }
    
    .type-card p {
        font-size: 0.85rem !important;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        font-size: 1rem;
    }
    
    .form-control {
        padding: 12px 14px;
        font-size: 0.95rem;
    }
    
    .action-buttons-container {
        flex-direction: column;
        gap: 12px;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
        padding: 14px 24px;
        font-size: 1rem;
    }
    
    .template-info {
        padding: 18px;
        font-size: 0.9rem;
    }
    
    .template-info h3 {
        font-size: 1.05rem;
    }
    
    .template-info ol {
        margin: 10px 0 0 15px;
        padding-left: 5px;
    }
    
    .template-info code {
        font-size: 0.85rem;
        padding: 3px 8px;
    }
    
    .preview-table {
        font-size: 0.85rem;
    }
    
    .preview-table th,
    .preview-table td {
        padding: 10px 8px;
    }
    
    .alert {
        padding: 12px 15px;
        font-size: 0.9rem;
    }
}

@media (max-width: 480px) {
    .import-header h1 {
        font-size: 1.4rem;
    }
    
    .type-card i {
        font-size: 2.5rem;
    }
    
    .btn {
        padding: 12px 20px;
        font-size: 0.95rem;
    }
}
</style>

<div class="import-container">
    <div class="import-header">
        <h1><i class="fas fa-file-import"></i> Import Learning Content</h1>
        <p>Bulk import topics, videos, and quiz questions from Google Sheets</p>
        <a href="import-templates-guide.html" target="_blank" class="template-guide-btn">
            <i class="fas fa-table"></i>
            <span>View Visual Template Guide</span>
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="import-card">
        <form method="POST" id="importForm">
            <!-- Import Type Selector -->
            <div class="import-type-selector">
                <div class="type-card" onclick="selectType('topics')">
                    <i class="fas fa-graduation-cap" style="color: #3b82f6;"></i>
                    <h3>Topics & Videos</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin: 10px 0 0 0;">
                        Import course topics with video IDs
                    </p>
                </div>
                
                <div class="type-card" onclick="selectType('quizzes')">
                    <i class="fas fa-clipboard-check" style="color: #8b5cf6;"></i>
                    <h3>Quiz Questions</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin: 10px 0 0 0;">
                        Import quiz questions in bulk
                    </p>
                </div>
            </div>

            <input type="hidden" name="import_type" id="importType" value="<?php echo htmlspecialchars($import_type); ?>">

            <!-- Google Sheets URL -->
            <div class="form-group">
                <label><i class="fab fa-google"></i> Google Sheets URL</label>
                <input type="url" name="sheet_url" class="form-control" 
                       placeholder="https://docs.google.com/spreadsheets/d/..." 
                       value="<?php echo htmlspecialchars($_POST['sheet_url'] ?? ''); ?>" required>
                <small style="color: #64748b;">Make sure your sheet is set to "Anyone with the link can view"</small>
            </div>

            <!-- Module Selector (for topics only) -->
            <div class="form-group" id="moduleSelector" style="display: none;">
                <label><i class="fas fa-folder"></i> Target Module (Optional - for filtering)</label>
                <select name="module_id" class="form-control">
                    <option value="">All Modules</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?php echo $module['id']; ?>">
                            <?php echo htmlspecialchars($module['module_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Template Info -->
            <div class="template-info" id="topicsTemplate" style="display: none;">
                <h3><i class="fas fa-info-circle"></i> Topics Template Format</h3>
                <p><strong>Required Columns (in order):</strong></p>
                <ol style="margin: 10px 0 0 20px; color: #78350f;">
                    <li><code>Module</code> - Module key (e.g., module01, module02)</li>
                    <li><code>Topic Number</code> - Number (1, 2, 3...)</li>
                    <li><code>Title</code> - Topic title</li>
                    <li><code>Video EN ID</code> - YouTube video ID for English</li>
                    <li><code>Video UR ID</code> - YouTube video ID for Urdu (optional)</li>
                    <li><code>Duration</code> - Video duration in seconds</li>
                    <li><code>Type</code> - video, quiz, or assessment</li>
                    <li><code>Sort Order</code> - Display order number</li>
                    <li><code>Status</code> - active or inactive</li>
                </ol>
                <p style="margin-top: 10px;"><a href="https://docs.google.com/spreadsheets/d/YOUR_TEMPLATE_ID/copy" target="_blank" style="color: #2563eb; font-weight: 600;">📄 Copy Template Sheet</a></p>
            </div>

            <div class="template-info" id="quizzesTemplate" style="display: none;">
                <h3><i class="fas fa-info-circle"></i> Quiz Questions Template Format</h3>
                <p><strong>Required Columns (in order):</strong></p>
                <ol style="margin: 10px 0 0 20px; color: #78350f;">
                    <li><code>Module</code> - Module key (e.g., module01)</li>
                    <li><code>Topic ID</code> - Topic number (1, 2, 3...)</li>
                    <li><code>Question</code> - Question text</li>
                    <li><code>Option A</code> - First option</li>
                    <li><code>Option B</code> - Second option</li>
                    <li><code>Option C</code> - Third option</li>
                    <li><code>Option D</code> - Fourth option</li>
                    <li><code>Correct Answer</code> - A, B, C, or D</li>
                    <li><code>Sort Order</code> - Display order number</li>
                </ol>
                <p style="margin-top: 10px;"><a href="https://docs.google.com/spreadsheets/d/YOUR_TEMPLATE_ID/copy" target="_blank" style="color: #2563eb; font-weight: 600;">📄 Copy Template Sheet</a></p>
            </div>

            <div class="action-buttons-container">
                <button type="submit" name="preview" class="btn btn-primary">
                    <i class="fas fa-eye"></i>
                    <span>Preview Data</span>
                </button>
                
                <?php if ($preview_data): ?>
                <button type="submit" name="confirm_import" class="btn btn-success">
                    <i class="fas fa-check"></i>
                    <span>Confirm Import</span>
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Preview Data -->
    <?php if ($preview_data): ?>
    <div class="import-card">
        <h2 style="margin: 0 0 20px 0;"><i class="fas fa-table"></i> Preview (First 10 Rows)</h2>
        <div style="overflow-x: auto;">
            <table class="preview-table">
                <thead>
                    <tr>
                        <?php foreach ($preview_data['headers'] as $header): ?>
                            <th><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_data['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?php echo htmlspecialchars(substr($cell, 0, 50)); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="color: #64748b; margin-top: 15px;">
            <i class="fas fa-info-circle"></i> Showing preview only. Click "Confirm Import" to import all rows.
        </p>
    </div>
    <?php endif; ?>
</div>

<script>
function selectType(type) {
    // Update hidden input
    document.getElementById('importType').value = type;
    
    // Update active state
    document.querySelectorAll('.type-card').forEach(card => {
        card.classList.remove('active');
    });
    event.currentTarget.classList.add('active');
    
    // Show/hide template info
    if (type === 'topics') {
        document.getElementById('topicsTemplate').style.display = 'block';
        document.getElementById('quizzesTemplate').style.display = 'none';
        document.getElementById('moduleSelector').style.display = 'block';
    } else {
        document.getElementById('topicsTemplate').style.display = 'none';
        document.getElementById('quizzesTemplate').style.display = 'block';
        document.getElementById('moduleSelector').style.display = 'none';
    }
}

// Set initial state if import_type is set
<?php if ($import_type): ?>
selectType('<?php echo $import_type; ?>');
const typeCard = document.querySelector('.type-card:nth-child(<?php echo $import_type == 'topics' ? 1 : 2; ?>)');
if (typeCard) typeCard.classList.add('active');
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
