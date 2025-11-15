<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$response = ['count' => 0];

if (isLoggedIn()) {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $response['count'] = $result['total'] ?: 0;
}

echo json_encode($response);
?>
