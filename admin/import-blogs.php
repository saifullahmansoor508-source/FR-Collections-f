<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';
$imported_count = 0;
$debug_info = [];

// Enable error logging for debugging
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("POST request received");
    error_log("Import form submitted: " . print_r($_POST, true));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_blogs']) && isset($_POST['confirmed'])) {
    error_log("Processing confirmed import for URL: " . ($_POST['sheet_url'] ?? 'NO URL'));
    
    // Add a visible indicator that form was submitted
    $debug_info['form_submitted'] = true;
    $sheet_url = isset($_POST['sheet_url']) ? sanitizeInput($_POST['sheet_url']) : '';
    
    if (empty($sheet_url)) {
        $error = "Please enter a Google Sheets URL.";
    } else {
        try {
            // Validate URL format
            if (!filter_var($sheet_url, FILTER_VALIDATE_URL)) {
                $error = "Invalid URL format. Please enter a valid Google Sheets URL.";
            }
            // Extract the sheet ID from the URL
            elseif (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $sheet_url, $matches)) {
                $sheet_id = $matches[1];
                
                // Convert to CSV export URL
                $csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv";
                
                // Try to fetch the CSV data with error suppression
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 30,
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                    ]
                ]);
                $csv_data = @file_get_contents($csv_url, false, $context);
                
                if ($csv_data === false) {
                    $error = "<strong>Could not fetch data from Google Sheets.</strong><br><br>
                              <strong>Possible reasons:</strong><br>
                              • Sheet is not set to 'Anyone with the link can view'<br>
                              • Sheet has been deleted or URL is incorrect<br>
                              • Network connection issue<br><br>
                              <strong>How to fix:</strong><br>
                              1. Open your Google Sheet<br>
                              2. Click Share button (top right)<br>
                              3. Change to 'Anyone with the link can view'<br>
                              4. Copy the URL again and try importing";
                } else {
                    // Parse CSV data with new format
                    // Expected columns: Blog#, Outside Title, Inside Title, Tagline, Topic#, Topic Title, Topic Text
                    
                    // Use a temporary file to properly parse CSV with multiline cells
                    $temp_file = tmpfile();
                    fwrite($temp_file, $csv_data);
                    rewind($temp_file);
                    
                    $all_rows = [];
                    while (($data = fgetcsv($temp_file)) !== false) {
                        $all_rows[] = $data;
                    }
                    fclose($temp_file);
                    
                    // Skip the header row (first row)
                    array_shift($all_rows);
                    
                    $blogs = [];
                    $current_blog = null;
                    $blog_number = null;
                    
                    foreach ($all_rows as $data) {
                        if (empty($data) || count($data) < 7) continue;
                        
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
                        
                        // Check if this is a new blog
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
                    
                    if (empty($blogs)) {
                        $error = "<strong>No valid blogs found.</strong><br><br>
                                 Please ensure your sheet follows the correct format:<br>
                                 Column 1: Blog Number<br>
                                 Column 2: Outside Title<br>
                                 Column 3: Inside Title<br>
                                 Column 4: Tagline<br>
                                 Column 5: Topic Number<br>
                                 Column 6: Topic Title (optional)<br>
                                 Column 7: Topic Text";
                    } else {
                        error_log("Starting import of " . count($blogs) . " blogs");
                        
                        $db->beginTransaction();
                        
                        foreach ($blogs as $blog) {
                            // Sanitize data
                            $outside_title = sanitizeInput($blog['outside_title']);
                            $inside_title = sanitizeInput($blog['inside_title']);
                            $tagline = sanitizeInput($blog['tagline']);
                            
                            // Build paragraphs and headings
                            $paragraphs = [];
                            $headings = [];
                            
                            foreach ($blog['topics'] as $topic) {
                                // Use topic title as heading if available, leave empty if not provided
                                $heading = !empty($topic['title']) ? sanitizeInput($topic['title']) : "";
                                $headings[] = $heading;
                                
                                // Process topic text with multiple formatting options
                                $text = $topic['text'];
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
                            
                            $paragraphs_json = json_encode($paragraphs);
                            $headings_json = json_encode($headings);
                            
                            // Use outside title as topic/category
                            $topic = $outside_title;
                            
                            // Insert blog post (default as draft)
                            $stmt = $db->prepare("
                                INSERT INTO blog_posts (topic, title, heading, short_description, content, paragraphs_json, paragraph_headings_json, is_published)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                            ");
                            $stmt->execute([
                                $topic,
                                $inside_title,
                                $tagline,
                                $short_desc,
                                $full_content,
                                $paragraphs_json,
                                $headings_json
                            ]);
                            
                            $imported_count++;
                            error_log("Successfully imported blog: " . $inside_title);
                        }
                        
                        $db->commit();
                        
                        $success = "<strong>Import Completed Successfully!</strong><br><br>
                                   <i class='fas fa-check-circle text-success'></i> <strong>{$imported_count}</strong> blog post(s) imported<br>
                                   <i class='fas fa-eye text-info'></i> Check the <a href='blog-posts.php' class='alert-link'>Blog Posts page</a> to view your imported content<br><br>
                                   <small class='text-muted'>All blogs have been imported as drafts. You can edit and publish them individually.</small>";
                    }
                }
            } else {
                $error = "<strong>Invalid Google Sheets URL</strong><br><br>
                         The URL format is incorrect. A valid Google Sheets URL should look like:<br>
                         <code style='background:#f1f5f9;padding:5px 10px;border-radius:5px;display:inline-block;margin-top:5px;'>
                         https://docs.google.com/spreadsheets/d/YOUR_SHEET_ID/edit
                         </code><br><br>
                         Please copy the URL from your browser's address bar when viewing the sheet.";
            }
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "<strong>Error importing blogs:</strong><br><br>" . htmlspecialchars($e->getMessage()) . "<br><br>
                     <small class='text-muted'>Error on line: " . $e->getLine() . " in file: " . basename($e->getFile()) . "</small>";
        }
    }
}

