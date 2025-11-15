<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (!isLoggedIn()) {
    $response['message'] = 'Please login first';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount = floatval($_POST['amount']);
    $method = sanitizeInput($_POST['method']);
    $account_number = sanitizeInput($_POST['account_number']);
    
    if ($amount < 100) {
        $response['message'] = 'Minimum withdrawal amount is PKR 100';
        echo json_encode($response);
        exit;
    }
    
    if (empty($method) || empty($account_number)) {
        $response['message'] = 'All fields are required';
        echo json_encode($response);
        exit;
    }
    
    // Validate account number format (should be +92 followed by 10 digits)
    if (!preg_match('/^\+92\d{10}$/', $account_number)) {
        $response['message'] = 'Please enter a valid account number (+92 followed by 10 digits)';
        echo json_encode($response);
        exit;
    }
    
    // Validate payment method
    $valid_methods = ['JazzCash', 'Easypaisa', 'Upaisa'];
    if (!in_array($method, $valid_methods)) {
        $response['message'] = 'Invalid payment method';
        echo json_encode($response);
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if user is an affiliate
    $stmt = $db->prepare("SELECT * FROM affiliates WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$affiliate) {
        $response['message'] = 'You are not an affiliate member';
        echo json_encode($response);
        exit;
    }
    
    // Calculate REAL available balance dynamically from orders
    $stmt = $db->prepare("
        SELECT DISTINCT o.id
        FROM orders o
        WHERE o.partner_id = ? 
        AND o.status IN ('Confirmed', 'On The Way', 'Delivered')
    ");
    $stmt->execute([$affiliate['partner_id']]);
    $confirmed_orders = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $available_balance = 0;
    foreach ($confirmed_orders as $order_id) {
        $stmt = $db->prepare("
            SELECT SUM(commission_amount) as order_commission
            FROM order_items
            WHERE order_id = ?
            AND commission_amount > 0
        ");
        $stmt->execute([$order_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['order_commission']) {
            $available_balance += floatval($result['order_commission']);
        }
    }
    
    // Subtract already withdrawn amount
    $stmt = $db->prepare("
        SELECT SUM(amount) as total_withdrawn 
        FROM withdrawals 
        WHERE user_id = ? AND status = 'Completed'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $withdrawn_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_withdrawn = $withdrawn_data['total_withdrawn'] ? floatval($withdrawn_data['total_withdrawn']) : 0;
    
    $available_balance -= $total_withdrawn;
    
    // Check if sufficient balance
    if ($available_balance < $amount) {
        $response['message'] = 'Insufficient balance. Available: PKR ' . number_format($available_balance, 2);
        echo json_encode($response);
        exit;
    }
    
    try {
        // Create withdrawal request (NO balance update in affiliates table)
        $stmt = $db->prepare("INSERT INTO withdrawals (user_id, amount, method, account_number, status, created_at) VALUES (?, ?, ?, ?, 'Pending', NOW())");
        $stmt->execute([$_SESSION['user_id'], $amount, $method, $account_number]);
        
        $response['success'] = true;
        $response['message'] = 'Withdrawal request submitted successfully';
        
    } catch (Exception $e) {
        error_log('Withdrawal Error: ' . $e->getMessage());
        $response['message'] = 'Error processing withdrawal request: ' . $e->getMessage();
    }
}

echo json_encode($response);
?>
