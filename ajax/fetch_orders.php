<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

try {
    // Count confirmed orders for this user
    $stmt = $db->prepare("SELECT COUNT(*) as confirmed_orders FROM orders WHERE user_id = ? AND status = 'Confirmed'");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $confirmed_orders = (int)$result['confirmed_orders'];
    
    // Determine phase and lock status
    $phase = 1;
    $next_phase_locked = true;
    
    if ($confirmed_orders >= 10) {
        $phase = 2;
        $next_phase_locked = false;
    } elseif ($confirmed_orders >= 20) {
        $phase = 2;
        $next_phase_locked = false;
    }
    
    echo json_encode([
        'confirmed_orders' => $confirmed_orders,
        'phase' => $phase,
        'next_phase_locked' => $next_phase_locked
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>
