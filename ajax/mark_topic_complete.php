<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['module']) || !isset($input['topic_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$module = $input['module'];
$topic_id = intval($input['topic_id']);

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get module_id from module_key
    $stmt = $db->prepare("SELECT id FROM course_modules WHERE module_key = ?");
    $stmt->execute([$module]);
    $module_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$module_data) {
        echo json_encode(['success' => false, 'message' => 'Module not found']);
        exit();
    }
    
    $module_id = $module_data['id'];
    
    // Mark topic as complete in completed_topics table (syncs with admin records)
    $stmt = $db->prepare("INSERT INTO completed_topics (user_id, module_id, topic_id, completed_at) 
                          VALUES (?, ?, ?, NOW()) 
                          ON DUPLICATE KEY UPDATE completed_at = NOW()");
    $stmt->execute([$user_id, $module_id, $topic_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Topic marked as complete'
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
