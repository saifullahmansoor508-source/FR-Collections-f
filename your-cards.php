<?php
require_once 'config/config.php';

if (!isLoggedIn()) {
    redirectTo('auth.php');
}

$page_title = "Your Cards";
require_once 'includes/header.php';

// Get user details
$user_id = $_SESSION['user_id'];

// Check if user is affiliate
$stmt = $db->prepare("SELECT * FROM affiliates WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
$is_affiliate = !empty($affiliate);

// DIRECT DATABASE CLEANUP - Remove invalid card collections immediately
try {
    // Clean up user_order cards that don't have valid orders
    $stmt = $db->prepare("
        DELETE ucc FROM user_card_collections ucc 
        LEFT JOIN orders o ON ucc.order_id = o.id AND o.user_id = ? AND o.status IN ('Confirmed', 'On The Way', 'Delivered')
        WHERE ucc.user_id = ? AND ucc.card_type = 'user_order' AND o.id IS NULL
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    
    // Clean up partner_order cards that don't have valid orders
    if ($is_affiliate) {
        $stmt = $db->prepare("
            DELETE ucc FROM user_card_collections ucc 
            LEFT JOIN orders o ON ucc.order_id = o.id AND o.status IN ('Confirmed', 'On The Way', 'Delivered')
            LEFT JOIN affiliates a ON o.partner_id = a.partner_id AND a.user_id = ?
            WHERE ucc.user_id = ? AND ucc.card_type = 'partner_order' AND (o.id IS NULL OR a.user_id IS NULL)
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    }
    
    // Clear all phase progress and rebuild from scratch
    $stmt = $db->prepare("DELETE FROM user_phase_progress WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    // Rebuild phase progress for user_order cards
    $stmt = $db->prepare("SELECT * FROM user_card_collections WHERE user_id = ? AND card_type = 'user_order' AND is_collected = TRUE ORDER BY collected_at ASC");
    $stmt->execute([$_SESSION['user_id']]);
    $user_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $user_phases = [];
    foreach ($user_cards as $index => $card) {
        $new_phase = floor($index / 10) + 1;
        $new_position = ($index % 10) + 1;
        
        // Update card with correct phase and position
        $stmt = $db->prepare("UPDATE user_card_collections SET phase_number = ?, card_position = ? WHERE id = ?");
        $stmt->execute([$new_phase, $new_position, $card['id']]);
        
        if (!isset($user_phases[$new_phase])) {
            $user_phases[$new_phase] = 0;
        }
        $user_phases[$new_phase]++;
    }
    
    // Create phase progress records for user cards
    foreach ($user_phases as $phase_number => $cards_collected) {
        $is_completed = ($cards_collected >= 10);
        $stmt = $db->prepare("
            INSERT INTO user_phase_progress (user_id, phase_number, card_type, cards_collected, is_unlocked, is_phase_completed, unlocked_at, phase_completed_at) 
            VALUES (?, ?, 'user_order', ?, TRUE, ?, NOW(), ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'], 
            $phase_number, 
            $cards_collected, 
            $is_completed,
            $is_completed ? date('Y-m-d H:i:s') : null
        ]);
    }
    
    // Ensure Phase 1 exists for user cards
    if (empty($user_phases)) {
        $stmt = $db->prepare("
            INSERT INTO user_phase_progress (user_id, phase_number, card_type, cards_collected, is_unlocked, unlocked_at) 
            VALUES (?, 1, 'user_order', 0, TRUE, NOW())
        ");
        $stmt->execute([$_SESSION['user_id']]);
    }
    
    // Rebuild phase progress for partner_order cards if affiliate
    if ($is_affiliate) {
        $stmt = $db->prepare("SELECT * FROM user_card_collections WHERE user_id = ? AND card_type = 'partner_order' AND is_collected = TRUE ORDER BY collected_at ASC");
        $stmt->execute([$_SESSION['user_id']]);
        $partner_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $partner_phases = [];
        foreach ($partner_cards as $index => $card) {
            $new_phase = floor($index / 10) + 1;
            $new_position = ($index % 10) + 1;
            
            // Update card with correct phase and position
            $stmt = $db->prepare("UPDATE user_card_collections SET phase_number = ?, card_position = ? WHERE id = ?");
            $stmt->execute([$new_phase, $new_position, $card['id']]);
            
            if (!isset($partner_phases[$new_phase])) {
                $partner_phases[$new_phase] = 0;
            }
            $partner_phases[$new_phase]++;
        }
        
        // Create phase progress records for partner cards
        foreach ($partner_phases as $phase_number => $cards_collected) {
            $is_completed = ($cards_collected >= 10);
            $stmt = $db->prepare("
                INSERT INTO user_phase_progress (user_id, phase_number, card_type, cards_collected, is_unlocked, is_phase_completed, unlocked_at, phase_completed_at) 
                VALUES (?, ?, 'partner_order', ?, TRUE, ?, NOW(), ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                $phase_number, 
                $cards_collected, 
                $is_completed,
                $is_completed ? date('Y-m-d H:i:s') : null
            ]);
        }
        
        // Ensure Phase 1 exists for partner cards
        if (empty($partner_phases)) {
            $stmt = $db->prepare("
                INSERT INTO user_phase_progress (user_id, phase_number, card_type, cards_collected, is_unlocked, unlocked_at) 
                VALUES (?, 1, 'partner_order', 0, TRUE, NOW())
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
    }
    
} catch (Exception $e) {
    error_log("Database cleanup error: " . $e->getMessage());
}

// Get active tab (default to user)
$active_tab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'user';

// Validate tab
if (!in_array($active_tab, ['user', 'partner'])) {
    $active_tab = 'user';
}

// If not affiliate and trying to access partner tab, redirect to user tab
if (!$is_affiliate && $active_tab === 'partner') {
    redirectTo('your-cards.php?tab=user');
}

// Get user's card collections and phase progress
function getUserCardData($db, $user_id, $card_type) {
    // Get collected cards
    $stmt = $db->prepare("
        SELECT * FROM user_card_collections 
        WHERE user_id = ? AND card_type = ? AND is_collected = TRUE
        ORDER BY phase_number, card_position
    ");
    $stmt->execute([$user_id, $card_type]);
    $collected_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get phase progress
    $stmt = $db->prepare("
        SELECT * FROM user_phase_progress 
        WHERE user_id = ? AND card_type = ?
        ORDER BY phase_number
    ");
    $stmt->execute([$user_id, $card_type]);
    $phase_progress = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to associative arrays for easier access
    $cards_by_phase = [];
    $progress_by_phase = [];
    
    foreach ($collected_cards as $card) {
        $phase = $card['phase_number'];
        if (!isset($cards_by_phase[$phase])) {
            $cards_by_phase[$phase] = [];
        }
        $cards_by_phase[$phase][$card['card_position']] = $card;
    }
    
    foreach ($phase_progress as $progress) {
        $progress_by_phase[$progress['phase_number']] = $progress;
    }
    
    return [
        'cards' => $cards_by_phase,
        'progress' => $progress_by_phase
    ];
}

$user_data = getUserCardData($db, $user_id, 'user_order');
$partner_data = $is_affiliate ? getUserCardData($db, $user_id, 'partner_sale') : ['cards' => [], 'progress' => []];

// Get total counts for stats
$stmt = $db->prepare("SELECT COUNT(*) as count FROM user_card_collections WHERE user_id = ? AND card_type = 'user_order' AND is_collected = TRUE");
$stmt->execute([$user_id]);
$user_total_cards = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$partner_total_cards = 0;
if ($is_affiliate) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM user_card_collections WHERE user_id = ? AND card_type = 'partner_sale' AND is_collected = TRUE");
    $stmt->execute([$user_id]);
    $partner_total_cards = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}
?>

<style>
/* Your Cards Page Styles */
.your-cards-container {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 40px 0;
}

.your-cards-header {
    text-align: center;
    color: white;
    margin-bottom: 40px;
}

.your-cards-title {
    font-size: 3rem;
    font-weight: 700;
    margin-bottom: 10px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

.your-cards-subtitle {
    font-size: 1.2rem;
    opacity: 0.9;
    margin-bottom: 30px;
}

.cards-stats {
    display: flex;
    justify-content: center;
    gap: 40px;
    margin-bottom: 40px;
}

.stat-item {
    text-align: center;
    color: white;
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 1rem;
    opacity: 0.8;
}

.cards-tabs {
    display: flex;
    justify-content: center;
    margin-bottom: 40px;
}

.tab-button {
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.2);
    padding: 15px 30px;
    margin: 0 10px;
    border-radius: 50px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 10px;
}

.tab-button:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-2px);
    color: white;
    text-decoration: none;
}

.tab-button.active {
    background: rgba(255, 255, 255, 0.3);
    border-color: rgba(255, 255, 255, 0.5);
    box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
}

.tab-button.locked {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

/* Horizontal Phases Container */
.phases-container {
    position: relative;
    max-width: 100%;
    margin: 0 auto;
    padding: 0 20px;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.3) rgba(255, 255, 255, 0.1);
}

/* Custom Scrollbar for Desktop */
.phases-container::-webkit-scrollbar {
    height: 12px;
}

.phases-container::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    margin: 0 20px;
}

.phases-container::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0.5) 100%);
    border-radius: 10px;
    border: 2px solid rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
}

.phases-container::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.4) 0%, rgba(255, 255, 255, 0.6) 100%);
    box-shadow: 0 2px 8px rgba(255, 255, 255, 0.2);
}

