<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in and is admin (you may need to adjust this based on your auth system)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['type_name']) || empty(trim($input['type_name']))) {
    echo json_encode(['success' => false, 'message' => 'Variant type name is required']);
    exit;
}

$typeName = trim($input['type_name']);

// Validate type name
if (strlen($typeName) > 100) {
    echo json_encode(['success' => false, 'message' => 'Variant type name must be 100 characters or less']);
    exit;
}

// Sanitize the type name (remove special characters, keep only letters, numbers, spaces, hyphens)
$typeName = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $typeName);

if (empty($typeName)) {
    echo json_encode(['success' => false, 'message' => 'Invalid variant type name']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if variant type already exists
    $stmt = $db->prepare("SELECT id FROM variant_types WHERE type_name = ?");
    $stmt->execute([$typeName]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Variant type already exists']);
        exit;
    }
    
    // Insert new variant type
    $stmt = $db->prepare("INSERT INTO variant_types (type_name) VALUES (?)");
    $stmt->execute([$typeName]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Variant type added successfully',
        'type_name' => $typeName
    ]);
    
} catch (Exception $e) {
    error_log("Error adding variant type: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
