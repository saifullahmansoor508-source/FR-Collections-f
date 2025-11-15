<?php
/**
 * List all products in database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require_once '../../config/config.php';
require_once '../../config/database.php';

echo "<h2>Products List</h2>";

// Check session
if (!isset($_SESSION['admin_email'])) {
    echo "❌ Please log in first<br>";
    exit;
}

// Get database connection
try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        echo "❌ Database connection failed<br>";
        exit;
    }
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
    exit;
}

// Get all products
try {
    $stmt = $db->query("SELECT id, name, status, created_at FROM products ORDER BY id ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($products) > 0) {
        echo "<h3>Found " . count($products) . " product(s):</h3>";
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>ID</th>";
        echo "<th>Name</th>";
        echo "<th>Status</th>";
        echo "<th>Created</th>";
        echo "<th>Actions</th>";
        echo "</tr>";
        
        foreach ($products as $product) {
            echo "<tr>";
            echo "<td><strong>{$product['id']}</strong></td>";
            echo "<td>{$product['name']}</td>";
            echo "<td>{$product['status']}</td>";
            echo "<td>{$product['created_at']}</td>";
            echo "<td><a href='test_delete.php?id={$product['id']}' style='color: blue;'>Test Delete</a></td>";
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<h3>❌ No products found in database</h3>";
        echo "<p>Your products table is empty.</p>";
    }
    
    // Show table info
    echo "<hr>";
    echo "<h3>Database Info:</h3>";
    $stmt = $db->query("SELECT COUNT(*) as total FROM products");
    $total = $stmt->fetchColumn();
    echo "Total products: <strong>$total</strong><br>";
    
    $stmt = $db->query("SHOW TABLE STATUS LIKE 'products'");
    $status = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Next auto-increment ID: <strong>{$status['Auto_increment']}</strong><br>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<p><a href='../products.php'>← Back to Products Page</a></p>";
?>
