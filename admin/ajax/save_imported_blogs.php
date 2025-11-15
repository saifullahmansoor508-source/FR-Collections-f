<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

// Suppress any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_clean();

// Check if admin is logged in
if (!isset($_SESSION['admin_email']) || !array_key_exists($_SESSION['admin_email'], ADMIN_EMAILS)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $blogs = $input['blogs'] ?? [];
    
    if (empty($blogs)) {
        echo json_encode(['success' => false, 'error' => 'No blogs provided']);
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $db->beginTransaction();
        $imported_count = 0;
        $failed_imports = [];
        
        foreach ($blogs as $blog) {
            try {
                $title = $blog['title'] ?? '';
                $topic = $blog['topic'] ?? '';
                $short_desc = $blog['short_description'] ?? '';
                $content = $blog['content'] ?? '';
                $image_url = $blog['image_url'] ?? '';
                
                if (empty($title) || empty($content)) {
                    $failed_imports[] = $title ?: 'Untitled';
                    continue;
                }
                
                // Sanitize data
                $title = sanitizeInput($title);
                $topic = sanitizeInput($topic);
                $short_desc = sanitizeInput($short_desc);
                $content = sanitizeInput($content);
                
                // Handle image download if URL is provided
                $image_path = '';
                if (!empty($image_url) && filter_var($image_url, FILTER_VALIDATE_URL)) {
                    $upload_dir = '../../' . BLOG_IMAGES_DIR;
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $image_data = @file_get_contents($image_url);
                    if ($image_data !== false) {
                        $file_ext = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
                        if (empty($file_ext)) $file_ext = 'jpg';
                        $image_path = 'blog_imported_' . uniqid() . '.' . $file_ext;
                        file_put_contents($upload_dir . $image_path, $image_data);
                    }
                }
                
                // Split content into paragraphs
                $paragraphs = array_filter(array_map('trim', explode("\n\n", $content)));
                $paragraphs_json = json_encode(array_values($paragraphs));
                
                // Generate simple headings
                $headings = [];
                foreach ($paragraphs as $i => $p) {
                    $headings[] = "Section " . ($i + 1);
                }
                $headings_json = json_encode($headings);
                
                // Insert blog post as DRAFT (is_published = 0)
                $stmt = $db->prepare("
                    INSERT INTO blog_posts (topic, title, heading, short_description, content, paragraphs_json, paragraph_headings_json, image_path, is_published)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
                ");
                $stmt->execute([
                    $topic, $title, $title, $short_desc, $content, $paragraphs_json, $headings_json, $image_path
                ]);
                
                $imported_count++;
                
            } catch (Exception $e) {
                $failed_imports[] = $title ?: 'Untitled';
            }
        }
        
        $db->commit();
        
        $response = [
            'success' => true,
            'imported_count' => $imported_count,
            'message' => "$imported_count blog post(s) imported successfully as drafts!"
        ];
        
        if (!empty($failed_imports)) {
            $response['failed_imports'] = $failed_imports;
            $response['message'] .= " (" . count($failed_imports) . " failed)";
        }
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode([
            'success' => false,
            'error' => 'Import failed: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}

ob_end_flush();
?>
