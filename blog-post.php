<?php
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$post_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($post_id <= 0) {
    redirectTo('blog.php');
}

// Get blog post
$stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ? AND is_published = 1");
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    redirectTo('blog.php');
}

// Get paragraphs and headings if available
$paragraphs = [];
$headings = [];

if ($post['paragraphs_json']) {
    $paragraphs = json_decode($post['paragraphs_json'], true);
    $headings = $post['paragraph_headings_json'] ? json_decode($post['paragraph_headings_json'], true) : [];
} else {
    // Fallback to old content format
    $paragraphs = explode("\n\n", $post['content']);
    foreach ($paragraphs as $index => $paragraph) {
        if (trim($paragraph) !== '') {
            $headings[] = "Section " . ($index + 1);
        }
    }
}

$page_title = $post['title'];

// DEBUG: Uncomment to see raw paragraph data
// echo '<pre style="background: #f0f0f0; padding: 20px; margin: 20px;">'; 
// echo "Paragraphs JSON:\n";
// print_r($paragraphs);
// echo "\n\nSecond Paragraph Raw (Topic 2):\n";
// echo htmlspecialchars($paragraphs[1] ?? 'No data');
// echo "\n\nHeadings JSON:\n";
// print_r($headings);
// echo '</pre>';

require_once 'includes/header.php';
?>

<style>
/* Full-width blog post styles */
.blog-post-container {
    width: 100%;
    max-width: 100%;
    margin: 0;
    padding: 0;
    background: #f8f9fa;
}

.blog-post-hero {
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    padding: 80px 0 60px;
    position: relative;
    width: 100%;
    text-align: center;
}

.blog-hero-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 25px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.blog-hero-icon i {
    font-size: 60px;
    color: white;
}

.blog-post-hero-content {
    position: relative;
    z-index: 1;
    color: white;
    text-align: center;
    max-width: 900px;
    margin: 0 auto;
    padding: 0 20px;
}

.blog-post-hero h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 15px;
    line-height: 1.3;
    color: white;
}

.blog-post-subtitle {
    font-size: 1.1rem;
    color: rgba(255,255,255,0.9);
    margin-bottom: 0;
}

.blog-content-wrapper {
    background: #f5f5f5;
    padding: 60px 0;
    width: 100%;
}

.blog-content-container {
    max-width: 850px;
    margin: 0 auto;
    padding: 0 30px;
    background: transparent;
}

