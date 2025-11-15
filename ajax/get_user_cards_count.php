<?php
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

try {
    $user_id = $_SESSION['user_id'];
    
    // Get total collected cards for the user
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM user_card_collections WHERE user_id = ? AND is_collected = TRUE");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $count = $result['count'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'count' => intval($count)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching card count: ' . $e->getMessage()
    ]);
}
?>
