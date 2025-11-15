<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized access');
}

$database = new Database();
$db = $database->getConnection();

// Get format
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query
$where_condition = '';
$params = [];

if ($search) {
    $where_condition = "WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ?";
    $search_param = '%' . $search . '%';
    $params = [$search_param, $search_param, $search_param];
}

$stmt = $db->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
           (SELECT SUM(total_amount) FROM orders WHERE user_id = u.id AND status = 'Delivered') as total_spent,
           (SELECT COUNT(*) FROM affiliates WHERE user_id = u.id) as is_affiliate
    FROM users u 
    $where_condition
    ORDER BY u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($format == 'csv') {
    // Export as CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=users_export_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, ['ID', 'Full Name', 'Email', 'Phone', 'Status', 'Is Affiliate', 'Total Orders', 'Total Spent', 'Created At']);
    
    // Add data
    foreach ($users as $user) {
        fputcsv($output, [
            $user['id'],
            $user['full_name'],
            $user['email'],
            $user['phone'] ? $user['phone'] : 'N/A',
            $user['is_blocked'] ? 'Blocked' : 'Active',
            $user['is_affiliate'] ? 'Yes' : 'No',
            $user['total_orders'],
            $user['total_spent'] ? 'PKR ' . number_format($user['total_spent'], 2) : 'PKR 0.00',
            date('Y-m-d H:i:s', strtotime($user['created_at']))
        ]);
    }
    
    fclose($output);
    exit;
} 
elseif ($format == 'pdf') {
    // For PDF export, we'll use a simple HTML to PDF approach
    // You can enhance this with a library like TCPDF or FPDF
    
    require_once '../vendor/autoload.php'; // If you have TCPDF or similar installed
    
    // If TCPDF is not available, create a simple HTML table that can be printed as PDF
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Users Export - <?php echo date('Y-m-d'); ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
            }
            h1 {
                color: #0058A3;
                text-align: center;
                margin-bottom: 30px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th, td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
                font-size: 12px;
            }
            th {
                background-color: #0058A3;
                color: white;
                font-weight: bold;
            }
            tr:nth-child(even) {
                background-color: #f2f2f2;
            }
            .footer {
                margin-top: 30px;
                text-align: center;
                color: #666;
                font-size: 12px;
            }
            @media print {
                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <button class="no-print" onclick="window.print();" style="padding: 10px 20px; background: #0058A3; color: white; border: none; border-radius: 5px; cursor: pointer; margin-bottom: 20px;">
            Print / Save as PDF
        </button>
        
        <h1>Users Export Report</h1>
        <p style="text-align: center; color: #666;">Generated on <?php echo date('F d, Y H:i:s'); ?></p>
        
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Affiliate</th>
                    <th>Orders</th>
                    <th>Total Spent</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : 'N/A'; ?></td>
                    <td><?php echo $user['is_blocked'] ? 'Blocked' : 'Active'; ?></td>
                    <td><?php echo $user['is_affiliate'] ? 'Yes' : 'No'; ?></td>
                    <td><?php echo $user['total_orders']; ?></td>
                    <td><?php echo $user['total_spent'] ? 'PKR ' . number_format($user['total_spent'], 2) : 'PKR 0.00'; ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="footer">
            <p>Total Users: <?php echo count($users); ?></p>
            <p>&copy; <?php echo date('Y'); ?> - Users Management System</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