$page_title = "Import Blogs from Google Sheets";

// Simple test to see if POST is working
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $debug_info['post_detected'] = 'YES - Form was submitted!';
    $debug_info['import_blogs_button'] = isset($_POST['import_blogs']) ? 'YES' : 'NO';
    $debug_info['sheet_url'] = isset($_POST['sheet_url']) ? 'YES - URL provided' : 'NO - No URL';
}

require_once 'includes/header.php';
?>

<style>
.import-hero {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
    border-radius: 15px;
    padding: 40px;
    color: white;
    margin-bottom: 30px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}

.import-hero h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 15px;
}

.import-hero p {
    font-size: 1.1rem;
    opacity: 0.95;
    margin-bottom: 0;
}

.instructions-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.instructions-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.step-item {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    padding: 15px;
    background: #f8fafc;
    border-radius: 10px;
    border-left: 4px solid var(--accent-color);
}

.step-number {
    width: 35px;
    height: 35px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
}

.step-content h6 {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 5px;
}

.step-content p {
    color: #64748b;
    margin: 0;
    font-size: 0.95rem;
}

.template-table {
    background: #f8fafc;
    border-radius: 10px;
    padding: 20px;
    overflow-x: auto;
}

.template-table table {
    width: 100%;
    border-collapse: collapse;
}

.template-table th {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    white-space: nowrap;
}

.template-table td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 0.9rem;
}

.import-form-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
}

.form-group-custom {
    margin-bottom: 25px;
}

.form-label-custom {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-input-custom {
    width: 100%;
    padding: 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.form-input-custom:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 4px rgba(0,88,163,0.1);
}

.btn-import {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 15px 40px;
    border-radius: 10px;
    font-weight: 600;
    border: none;
    font-size: 1.1rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.btn-import:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,88,163,0.3);
    color: white;
}

.alert-custom {
    border-radius: 10px;
    padding: 15px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.template-download-btn {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    padding: 12px 25px;
    border-radius: 8px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.template-download-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16,185,129,0.3);
    color: white;
}

