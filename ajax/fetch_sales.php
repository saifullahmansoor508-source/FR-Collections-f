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
    // Count confirmed sales for this partner (via affiliate_earnings table)
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT ae.order_id) as confirmed_sales 
        FROM affiliate_earnings ae 
        JOIN orders o ON ae.order_id = o.id 
        WHERE ae.affiliate_id = (SELECT id FROM affiliates WHERE user_id = ?) 
        AND ae.status = 'Confirmed' 
        AND o.status = 'Delivered'
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $confirmed_sales = (int)($result['confirmed_sales'] ?? 0);
    
    // Determine phase and lock status
    $phase = 1;
    $next_phase_locked = true;
    
    if ($confirmed_sales >= 10) {
        $phase = 2;
        $next_phase_locked = false;
    } elseif ($confirmed_sales >= 20) {
        $phase = 2;
        $next_phase_locked = false;
    }
    
    echo json_encode([
        'confirmed_orders' => $confirmed_sales, // Using same key for consistency
        'phase' => $phase,
        'next_phase_locked' => $next_phase_locked
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>
