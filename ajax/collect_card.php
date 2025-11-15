<?php
session_start();
require_once '../config/database.php';

// Ensure no output before JSON
ob_clean();
header('Content-Type: application/json');

// Initialize database connection
try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error: ' . $e->getMessage()]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = $_SESSION['user_id'];
$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
$card_type = isset($_POST['card_type']) ? $_POST['card_type'] : 'user_order';

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    // Verify order belongs to user or is a partner sale
    if ($card_type === 'user_order') {
        $stmt = $db->prepare("SELECT id, status FROM orders WHERE id = ? AND user_id = ? AND status IN ('Confirmed', 'On The Way', 'Delivered')");
        $stmt->execute([$order_id, $user_id]);
    } else {
        // For partner sales, check if user is affiliate and order has their partner_id
        $stmt = $db->prepare("
            SELECT o.id, o.status 
            FROM orders o 
            INNER JOIN affiliates a ON o.partner_id = a.partner_id 
            WHERE o.id = ? AND a.user_id = ? AND o.status IN ('Confirmed', 'On The Way', 'Delivered')
        ");
        $stmt->execute([$order_id, $user_id]);
    }
    
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found or not eligible for card collection']);
        exit;
    }

    // Check if card already collected
    $stmt = $db->prepare("SELECT id FROM user_card_collections WHERE user_id = ? AND order_id = ? AND card_type = ?");
    $stmt->execute([$user_id, $order_id, $card_type]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Card already collected for this order']);
        exit;
    }

    // Count total collected cards for this user and card type
    $stmt = $db->prepare("SELECT COUNT(*) as total_cards FROM user_card_collections WHERE user_id = ? AND card_type = ? AND is_collected = TRUE");
    $stmt->execute([$user_id, $card_type]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_collected = $result['total_cards'];

    // Calculate phase and position
    $phase_number = floor($total_collected / 10) + 1;
    $card_position = ($total_collected % 10) + 1;

    // Determine card gradient type based on position in phase
    $gradient_type = 'black'; // Default
    if ($card_position >= 1 && $card_position <= 3) {
        $gradient_type = 'black';
    } elseif ($card_position >= 4 && $card_position <= 6) {
        $gradient_type = 'blue';
    } elseif ($card_position >= 7 && $card_position <= 9) {
        $gradient_type = 'silver';
    } elseif ($card_position == 10) {
        $gradient_type = 'golden';
    }

    // Insert card collection record
    $stmt = $db->prepare("
        INSERT INTO user_card_collections (user_id, order_id, card_type, phase_number, card_position, card_gradient_type, is_collected, collected_at) 
        VALUES (?, ?, ?, ?, ?, ?, TRUE, NOW())
    ");
    $stmt->execute([$user_id, $order_id, $card_type, $phase_number, $card_position, $gradient_type]);

    // Update or create phase progress
    $stmt = $db->prepare("
        INSERT INTO user_phase_progress (user_id, phase_number, card_type, cards_collected, is_unlocked, unlocked_at) 
        VALUES (?, ?, ?, 1, TRUE, NOW())
        ON DUPLICATE KEY UPDATE 
        cards_collected = cards_collected + 1,
        is_phase_completed = (cards_collected + 1 >= 10),
        phase_completed_at = CASE WHEN (cards_collected + 1 >= 10) THEN NOW() ELSE phase_completed_at END,
        updated_at = NOW()
    ");
    $stmt->execute([$user_id, $phase_number, $card_type]);

    // Check if phase is completed and unlock next phase
    if ($card_position == 10) {
        $next_phase = $phase_number + 1;
        if ($next_phase <= 20) {
            $stmt = $db->prepare("
                INSERT IGNORE INTO user_phase_progress (user_id, phase_number, card_type, is_unlocked, unlocked_at) 
                VALUES (?, ?, ?, TRUE, NOW())
            ");
            $stmt->execute([$user_id, $next_phase, $card_type]);
        }
    }

    // Calculate remaining cards for current phase
    $remaining_cards = 10 - $card_position;
    $is_phase_completed = ($card_position == 10);

    // Get user's order count for progress message
    if ($card_type === 'user_order') {
        $stmt = $db->prepare("SELECT COUNT(*) as order_count FROM orders WHERE user_id = ? AND status IN ('Confirmed', 'On The Way', 'Delivered')");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $db->prepare("
            SELECT COUNT(*) as order_count 
            FROM orders o 
            INNER JOIN affiliates a ON o.partner_id = a.partner_id 
            WHERE a.user_id = ? AND o.status IN ('Confirmed', 'On The Way', 'Delivered')
        ");
        $stmt->execute([$user_id]);
    }
    $order_count_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_order_number = $order_count_result['order_count'];

    echo json_encode([
        'success' => true,
        'message' => 'Card collected successfully!',
        'data' => [
            'phase_number' => $phase_number,
            'card_position' => $card_position,
            'gradient_type' => $gradient_type,
            'is_phase_completed' => $is_phase_completed,
            'remaining_cards' => $remaining_cards,
            'user_order_number' => $user_order_number,
            'total_collected' => $total_collected + 1
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}

// Ensure clean exit
exit;
?>
