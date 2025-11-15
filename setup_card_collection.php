<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h2>Setting up Card Collection System...</h2>";

try {
    // Read and execute the SQL file
    $sql = file_get_contents('database/card_collections.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $db->exec($statement);
            echo "<p>✅ Executed: " . substr($statement, 0, 50) . "...</p>";
        }
    }
    
    echo "<h3>✅ Card Collection System setup completed successfully!</h3>";
    echo "<p><strong>Features installed:</strong></p>";
    echo "<ul>";
    echo "<li>✅ User card collections table</li>";
    echo "<li>✅ User phase progress table</li>";
    echo "<li>✅ Phase 1 unlocked for all existing users</li>";
    echo "<li>✅ Phase 1 unlocked for affiliates (partner sales)</li>";
    echo "</ul>";
    
    echo "<p><a href='profile.php?tab=orders'>Go to Profile → My Orders</a> to test the collect card buttons!</p>";
    
} catch (Exception $e) {
    echo "<h3>❌ Error setting up Card Collection System:</h3>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
}
?>
