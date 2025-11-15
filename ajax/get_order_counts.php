<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

try {
    // Check if user is an affiliate
    $stmt = $db->prepare("SELECT partner_id FROM affiliates WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_affiliate = $affiliate ? true : false;
    $partner_id = $affiliate ? $affiliate['partner_id'] : null;

    // Count user's confirmed orders (confirmed, on the way, delivered)
    $stmt = $db->prepare("
        SELECT COUNT(*) as user_orders 
        FROM orders 
        WHERE user_id = ? 
        AND status IN ('confirmed', 'on the way', 'delivered')
    ");
    $stmt->execute([$user_id]);
    $user_orders = $stmt->fetchColumn();

    // Count partner's sales (orders where partner_id was used)
    $partner_sales = 0;
    if ($is_affiliate && $partner_id) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as partner_sales 
            FROM orders 
            WHERE partner_id = ? 
            AND status IN ('confirmed', 'on the way', 'delivered')
        ");
        $stmt->execute([$partner_id]);
        $partner_sales = $stmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'user_orders' => (int)$user_orders,
        'partner_sales' => (int)$partner_sales,
        'is_affiliate' => $is_affiliate,
        'partner_id' => $partner_id
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
