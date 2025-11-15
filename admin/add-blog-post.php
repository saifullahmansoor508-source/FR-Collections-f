<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $topic = sanitizeInput($_POST['topic']);
    $heading = sanitizeInput($_POST['heading']);
    $short_description = sanitizeInput($_POST['short_description']);
    $paragraphs_count = intval($_POST['paragraphs_count']);
    
    // Determine post status
    $is_published = 0;
    $is_scheduled = 0;
    $scheduled_date = null;
    $scheduled_time = null;
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'publish') {
            $is_published = 1;
        } elseif ($_POST['action'] === 'schedule') {
            $is_scheduled = 1;
            $scheduled_date = sanitizeInput($_POST['scheduled_date']);
            $scheduled_time = sanitizeInput($_POST['scheduled_time']);
        }
        // If action is 'draft', both remain 0
    }
    
    $image_path = '';

    // Collect paragraphs and headings
    $paragraphs = [];
    $headings = [];
    for ($i = 1; $i <= $paragraphs_count; $i++) {
        $paragraph = sanitizeInput($_POST["paragraph_$i"] ?? '');
        $heading = sanitizeInput($_POST["paragraph_heading_$i"] ?? '');

        if (!empty($paragraph)) {
            $paragraphs[] = $paragraph;
            $headings[] = !empty($heading) ? $heading : "Section $i";
        }
    }

    if (empty($topic) || empty($heading) || empty($paragraphs)) {
        $error = "Please fill all required fields.";
    } else {
        try {
            $db->beginTransaction();

            // Handle image upload
            if (!empty($_FILES['image']['name'])) {
                $upload_dir = '../' . BLOG_IMAGES_DIR;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $file = $_FILES['image'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (in_array($file_ext, $allowed_types) && $file['size'] <= 5242880) {
                    $image_path = 'blog_' . uniqid() . '.' . $file_ext;
                    move_uploaded_file($file['tmp_name'], $upload_dir . $image_path);
                }
            }

            // Convert paragraphs and headings to JSON for structured storage
            $paragraphs_json = json_encode($paragraphs);
            $headings_json = json_encode($headings);
            $content = implode("\n\n", $paragraphs); // Keep original content format for backward compatibility

            // Insert blog post
            $stmt = $db->prepare("
                INSERT INTO blog_posts (topic, title, heading, short_description, content, paragraphs_json, paragraph_headings_json, image_path, is_published, is_scheduled, scheduled_date, scheduled_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $topic, $heading, $heading, $short_description, $content, $paragraphs_json, $headings_json, $image_path, $is_published, $is_scheduled, $scheduled_date, $scheduled_time
            ]);

            $db->commit();
            $success = "Blog post created successfully!";

            // Reset form
            $topic = $heading = $short_description = '';
            $paragraphs_count = 1;
            $paragraphs = [];

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error creating blog post: " . $e->getMessage();
        }
    }
}

$page_title = "Add Blog Post";
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

.featured-image-upload-container {
    max-width: 100%;
}

.image-upload-area {
    border: 3px dashed #e2e8f0;
    border-radius: 15px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
}

.image-upload-area:hover {
    border-color: var(--primary-color);
    background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(30, 58, 138, 0.1);
}

.image-upload-content {
    color: #64748b;
}

.image-upload-area:hover .image-upload-content {
    color: var(--primary-color);
}

.image-upload-area:hover .image-upload-content i {
    color: var(--primary-color);
    transform: scale(1.1);
}

.content-tips {
    border-left: 4px solid var(--accent-color);
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
}

.publish-options {
    border: 1px solid #e2e8f0;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
}

.form-check-input:checked {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}

.form-check-input:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 0.25rem rgba(30, 58, 138, 0.25);
}

.publish-info .text-success {
    color: #059669 !important;
}

.paragraph-header {
    border-left: 4px solid var(--accent-color);
    padding-left: 15px;
    margin-bottom: 20px;
}