.phases-slider {
    display: flex;
    transition: transform 0.3s ease;
    gap: 30px;
}

.phase-section {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    padding: 20px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    min-width: 320px;
    max-width: 320px;
    height: auto;
    flex-shrink: 0;
}

.phase-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    color: white;
}

.phase-title {
    font-size: 1.5rem;
    font-weight: 700;
}

.phase-progress {
    font-size: 1rem;
    opacity: 0.8;
}

.phase-locked {
    opacity: 0.6;
    position: relative;
}

.phase-locked::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 20px;
    z-index: 1;
}

.lock-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 2;
    text-align: center;
    color: white;
}

.lock-icon {
    font-size: 3rem;
    margin-bottom: 10px;
}

.lock-text {
    font-size: 1.1rem;
    font-weight: 600;
}

/* Remove arrow styles - using scrollbar instead */

/* Cards Grid Layout - FORCE 3 CARDS PER ROW */
.cards-grid {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr) !important;
    grid-template-rows: repeat(4, 1fr) !important;
    gap: 8px !important;
    margin-top: 15px !important;
    width: 100% !important;
}

/* Center the 10th card (last card) in its row */
.collection-card:nth-child(10) {
    grid-column: 2 / 3 !important;
    grid-row: 4 / 5 !important;
}

.collection-card {
    aspect-ratio: 1.6/1 !important;
    min-height: 50px !important;
    max-height: 70px !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
    width: 100% !important;
}