.example-sheet-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--primary-color);
    text-decoration: none;
    font-weight: 600;
    padding: 10px 15px;
    background: #f0f9ff;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.example-sheet-link:hover {
    background: #e0f2fe;
    color: var(--primary-color);
}

.feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.feature-item {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    padding: 20px;
    border-radius: 10px;
    border-left: 4px solid var(--accent-color);
}

.feature-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}

.feature-title {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 5px;
}

.feature-desc {
    color: #64748b;
    font-size: 0.9rem;
    margin: 0;
}

.tips-card {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border-radius: 10px;
    padding: 20px;
    border-left: 4px solid #f59e0b;
    margin-top: 20px;
}

.tips-title {
    font-weight: 700;
    color: #92400e;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tips-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.tips-list li {
    padding: 8px 0;
    color: #78350f;
    display: flex;
    align-items: start;
    gap: 10px;
}

.tips-list li i {
    color: #f59e0b;
    margin-top: 3px;
}

/* Preview Section Styles */
#previewSection {
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#previewSection .card {
    border-radius: 12px;
    overflow: hidden;
}

#previewSection .card-header {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color)) !important;
    padding: 20px;
}

#previewSection .card-header h4 {
    font-size: 1.5rem;
    font-weight: 700;
}

#previewSection .card-body {
    max-height: 600px;
    overflow-y: auto;
    padding: 25px;
}

#previewSection .card-footer {
    padding: 20px;
    background: #f8fafc !important;
}

#previewContent .card {
    transition: all 0.3s ease;
    border: 2px solid #e2e8f0 !important;
}

#previewContent .card:hover {
    border-color: var(--primary-color) !important;
    box-shadow: 0 4px 15px rgba(0,88,163,0.1);
}
</style>

<?php if (!empty($debug_info)): ?>
    <div class="alert alert-info alert-custom">
        <i class="fas fa-info-circle fa-lg"></i>
        <div>
            <strong>Debug Information:</strong><br>
            <?php foreach ($debug_info as $key => $value): ?>
                • <?php echo htmlspecialchars($key); ?>: <?php echo htmlspecialchars($value); ?><br>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-custom alert-dismissible fade show">
        <i class="fas fa-check-circle fa-lg"></i>
        <div>
            <?php echo $success; ?>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-custom alert-dismissible fade show">
        <i class="fas fa-exclamation-circle fa-lg"></i>
        <div>
            <?php echo $error; ?>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Hero Section -->
<div class="import-hero">
    <div class="row align-items-center">
        <div class="col-lg-8">
            <h1><i class="fas fa-cloud-upload-alt me-3"></i>Import Blogs from Google Sheets</h1>
            <p>Easily bulk import blog posts directly from a Google Sheets document. Save time and streamline your content management process.</p>
        </div>
        <div class="col-lg-4 text-end">
            <a href="blog-posts.php" class="btn btn-light btn-lg">
                <i class="fas fa-arrow-left me-2"></i>Back to Blog Posts
            </a>
        </div>
    </div>
</div>

<!-- Import Form Section -->
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h3><i class="fas fa-upload me-2"></i>Import Your Blogs</h3>
        <button type="button" class="btn btn-outline-primary" id="toggleGuideBtn" onclick="toggleGuide()">
            <i class="fas fa-book-open me-2"></i>Show Guide
        </button>
    </div>
</div>

