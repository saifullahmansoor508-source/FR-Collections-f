<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

// No need for extended timeout for simple DB operations
set_time_limit(10);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = ['success' => false, 'message' => '', 'review' => null];

try {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $product_id = intval($_POST['product_id']);
        $user_name = sanitizeInput($_POST['user_name']);
        $rating = intval($_POST['rating']);
        $review_text = sanitizeInput($_POST['review_text']);
        
        if ($product_id <= 0 || empty($user_name) || $rating < 1 || $rating > 5 || empty($review_text)) {
            $response['message'] = 'All fields are required and rating must be between 1-5';
            echo json_encode($response);
            exit;
        }
        
        $database = new Database();
        $db = $database->getConnection();
        
        if (!$db) {
            $response['message'] = 'Database connection failed';
            echo json_encode($response);
            exit;
        }
        
        // Check if product exists
        $stmt = $db->prepare("SELECT id FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        
        if (!$stmt->fetch()) {
            $response['message'] = 'Product not found';
            echo json_encode($response);
            exit;
        }
        
        // Insert review with auto-approval
        $stmt = $db->prepare("INSERT INTO reviews (product_id, user_name, rating, review_text, is_approved) VALUES (?, ?, ?, ?, 1)");
        
        if ($stmt->execute([$product_id, $user_name, $rating, $review_text])) {
            // Get the inserted review data
            $review_id = $db->lastInsertId();
            
            // Get the review data with formatted date
            $stmt = $db->prepare("SELECT *, DATE_FORMAT(created_at, '%d %M %Y') as formatted_date FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Format the review data for frontend
            $formattedReview = [
                'id' => $review['id'],
                'user_name' => $review['user_name'],
                'rating' => $review['rating'],
                'review_text' => $review['review_text'],
                'date' => $review['formatted_date']
            ];
            
            $response['success'] = true;
            $response['message'] = 'Review submitted successfully! Thank you for your feedback.';
            $response['review'] = $formattedReview;
        } else {
            $response['message'] = 'Error submitting review';
        }
    } else {
        $response['message'] = 'Invalid request method';
    }
} catch (Exception $e) {
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
?>