.blog-content {
    font-size: 1.05rem;
    line-height: 1.8;
    color: #333;
    width: 100%;
    background: white;
    padding: 50px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.blog-content p {
    margin-bottom: 1.5rem;
    text-align: justify;
}

.blog-content h1, 
.blog-content h2, 
.blog-content h3, 
.blog-content h4, 
.blog-content h5, 
.blog-content h6 {
    color: #003d82;
    font-weight: 700;
    margin-top: 2rem;
    margin-bottom: 1rem;
}

.blog-content h1 { 
    font-size: 1.8rem; 
}
.blog-content h2 { 
    font-size: 1.6rem; 
    color: #003d82;
}
.blog-content h3 { 
    font-size: 1.4rem; 
    color: #003d82;
}
.blog-content h4 { 
    font-size: 1.2rem; 
    color: #333;
}
.blog-content h5 { 
    font-size: 1.1rem; 
    color: #333;
}
.blog-content h6 { 
    font-size: 1rem; 
    color: #666;
}

.blog-content ul,
.blog-content ol {
    margin-bottom: 1.5rem;
    padding-left: 2rem;
}

.blog-content li {
    margin-bottom: 0.5rem;
    line-height: 1.6;
    color: #d32f2f;
}

.blog-content a {
    color: #003d82;
    text-decoration: none;
    transition: color 0.3s ease;
    font-weight: 500;
}

.blog-content a:hover {
    color: #0056b3;
    text-decoration: underline;
}

.blog-content img {
    max-width: 100%;
    height: auto;
    margin: 1.5rem 0;
}

.blog-content blockquote {
    border-left: 3px solid #003d82;
    padding-left: 1.5rem;
    margin: 1.5rem 0;
    font-style: italic;
    color: #666;
    background: #f8f9fa;
    padding: 1rem 1.5rem;
}

.back-to-blog-btn {
    background: linear-gradient(135deg, #003d82 0%, #0056b3 100%);
    color: white;
    padding: 14px 40px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 1rem;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    margin: 40px 0;
    border: none;
}

.back-to-blog-btn:hover {
    background: linear-gradient(135deg, #0056b3 0%, #007bff 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,61,130,0.3);
    color: white;
}

.paragraph-section {
    margin-bottom: 2.5rem;
}

.paragraph-content ul.bullet-list {
    list-style: none;
    padding-left: 0;
    margin: 1rem 0;
}

.paragraph-content ul.bullet-list li {
    position: relative;
    padding-left: 30px;
    margin-bottom: 12px;
    line-height: 1.7;
    color: #333;
}

.paragraph-content ul.bullet-list li:before {
    content: "•";
    position: absolute;
    left: 10px;
    color: #dc3545;
    font-weight: bold;
    font-size: 1.2em;
}

.paragraph-content p {
    margin-bottom: 1rem;
    line-height: 1.8;
}

.paragraph-heading {
    color: #003d82;
    font-size: 1.6rem;
    font-weight: 700;
    margin-bottom: 1.2rem;
}

.paragraph-content {
    font-size: 1.05rem;
    line-height: 1.8;
    color: #333;
    text-align: justify;
}

.paragraph-content .subheading {
    color: #1a1a1a;
    font-size: 1.2rem;
    font-weight: 700;
    margin: 1.5rem 0 1rem 0;
}

.paragraph-content strong {
    font-weight: 700;
    color: #1a1a1a;
}

.paragraph-content .blog-link {
    color: #003d82;
    text-decoration: none;
    font-weight: 500;
}

.paragraph-content .blog-link:hover {
    text-decoration: underline;
}

.section-divider {
    height: 2px;
    background: #28a745;
    margin: 3rem 0;
}

@media (max-width: 768px) {
    .blog-post-hero {
        padding: 60px 0 40px;
    }
    
    .blog-post-hero h1 {
        font-size: 1.8rem;
    }
    
    .blog-hero-icon {
        width: 60px;
        height: 60px;
    }
    
    .blog-hero-icon i {
        font-size: 30px;
    }
    
    .blog-content-wrapper {
        padding: 40px 0;
    }
    
    .blog-content-container {
        padding: 0 20px;
    }
    
    .blog-content {
        font-size: 1rem;
    }
    
    .paragraph-heading {
        font-size: 1.4rem;
    }
    
    .back-to-blog-btn {
        padding: 12px 30px;
        font-size: 0.95rem;
    }
}

@media (max-width: 480px) {
    .blog-post-hero h1 {
        font-size: 1.5rem;
    }
    
    .blog-content h2 {
        font-size: 1.4rem;
    }
}
</style>

<div class="blog-post-container">
    <!-- Hero Header -->
    <div class="blog-post-hero">
        <div class="blog-post-hero-content">
            <!-- Shopping Bag Icon -->
            <div class="blog-hero-icon">
                <i class="fas fa-shopping-bag"></i>
            </div>
            
            <h1><?php echo htmlspecialchars($post['title']); ?></h1>
            
            <?php if (!empty($post['heading'])): ?>
                <p class="blog-post-subtitle">
                    <?php echo htmlspecialchars($post['heading']); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Blog Post Content -->
    <div class="blog-content-wrapper">
        <div class="blog-content-container">
            <!-- Blog Content -->
            <article class="blog-content">
                <?php
                if (!empty($paragraphs) && is_array($paragraphs)) {
                    $totalParagraphs = count($paragraphs);
                    foreach ($paragraphs as $index => $paragraph) {
                        if (trim($paragraph) !== '') {
                            $heading = isset($headings[$index]) ? $headings[$index] : "";
                            
                            echo '<div class="paragraph-section">';
                            if (!empty($heading)) {
                                echo '<h2 class="paragraph-heading">' . htmlspecialchars($heading) . '</h2>';
                            }
                            
                            // Process paragraph with advanced formatting
                            $lines = explode("\n", $paragraph);
                            $formatted_content = '';
                            $in_list = false;
                            
                            foreach ($lines as $line) {
                                $line = trim($line);
                                if (empty($line)) continue;
                                
                                // Check for [BULLET] marker
                                if (strpos($line, '[BULLET]') === 0) {
                                    if (!$in_list) {
                                        $formatted_content .= '<ul class="bullet-list">';
                                        $in_list = true;
                                    }
                                    $bullet_text = str_replace('[BULLET]', '', $line);
                                    
                                    // Process inline formatting for bullet text
                                    $bullet_text = preg_replace_callback('/\[BOLD\](.+?)\[\/BOLD\]/', function($m) {
                                        return '<strong>' . htmlspecialchars($m[1]) . '</strong>';
                                    }, $bullet_text);
                                    $bullet_text = preg_replace_callback('/\[LINK\](.+?)\[\/LINK\]/', function($m) {
                                        return '<a href="#" class="blog-link">' . htmlspecialchars($m[1]) . '</a>';
                                    }, $bullet_text);
                                    
                                    // Escape remaining text while preserving HTML tags
                                    $bullet_text = preg_replace_callback('/(<[^>]+>)|([^<]+)/', function($m) {
                                        return isset($m[2]) ? htmlspecialchars($m[2]) : $m[1];
                                    }, $bullet_text);
                                    
                                    $formatted_content .= '<li>' . $bullet_text . '</li>';
                                }
                                // Regular paragraph
                                else {
                                    if ($in_list) {
                                        $formatted_content .= '</ul>';
                                        $in_list = false;
                                    }
                                    
                                    // Process inline formatting
                                    $processed_line = $line;
                                    
                                    // Handle [SUBHEADING]text[/SUBHEADING] - convert to h3 tags
                                    $has_subheading = false;
                                    if (preg_match('/\[SUBHEADING\](.+?)\[\/SUBHEADING\]/', $processed_line, $match)) {
                                        $has_subheading = true;
                                        $subheading_text = htmlspecialchars($match[1]);
                                        // Remove the subheading from the line and display it separately
                                        $processed_line = str_replace($match[0], '', $processed_line);
                                        $formatted_content .= '<h3 class="subheading">' . $subheading_text . '</h3>';
                                    }
                                    
                                    // Handle [BOLD]text[/BOLD]
                                    $processed_line = preg_replace_callback('/\[BOLD\](.+?)\[\/BOLD\]/', function($m) {
                                        return '<strong>' . htmlspecialchars($m[1]) . '</strong>';
                                    }, $processed_line);
                                    
                                    // Handle [LINK]text[/LINK]
                                    $processed_line = preg_replace_callback('/\[LINK\](.+?)\[\/LINK\]/', function($m) {
                                        return '<a href="#" class="blog-link">' . htmlspecialchars($m[1]) . '</a>';
                                    }, $processed_line);
                                    
                                    // Escape any remaining text that's not already HTML
                                    $processed_line = preg_replace_callback('/(<[^>]+>)|([^<]+)/', function($m) {
                                        return isset($m[2]) ? htmlspecialchars($m[2]) : $m[1];
                                    }, $processed_line);
                                    
                                    // Only add paragraph if there's remaining content (not just subheading)
                                    $processed_line = trim($processed_line);
                                    if (!empty($processed_line)) {
                                        $formatted_content .= '<p>' . $processed_line . '</p>';
                                    }
                                }
                            }
                            
                            if ($in_list) {
                                $formatted_content .= '</ul>';
                            }
                            
                            echo '<div class="paragraph-content">' . $formatted_content . '</div>';
                            echo '</div>';
                            
                            // Add divider between sections (except for the last one)
                            if ($index < $totalParagraphs - 1 && trim($paragraphs[$index + 1]) !== '') {
                                echo '<div class="section-divider"></div>';
                            }
                        }
                    }
                } else {
                    // Fallback for old content format
                    $content = $post['content'];
                    $paragraphs = explode("\n\n", $content);
                    $filteredParagraphs = array_filter($paragraphs, function($p) { return trim($p) !== ''; });
                    $totalParagraphs = count($filteredParagraphs);
                    $currentIndex = 0;
                    
                    foreach ($paragraphs as $paragraph) {
                        if (trim($paragraph) !== '') {
                            $currentIndex++;
                            echo '<div class="paragraph-section">';
                            echo '<div class="paragraph-content">' . nl2br(htmlspecialchars($paragraph)) . '</div>';
                            echo '</div>';
                            
                            // Add divider between sections (except for the last one)
                            if ($currentIndex < $totalParagraphs) {
                                echo '<div class="section-divider"></div>';
                            }
                        }
                    }
                }
                ?>
            </article>
            
            <!-- Back to Blog -->
            <div class="text-center">
                <a href="blog.php" class="back-to-blog-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to All Posts</span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>