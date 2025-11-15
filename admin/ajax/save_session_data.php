<?php
/**
 * Save products data to session for use in Step 3 (Image Upload)
 * This allows the image upload system to access variant counts dynamically
 */

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['products'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid data format']);
        exit;
    }
    
    // Store products data in session
    $_SESSION['bulk_import_products'] = $data['products'];
    
    echo json_encode([
        'success' => true, 
        'message' => 'Session data saved',
        'productCount' => count($data['products'])
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Error saving session data: ' . $e->getMessage()
    ]);
}
?>
