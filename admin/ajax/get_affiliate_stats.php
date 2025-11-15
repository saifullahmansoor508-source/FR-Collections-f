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
    // Get filter parameters (same as in affiliates.php)
    $search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
    $filter_partner = isset($_GET['partner_id']) ? sanitizeInput($_GET['partner_id']) : '';
    $filter_from = isset($_GET['from']) ? sanitizeInput($_GET['from']) : '';
    $filter_to = isset($_GET['to']) ? sanitizeInput($_GET['to']) : '';

    // Build query (same logic as affiliates.php)
    $where_conditions = [];
    $params = [];

    if ($search) {
        $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR a.partner_id LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // Filter by specific partner id
    if ($filter_partner !== '') {
        $where_conditions[] = "a.partner_id = ?";
        $params[] = $filter_partner;
    }

    // Filter by created_at date interval
    if ($filter_from !== '' && $filter_to !== '') {
        $where_conditions[] = "DATE(a.created_at) BETWEEN ? AND ?";
        $params[] = $filter_from;
        $params[] = $filter_to;
    } elseif ($filter_from !== '') {
        $where_conditions[] = "DATE(a.created_at) >= ?";
        $params[] = $filter_from;
    } elseif ($filter_to !== '') {
        $where_conditions[] = "DATE(a.created_at) <= ?";
        $params[] = $filter_to;
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Get all affiliates with their sales data
    $stmt = $db->prepare("
        SELECT a.*, u.full_name, u.email, u.phone,
               COUNT(DISTINCT CASE WHEN o.status IN ('Confirmed', 'On The Way', 'Delivered') THEN o.id END) as total_orders,
               COUNT(DISTINCT CASE WHEN o.status IN ('Confirmed', 'On The Way', 'Delivered') THEN o.id END) as total_sales
        FROM affiliates a 
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN orders o ON o.partner_id = a.partner_id
        $where_clause
        GROUP BY a.id
        ORDER BY a.created_at DESC
    ");
    $stmt->execute($params);
    $affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $total_affiliates = count($affiliates);
    $total_sales_count = 0;
    
    foreach ($affiliates as $affiliate) {
        $total_sales_count += (int)$affiliate['total_sales'];
    }

    // Get available users count
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id NOT IN (SELECT user_id FROM affiliates)");
    $stmt->execute();
    $available_users = $stmt->fetchColumn();

    // Return statistics
    echo json_encode([
        'success' => true,
        'stats' => [
            'totalAffiliates' => $total_affiliates,
            'availableUsers' => $available_users,
            'totalSalesCount' => $total_sales_count,
            'totalSalesCountFormatted' => number_format($total_sales_count),
            'affiliates' => array_map(function($affiliate) {
                return [
                    'id' => $affiliate['id'],
                    'partner_id' => $affiliate['partner_id'],
                    'total_sales' => (int)$affiliate['total_sales'],
                    'total_sales_formatted' => number_format($affiliate['total_sales'])
                ];
            }, $affiliates)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