.paragraph-content {
    border-left: 4px solid var(--primary-color);
    padding-left: 15px;
}

.paragraph-heading {
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 15px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: #f8fafc;
    font-weight: 500;
}

.paragraph-heading:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    outline: none;
    background: white;
}

.paragraph-container:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 15px rgba(30, 58, 138, 0.1);
    transform: translateY(-2px);
}

.paragraph-container::before {
    content: '';
    position: absolute;
    left: -8px;
    top: 20px;
    width: 4px;
    height: 30px;
    background: var(--accent-color);
    border-radius: 2px;
}

.paragraph-label {
    font-weight: 600;
    color: var(--primary-color);
    margin-bottom: 10px;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.paragraph-label i {
    font-size: 0.9rem;
}

.paragraph-textarea {
    width: 100%;
    border: 2px solid #d1d5db;
    border-radius: 10px;
    padding: 15px;
    font-size: 1rem;
    resize: vertical;
    min-height: 120px;
    transition: all 0.3s ease;
    background: white;
    font-family: inherit;
}

.paragraph-textarea:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    outline: none;
    background: white;
}

.remove-paragraph {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    border: none;
    border-radius: 50%;
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    margin-top: 10px;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
}

.remove-paragraph:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

/* Responsive improvements */
@media (max-width: 768px) {
    .image-upload-area {
        padding: 30px 15px;
    }

    .image-upload-content h5 {
        font-size: 1.25rem;
    }

    .d-flex.gap-3 {
        flex-wrap: wrap;
        gap: 10px !important;
    }

    .btn-lg {
        padding: 10px 20px;
        font-size: 0.95rem;
    }

    .content-tips ul {
        padding-left: 20px;
    }

    .publish-options .row {
        text-align: center;
    }

    .publish-options .d-flex {
        flex-direction: column;
        gap: 15px;
    }
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

<div class="row">
    <div class="col-12">
        <div class="bg-white rounded-lg shadow p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">Create New Blog Post</h5>
                <a href="blog-posts.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Blog Posts
                </a>
            </div>

            <form method="POST" enctype="multipart/form-data" id="blogPostForm">
                <!-- Hero Section Setup -->
                <div class="form-section">
                    <div class="form-section-header">
                        <h6 class="form-section-title">
                            <i class="fas fa-image"></i>
                            Hero Section & Featured Image
                        </h6>
                    </div>
                    <div class="form-section-body">
                        <div class="row">
                            <div class="col-12 mb-4">
                                <div class="featured-image-upload-container">
                                    <label for="image" class="form-label">Featured Image <span class="text-danger">*</span></label>
                                    <div class="image-upload-area" onclick="document.getElementById('image').click()">
                                        <div class="image-upload-content">
                                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                            <h5 class="text-primary-custom">Upload Featured Image</h5>
                                            <p class="text-muted">Choose an attractive image that will serve as your blog post header</p>
                                            <small class="text-muted">Recommended size: 1200x600px • Max file size: 5MB</small>
                                        </div>
                                        <input type="file" class="form-control form-control-beautiful d-none" id="image" name="image" accept="image/*" required>
                                    </div>
                                    <div id="imagePreview" class="mt-3"></div>
                                </div>
                            </div>
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
                                        <span id="selectedTopic">Select a topic...</span>
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
                                <input type="hidden" name="topic" id="topic" required>
                                <small class="form-text text-muted">Choose the main category for your blog post</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="heading" class="form-label">Blog Post Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-beautiful" id="heading" name="heading"
                                       placeholder="Enter a compelling title that grabs attention" required>
                                <small class="form-text text-muted">This will be prominently displayed in the hero header</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="short_description" class="form-label">Short Description/Summary</label>
                            <textarea class="form-control form-control-beautiful" id="short_description" name="short_description"
                                      rows="3" placeholder="Write a brief, engaging summary that will appear below the title and entice readers to continue reading..."><?php echo htmlspecialchars($short_description ?? ''); ?></textarea>
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
                                            <span id="selectedCount">Select number of paragraphs...</span>
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
                                    <input type="hidden" name="paragraphs_count" id="paragraphs_count" value="<?php echo $paragraphs_count ?? 1; ?>">
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
                            <!-- Dynamic paragraphs with headings will be added here -->
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
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Publishing Options:</strong> You can publish immediately, save as draft, or schedule for later.
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Schedule Section (Hidden by default) -->
                            <div id="scheduleSection" class="mt-3" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="scheduled_date" class="form-label">Schedule Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control form-control-beautiful" id="scheduled_date" name="scheduled_date" min="<?php echo date('Y-m-d'); ?>">
                                        <small class="text-muted">Select the date when this post should be published</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="scheduled_time" class="form-label">Schedule Time <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control form-control-beautiful" id="scheduled_time" name="scheduled_time">
                                        <small class="text-muted">Select the time when this post should be published</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <input type="hidden" name="action" id="formAction" value="">
                <div class="form-section">
                    <div class="form-section-body">
                        <div class="d-flex gap-3 align-items-center justify-content-center flex-wrap">
                            <button type="button" class="btn btn-beautiful btn-primary-beautiful btn-lg px-5" onclick="submitForm('publish')">
                                <i class="fas fa-rocket me-2"></i>Publish Now
                            </button>
                            <button type="button" class="btn btn-beautiful btn-secondary-beautiful btn-lg px-4" onclick="submitForm('draft')">
                                <i class="fas fa-save me-2"></i>Save as Draft
                            </button>
                            <button type="button" class="btn btn-beautiful btn-lg px-4" onclick="toggleSchedule()" style="background: linear-gradient(135deg, #8b5cf6, #6366f1); color: white;">
                                <i class="fas fa-clock me-2"></i>Schedule Post
                            </button>
                            <a href="blog-posts.php" class="btn btn-beautiful btn-outline-danger btn-lg px-4">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
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
let currentParagraphCount = <?php echo $paragraphs_count ?? 1; ?>;

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
            <div class="paragraph-label">
                <i class="fas fa-paragraph me-2"></i>
                Paragraph ${i} <span class="text-danger">*</span>
            </div>
            <textarea class="paragraph-textarea" name="paragraph_${i}" rows="4"
                      placeholder="Write your content for paragraph ${i}... Make it engaging and informative!"
                      required></textarea>
            ${count > 1 ? `<button type="button" class="remove-paragraph" onclick="removeParagraph(this)" title="Remove this paragraph">
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

        // Renumber remaining paragraphs
        paragraphs.forEach((para, index) => {
            const label = para.querySelector('.paragraph-label');
            const textarea = para.querySelector('.paragraph-textarea');
            const removeBtn = para.querySelector('.remove-paragraph');

            label.innerHTML = `<i class="fas fa-paragraph me-2"></i> Paragraph ${index + 1} <span class="text-danger">*</span>`;
            textarea.name = `paragraph_${index + 1}`;
            textarea.placeholder = `Write your content for paragraph ${index + 1}... Make it engaging and informative!`;

            if (currentParagraphCount > 1) {
                if (!removeBtn) {
                    const newRemoveBtn = document.createElement('button');
                    newRemoveBtn.type = 'button';
                    newRemoveBtn.className = 'remove-paragraph';
                    newRemoveBtn.onclick = function() { removeParagraph(this); };
                    newRemoveBtn.title = 'Remove this paragraph';
                    newRemoveBtn.innerHTML = '<i class="fas fa-times"></i>';
                    para.appendChild(newRemoveBtn);
                }
            } else {
                if (removeBtn) {
                    removeBtn.remove();
                }
            }
        });
    }
}

function saveDraft() {
    // Set a hidden field to indicate draft status
    const draftField = document.createElement('input');
    draftField.type = 'hidden';
    draftField.name = 'is_draft';
    draftField.value = '1';
    document.getElementById('blogPostForm').appendChild(draftField);

    // Submit form
    document.getElementById('blogPostForm').submit();
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
                    Section ${i} Heading <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control form-control-beautiful paragraph-heading"
                       name="paragraph_heading_${i}" placeholder="Enter a compelling heading for this section..."
                       required style="margin-bottom: 15px;">
            </div>
            <div class="paragraph-content">
                <label class="paragraph-label">
                    <i class="fas fa-edit me-2"></i>
                    Section ${i} Content <span class="text-danger">*</span>
                </label>
                <textarea class="paragraph-textarea" name="paragraph_${i}" rows="4"
                          placeholder="Write engaging content for section ${i}... Make it informative and captivating for your readers!"
                          required></textarea>
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
        showNotification('At least one section is required!', 'error');
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
                headerLabel.innerHTML = `<i class="fas fa-heading me-2"></i> Section ${sectionNum} Heading <span class="text-danger">*</span>`;
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

function submitForm(action) {
    // Set the action
    document.getElementById('formAction').value = action;
    
    // Validate schedule fields if scheduling
    if (action === 'schedule') {
        const scheduleDate = document.getElementById('scheduled_date').value;
        const scheduleTime = document.getElementById('scheduled_time').value;
        
        if (!scheduleDate || !scheduleTime) {
            showNotification('Please select both date and time for scheduling', 'error');
            return;
        }
    }
    
    // Submit the form
    document.getElementById('blogPostForm').submit();
}

function toggleSchedule() {
    const scheduleSection = document.getElementById('scheduleSection');
    const isVisible = scheduleSection.style.display !== 'none';
    
    if (isVisible) {
        // Hide schedule section and submit as scheduled
        submitForm('schedule');
    } else {
        // Show schedule section
        scheduleSection.style.display = 'block';
        showNotification('Please select date and time, then click "Schedule Post" again', 'info');
    }
}

// Enhanced image preview
document.getElementById('image').addEventListener('change', function(e) {
    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';

    if (e.target.files && e.target.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'image-preview';
            img.style.cssText = 'max-width: 100%; height: 250px; object-fit: cover; border-radius: 15px; border: 3px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.1);';
            preview.appendChild(img);

            // Add remove button
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-danger btn-sm position-absolute';
            removeBtn.style.cssText = 'top: 10px; right: 10px; border-radius: 50%; width: 30px; height: 30px; padding: 0;';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.onclick = function() {
                document.getElementById('image').value = '';
                preview.innerHTML = '';
            };
            preview.style.position = 'relative';
            preview.appendChild(removeBtn);
        };
        reader.readAsDataURL(e.target.files[0]);
    }
});

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    const topicDropdown = document.getElementById('topicDropdown');
    const topicToggle = document.querySelector('[onclick="toggleTopicDropdown()"]');

    const paragraphsDropdown = document.getElementById('paragraphsDropdown');
    const paragraphsToggle = document.querySelector('[onclick="toggleDropdown()"]');

    // Close topic dropdown
    if (!topicToggle?.contains(e.target) && !topicDropdown?.contains(e.target)) {
        topicDropdown?.classList.remove('show');
    }

    // Close paragraphs dropdown
    if (!paragraphsToggle?.contains(e.target) && !paragraphsDropdown?.contains(e.target)) {
        paragraphsDropdown?.classList.remove('show');
    }
});


// Show notification
function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 350px; max-width: 500px;';
    notification.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation-triangle' : 'info'}-circle me-2"></i>${message}`;

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 4000);
}

// Initialize paragraphs on page load
document.addEventListener('DOMContentLoaded', function() {
    generateParagraphs(currentParagraphCount);

    // Auto-resize textareas
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
</script>

<?php require_once 'includes/footer.php'; ?>