<div class="row">
    <!-- Import Form -->
    <div class="col-lg-6 mx-auto">
        <div class="import-form-card">
            <form method="POST">
                <div class="form-group-custom">
                    <label class="form-label-custom">
                        <i class="fas fa-link"></i>
                        Google Sheets URL
                    </label>
                    <input type="url" 
                           name="sheet_url" 
                           class="form-input-custom" 
                           placeholder="https://docs.google.com/spreadsheets/d/..."
                           required>
                    <small class="text-muted d-block mt-2">
                        <i class="fas fa-info-circle me-1"></i>Paste the full URL from your Google Sheets
                    </small>
                </div>

                <button type="submit" name="import_blogs" class="btn-import w-100" id="importBtn">
                    <i class="fas fa-cloud-download-alt" id="importIcon"></i>
                    <span id="importText">Import Blogs Now</span>
                </button>
                
                <button type="button" class="btn btn-outline-secondary w-100 mt-2" onclick="testConnection()">
                    <i class="fas fa-vial"></i>
                    <span>Test Connection</span>
                </button>
            </form>

            <!-- Import Progress (Hidden by default) -->
            <div id="importProgress" class="mt-3" style="display: none;">
                <div class="text-center">
                    <div class="spinner-border text-primary mb-2" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mb-0">
                        <i class="fas fa-hourglass-half me-1"></i>
                        Importing blogs... This may take a moment.
                    </p>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Preview Section (Hidden by default) -->
