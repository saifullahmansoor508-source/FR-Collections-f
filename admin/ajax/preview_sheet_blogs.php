<?php
session_start();
require_once '../../config/config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$sheet_url = $input['sheet_url'] ?? '';

if (empty($sheet_url)) {
    echo json_encode(['success' => false, 'error' => 'No URL provided']);
    exit;
}

try {
    // Validate URL format
    if (!filter_var($sheet_url, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid URL format');
    }
    
    // Extract the sheet ID from the URL
    if (!preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $sheet_url, $matches)) {
        throw new Exception('Invalid Google Sheets URL format');
    }
    
    $sheet_id = $matches[1];
    
    // Convert to CSV export URL
    $csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv";
    
    // Fetch CSV data
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    $csv_data = @file_get_contents($csv_url, false, $context);
    
    if ($csv_data === false) {
        throw new Exception('Could not fetch data from Google Sheets. Make sure the sheet is set to "Anyone with the link can view"');
    }
    
    // Parse CSV data with new format
    $lines = explode("\n", $csv_data);
    
    // Skip the header row (first line)
    array_shift($lines);
    
    // Expected columns: Blog#, Outside Title, Inside Title, Tagline, Topic#, Topic Title, Topic Text
    $blogs = [];
    $current_blog = null;
    $blog_number = null;
    
    foreach ($lines as $line_index => $line) {
        if (empty(trim($line))) continue;
        
        $data = str_getcsv($line);
        
        // Skip if not enough columns
        if (count($data) < 7) continue;
        
        // Pad array if needed
        while (count($data) < 7) {
            $data[] = '';
        }
        
        $row_blog_number = trim($data[0]);
        $outside_title = trim($data[1]);
        $inside_title = trim($data[2]);
        $tagline = trim($data[3]);
        $topic_number = trim($data[4]);
        $topic_title = trim($data[5]);
        $topic_text = trim($data[6]);
        
        // Check if this is a new blog (blog number changed)
        if (!empty($row_blog_number) && $row_blog_number !== $blog_number) {
            // Save previous blog if exists
            if ($current_blog !== null && !empty($current_blog['topics'])) {
                $blogs[] = $current_blog;
            }
            
            // Start new blog
            $blog_number = $row_blog_number;
            $current_blog = [
                'blog_number' => $blog_number,
                'outside_title' => $outside_title,
                'inside_title' => $inside_title,
                'tagline' => $tagline,
                'topics' => []
            ];
        }
        
        // Add topic to current blog
        if ($current_blog !== null && !empty($topic_number) && !empty($topic_text)) {
            $current_blog['topics'][] = [
                'number' => $topic_number,
                'title' => $topic_title,
                'text' => $topic_text
            ];
        }
    }
    
    // Add last blog
    if ($current_blog !== null && !empty($current_blog['topics'])) {
        $blogs[] = $current_blog;
    }
    
    // Process and format blogs for preview
    $formatted_blogs = [];
    foreach ($blogs as $blog) {
        // Build paragraphs array from topics
        $paragraphs = [];
        $headings = [];
        
        foreach ($blog['topics'] as $topic) {
            // Use topic title as heading if available, leave empty if not provided
            $heading = !empty($topic['title']) ? $topic['title'] : "";
            $headings[] = $heading;
            
            // Process topic text with multiple formatting options
            $text = $topic['text'];
            
            // Split by new lines to handle formatting
            $lines = explode("\n", $text);
            $formatted_text = '';
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Check if entire line is [text] = bullet point
                if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
                    $line = '[BULLET]' . trim($matches[1]);
                }
                // * at start = bullet point (alternative)
                elseif (substr($line, 0, 1) === '*') {
                    $line = '[BULLET]' . trim(substr($line, 1));
                }
                else {
                    // Process inline formatting for the entire line
                    
                    // {text} = subheading (inline, at start or middle of line)
                    $line = preg_replace('/\{([^}]+)\}/', '[SUBHEADING]$1[/SUBHEADING]', $line);
                    
                    // (text) = bold
                    $line = preg_replace('/\(([^)]+)\)/', '[BOLD]$1[/BOLD]', $line);
                    
                    // "text" = link
                    $line = preg_replace('/"([^"]+)"/', '[LINK]$1[/LINK]', $line);
                }
                
                $formatted_text .= $line . "\n";
            }
            
            $paragraphs[] = trim($formatted_text);
        }
        
        // Create short description from first topic
        $short_desc = '';
        if (!empty($paragraphs[0])) {
            $short_desc = substr(strip_tags($paragraphs[0]), 0, 150);
            if (strlen($paragraphs[0]) > 150) {
                $short_desc .= '...';
            }
        }
        
        // Combine all paragraphs for full content
        $full_content = implode("\n\n", $paragraphs);
        
        $formatted_blogs[] = [
            'blog_number' => $blog['blog_number'],
            'outside_title' => $blog['outside_title'],
            'inside_title' => $blog['inside_title'],
            'title' => $blog['inside_title'], // Use inside title as main title
            'tagline' => $blog['tagline'],
            'short_description' => $short_desc,
            'content' => $full_content,
            'paragraphs' => $paragraphs,
            'headings' => $headings,
            'topic_count' => count($blog['topics'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'blogs' => $formatted_blogs,
        'total' => count($formatted_blogs)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
