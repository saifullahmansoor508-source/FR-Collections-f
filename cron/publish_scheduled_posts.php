<?php
/**
 * Cron Job: Publish Scheduled Blog Posts
 * 
 * This script should be run every minute via cron job to check for scheduled posts
 * that need to be published.
 * 
 * Cron command (run every minute):
 * * * * * * php /path/to/your/project/cron/publish_scheduled_posts.php
 * 
 * Or via wget/curl:
 * * * * * * curl http://yourdomain.com/cron/publish_scheduled_posts.php
 */

require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Get current date and time
    $current_datetime = date('Y-m-d H:i:s');
    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');
    
    // Find all scheduled posts that should be published now
    $stmt = $db->prepare("
        SELECT id, title 
        FROM blog_posts 
        WHERE is_scheduled = 1 
        AND is_published = 0 
        AND scheduled_date <= ? 
        AND scheduled_time <= ?
    ");
    $stmt->execute([$current_date, $current_time]);
    $posts_to_publish = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($posts_to_publish)) {
        // Update posts to published status
        $post_ids = array_column($posts_to_publish, 'id');
        $placeholders = str_repeat('?,', count($post_ids) - 1) . '?';
        
        $update_stmt = $db->prepare("
            UPDATE blog_posts 
            SET is_published = 1, 
                is_scheduled = 0,
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN ($placeholders)
        ");
        $update_stmt->execute($post_ids);
        
        // Log the published posts
        $log_message = date('Y-m-d H:i:s') . " - Published " . count($posts_to_publish) . " scheduled post(s):\n";
        foreach ($posts_to_publish as $post) {
            $log_message .= "  - ID: {$post['id']}, Title: {$post['title']}\n";
        }
        
        // Write to log file
        $log_file = __DIR__ . '/publish_log.txt';
        file_put_contents($log_file, $log_message, FILE_APPEND);
        
        echo "Success: Published " . count($posts_to_publish) . " post(s)\n";
    } else {
        echo "No posts to publish at this time.\n";
    }
    
} catch (Exception $e) {
    $error_message = date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
    $log_file = __DIR__ . '/publish_log.txt';
    file_put_contents($log_file, $error_message, FILE_APPEND);
    
    echo "Error: " . $e->getMessage() . "\n";
}
