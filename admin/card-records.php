<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/config.php';
require_once '../config/database.php';

// Check if admin is logged in (using the same method as other admin pages)
$admin_emails = ADMIN_EMAILS;
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], $admin_emails)) {
    header('Location: login.php');
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

$page_title = "Card Records";

// Check if card collection tables exist
try {
    $stmt = $db->query("SHOW TABLES LIKE 'user_card_collections'");
    $table_exists = $stmt->rowCount() > 0;
    
    if (!$table_exists) {
        echo "<div class='container-fluid mt-4'>";
        echo "<div class='alert alert-warning'>";
        echo "<h4><i class='fas fa-exclamation-triangle'></i> Card Collection System Not Set Up</h4>";
        echo "<p>The card collection tables don't exist in the database. Please run the setup first.</p>";
        echo "<a href='../setup_card_collection.php' class='btn btn-primary' target='_blank'>";
        echo "<i class='fas fa-cog'></i> Setup Card Collection System";
        echo "</a>";
        echo "</div>";
        echo "</div>";
        require_once 'includes/footer.php';
        exit;
    }
} catch (Exception $e) {
    echo "<div class='container-fluid mt-4'>";
    echo "<div class='alert alert-danger'>";
    echo "<h4><i class='fas fa-times-circle'></i> Database Error</h4>";
    echo "<p>Error checking tables: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
    echo "</div>";
    require_once 'includes/footer.php';
    exit;
}

require_once 'includes/header.php';

// Get filter parameters
$user_filter = isset($_GET['user_filter']) ? $_GET['user_filter'] : 'all';
$card_type_filter = isset($_GET['card_type']) ? $_GET['card_type'] : 'all';
$phase_filter = isset($_GET['phase']) ? intval($_GET['phase']) : 0;

// Build query conditions
$conditions = ["ucc.is_collected = TRUE"];
$params = [];

if ($user_filter === 'affiliates') {
    $conditions[] = "a.id IS NOT NULL";
} elseif ($user_filter === 'normal') {
    $conditions[] = "a.id IS NULL";
}

if ($card_type_filter !== 'all') {
    $conditions[] = "ucc.card_type = ?";
    $params[] = $card_type_filter;
}

if ($phase_filter > 0) {
    $conditions[] = "ucc.phase_number = ?";
    $params[] = $phase_filter;
}

$where_clause = implode(' AND ', $conditions);

// Get card records with user details
$query = "
    SELECT 
        ucc.*,
        u.full_name,
        u.email,
        o.order_number,
        o.total_amount,
        o.status as order_status,
        CASE WHEN a.id IS NOT NULL THEN 'Affiliate' ELSE 'Normal User' END as user_type
    FROM user_card_collections ucc
    INNER JOIN users u ON ucc.user_id = u.id
    INNER JOIN orders o ON ucc.order_id = o.id
    LEFT JOIN affiliates a ON u.id = a.user_id
    WHERE {$where_clause}
    ORDER BY ucc.collected_at DESC
    LIMIT 100
";

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $card_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "<div class='container-fluid mt-4'>";
    echo "<div class='alert alert-danger'>";
    echo "<h4><i class='fas fa-times-circle'></i> Query Error</h4>";
    echo "<p>Error fetching card records: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
    echo "</div>";
    require_once 'includes/footer.php';
    exit;
}

// Get comprehensive statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_cards,
        COUNT(DISTINCT ucc.user_id) as unique_users,
        COUNT(CASE WHEN ucc.card_type = 'user_order' THEN 1 END) as user_cards,
        COUNT(CASE WHEN ucc.card_type = 'partner_sale' THEN 1 END) as partner_cards,
        COUNT(CASE WHEN ucc.card_gradient_type = 'golden' THEN 1 END) as golden_cards
    FROM user_card_collections ucc
    WHERE ucc.is_collected = TRUE
";

try {
    $stmt = $db->prepare($stats_query);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats) {
        $stats = [
            'total_cards' => 0,
            'unique_users' => 0,
            'user_cards' => 0,
            'partner_cards' => 0,
            'golden_cards' => 0
        ];
    }
} catch (Exception $e) {
    echo "<div class='container-fluid mt-4'>";
    echo "<div class='alert alert-danger'>";
    echo "<h4><i class='fas fa-times-circle'></i> Statistics Error</h4>";
    echo "<p>Error fetching statistics: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
    echo "</div>";
    require_once 'includes/footer.php';
    exit;
}