.collection-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2) !important;
}

.collection-card.locked {
    opacity: 0.6 !important;
    cursor: not-allowed !important;
}

.collection-card .lock-icon {
    font-size: 1.2rem !important;
    color: rgba(255, 255, 255, 0.7) !important;
}

.collection-card .lock-icon i {
    font-size: 1.2rem !important;
}

/* Collected Card Green Gradient */
.collected-card {
    background: linear-gradient(135deg, #10b981 0%, #34d399 25%, #6ee7b7 50%, #34d399 75%, #10b981 100%) !important;
    background-size: 200% 200% !important;
    animation: greenShimmer 3s ease-in-out infinite !important;
    box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4), 
                0 0 30px rgba(52, 211, 153, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.3) !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    position: relative !important;
    overflow: hidden !important;
}

.collected-card::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.2) 50%, transparent 70%);
    animation: collectShimmer 2s linear infinite;
    pointer-events: none;
}

.collected-card:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 30px rgba(16, 185, 129, 0.6), 
                0 0 40px rgba(52, 211, 153, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.4) !important;
}

@keyframes greenShimmer {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

@keyframes collectShimmer {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

/* Card Gradient Colors by Position */
.card-gradient-black {
    background: linear-gradient(135deg, #1f2937 0%, #374151 25%, #4b5563 50%, #374151 75%, #1f2937 100%) !important;
    background-size: 200% 200% !important;
    animation: blackShimmer 3s ease-in-out infinite !important;
    box-shadow: 0 4px 20px rgba(31, 41, 55, 0.4), 
                0 0 30px rgba(75, 85, 99, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2) !important;
}

.card-gradient-blue {
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 25%, #60a5fa 50%, #3b82f6 75%, #1e40af 100%) !important;
    background-size: 200% 200% !important;
    animation: blueShimmer 3s ease-in-out infinite !important;
    box-shadow: 0 4px 20px rgba(30, 64, 175, 0.4), 
                0 0 30px rgba(59, 130, 246, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2) !important;
}

.card-gradient-silver {
    background: linear-gradient(135deg, #6b7280 0%, #9ca3af 25%, #d1d5db 50%, #9ca3af 75%, #6b7280 100%) !important;
    background-size: 200% 200% !important;
    animation: silverShimmer 3s ease-in-out infinite !important;
    box-shadow: 0 4px 20px rgba(107, 114, 128, 0.4), 
                0 0 30px rgba(156, 163, 175, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.3) !important;
}

.card-gradient-golden {
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 25%, #fcd34d 50%, #f59e0b 75%, #fbbf24 100%) !important;
    background-size: 200% 200% !important;
    animation: goldenShimmer 3s ease-in-out infinite !important;
    box-shadow: 0 4px 20px rgba(251, 191, 36, 0.4), 
                0 0 30px rgba(245, 158, 11, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.3) !important;
    color: #92400e !important;
}

@keyframes blackShimmer {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

@keyframes blueShimmer {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

@keyframes silverShimmer {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

@keyframes goldenShimmer {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}


/* Credit Card Preview Styles */
.credit-card-preview {
    width: 340px;
    height: 214px;
    aspect-ratio: 1.586;
    border-radius: 12px;
    padding: 20px;
    color: white;
    position: relative;
    overflow: hidden;
    margin: 0 auto;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.credit-card-preview::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
    animation: shimmer 3s linear infinite;
    pointer-events: none;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.card-logo i {
    font-size: 2rem;
    opacity: 0.8;
}

.card-type {
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.9;
}

.card-number {
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: 3px;
    margin-bottom: 20px;
    font-family: 'Courier New', monospace;
}

.card-details {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
}

.card-info {
    text-align: left;
}

.card-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.7;
    margin-bottom: 4px;
}

.card-value {
    font-size: 0.9rem;
    font-weight: 600;
}

.card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.8rem;
    opacity: 0.8;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .credit-card-preview {
        width: 280px;
        height: 176px;
        aspect-ratio: 1.586;
        padding: 16px;
    }
    
    .card-number {
        font-size: 1.1rem;
        letter-spacing: 2px;
    }
    
    .card-logo i {
        font-size: 1.3rem;
    }
    
    .card-type {
        font-size: 0.8rem;
    }
    
    .card-value {
        font-size: 0.8rem;
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .your-cards-title {
        font-size: 2rem;
    }
    
    .cards-stats {
        flex-direction: row;
        gap: 30px;
        justify-content: center;
        margin-bottom: 25px;
    }
    
    .stat-number {
        font-size: 1.8rem;
    }
    
    .stat-label {
        font-size: 0.9rem;
    }
    
    .cards-tabs {
        flex-direction: row;
        justify-content: center;
        gap: 15px;
        margin-bottom: 30px;
    }
    
    .tab-button {
        margin: 0;
        padding: 12px 20px;
        font-size: 0.9rem;
        flex: 1;
        max-width: 150px;
        justify-content: center;
    }
    
    .phases-container {
        padding: 0 20px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    
    .phases-container::-webkit-scrollbar {
        display: none;
    }
    
    .phase-section {
        min-width: calc(100vw - 80px);
        max-width: calc(100vw - 80px);
        margin: 0 10px;
        padding: 15px;
        flex-shrink: 0;
    }
    
    .phase-header {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    
    /* Mobile Cards Grid - FORCE 3 cards per row */
    .cards-grid {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        grid-template-rows: repeat(4, 1fr) !important;
        gap: 6px !important;
        width: 100% !important;
    }
    
    /* Center the 10th card on mobile too */
    .collection-card:nth-child(10) {
        grid-column: 2 / 3 !important;
        grid-row: 4 / 5 !important;
    }
    
    .collection-card {
        height: 50px !important;
        max-height: 50px !important;
        width: 100% !important;
        font-size: 0.7rem !important;
        padding: 4px !important;
    }
    
    .collection-card .lock-icon {
        font-size: 1rem !important;
    }
    
    .collection-card .lock-icon i {
        font-size: 1rem !important;
    }
}
</style>

<div class="your-cards-container">
    <div class="container">
        <!-- Header -->
        <div class="your-cards-header">
            <h1 class="your-cards-title">Your Cards Collection</h1>
            <p class="your-cards-subtitle">Collect cards by completing orders and unlock amazing rewards!</p>
            
            <!-- Stats -->
            <div class="cards-stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $user_total_cards; ?></div>
                    <div class="stat-label">User Cards</div>
                </div>
                <?php if ($is_affiliate): ?>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $partner_total_cards; ?></div>
                    <div class="stat-label">Partner Cards</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="cards-tabs">
            <a href="your-cards.php?tab=user" class="tab-button <?php echo $active_tab === 'user' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i>
                User Cards
            </a>
            <a href="your-cards.php?tab=partner" class="tab-button <?php echo $active_tab === 'partner' ? 'active' : ''; ?> <?php echo !$is_affiliate ? 'locked' : ''; ?>">
                <i class="fas fa-<?php echo $is_affiliate ? 'handshake' : 'lock'; ?>"></i>
                Partner Cards
                <?php if (!$is_affiliate): ?>
                <small>(Affiliate Only)</small>
                <?php endif; ?>
            </a>
        </div>
        
        <!-- Phases -->
        <div class="phases-container">
            
            <div class="phases-slider" id="phasesSlider">
                <?php
                $current_data = $active_tab === 'user' ? $user_data : $partner_data;
                
                for ($phase = 1; $phase <= 20; $phase++):
                    $phase_progress = isset($current_data['progress'][$phase]) ? $current_data['progress'][$phase] : null;
                    $is_unlocked = $phase_progress && $phase_progress['is_unlocked'];
                    $cards_collected = $phase_progress ? $phase_progress['cards_collected'] : 0;
                    $is_completed = $phase_progress && $phase_progress['is_phase_completed'];
                    
                    // Phase 1 is always unlocked
                    if ($phase === 1) {
                        $is_unlocked = true;
                    }
                ?>
                <div class="phase-section <?php echo !$is_unlocked ? 'phase-locked' : ''; ?>">
                    <?php if (!$is_unlocked): ?>
                    <div class="lock-overlay">
                        <div class="lock-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="lock-text">Complete Phase <?php echo $phase - 1; ?> to unlock</div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="phase-header">
                        <div class="phase-title">
                            <i class="fas fa-layer-group"></i>
                            Phase <?php echo $phase; ?>
                            <?php if ($is_completed): ?>
                            <i class="fas fa-check-circle" style="color: #10b981; margin-left: 10px;"></i>
                            <?php endif; ?>
                        </div>
                        <div class="phase-progress">
                            <?php echo $cards_collected; ?>/10 Cards Collected
                        </div>
                    </div>
                    
                    <div class="cards-grid">
                        <?php for ($position = 1; $position <= 10; $position++):
                            $card = isset($current_data['cards'][$phase][$position]) ? $current_data['cards'][$phase][$position] : null;
                            $is_collected = !empty($card);
                            
                            // Determine gradient type based on position
                            $gradient_type = 'black';
                            if ($position >= 1 && $position <= 3) {
                                $gradient_type = 'black';
                            } elseif ($position >= 4 && $position <= 6) {
                                $gradient_type = 'blue';
                            } elseif ($position >= 7 && $position <= 9) {
                                $gradient_type = 'silver';
                            } elseif ($position == 10) {
                                $gradient_type = 'golden';
                            }
                        ?>
                        <div class="collection-card <?php echo $is_collected ? 'collected-card' : 'card-gradient-' . $gradient_type; ?> <?php echo !$is_collected ? 'locked' : ''; ?>" 
                             <?php if ($is_collected): ?>
                             onclick="showCardDetails(<?php echo htmlspecialchars(json_encode($card), ENT_QUOTES, 'UTF-8'); ?>)"
                             style="cursor: pointer;"
                             <?php endif; ?>>
                            <?php if (!$is_collected): ?>
                            <div class="lock-icon">
                                <i class="fas fa-lock"></i>
                            </div>
                            <?php else: ?>
                            <div style="padding: 8px; height: 100%; display: flex; flex-direction: column; justify-content: center; text-align: center;">
                                <div style="font-size: 0.8rem; font-weight: 700; margin-bottom: 2px;">
                                    #<?php echo $position; ?>
                                </div>
                                <div style="font-size: 0.6rem; opacity: 0.8;">
                                    P<?php echo $phase; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
</div>

<!-- Card Details Modal -->
<div id="cardDetailsModal" class="card-collection-modal">
    <div class="congratulations-card">
        <div class="card-icon">
            <i class="fas fa-credit-card"></i>
        </div>
        <h2 id="cardTitle">Card Details</h2>
        <div id="cardContent"></div>
        <button class="close-modal-btn" onclick="closeCardDetailsModal()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>

<script>
let currentPhaseIndex = 0;
const totalPhases = 20;

function showCardDetails(card) {
    // Create the same modal as congratulations modal but for card details
    const existingModal = document.querySelector('.card-collection-modal');
    if (existingModal) {
        existingModal.remove();
    }
    
    const modal = document.createElement('div');
    modal.className = 'card-collection-modal';
    
    const gradientClass = card.card_gradient_type === 'golden' ? 'congratulations-card golden-card' : 'congratulations-card';
    const cardDate = new Date(card.collected_at).toLocaleDateString();
    
    modal.innerHTML = `
        <div class="${gradientClass}">
            <div class="card-icon">
                <i class="fas fa-credit-card"></i>
            </div>
            <h2>Card #${card.card_position} - Phase ${card.phase_number}</h2>
            <div class="credit-card-preview card-gradient-${card.card_gradient_type}">
                <div class="card-header">
                    <div class="card-logo">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="card-type">${card.card_gradient_type.charAt(0).toUpperCase() + card.card_gradient_type.slice(1)} Card</div>
                </div>
                <div class="card-number">
                    <span>**** **** **** ${String(card.card_position).padStart(4, '0')}</span>
                </div>
                <div class="card-details">
                    <div class="card-info">
                        <div class="card-label">Phase</div>
                        <div class="card-value">${card.phase_number}</div>
                    </div>
                    <div class="card-info">
                        <div class="card-label">Collected</div>
                        <div class="card-value">${cardDate}</div>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="order-info">Order #${card.order_id}</div>
                    <div class="card-position">Card ${card.card_position}/10</div>
                </div>
            </div>
            <button class="close-modal-btn" onclick="closeCongratulationsModal(this.closest('.card-collection-modal'))">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Show modal with animation
    setTimeout(() => {
        modal.classList.add('show');
    }, 100);
}

// Add the close function for congratulations modal
function closeCongratulationsModal(modal) {
    modal.classList.remove('show');
    setTimeout(() => {
        if (modal.parentNode) {
            modal.parentNode.removeChild(modal);
        }
    }, 300);
}

function closeCardDetailsModal() {
    const modal = document.getElementById('cardDetailsModal');
    modal.classList.remove('show');
}

// Remove arrow functionality - using scrollbar navigation instead

// Native scrolling - no custom swipe needed


// Close modal when clicking outside
document.getElementById('cardDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCardDetailsModal();
    }
});
</script>

<!-- Card Collection System Styles -->
<link rel="stylesheet" href="assets/css/card-collection.css">

<?php require_once 'includes/footer.php'; ?>
