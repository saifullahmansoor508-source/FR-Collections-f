<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';
$post_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($post_id <= 0) {
    header('Location: blog-posts.php');
    exit;
}

// Get blog post
$stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: blog-posts.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $topic = sanitizeInput($_POST['topic']);
    $heading = sanitizeInput($_POST['heading']);
    $short_description = sanitizeInput($_POST['short_description']);
    $paragraphs_count = intval($_POST['paragraphs_count']);
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    // Collect paragraphs and headings
    $paragraphs = [];
    $headings = [];
    for ($i = 1; $i <= $paragraphs_count; $i++) {
        $paragraph = sanitizeInput($_POST["paragraph_$i"] ?? '');
        $heading = sanitizeInput($_POST["paragraph_heading_$i"] ?? '');

        if (!empty($paragraph)) {
            $paragraphs[] = $paragraph;
            $headings[] = !empty($heading) ? $heading : "";
        }
    }

    if (empty($topic) || empty($heading) || empty($paragraphs)) {
        $error = "Please fill all required fields.";
    } else {
        try {
            $db->beginTransaction();

            // Handle image upload
            $image_path = $post['image_path']; // Keep existing image by default
            if (!empty($_FILES['image']['name'])) {
                $upload_dir = '../' . BLOG_IMAGES_DIR;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $file = $_FILES['image'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (in_array($file_ext, $allowed_types) && $file['size'] <= 5242880) {
                    // Delete old image if exists
                    if ($post['image_path']) {
                        unlink($upload_dir . $post['image_path']);
                    }

                    $image_path = 'blog_' . uniqid() . '.' . $file_ext;
                    move_uploaded_file($file['tmp_name'], $upload_dir . $image_path);
                }
            }

            // Convert paragraphs and headings to JSON for structured storage
            $paragraphs_json = json_encode($paragraphs);
            $headings_json = json_encode($headings);
            $content = implode("\n\n", $paragraphs); // Keep original content format for backward compatibility

            // Update blog post
            $stmt = $db->prepare("
                UPDATE blog_posts SET
                topic = ?, title = ?, heading = ?, short_description = ?,
                content = ?, paragraphs_json = ?, paragraph_headings_json = ?, image_path = ?, is_published = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $topic, $heading, $heading, $short_description,
                $content, $paragraphs_json, $headings_json, $image_path, $is_published, $post_id
            ]);

            $db->commit();
            $success = "Blog post updated successfully!";

            // Refresh post data
            $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->execute([$post_id]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error updating blog post: " . $e->getMessage();
        }
    }
}

$page_title = "Edit Blog Post";
require_once 'includes/header.php';
?>

<style>
.form-section {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    overflow: hidden;
    margin-bottom: 25px;
    border: 1px solid #e2e8f0;
}

.form-section-header {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 20px 25px;
    border-bottom: none;
}

.form-section-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-section-body {
    padding: 25px;
}

.paragraph-container {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.3s ease;
}

.paragraph-container:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(30, 58, 138, 0.1);
}

.paragraph-label {
    font-weight: 600;
    color: var(--primary-color);
    margin-bottom: 10px;
    font-size: 0.95rem;
}

.paragraph-textarea {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 12px 15px;
    font-size: 1rem;
    resize: vertical;
    min-height: 100px;
    transition: all 0.3s ease;
}

.paragraph-textarea:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    outline: none;
}

.remove-paragraph {
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.8rem;
    transition: all 0.3s ease;
    margin-top: 10px;
}

.remove-paragraph:hover {
    background: #dc2626;
    transform: scale(1.1);
}

.image-preview {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 15px;
    border: 3px solid #e2e8f0;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.current-image {
    max-width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
}

.current-image-square {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 15px;
    border: 3px solid #e2e8f0;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.custom-dropdown {
    position: relative;
}

.dropdown-toggle-custom {
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 15px;
    font-size: 1rem;
    color: #374151;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
}

.dropdown-toggle-custom:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(30, 58, 138, 0.1);
}

.dropdown-toggle-custom:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    outline: none;
}

.dropdown-menu-custom {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    z-index: 1000;
    max-height: 200px;
    overflow-y: auto;
    margin: 0;
    padding: 0;
    display: none;
}

.dropdown-menu-custom.show {
    display: block;
}

.dropdown-item-custom {
    padding: 12px 15px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: all 0.3s ease;
    list-style: none;
}