// Get Phase 1 completion statistics
$phase1_stats_query = "
    SELECT 
        COUNT(DISTINCT upp.user_id) as phase1_completed_users,
        COUNT(DISTINCT CASE WHEN upp.card_type = 'user_order' THEN upp.user_id END) as phase1_user_completed,
        COUNT(DISTINCT CASE WHEN upp.card_type = 'partner_sale' THEN upp.user_id END) as phase1_partner_completed
    FROM user_phase_progress upp
    WHERE upp.phase_number = 1 AND upp.is_phase_completed = TRUE
";

try {
    $stmt = $db->prepare($phase1_stats_query);
    $stmt->execute();
    $phase1_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$phase1_stats) {
        $phase1_stats = [
            'phase1_completed_users' => 0,
            'phase1_user_completed' => 0,
            'phase1_partner_completed' => 0
        ];
    }
} catch (Exception $e) {
    $phase1_stats = [
        'phase1_completed_users' => 0,
        'phase1_user_completed' => 0,
        'phase1_partner_completed' => 0
    ];
}

// Get users with golden cards (10th card in any phase)
$golden_users_query = "
    SELECT COUNT(DISTINCT user_id) as golden_card_users
    FROM user_card_collections 
    WHERE card_gradient_type = 'golden' AND is_collected = TRUE
";

try {
    $stmt = $db->prepare($golden_users_query);
    $stmt->execute();
    $golden_users_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$golden_users_result) {
        $golden_users_result = ['golden_card_users' => 0];
    }
} catch (Exception $e) {
    $golden_users_result = ['golden_card_users' => 0];
}

// Get overall user progress summary
$user_progress_query = "
    SELECT 
        u.id,
        u.full_name,
        u.email,
        CASE WHEN a.id IS NOT NULL THEN 'Affiliate' ELSE 'Normal User' END as user_type,
        COALESCE(user_cards.cards_collected, 0) as user_cards_collected,
        COALESCE(partner_cards.cards_collected, 0) as partner_cards_collected,
        COALESCE(user_cards.golden_cards, 0) as user_golden_cards,
        COALESCE(partner_cards.golden_cards, 0) as partner_golden_cards,
        COALESCE(user_progress.phase1_completed, FALSE) as user_phase1_completed,
        COALESCE(partner_progress.phase1_completed, FALSE) as partner_phase1_completed,
        COALESCE(user_progress.highest_phase, 0) as user_highest_phase,
        COALESCE(partner_progress.highest_phase, 0) as partner_highest_phase
    FROM users u
    LEFT JOIN affiliates a ON u.id = a.user_id
    LEFT JOIN (
        SELECT user_id, 
               COUNT(*) as cards_collected,
               COUNT(CASE WHEN card_gradient_type = 'golden' THEN 1 END) as golden_cards
        FROM user_card_collections 
        WHERE card_type = 'user_order' AND is_collected = TRUE 
        GROUP BY user_id
    ) user_cards ON u.id = user_cards.user_id
    LEFT JOIN (
        SELECT user_id, 
               COUNT(*) as cards_collected,
               COUNT(CASE WHEN card_gradient_type = 'golden' THEN 1 END) as golden_cards
        FROM user_card_collections 
        WHERE card_type = 'partner_sale' AND is_collected = TRUE 
        GROUP BY user_id
    ) partner_cards ON u.id = partner_cards.user_id
    LEFT JOIN (
        SELECT user_id,
               MAX(CASE WHEN is_phase_completed = TRUE THEN 1 ELSE 0 END) as phase1_completed,
               MAX(phase_number) as highest_phase
        FROM user_phase_progress 
        WHERE card_type = 'user_order'
        GROUP BY user_id
    ) user_progress ON u.id = user_progress.user_id
    LEFT JOIN (
        SELECT user_id,
               MAX(CASE WHEN is_phase_completed = TRUE THEN 1 ELSE 0 END) as phase1_completed,
               MAX(phase_number) as highest_phase
        FROM user_phase_progress 
        WHERE card_type = 'partner_sale'
        GROUP BY user_id
    ) partner_progress ON u.id = partner_progress.user_id
    WHERE (user_cards.cards_collected > 0 OR partner_cards.cards_collected > 0)
    ORDER BY (COALESCE(user_cards.cards_collected, 0) + COALESCE(partner_cards.cards_collected, 0)) DESC
    LIMIT 50