<div id="previewSection" class="mt-4" style="display: none;">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="fas fa-eye me-2"></i>Blog Posts Preview</h4>
            <small>Review the blogs below before confirming import</small>
        </div>
        <div class="card-body" id="previewContent">
            <!-- Preview content will be loaded here -->
        </div>
        <div class="card-footer bg-white border-top">
            <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-outline-secondary" onclick="cancelPreview()">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-success btn-lg" onclick="confirmImport()">
                    <i class="fas fa-check-circle me-2"></i>Confirm & Import All
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Guide Section (Hidden by default) -->
<div id="guideSection" style="display: none;">
<div class="row mt-4">
    <!-- Instructions -->
    <div class="col-lg-8">
        <div class="instructions-card">
            <h2 class="instructions-title">
                <i class="fas fa-book"></i>
                How to Import Blogs
            </h2>

            <div class="step-item">
                <div class="step-number">1</div>
                <div class="step-content">
                    <h6>Create Your Google Sheet</h6>
                    <p>Create a new Google Sheet or use your existing one with blog post data.</p>
                </div>
            </div>

            <div class="step-item">
                <div class="step-number">2</div>
                <div class="step-content">
                    <h6>Format Your Sheet Correctly</h6>
                    <p>Use 7 columns: Blog#, Outside Title, Inside Title, Tagline, Topic#, Topic Title, Topic Text. Each topic gets its own row.</p>
                </div>
            </div>

            <div class="step-item">
                <div class="step-number">3</div>
                <div class="step-content">
                    <h6>Make Sheet Public</h6>
                    <p>Share your sheet and set permission to "Anyone with the link can view".</p>
                </div>
            </div>

            <div class="step-item">
                <div class="step-number">4</div>
                <div class="step-content">
                    <h6>Copy the Sheet URL</h6>
                    <p>Copy the full URL from your browser's address bar.</p>
                </div>
            </div>

            <div class="step-item">
                <div class="step-number">5</div>
                <div class="step-content">
                    <h6>Paste & Import</h6>
                    <p>Paste the URL in the form on the right and click "Import Blogs".</p>
                </div>
            </div>

            <!-- Quick Reference Guide -->
            <div class="alert alert-info mt-4 d-flex align-items-start gap-3">
                <div style="flex-shrink: 0;">
                    <i class="fas fa-book-open fa-2x"></i>
                </div>
                <div>
                    <h6 class="mb-2"><strong>Quick Reference Guide</strong></h6>
                    <p class="mb-2">Need a printable reference? Open our comprehensive quick reference guide with all the details, examples, and troubleshooting tips.</p>
                    <div class="d-flex gap-2">
                        <a href="import-blogs-reference.html" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fas fa-external-link-alt me-1"></i>Open Reference Guide
                        </a>
                        <a href="../GOOGLE_SHEETS_BLOG_IMPORT_GUIDE.md" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-file-alt me-1"></i>Full Documentation
                        </a>
                    </div>
                </div>
            </div>

            <!-- Required Columns -->
            <div class="mt-4">
                <h5 class="mb-3"><i class="fas fa-table me-2"></i>Required Sheet Format</h5>
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>New Format:</strong> Each blog can have multiple topics/sections. Add one row per topic.
                </div>
                <div class="template-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Blog#</th>
                                <th>Outside Title</th>
                                <th>Inside Title</th>
                                <th>Tagline</th>
                                <th>Topic#</th>
                                <th>Topic Title</th>
                                <th>Topic Text</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Fashion Guide</td>
                                <td>Complete Fashion Guide 2025</td>
                                <td>Your ultimate style companion</td>
                                <td>1</td>
                                <td>Introduction</td>
                                <td>Welcome to fashion...<br>*Point one<br>*Point two</td>
                            </tr>
                            <tr>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td>2</td>
                                <td>Style Tips</td>
                                <td>Here are some tips...<br>*Tip one<br>*Tip two</td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>Tech News</td>
                                <td>Latest in Technology</td>
                                <td>Stay updated with tech</td>
                                <td>1</td>
                                <td>AI Revolution</td>
                                <td>Artificial intelligence is...<br>*AI benefits<br>*Future trends</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <p class="text-muted mb-2"><strong>Column Descriptions:</strong></p>
                    <ul class="text-muted" style="font-size: 0.9rem;">
                        <li><strong>Blog# (Column 1):</strong> Blog number - only fill for the first row of each blog</li>
                        <li><strong>Outside Title (Column 2):</strong> Title displayed on blog cards/thumbnails - only fill for first row</li>
                        <li><strong>Inside Title (Column 3):</strong> Main title displayed inside the blog post - only fill for first row</li>
                        <li><strong>Tagline (Column 4):</strong> Subtitle/tagline below the inside title - only fill for first row</li>
                        <li><strong>Topic# (Column 5):</strong> Section/topic number (1, 2, 3, etc.) - required for each row</li>
                        <li><strong>Topic Title (Column 6):</strong> Heading for this section - optional, will use "Section #" if empty</li>
                        <li><strong>Topic Text (Column 7):</strong> Content for this section - use * at the start of lines for bullet points</li>
                    </ul>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Pro Tip:</strong> Start lines with <code>*</code> to convert them into bullet points automatically!
                    </div>
                </div>
            </div>

            <!-- Example Template Link -->
            <div class="mt-4 text-center">
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="sample-blog-import-template-new.csv" download class="template-download-btn">
                        <i class="fas fa-download"></i>
                        Download CSV Template
                    </a>
                    <a href="https://docs.google.com/spreadsheets/create" target="_blank" class="example-sheet-link">
                        <i class="fas fa-file-spreadsheet"></i>
                        Create New Google Sheet
                    </a>
                </div>
                <p class="text-muted mt-3 mb-0" style="font-size: 0.85rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    Download the template to see the exact format with examples, or create a new sheet from scratch
                </p>
            </div>

            <!-- Tips -->
            <div class="tips-card">
                <h6 class="tips-title">
                    <i class="fas fa-lightbulb"></i>
                    Pro Tips for Best Results
                </h6>
                <ul class="tips-list">
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Fill Blog# only in the first row of each blog, leave blank for additional topics</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Use * at the start of lines to create bullet points automatically</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Topic Title (Column 6) is optional - leave blank to auto-generate "Section 1", "Section 2", etc.</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Add as many topics/sections as you need - each topic is a separate row</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Make sure your Google Sheet is set to "Anyone with the link can view"</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Test with 1-2 blogs first before importing in bulk</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
</div>

<script>
// Toggle Guide Section
function toggleGuide() {
    const guideSection = document.getElementById('guideSection');
    const toggleBtn = document.getElementById('toggleGuideBtn');
    
    if (guideSection.style.display === 'none') {
        guideSection.style.display = 'block';
        toggleBtn.innerHTML = '<i class="fas fa-times me-2"></i>Hide Guide';
        toggleBtn.classList.remove('btn-outline-primary');
        toggleBtn.classList.add('btn-primary');
        
        // Smooth scroll to guide
        setTimeout(() => {
            guideSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    } else {
        guideSection.style.display = 'none';
        toggleBtn.innerHTML = '<i class="fas fa-book-open me-2"></i>Show Guide';
        toggleBtn.classList.remove('btn-primary');
        toggleBtn.classList.add('btn-outline-primary');
        
        // Scroll back to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// Add animation to steps on scroll
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateX(0)';
        }
    });
}, observerOptions);