.dropdown-item-custom:hover {
    background: #f8fafc;
    color: var(--primary-color);
}

.dropdown-item-custom:last-child {
    border-bottom: none;
}

.form-control-beautiful {
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 15px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
}

.form-control-beautiful:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    outline: none;
}

.btn-beautiful {
    border-radius: 12px;
    font-weight: 600;
    padding: 12px 25px;
    transition: all 0.3s ease;
    border: none;
    font-size: 1rem;
}

.btn-primary-beautiful {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
}

.btn-primary-beautiful:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
    color: white;
}

.btn-secondary-beautiful {
    background: #f8fafc;
    color: #64748b;
    border: 2px solid #e2e8f0;
}

.btn-secondary-beautiful:hover {
    background: #f1f5f9;
    color: #374151;
    border-color: #cbd5e1;
}

.publish-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    background: #f8fafc;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
}

.publish-toggle label {
    font-weight: 600;
    color: #374151;
    margin: 0;
    cursor: pointer;
}

.switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: var(--primary-color);
}

input:checked + .slider:before {
    transform: translateX(26px);
}

/* ========================================
   COMPACT HEADER BAR
   ======================================== */
.compact-header-bar {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    padding: 16px 0;
    margin-bottom: 24px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    position: sticky;
    top: 0;
    z-index: 100;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        transform: translateY(-100%);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.page-title-compact {
    color: white;
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.header-actions {
    display: flex;
    gap: 10px;
}

.btn-compact {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-compact-secondary {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.btn-compact-secondary:hover {
    background: rgba(255, 255, 255, 0.3);
    color: white;
    transform: translateY(-2px);
}

.btn-compact-primary {
    background: white;
    color: var(--primary-color);
}

.btn-compact-primary:hover {
    background: #f8f9fa;
    color: var(--primary-color);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* ========================================
   COMPACT IMAGE UPLOAD SECTION
   ======================================== */
.compact-image-section {
    margin-bottom: 24px;
}

.image-upload-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 2px solid #f0f0f0;
    transition: all 0.3s ease;
}

.image-upload-card:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 15px rgba(0, 88, 163, 0.1);
}

.image-label {
    display: block;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 12px;
    font-size: 0.95rem;
}

.image-upload-wrapper {
    position: relative;
}

.current-image-display {
    position: relative;
    width: 100%;
    max-width: 400px;
    height: 200px;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.3s ease;
    margin: 0 auto;
}

.current-image-display:hover {
    transform: scale(1.02);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.img-preview-compact {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.image-hover-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(0, 88, 163, 0.9), rgba(255, 107, 0, 0.9));
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    opacity: 0;
    transition: all 0.3s ease;
    color: white;
}

.current-image-display:hover .image-hover-overlay {
    opacity: 1;
}

.image-hover-overlay i {
    font-size: 2rem;
}

.image-hover-overlay span {
    font-weight: 600;
    font-size: 1rem;
}

.upload-placeholder {
    width: 100%;
    max-width: 400px;
    height: 200px;
    border: 3px dashed #cbd5e1;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #f8fafc;
    margin: 0 auto;
}

.upload-placeholder:hover {
    border-color: var(--primary-color);
    background: #f0f7ff;
    transform: scale(1.02);
}

.upload-placeholder i {
    font-size: 3rem;
    color: var(--primary-color);
    margin-bottom: 12px;
}

.upload-placeholder p {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 4px;
}

.upload-placeholder small {
    color: #64748b;
    font-size: 0.85rem;
}

.new-image-display {
    margin-top: 16px;
}

.new-image-display img {
    width: 100%;
    max-width: 400px;
    height: 200px;
    object-fit: cover;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    display: block;
    margin: 0 auto;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

/* ========================================
   ACTION BUTTONS - DESKTOP & MOBILE
   ======================================== */
.desktop-action-buttons {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
}

.mobile-action-icons {
    display: none;
    gap: 12px;
    align-items: center;
    justify-content: center;
}

.action-icon-btn {
    width: 56px;
    height: 56px;
    border: none;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    font-size: 1.2rem;
    text-decoration: none;
}

/* Shine animation overlay */
.action-icon-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
        45deg,
        transparent 30%,
        rgba(255, 255, 255, 0.4) 50%,
        transparent 70%
    );
    transform: rotate(45deg);
    animation: iconShine 3s infinite;
}

@keyframes iconShine {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

/* Pulse animation on tap */
@keyframes iconPulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.1);
    }
}

