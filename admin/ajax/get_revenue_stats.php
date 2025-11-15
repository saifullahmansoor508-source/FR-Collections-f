<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

// Check if user is admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

try {
    // Get filter parameters (same as in orders.php)
    $status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
    $partner_id_filter = isset($_GET['partner_id']) ? sanitizeInput($_GET['partner_id']) : '';
    $search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

    // Build query for filtered orders (for display counts)
    $where_conditions = [];
    $params = [];

    $filtered_query = "
        SELECT o.*, u.full_name as user_name, u.email as user_email
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE 1=1
    ";

    if ($status_filter) {
        $where_conditions[] = "o.status = ?";
        $params[] = $status_filter;
    }

    if ($partner_id_filter) {
        $where_conditions[] = "o.partner_id = ?";
        $params[] = $partner_id_filter;
    }

    if ($search) {
        $where_conditions[] = "(o.order_number LIKE ? OR o.full_name LIKE ? OR o.email LIKE ? OR o.partner_id LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    if (!empty($where_conditions)) {
        $filtered_query .= " AND " . implode(" AND ", $where_conditions);
    }

    $filtered_query .= " ORDER BY o.created_at DESC";

    $stmt = $db->prepare($filtered_query);
    $stmt->execute($params);
    $filtered_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get ALL orders for total revenue calculation (not filtered)
    $all_orders_query = "SELECT * FROM orders";
    $stmt = $db->prepare($all_orders_query);
    $stmt->execute();
    $all_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $totalOrders = count($filtered_orders); // Use filtered orders for display count
    $totalRevenue = 0;
    $pendingCount = 0;
    $confirmedCount = 0;
    $onTheWayCount = 0;
    $deliveredCount = 0;
    $canceledCount = 0;

    // Calculate total revenue from ALL orders (not filtered)
    foreach ($all_orders as $order) {
        if (in_array($order['status'], ['Confirmed', 'On The Way', 'Delivered'])) {
            $totalRevenue += $order['total_amount'];
        }
    }

    // Count filtered orders by status for display
    foreach ($filtered_orders as $order) {
        switch ($order['status']) {
            case 'Pending':
                $pendingCount++;
                break;
            case 'Confirmed':
                $confirmedCount++;
                break;
            case 'On The Way':
                $onTheWayCount++;
                break;
            case 'Delivered':
                $deliveredCount++;
                break;
            case 'Canceled':
                $canceledCount++;
                break;
        }
    }

    // Return statistics
    echo json_encode([
        'success' => true,
        'stats' => [
            'totalOrders' => $totalOrders,
            'totalRevenue' => $totalRevenue,
            'totalRevenueFormatted' => formatPrice($totalRevenue),
            'pendingCount' => $pendingCount,
            'confirmedCount' => $confirmedCount,
            'onTheWayCount' => $onTheWayCount,
            'deliveredCount' => $deliveredCount,
            'canceledCount' => $canceledCount
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