document.querySelectorAll('.step-item').forEach(step => {
    step.style.opacity = '0';
    step.style.transform = 'translateX(-20px)';
    step.style.transition = 'all 0.5s ease';
    observer.observe(step);
});

// Handle form submission with loading state and preview
const importForm = document.querySelector('form[method="POST"]');
const importBtn = document.getElementById('importBtn');
const importIcon = document.getElementById('importIcon');
const importText = document.getElementById('importText');
const importProgress = document.getElementById('importProgress');
let previewData = null;

if (importForm) {
    importForm.addEventListener('submit', async function(e) {
        e.preventDefault(); // Prevent default form submission
        
        const urlInput = document.querySelector('input[name="sheet_url"]');
        const url = urlInput.value.trim();
        
        if (!url) {
            alert('Please enter a Google Sheets URL');
            return;
        }
        
        // Show loading state
        importBtn.disabled = true;
        importBtn.style.opacity = '0.7';
        importIcon.className = 'fas fa-spinner fa-spin';
        importText.textContent = 'Loading Preview...';
        importProgress.style.display = 'block';
        
        try {
            // Fetch preview data
            const response = await fetch('ajax/preview_sheet_blogs.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ sheet_url: url })
            });
            
            const result = await response.json();
            
            if (result.success) {
                previewData = result.blogs;
                displayPreview(result.blogs);
                
                // Hide progress and reset button
                importProgress.style.display = 'none';
                importBtn.disabled = false;
                importBtn.style.opacity = '1';
                importIcon.className = 'fas fa-cloud-download-alt';
                importText.textContent = 'Import Blogs Now';
            } else {
                throw new Error(result.error || 'Failed to load preview');
            }
        } catch (error) {
            alert('Error loading preview: ' + error.message);
            
            // Reset button state
            importProgress.style.display = 'none';
            importBtn.disabled = false;
            importBtn.style.opacity = '1';
            importIcon.className = 'fas fa-cloud-download-alt';
            importText.textContent = 'Import Blogs Now';
        }
    });
}