.action-icon-btn:active {
    animation: iconPulse 0.3s ease;
}

/* Save button - Green gradient */
.icon-save {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.icon-save:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
}

/* Preview button - Blue gradient */
.icon-preview {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.icon-preview:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

/* Back button - Gray gradient */
.icon-back {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    color: white;
}

.icon-back:hover {
    background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
    box-shadow: 0 6px 20px rgba(107, 114, 128, 0.4);
}

/* Delete button - Red gradient */
.icon-delete {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.icon-delete:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

/* Glossy effect */
.action-icon-btn::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(
        to bottom,
        rgba(255, 255, 255, 0.3) 0%,
        rgba(255, 255, 255, 0) 100%
    );
    border-radius: 14px 14px 0 0;
    pointer-events: none;
}

.action-icon-btn i {
    position: relative;
    z-index: 1;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .compact-header-bar {
        padding: 12px 0;
    }
    
    .page-title-compact {
        font-size: 1rem;
    }
    
    .btn-compact {
        padding: 6px 12px;
        font-size: 0.85rem;
    }
    
    .btn-text {
        display: none;
    }
    
    .btn-compact i {
        margin: 0;
    }
    
    .current-image-display,
    .upload-placeholder,
    .new-image-display img {
        max-width: 100%;
        height: 180px;
    }
    
    .image-upload-card {
        padding: 16px;
    }
    
    /* Hide desktop buttons, show mobile icons */
    .desktop-action-buttons {
        display: none !important;
    }
    
    .mobile-action-icons {
        display: flex !important;
    }
    
    .action-buttons-section .form-section-body {
        padding: 20px 16px !important;
    }
}
</style>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Compact Header Bar -->
<div class="compact-header-bar">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
            <div class="header-left">
                <h5 class="page-title-compact">
                    <i class="fas fa-edit me-2"></i>Edit Blog Post
                </h5>
            </div>
            <div class="header-actions">
                <a href="blog-posts.php" class="btn-compact btn-compact-secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span class="btn-text">Back</span>
                </a>
                <a href="../blog-post.php?id=<?php echo $post_id; ?>" target="_blank" class="btn-compact btn-compact-primary">
                    <i class="fas fa-external-link-alt"></i>
                    <span class="btn-text">View</span>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <form method="POST" enctype="multipart/form-data" id="blogPostForm">
            <!-- Compact Image Upload Section -->
            <div class="compact-image-section">
                <div class="image-upload-card">
                    <label class="image-label">
                        <i class="fas fa-image me-2"></i>Featured Image
                    </label>
                    <div class="image-upload-wrapper">
                        <?php if ($post['image_path']): ?>
                            <div class="current-image-display" onclick="document.getElementById('image').click()">
                                <img src="../<?php echo BLOG_IMAGES_DIR . $post['image_path']; ?>" 
                                     alt="Current blog image" class="img-preview-compact">
                                <div class="image-hover-overlay">
                                    <i class="fas fa-camera"></i>
                                    <span>Change Image</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="upload-placeholder" onclick="document.getElementById('image').click()">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Click to upload image</p>
                                <small>JPG, PNG, GIF, WEBP (Max 5MB)</small>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="d-none" id="image" name="image" accept="image/*" onchange="previewImage(this)">
                        <div id="imagePreview" class="new-image-display"></div>
                    </div>
                </div>
            </div>

                <!-- Basic Information -->
                <div class="form-section">
                    <div class="form-section-header">
                        <h6 class="form-section-title">
                            <i class="fas fa-info-circle"></i>
                            Basic Information
                        </h6>
                    </div>
                    <div class="form-section-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="topic" class="form-label">Topic/Category <span class="text-danger">*</span></label>
                                <div class="custom-dropdown">
                                    <div class="dropdown-toggle-custom" onclick="toggleTopicDropdown()">
                                        <span id="selectedTopic"><?php echo htmlspecialchars($post['topic'] ?? 'Select a topic...'); ?></span>
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                    <ul class="dropdown-menu-custom" id="topicDropdown">
                                        <li class="dropdown-item-custom" onclick="selectTopic('Fashion & Style')">Fashion & Style</li>
                                        <li class="dropdown-item-custom" onclick="selectTopic('Technology')">Technology</li>
                                        <li class="dropdown-item-custom" onclick="selectTopic('Lifestyle')">Lifestyle</li>
                                        <li class="dropdown-item-custom" onclick="selectTopic('Business')">Business</li>
                                        <li class="dropdown-item-custom" onclick="selectTopic('Health & Wellness')">Health & Wellness</li>
                                        <li class="dropdown-item-custom" onclick="selectTopic('Travel')">Travel</li>
                                        <li class="dropdown-item-custom" onclick="selectTopic('Food & Cooking')">Food & Cooking</li>
                                        <li class="dropdown-item-custom" onclick="selectTopic('Home & Garden')">Home & Garden</li>
                                        <li class="dropdown-item-custom" onclick="selectTopic('Other')">Other</li>
                                    </ul>
                                </div>
                                <input type="hidden" name="topic" id="topic" value="<?php echo htmlspecialchars($post['topic'] ?? ''); ?>" required>
                                <small class="form-text text-muted">Choose the main category for your blog post</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="heading" class="form-label">Blog Post Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-beautiful" id="heading" name="heading"
                                       value="<?php echo htmlspecialchars($post['heading'] ?? ''); ?>" placeholder="Enter a compelling title that grabs attention" required>
                                <small class="form-text text-muted">This will be prominently displayed in the hero header</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="short_description" class="form-label">Short Description/Summary</label>
                            <textarea class="form-control form-control-beautiful" id="short_description" name="short_description"
                                      rows="3" placeholder="Write a brief, engaging summary that will appear below the title and entice readers to continue reading..."><?php echo htmlspecialchars($post['short_description'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">A compelling summary that appears in blog cards and search results (100-150 characters recommended)</small>
                        </div>
                    </div>
                </div>

                <!-- Content Paragraphs -->
                <div class="form-section">
                    <div class="form-section-header">
                        <h6 class="form-section-title">
                            <i class="fas fa-edit"></i>
                            Blog Content
                        </h6>
                    </div>
                    <div class="form-section-body">
                        <div class="mb-4">
                            <label for="paragraphs_count" class="form-label">Content Structure <span class="text-danger">*</span></label>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="custom-dropdown">
                                        <div class="dropdown-toggle-custom" onclick="toggleDropdown()">
                                            <span id="selectedCount">
                                                <?php
                                                $current_paragraphs = $post['paragraphs_json'] ? json_decode($post['paragraphs_json'], true) : [];
                                                $paragraph_count = is_array($current_paragraphs) ? count($current_paragraphs) : 1;
                                                echo $paragraph_count . ' Paragraph' . ($paragraph_count > 1 ? 's' : '');
                                                ?>
                                            </span>
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                        <ul class="dropdown-menu-custom" id="paragraphsDropdown">
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                                <li class="dropdown-item-custom" onclick="selectParagraphCount(<?php echo $i; ?>)">
                                                    <?php echo $i; ?> Paragraph<?php echo $i > 1 ? 's' : ''; ?>
                                                </li>
                                            <?php endfor; ?>
                                        </ul>
                                    </div>
                                    <input type="hidden" name="paragraphs_count" id="paragraphs_count" value="<?php echo $paragraph_count; ?>">
                                </div>
                                <div class="col-md-6">
                                    <div class="content-tips bg-light rounded-lg p-3">
                                        <h6 class="text-primary-custom mb-2"><i class="fas fa-lightbulb me-1"></i>Content Tips</h6>
                                        <ul class="text-sm text-muted mb-0" style="font-size: 0.85rem;">
                                            <li>Write in a conversational tone</li>
                                            <li>Use short paragraphs (2-4 sentences)</li>
                                            <li>Add headings for better readability</li>
                                            <li>Include relevant keywords naturally</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <small class="form-text text-muted">Choose how many paragraphs your blog post will have</small>
                        </div>

                        <div id="paragraphsContainer">
                            <?php
                            $current_paragraphs = $post['paragraphs_json'] ? json_decode($post['paragraphs_json'], true) : [];
                            $current_headings = $post['paragraph_headings_json'] ? json_decode($post['paragraph_headings_json'], true) : [];

                            if (is_array($current_paragraphs)) {
                                foreach ($current_paragraphs as $index => $paragraph) {
                                    $heading = is_array($current_headings) && isset($current_headings[$index])
                                             ? $current_headings[$index]
                                             : "";
                                    echo '
                                    <div class="paragraph-container">
                                        <div class="paragraph-header mb-3">
                                            <label class="paragraph-label">
                                                <i class="fas fa-heading me-2"></i>
                                                Section ' . ($index + 1) . ' Heading <span class="text-muted">(Optional)</span>
                                            </label>
                                            <input type="text" class="form-control form-control-beautiful paragraph-heading"
                                                   name="paragraph_heading_' . ($index + 1) . '"
                                                   value="' . htmlspecialchars($heading) . '"
                                                   placeholder="Enter a compelling heading for this section...">
                                        </div>
                                        <div class="paragraph-content">
                                            <label class="paragraph-label">
                                                <i class="fas fa-edit me-2"></i>
                                                Section ' . ($index + 1) . ' Content <span class="text-danger">*</span>
                                            </label>
                                            <textarea class="paragraph-textarea" name="paragraph_' . ($index + 1) . '" rows="4"
                                                      placeholder="Write engaging content for section ' . ($index + 1) . '..." required>' .
                                                      htmlspecialchars($paragraph) . '</textarea>
                                        </div>
                                        ' . ($paragraph_count > 1 ? '<button type="button" class="remove-paragraph" onclick="removeParagraph(this)" title="Remove this section">
                                            <i class="fas fa-times"></i>
                                        </button>' : '') . '
                                    </div>';
                                }
                            } else {
                                // Fallback for old content format
                                $paragraphs = explode("\n\n", $post['content']);
                                foreach ($paragraphs as $index => $paragraph) {
                                    if (!empty(trim($paragraph))) {
                                        echo '
                                        <div class="paragraph-container">
                                            <div class="paragraph-header mb-3">
                                                <label class="paragraph-label">
                                                    <i class="fas fa-heading me-2"></i>
                                                    Section ' . ($index + 1) . ' Heading <span class="text-muted">(Optional)</span>
                                                </label>
                                                <input type="text" class="form-control form-control-beautiful paragraph-heading"
                                                       name="paragraph_heading_' . ($index + 1) . '"
                                                       placeholder="Enter a compelling heading for this section...">
                                            </div>
                                            <div class="paragraph-content">
                                                <label class="paragraph-label">
                                                    <i class="fas fa-edit me-2"></i>
                                                    Section ' . ($index + 1) . ' Content <span class="text-danger">*</span>
                                                </label>
                                                <textarea class="paragraph-textarea" name="paragraph_' . ($index + 1) . '" rows="4"
                                                          placeholder="Write engaging content for section ' . ($index + 1) . '..." required>' .
                                                          htmlspecialchars($paragraph) . '</textarea>
                                            </div>
                                            ' . (count($paragraphs) > 1 ? '<button type="button" class="remove-paragraph" onclick="removeParagraph(this)" title="Remove this section">
                                                <i class="fas fa-times"></i>
                                            </button>' : '') . '
                                        </div>';
                                    }
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Publishing Options -->
                <div class="form-section">
                    <div class="form-section-header">
                        <h6 class="form-section-title">
                            <i class="fas fa-cog"></i>
                            Publishing Options
                        </h6>
                    </div>
                    <div class="form-section-body">
                        <div class="publish-options bg-light rounded-lg p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="publish-toggle mb-3">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <label class="form-label mb-0">Publish Status</label>
                                                <small class="text-muted d-block">Choose when to make this post live</small>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="publish_status" name="is_published" <?php echo $post['is_published'] ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="publish_status">
                                                    <span id="publish_text"><?php echo $post['is_published'] ? 'Published' : 'Draft'; ?></span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="publish-info">
                                        <div class="text-<?php echo $post['is_published'] ? 'success' : 'warning'; ?>">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong><?php echo $post['is_published'] ? 'Currently Published' : 'Currently a Draft'; ?></strong>
                                        </div>
                                        <small class="text-muted">Last updated: <?php echo date('M d, Y H:i', strtotime($post['updated_at'])); ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="form-section action-buttons-section">
                    <div class="form-section-body">
                        <!-- Desktop Buttons -->
                        <div class="desktop-action-buttons">
                            <button type="submit" class="btn btn-beautiful btn-primary-beautiful btn-lg px-5">
                                <i class="fas fa-save me-2"></i>Update Blog Post
                            </button>
                            <button type="button" class="btn btn-beautiful btn-secondary-beautiful btn-lg px-4" onclick="previewPost()">
                                <i class="fas fa-eye me-2"></i>Preview
                            </button>
                            <a href="blog-posts.php" class="btn btn-beautiful btn-outline-secondary btn-lg px-4">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </a>
                            <button type="button" class="btn btn-beautiful btn-outline-danger btn-lg px-4" onclick="deletePost()">
                                <i class="fas fa-trash me-2"></i>Delete
                            </button>
                        </div>
                        
                        <!-- Mobile Shiny Gradient Icons -->
                        <div class="mobile-action-icons">
                            <button type="submit" class="action-icon-btn icon-save" title="Update Blog Post">
                                <i class="fas fa-save"></i>
                            </button>
                            <button type="button" class="action-icon-btn icon-preview" onclick="previewPost()" title="Preview">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a href="blog-posts.php" class="action-icon-btn icon-back" title="Back">
                                <i class="fas fa-arrow-left"></i>
                            </a>
                            <button type="button" class="action-icon-btn icon-delete" onclick="deletePost()" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        
                        <div class="text-center mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                All required fields are marked with <span class="text-danger">*</span>
                            </small>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentParagraphCount = <?php echo $paragraph_count; ?>;

function toggleDropdown() {
    const dropdown = document.getElementById('paragraphsDropdown');
    dropdown.classList.toggle('show');
}

function selectParagraphCount(count) {
    currentParagraphCount = count;
    document.getElementById('selectedCount').textContent = count + ' Paragraph' + (count > 1 ? 's' : '');
    document.getElementById('paragraphs_count').value = count;
    document.getElementById('paragraphsDropdown').classList.remove('show');
    generateParagraphs(count);
}

function generateParagraphs(count) {
    const container = document.getElementById('paragraphsContainer');
    container.innerHTML = '';

    for (let i = 1; i <= count; i++) {
        const paragraphDiv = document.createElement('div');
        paragraphDiv.className = 'paragraph-container';
        paragraphDiv.innerHTML = `
            <div class="paragraph-header mb-3">
                <label class="paragraph-label">
                    <i class="fas fa-heading me-2"></i>
                    Section ${i} Heading <span class="text-muted">(Optional)</span>
                </label>
                <input type="text" class="form-control form-control-beautiful paragraph-heading"
                       name="paragraph_heading_${i}" placeholder="Enter a compelling heading for this section...">
            </div>
            <div class="paragraph-content">
                <label class="paragraph-label">
                    <i class="fas fa-edit me-2"></i>
                    Section ${i} Content <span class="text-danger">*</span>
                </label>
                <textarea class="paragraph-textarea" name="paragraph_${i}" rows="4"
                          placeholder="Write engaging content for section ${i}..." required></textarea>
            </div>
            ${count > 1 ? `<button type="button" class="remove-paragraph" onclick="removeParagraph(this)" title="Remove this section">
                <i class="fas fa-times"></i>
            </button>` : ''}
        `;
        container.appendChild(paragraphDiv);
    }

    // Auto-resize textareas
    document.querySelectorAll('.paragraph-textarea').forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    });
}

function removeParagraph(button) {
    if (currentParagraphCount <= 1) {
        showAlert('At least one paragraph is required!', 'warning', 'Cannot Remove');
        return;
    }

    const paragraphDiv = button.closest('.paragraph-container');
    const container = document.getElementById('paragraphsContainer');
    const paragraphs = container.querySelectorAll('.paragraph-container');

    if (paragraphs.length > 1) {
        paragraphDiv.remove();
        currentParagraphCount--;
        document.getElementById('selectedCount').textContent = currentParagraphCount + ' Paragraph' + (currentParagraphCount > 1 ? 's' : '');
        document.getElementById('paragraphs_count').value = currentParagraphCount;

        // Renumber remaining paragraphs and headings
        paragraphs.forEach((para, index) => {
            if (para.parentNode) { // Check if still exists
                const headerLabel = para.querySelector('.paragraph-header .paragraph-label');
                const contentLabel = para.querySelector('.paragraph-content .paragraph-label');
                const headingInput = para.querySelector('.paragraph-heading');
                const textarea = para.querySelector('.paragraph-textarea');
                const removeBtn = para.querySelector('.remove-paragraph');

                const sectionNum = index + 1;
                headerLabel.innerHTML = `<i class="fas fa-heading me-2"></i> Section ${sectionNum} Heading <span class="text-muted">(Optional)</span>`;
                contentLabel.innerHTML = `<i class="fas fa-edit me-2"></i> Section ${sectionNum} Content <span class="text-danger">*</span>`;
                headingInput.name = `paragraph_heading_${sectionNum}`;
                headingInput.placeholder = `Enter a compelling heading for section ${sectionNum}...`;
                textarea.name = `paragraph_${sectionNum}`;
                textarea.placeholder = `Write engaging content for section ${sectionNum}... Make it informative and captivating for your readers!`;

                if (currentParagraphCount > 1) {
                    if (!removeBtn) {
                        const newRemoveBtn = document.createElement('button');
                        newRemoveBtn.type = 'button';
                        newRemoveBtn.className = 'remove-paragraph';
                        newRemoveBtn.onclick = function() { removeParagraph(this); };
                        newRemoveBtn.title = 'Remove this section';
                        newRemoveBtn.innerHTML = '<i class="fas fa-times"></i>';
                        para.appendChild(newRemoveBtn);
                    }
                } else {
                    if (removeBtn) {
                        removeBtn.remove();
                    }
                }
            }
        });
    }
}

function previewPost() {
    // Show a preview modal or open in new tab
    const form = document.getElementById('blogPostForm');
    const formData = new FormData(form);

    // This would typically open a preview modal
    showAlert('Preview functionality would open a modal showing how the blog post will look.', 'info', 'Preview');
}

// Image preview
document.getElementById('image').addEventListener('change', function(e) {
    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';

    if (e.target.files && e.target.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewContainer = document.createElement('div');
            previewContainer.className = 'new-image-container';
            
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'image-preview';
            
            const overlay = document.createElement('div');
            overlay.className = 'image-overlay';
            overlay.innerHTML = '<i class="fas fa-check"></i>';
            
            previewContainer.appendChild(img);
            previewContainer.appendChild(overlay);
            preview.appendChild(previewContainer);
        };
        reader.readAsDataURL(e.target.files[0]);
    }
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('paragraphsDropdown');
    const toggle = document.querySelector('.dropdown-toggle-custom');

    if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.remove('show');
    }
});

// Auto-resize textareas on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.paragraph-textarea').forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // Set initial height
        textarea.style.height = 'auto';
        textarea.style.height = (textarea.scrollHeight) + 'px';
    });
});

