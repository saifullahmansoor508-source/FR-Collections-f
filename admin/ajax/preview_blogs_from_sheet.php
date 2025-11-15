<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

// Suppress any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Clear any output buffered so far
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
    $sheet_url = $input['sheet_url'] ?? '';
    
    if (empty($sheet_url)) {
        echo json_encode(['success' => false, 'error' => 'Please provide a Google Sheets URL']);
        exit();
    }
    
    // Extract sheet ID
    if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $sheet_url, $matches)) {
        $sheet_id = $matches[1];
        $csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv";
        
        // Try to fetch the CSV data
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        $csv_data = @file_get_contents($csv_url, false, $context);
        
        if ($csv_data === false) {
            echo json_encode([
                'success' => false,
                'error' => 'Could not fetch data from Google Sheets',
                'message' => 'Make sure the sheet is set to "Anyone with the link can view"'
            ]);
            exit();
        }
        
        // Parse CSV
        $lines = explode("\n", $csv_data);
        $header_line = array_shift($lines);
        $headers = array_map('trim', str_getcsv($header_line));
        
        // Check required headers
        $required_headers = ['Title', 'Topic', 'Short Description', 'Content'];
        $missing_headers = array_diff($required_headers, $headers);
        
        if (!empty($missing_headers)) {
            echo json_encode([
                'success' => false,
                'error' => 'Missing required columns: ' . implode(', ', $missing_headers),
                'found_headers' => $headers
            ]);
            exit();
        }
        
        // Parse all blog posts
        $blogs = [];
        
        foreach ($lines as $index => $line) {
            if (empty(trim($line))) continue;
            
            $data = str_getcsv($line);
            
            // Skip if not enough data
            if (count($data) < count($required_headers)) continue;
            
            // Pad array if needed
            while (count($data) < count($headers)) {
                $data[] = '';
            }
            
            // Map data to fields
            $blog_data = array_combine($headers, array_slice($data, 0, count($headers)));
            
            $title = trim($blog_data['Title'] ?? '');
            $topic = trim($blog_data['Topic'] ?? '');
            $short_desc = trim($blog_data['Short Description'] ?? '');
            $content = trim($blog_data['Content'] ?? '');
            $image_url = isset($blog_data['Image URL']) ? trim($blog_data['Image URL']) : '';
            
            // Skip if required fields are empty
            if (empty($title) || empty($content)) continue;
            
            // Generate preview (first 200 chars of content)
            $content_preview = substr($content, 0, 200);
            if (strlen($content) > 200) {
                $content_preview .= '...';
            }
            
            // Count paragraphs
            $paragraphs = array_filter(array_map('trim', explode("\n\n", $content)));
            $paragraph_count = count($paragraphs);
            
            $blogs[] = [
                'title' => $title,
                'topic' => $topic,
                'short_description' => $short_desc,
                'content' => $content,
                'content_preview' => $content_preview,
                'image_url' => $image_url,
                'paragraph_count' => $paragraph_count,
                'word_count' => str_word_count($content)
            ];
        }
        
        if (empty($blogs)) {
            echo json_encode([
                'success' => false,
                'error' => 'No valid blog posts found in the sheet',
                'message' => 'Make sure your rows have Title and Content filled in'
            ]);
            exit();
        }
        
        echo json_encode([
            'success' => true,
            'blogs' => $blogs,
            'total_count' => count($blogs)
        ]);
        
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid Google Sheets URL format'
        ]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}

ob_end_flush();
?>