";

try {
    $stmt = $db->prepare($user_progress_query);
    $stmt->execute();
    $user_progress_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$user_progress_data) {
        $user_progress_data = [];
    }
} catch (Exception $e) {
    $user_progress_data = [];
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">Card Collection Records</h4>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary btn-sm" onclick="exportCardRecords()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="bg-primary text-white p-3 rounded text-center">
                                <h3 class="mb-0"><?php echo number_format($stats['total_cards']); ?></h3>
                                <small>Total Cards</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="bg-info text-white p-3 rounded text-center">
                                <h3 class="mb-0"><?php echo number_format($stats['unique_users']); ?></h3>
                                <small>Active Users</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="bg-success text-white p-3 rounded text-center">
                                <h3 class="mb-0"><?php echo number_format($stats['user_cards']); ?></h3>
                                <small>User Cards</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="bg-warning text-white p-3 rounded text-center">
                                <h3 class="mb-0"><?php echo number_format($stats['partner_cards']); ?></h3>
                                <small>Partner Cards</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="text-dark p-3 rounded text-center" style="background: linear-gradient(135deg, #ffd700, #ffed4e) !important;">
                                <h3 class="mb-0"><?php echo number_format($stats['golden_cards']); ?></h3>
                                <small>Golden Cards</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="bg-dark text-white p-3 rounded text-center">
                                <h3 class="mb-0"><?php echo number_format($golden_users_result['golden_card_users']); ?></h3>
                                <small>Golden Users</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Phase 1 Completion Stats -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h5 class="mb-3"><i class="fas fa-trophy text-warning"></i> Phase 1 Completion Statistics</h5>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="bg-gradient-primary text-white p-3 rounded text-center" style="background: linear-gradient(135deg, #667eea, #764ba2) !important;">
                                <h3 class="mb-0"><?php echo number_format($phase1_stats['phase1_completed_users']); ?></h3>
                                <small>Total Phase 1 Completed</small>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="bg-gradient-success text-white p-3 rounded text-center" style="background: linear-gradient(135deg, #56ab2f, #a8e6cf) !important;">
                                <h3 class="mb-0"><?php echo number_format($phase1_stats['phase1_user_completed']); ?></h3>
                                <small>User Orders Phase 1</small>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="bg-gradient-info text-white p-3 rounded text-center" style="background: linear-gradient(135deg, #4facfe, #00f2fe) !important;">
                                <h3 class="mb-0"><?php echo number_format($phase1_stats['phase1_partner_completed']); ?></h3>
                                <small>Partner Sales Phase 1</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <form method="GET" class="row mb-4">
                        <div class="col-md-3">
                            <label class="form-label">User Type</label>
                            <select name="user_filter" class="form-select">
                                <option value="all" <?php echo $user_filter === 'all' ? 'selected' : ''; ?>>All Users</option>
                                <option value="affiliates" <?php echo $user_filter === 'affiliates' ? 'selected' : ''; ?>>Affiliates Only</option>
                                <option value="normal" <?php echo $user_filter === 'normal' ? 'selected' : ''; ?>>Normal Users Only</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Card Type</label>
                            <select name="card_type" class="form-select">
                                <option value="all" <?php echo $card_type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="user_order" <?php echo $card_type_filter === 'user_order' ? 'selected' : ''; ?>>User Orders</option>
                                <option value="partner_sale" <?php echo $card_type_filter === 'partner_sale' ? 'selected' : ''; ?>>Partner Sales</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Phase</label>
                            <select name="phase" class="form-select">
                                <option value="0" <?php echo $phase_filter === 0 ? 'selected' : ''; ?>>All Phases</option>
                                <?php for ($i = 1; $i <= 20; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $phase_filter === $i ? 'selected' : ''; ?>>Phase <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="card-records.php" class="btn btn-secondary ms-2">Reset</a>
                        </div>
                    </form>
                    
                    <!-- User Progress Summary -->
                    <?php if (!empty($user_progress_data)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <h5 class="mb-3"><i class="fas fa-users text-primary"></i> Top Users Progress Summary</h5>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>User</th>
                                            <th>Type</th>
                                            <th>User Cards</th>
                                            <th>Partner Cards</th>
                                            <th>Golden Cards</th>
                                            <th>Phase 1 Status</th>
                                            <th>Highest Phase</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($user_progress_data as $user): ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($user['email']); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $user['user_type'] === 'Affiliate' ? 'warning' : 'secondary'; ?>">
                                                    <?php echo $user['user_type']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?php echo $user['user_cards_collected']; ?> cards
                                                </span>
                                                <?php if ($user['user_golden_cards'] > 0): ?>
                                                <br><small class="text-warning">⭐ <?php echo $user['user_golden_cards']; ?> golden</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo $user['partner_cards_collected']; ?> cards
                                                </span>
                                                <?php if ($user['partner_golden_cards'] > 0): ?>
                                                <br><small class="text-warning">⭐ <?php echo $user['partner_golden_cards']; ?> golden</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge" style="background: linear-gradient(135deg, #ffd700, #ffed4e); color: black;">
                                                    <?php echo ($user['user_golden_cards'] + $user['partner_golden_cards']); ?> total
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($user['user_phase1_completed']): ?>
                                                <span class="badge bg-success">✓ User Complete</span>
                                                <?php endif; ?>
                                                <?php if ($user['partner_phase1_completed']): ?>
                                                <span class="badge bg-info">✓ Partner Complete</span>
                                                <?php endif; ?>
                                                <?php if (!$user['user_phase1_completed'] && !$user['partner_phase1_completed']): ?>
                                                <span class="badge bg-secondary">In Progress</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-dark">
                                                    Phase <?php echo max($user['user_highest_phase'], $user['partner_highest_phase']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Records Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Order</th>
                                    <th>Phase</th>
                                    <th>Position</th>
                                    <th>Gradient</th>
                                    <th>Collected At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($card_records)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No card records found</p>
                                        <?php if ($stats['total_cards'] == 0): ?>
                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Setup Required:</strong> The card collection system may not be initialized. 
                                            <br>
                                            <a href="../setup_card_collection.php" class="btn btn-primary btn-sm mt-2" target="_blank">
                                                <i class="fas fa-cog"></i> Setup Card Collection System
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($card_records as $record): ?>
                                <tr>
                                    <td><?php echo $record['id']; ?></td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($record['full_name']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($record['email']); ?></small>
                                            <br>
                                            <span class="badge bg-<?php echo $record['user_type'] === 'Affiliate' ? 'warning' : 'secondary'; ?>">
                                                <?php echo $record['user_type']; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $record['card_type'] === 'user_order' ? 'primary' : 'info'; ?>">
                                            <?php echo $record['card_type'] === 'user_order' ? 'User Order' : 'Partner Sale'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <strong>#<?php echo $record['order_number']; ?></strong>
                                            <br>
                                            <small class="text-muted">Rs <?php echo number_format($record['total_amount']); ?></small>
                                            <br>
                                            <span class="badge bg-<?php 
                                                echo $record['order_status'] === 'Delivered' ? 'success' : 
                                                    ($record['order_status'] === 'On The Way' ? 'warning' : 'info'); 
                                            ?>">
                                                <?php echo $record['order_status']; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark">Phase <?php echo $record['phase_number']; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">Card <?php echo $record['card_position']; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: <?php 
                                            echo $record['card_gradient_type'] === 'black' ? 'linear-gradient(135deg, #2c3e50, #34495e)' :
                                                ($record['card_gradient_type'] === 'blue' ? 'linear-gradient(135deg, #667eea, #764ba2)' :
                                                ($record['card_gradient_type'] === 'silver' ? 'linear-gradient(135deg, #bdc3c7, #ecf0f1)' : 
                                                'linear-gradient(135deg, #ffd700, #ffed4e)'));
                                        ?>; color: <?php echo $record['card_gradient_type'] === 'silver' ? 'black' : 'white'; ?>;">
                                            <?php echo ucfirst($record['card_gradient_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo date('M d, Y H:i', strtotime($record['collected_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary btn-sm" onclick="viewCardDetails(<?php echo $record['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportCardRecords() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.open('?' + params.toString(), '_blank');
}

function viewCardDetails(cardId) {
    // You can implement a modal or redirect to a details page
    alert('Card details for ID: ' + cardId + '\n\nThis would show detailed information about the card collection.');
}
</script>

<?php require_once 'includes/footer.php'; ?>