async function deletePost() {
    if (await showConfirm('This blog post will be permanently deleted. This action cannot be undone.', 'Delete Blog Post', {confirmText: 'Yes, Delete', cancelText: 'Cancel', type: 'danger'})) {
        // Create a form to submit delete request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'blog-posts.php';

        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = 'delete_post';
        deleteInput.value = '1';

        const postIdInput = document.createElement('input');
        postIdInput.type = 'hidden';
        postIdInput.name = 'post_id';
        postIdInput.value = '<?php echo $post_id; ?>';

        form.appendChild(deleteInput);
        form.appendChild(postIdInput);
        document.body.appendChild(form);
        form.submit();
    }
}

// Topic dropdown functionality
function toggleTopicDropdown() {
    const dropdown = document.getElementById('topicDropdown');
    dropdown.classList.toggle('show');
}

function selectTopic(topic) {
    document.getElementById('selectedTopic').textContent = topic;
    document.getElementById('topic').value = topic;
    document.getElementById('topicDropdown').classList.remove('show');
}

// Close topic dropdown when clicking outside
document.addEventListener('click', function(e) {
    const topicDropdown = document.getElementById('topicDropdown');
    const topicToggle = document.querySelector('[onclick="toggleTopicDropdown()"]');

    if (topicToggle && !topicToggle.contains(e.target) && !topicDropdown.contains(e.target)) {
        topicDropdown.classList.remove('show');
    }
});
</script>

<script>
// Image Preview Function
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const currentDisplay = document.querySelector('.current-image-display');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            // Hide current image if exists
            if (currentDisplay) {
                currentDisplay.style.display = 'none';
            }
            
            // Show new image preview
            preview.innerHTML = '<img src="' + e.target.result + '" alt="New image preview">';
        }
        
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