// Display preview of blogs
function displayPreview(blogs) {
    const previewSection = document.getElementById('previewSection');
    const previewContent = document.getElementById('previewContent');
    
    if (blogs.length === 0) {
        previewContent.innerHTML = `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No valid blog posts found in the sheet. Please check your data and try again.
            </div>
        `;
    } else {
        let html = `<div class="alert alert-info mb-3">
            <i class="fas fa-info-circle me-2"></i>
            Found <strong>${blogs.length}</strong> blog post(s) ready to import
        </div>`;
        
        blogs.forEach((blog, index) => {
            html += `
                <div class="card mb-3 border">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="mb-2">
                                    <span class="badge bg-secondary">Blog #${escapeHtml(blog.blog_number || (index + 1))}</span>
                                </div>
                                <h5 class="card-title text-primary mb-1">
                                    ${escapeHtml(blog.inside_title)}
                                </h5>
                                <p class="text-muted mb-2" style="font-style: italic;">
                                    <i class="fas fa-quote-left me-1"></i>${escapeHtml(blog.tagline)}
                                </p>
                                <p class="mb-2">
                                    <strong><i class="fas fa-tag me-1"></i>Outside Title:</strong> ${escapeHtml(blog.outside_title)}
                                </p>
                                <p class="mb-2">
                                    <strong><i class="fas fa-list-ol me-1"></i>Topics:</strong> ${blog.topic_count} section(s)
                                </p>
                                <div class="mb-2">
                                    <strong>Section Headings:</strong>
                                    <ul class="mb-0 mt-1" style="font-size: 0.9rem;">
                                        ${blog.headings.map((heading, i) => `<li>${escapeHtml(heading)}</li>`).join('')}
                                    </ul>
                                </div>
                                <p class="mb-2 text-muted" style="font-size: 0.9rem;">
                                    <strong>Short Description:</strong><br>
                                    ${escapeHtml(blog.short_description)}
                                </p>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-file-alt me-1"></i>Draft
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        previewContent.innerHTML = html;
    }
    
    // Show preview section with animation
    previewSection.style.display = 'block';
    setTimeout(() => {
        previewSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

// Cancel preview and go back
function cancelPreview() {
    const previewSection = document.getElementById('previewSection');
    previewSection.style.display = 'none';
    previewData = null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Confirm and actually import
async function confirmImport() {
    if (!previewData || previewData.length === 0) {
        alert('No data to import');
        return;
    }
    
    const confirmBtn = event.target;
    const originalHTML = confirmBtn.innerHTML;
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
    
    try {
        const urlInput = document.querySelector('input[name="sheet_url"]');
        const url = urlInput.value.trim();
        
        // Submit form for actual import
        const formData = new FormData();
        formData.append('import_blogs', '1');
        formData.append('sheet_url', url);
        formData.append('confirmed', 'true');
        
        const response = await fetch('import-blogs.php', {
            method: 'POST',
            body: formData
        });
        
        // Reload page to show results
        window.location.reload();
        
    } catch (error) {
        alert('Error importing blogs: ' + error.message);
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = originalHTML;
    }
}

// URL validation on input
const sheetUrlInput = document.querySelector('input[name="sheet_url"]');
if (sheetUrlInput) {
    sheetUrlInput.addEventListener('input', function() {
        const url = this.value.trim();
        if (url && !url.includes('docs.google.com/spreadsheets')) {
            this.setCustomValidity('Please enter a valid Google Sheets URL');
            this.style.borderColor = '#ef4444';
        } else {
            this.setCustomValidity('');
            this.style.borderColor = '#e2e8f0';
        }
    });
    
    sheetUrlInput.addEventListener('blur', function() {
        if (this.value.trim() && !this.value.includes('docs.google.com/spreadsheets')) {
            this.style.borderColor = '#ef4444';
        }
    });
    
    sheetUrlInput.addEventListener('focus', function() {
        this.style.borderColor = 'var(--primary-color)';
    });
}

// Test connection function
async function testConnection() {
    const urlInput = document.querySelector('input[name="sheet_url"]');
    const url = urlInput.value.trim();
    
    if (!url) {
        alert('Please enter a Google Sheets URL first');
        return;
    }
    
    if (!url.includes('docs.google.com/spreadsheets')) {
        alert('Please enter a valid Google Sheets URL');
        return;
    }
    
    // Show loading
    const testBtn = event.target;
    const originalHTML = testBtn.innerHTML;
    testBtn.disabled = true;
    testBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
    
    try {
        const response = await fetch('ajax/test_sheet_connection.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ sheet_url: url })
        });
        
        const result = await response.json();
        
        if (result.success) {
            let message = `✅ Connection Successful!\n\n`;
            message += `📊 Headers Found: ${result.details.headers.join(', ')}\n`;
            message += `📝 Total Rows: ${result.details.total_rows}\n`;
            message += `✔️ Valid Rows: ${result.details.valid_rows}\n\n`;
            
            if (result.details.sample_data.length > 0) {
                message += `Sample Data:\n`;
                result.details.sample_data.forEach((row, index) => {
                    message += `Row ${index + 1}: ${row.slice(0, 2).join(' | ')}\n`;
                });
            }
            
            message += `\n✅ Ready to import!`;
            alert(message);
        } else {
            alert(`❌ Connection Failed\n\n${result.error}\n\n${result.details || ''}`);
        }
    } catch (error) {
        alert(`❌ Error: ${error.message}`);
    } finally {
        testBtn.disabled = false;
        testBtn.innerHTML = originalHTML;
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
