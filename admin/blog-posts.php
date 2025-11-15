<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_delete'])) {
    $post_ids = isset($_POST['selected_posts']) ? $_POST['selected_posts'] : [];
    
    if (!empty($post_ids)) {
        try {
            $db->beginTransaction();
            $deleted_count = 0;
            
            foreach ($post_ids as $post_id) {
                $post_id = intval($post_id);
                
                // Get image path before deleting
                $stmt = $db->prepare("SELECT image_path FROM blog_posts WHERE id = ?");
                $stmt->execute([$post_id]);
                $post = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Delete image file if exists
                if ($post && $post['image_path']) {
                    $image_path = '../' . BLOG_IMAGES_DIR . $post['image_path'];
                    if (file_exists($image_path)) {
                        unlink($image_path);
                    }
                }
                
                // Delete blog post
                $stmt = $db->prepare("DELETE FROM blog_posts WHERE id = ?");
                $stmt->execute([$post_id]);
                $deleted_count++;
            }
            
            $db->commit();
            $success = "{$deleted_count} blog post(s) deleted successfully!";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error deleting blog posts: " . $e->getMessage();
        }
    } else {
        $error = "No posts selected for deletion.";
    }
}

// Handle bulk publish
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_publish'])) {
    $post_ids = isset($_POST['selected_posts']) ? $_POST['selected_posts'] : [];
    
    if (!empty($post_ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
            $stmt = $db->prepare("UPDATE blog_posts SET is_published = 1 WHERE id IN ($placeholders)");
            $stmt->execute($post_ids);
            
            $success = count($post_ids) . " blog post(s) published successfully!";
            
        } catch (Exception $e) {
            $error = "Error publishing blog posts: " . $e->getMessage();
        }
    } else {
        $error = "No posts selected for publishing.";
    }
}

// Handle bulk unpublish
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_unpublish'])) {
    $post_ids = isset($_POST['selected_posts']) ? $_POST['selected_posts'] : [];
    
    if (!empty($post_ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
            $stmt = $db->prepare("UPDATE blog_posts SET is_published = 0 WHERE id IN ($placeholders)");
            $stmt->execute($post_ids);
            
            $success = count($post_ids) . " blog post(s) unpublished successfully!";
            
        } catch (Exception $e) {
            $error = "Error unpublishing blog posts: " . $e->getMessage();
        }
    } else {
        $error = "No posts selected for unpublishing.";
    }
}

// Handle blog post deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_post'])) {
    $post_id = intval($_POST['post_id']);

    try {
        $db->beginTransaction();

        // Get image path before deleting
        $stmt = $db->prepare("SELECT image_path FROM blog_posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        // Delete image file if exists
        if ($post['image_path']) {
            $image_path = '../' . BLOG_IMAGES_DIR . $post['image_path'];
            if (file_exists($image_path)) {
                unlink($image_path);
            }
        }

        // Delete blog post
        $stmt = $db->prepare("DELETE FROM blog_posts WHERE id = ?");
        $stmt->execute([$post_id]);

        $db->commit();
        $success = "Blog post deleted successfully!";

    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error deleting blog post: " . $e->getMessage();
    }
}

// Handle publish/unpublish toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_publish'])) {
    $post_id = intval($_POST['post_id']);
    $current_status = intval($_POST['current_status']);

    try {
        $new_status = $current_status ? 0 : 1;
        $stmt = $db->prepare("UPDATE blog_posts SET is_published = ? WHERE id = ?");
        $stmt->execute([$new_status, $post_id]);

        $success = $new_status ? "Blog post published successfully!" : "Blog post unpublished successfully!";

    } catch (Exception $e) {
        $error = "Error updating blog post status.";
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

$base_query = "SELECT * FROM blog_posts WHERE 1=1";

if ($status_filter) {
    if ($status_filter === 'published') {
        $where_conditions[] = "is_published = 1";
    } elseif ($status_filter === 'draft') {
        $where_conditions[] = "is_published = 0";
    }
}

if ($search) {
    $where_conditions[] = "(title LIKE ? OR short_description LIKE ? OR topic LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($where_conditions)) {
    $base_query .= " AND " . implode(" AND ", $where_conditions);
}

$base_query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($base_query);
$stmt->execute($params);
$blog_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Blog Posts Management";
require_once 'includes/header.php';

// Calculate stats
$total_posts = count($blog_posts);
$published_count = count(array_filter($blog_posts, function($post) { return $post['is_published']; }));
$draft_count = count(array_filter($blog_posts, function($post) { return !$post['is_published']; }));

// Calculate this week's posts (last 7 days)
$one_week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$weekly_count = count(array_filter($blog_posts, function($post) use ($one_week_ago) {
    return $post['created_at'] >= $one_week_ago;
}));
?>

<style>
/* ========================================
   MODERN ADMIN STYLES FOR BLOG POSTS
   ======================================== */

/* Page Header Card */
.page-header-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}

.page-header-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 200px;
    height: 200px;
    background: linear-gradient(135deg, rgba(0,88,163,0.07) 0%, rgba(255,107,0,0.07) 100%);
    border-radius: 50%;
    transform: translate(100px, -100px);
}

.page-header-content {
    display: flex;
    align-items: center;
    position: relative;
    z-index: 1;
    margin-bottom: 30px;
    flex: 1;
}

.page-header-icon {
    width: 70px;
    height: 70px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2rem;
    margin-right: 20px;
    box-shadow: 0 4px 15px rgba(0,88,163,0.3);
}

.page-header-text h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 5px;
}

.page-header-text .page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 5px;
}

.page-header-text .page-subtitle {
    font-size: 1rem;
    color: #718096;
    margin: 0;
}

.page-header-actions {
    position: relative;
    z-index: 1;
}

.btn-add-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    border: none;
    box-shadow: 0 4px 15px rgba(0,88,163,0.3);
    transition: all 0.3s ease;
}

.btn-add-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,88,163,0.4);
    color: white;
}

/* Stats Grid */
.stats-grid {
    display: flex;
    gap: 20px;
    position: relative;
    z-index: 1;
    margin-left: auto;
}

.stats-card-simple {
    background: linear-gradient(135deg, #0058A3, #FF6B00);
    color: white;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    height: 100%;
    min-height: 120px;
}

.stats-card-simple:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.stats-card-simple::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}

.stats-content-simple {
    position: relative;
    z-index: 1;
}

.stats-content-simple h3 {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 5px;
}

.stats-content-simple p {
    margin-bottom: 15px;
    font-weight: 500;
}

/* Search Card */
.search-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}

.search-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.search-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
}

.btn-clear-modern {
    background: #f7fafc;
    color: #4a5568;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.btn-clear-modern:hover {
    background: #edf2f7;
    color: #2d3748;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 16px;
    color: #a0aec0;
    font-size: 1.1rem;
}

.search-field {
    width: 100%;
    padding: 14px 16px 14px 48px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.search-field:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0,88,163,0.1);
}

.search-btn-modern {
    position: absolute;
    right: 6px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 10px 20px;
    border-radius: 6px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.search-btn-modern:hover {
    transform: scale(1.05);
}

/* Filters Card */
.filters-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}

.filters-header {
    margin-bottom: 20px;
}

.filters-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
}

.filters-grid {
    display: flex;
    gap: 20px;
    align-items: center;
}

.filter-group {
    display: flex;
    flex-direction: column;
    flex: 1;
    max-width: 300px;
}

.filter-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 8px;
}

.custom-dropdown-modern {
    position: relative;
    width: 100%;
}

.dropdown-selected-modern {
    padding: 12px 16px;
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
}

.dropdown-selected-modern:hover {
    border-color: var(--primary-color);
}

.custom-dropdown-modern.active .dropdown-selected-modern {
    border-color: var(--primary-color);
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}

.dropdown-arrow-modern {
    transition: transform 0.3s ease;
    color: #a0aec0;
}

.custom-dropdown-modern.active .dropdown-arrow-modern {
    transform: rotate(180deg);
}

.dropdown-options-modern {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid var(--primary-color);
    border-top: none;
    border-radius: 0 0 8px 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    max-height: 0;
    overflow: hidden;
    opacity: 0;
    transition: all 0.3s ease;
    z-index: 1000;
}

.custom-dropdown-modern.active .dropdown-options-modern {
    max-height: 300px;
    opacity: 1;
}

.dropdown-option-modern {
    padding: 12px 16px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 10px;
}

.dropdown-option-modern:hover {
    background: #f7fafc;
}

.dropdown-option-modern.selected {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    font-weight: 600;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}

.status-pending { background: #f59e0b; }
.status-confirmed { background: #3b82f6; }
.status-on-the-way { background: #8b5cf6; }
.status-delivered { background: #10b981; }
.status-canceled { background: #ef4444; }


/* Table Card */
.table-card-modern {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow: hidden;
}

.table-header-modern {
    padding: 20px 24px;
    border-bottom: 2px solid #f7fafc;
}

.table-title-modern {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
}

.table-modern {
    width: 100%;
    border-collapse: collapse;
}

.table-modern thead {
    background: #f7fafc;
}

.table-modern th {
    padding: 16px 24px;
    text-align: left;
    font-size: 0.85rem;
    font-weight: 600;
    color: #4a5568;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table-modern td {
    padding: 20px 24px;
    border-top: 1px solid #f7fafc;
    vertical-align: middle;
}

.table-modern tbody tr {
    transition: all 0.2s ease;
}

.table-modern tbody tr:hover {
    background: #f7fafc;
}

.table-product-image {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    overflow: hidden;
    flex-shrink: 0;
}

.table-product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.table-product-name {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 4px;
}

.table-product-sku {
    font-size: 0.85rem;
    color: #a0aec0;
}

.table-date {
    font-weight: 500;
    color: #4a5568;
}

.table-time {
    font-size: 0.85rem;
    color: #a0aec0;
}

.badge-modern {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.badge-success {
    background: #d1fae5;
    color: #065f46;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-info {
    background: #dbeafe;
    color: #1e40af;
}

.action-buttons-modern {
    display: flex;
    gap: 8px;
}

.btn-action-modern {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.9rem;
}

.btn-action-edit {
    background: #dbeafe;
    color: #1e40af;
}

.btn-action-edit:hover {
    background: #3b82f6;
    color: white;
}

.btn-action-success {
    background: #d1fae5;
    color: #065f46;
}

.btn-action-success:hover {
    background: #10b981;
    color: white;
}

.btn-action-warning {
    background: #fef3c7;
    color: #92400e;
}

.btn-action-warning:hover {
    background: #f59e0b;
    color: white;
}

.btn-action-info {
    background: #e0e7ff;
    color: #3730a3;
}

.btn-action-info:hover {
    background: #6366f1;
    color: white;
}

.btn-action-delete {
    background: #fee2e2;
    color: #991b1b;
}

.btn-action-delete:hover {
    background: #ef4444;
    color: white;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 60px 20px;
}

.empty-state-icon-modern {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: #f7fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    font-size: 2.5rem;
    color: #a0aec0;
}

.empty-state-title-modern {
    font-size: 1.5rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 12px;
}

.empty-state-text-modern {
    font-size: 1rem;
    color: #718096;
    margin-bottom: 24px;
}

/* Checkbox Styles */
.form-check-input {
    width: 20px;
    height: 20px;
    border: 2px solid #cbd5e0;
    cursor: pointer;
    transition: all 0.2s ease;
}

.form-check-input:checked {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}

.form-check-input:hover {
    border-color: var(--primary-color);
}

.table-modern tbody tr.selected {
    background-color: #f0f9ff !important;
}

#bulkActions {
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ========================================
   BLOG POSTS LIST CARD LAYOUT (DESKTOP)
   ======================================== */
.posts-list-card-modern {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow: hidden;
}

.list-header-modern {
    padding: 20px 24px;
    border-bottom: 2px solid #f7fafc;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.list-header-left {
    flex: 1;
}

.bulk-actions-modern {
    display: flex;
    align-items: center;
    gap: 16px;
}

.checkbox-modern {
    position: relative;
    display: flex;
    align-items: center;
    cursor: pointer;
    user-select: none;
}

.checkbox-modern input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    height: 0;
    width: 0;
}

.checkmark-modern {
    height: 22px;
    width: 22px;
    background-color: #fff;
    border: 2px solid #d1d5db;
    border-radius: 6px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.checkbox-modern:hover .checkmark-modern {
    border-color: var(--primary-color);
}

.checkbox-modern input:checked ~ .checkmark-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    border-color: transparent;
}

.checkmark-modern:after {
    content: "";
    display: none;
}

.checkbox-modern input:checked ~ .checkmark-modern:after {
    display: block;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.checkbox-label {
    margin-left: 10px;
    font-weight: 600;
    color: #374151;
    font-size: 0.95rem;
}

.btn-delete-selected-modern {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
    border: 2px solid #fecaca;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
}

.btn-delete-selected-modern:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: #f3f4f6;
    color: #9ca3af;
    border-color: #e5e7eb;
}

.btn-delete-selected-modern.active {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border-color: #dc2626;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}

.btn-delete-selected-modern.active:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

.selected-count-badge {
    background: white;
    color: #991b1b;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 700;
    min-width: 24px;
    text-align: center;
}

.btn-delete-selected-modern.active .selected-count-badge {
    background: rgba(255, 255, 255, 0.3);
    color: white;
}

.list-title-modern {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
    display: flex;
    align-items: center;
}

.product-count-badge {
    color: #718096;
    font-weight: 500;
}

.posts-list-modern {
    padding: 24px;
}

.post-item-modern {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px;
    background: white;
    border: 2px solid #f7fafc;
    border-radius: 12px;
    margin-bottom: 16px;
    transition: all 0.3s ease;
}

.post-item-modern:hover {
    border-color: #e2e8f0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    transform: translateY(-2px);
}

.post-checkbox-section {
    display: flex;
    align-items: center;
    justify-content: center;
}

.post-image-section-list {
    width: 80px;
    height: 80px;
    flex-shrink: 0;
}

.post-thumbnail-modern {
    width: 80px;
    height: 80px;
    border-radius: 10px;
    object-fit: cover;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.post-details-modern {
    flex: 1;
    min-width: 0;
}

.post-name-modern {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 8px;
    line-height: 1.4;
}

.post-meta-modern {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 0.85rem;
    color: #718096;
}

.post-meta-modern span {
    display: flex;
    align-items: center;
}

.post-actions-modern {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

.btn-action-modern {
    padding: 10px 16px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.btn-view-modern {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
}

.btn-view-modern:hover {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-edit-modern {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
}

.btn-edit-modern:hover {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-delete-modern {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
}

.btn-delete-modern:hover {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.post-actions-mobile-vertical {
    display: none;
}

/* ========================================
   MOBILE ACTION BAR - BEAUTIFUL GRADIENT BUTTONS
   ======================================== */
.mobile-action-bar {
    display: none;
    gap: 8px;
    padding: 12px 15px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    margin-bottom: 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.mobile-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 10px 12px;
    border: none;
    border-radius: 12px;
    background: white;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    min-width: 70px;
    flex-shrink: 0;
}

.mobile-action-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.3) 50%, transparent 70%);
    animation: mobileShine 3s infinite;
    z-index: 0;
}

@keyframes mobileShine {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

.mobile-btn-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 1;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
}

.icon-letter {
    font-size: 1.3rem;
    font-weight: 800;
    color: white;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.mobile-btn-label {
    font-size: 0.7rem;
    font-weight: 600;
    color: #374151;
    position: relative;
    z-index: 1;
    white-space: nowrap;
}

/* Gradient backgrounds for icons */
.gradient-purple .mobile-btn-icon {
    background: linear-gradient(135deg, #8B5CF6 0%, #6366F1 100%);
}

.gradient-blue .mobile-btn-icon {
    background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
}

.gradient-green .mobile-btn-icon {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
}

.gradient-red .mobile-btn-icon {
    background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
}

/* Hover/Active effects */
.mobile-action-btn:active {
    transform: scale(0.95);
}

.mobile-action-btn:active .mobile-btn-icon {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
}

.mobile-action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.mobile-action-btn:disabled:active {
    transform: none;
}

.mobile-action-btn:disabled .mobile-btn-icon {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

/* Selection mode active state */
.mobile-action-btn.active {
    background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%);
}

.mobile-action-btn.active .mobile-btn-label {
    color: var(--primary-color);
    font-weight: 700;
}

/* ========================================
   MOBILE FILTER BUTTONS (MINI)
   ======================================== */
.mobile-filter-section {
    display: none;
    padding: 12px 15px;
    background: #f8f9fa;
}

.mobile-filter-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 12px;
}

.mobile-filter-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 14px;
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #374151;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}

.mobile-filter-btn:hover,
.mobile-filter-btn:active {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border-color: var(--primary-color);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.2);
}

.mobile-filter-btn i:first-child {
    font-size: 0.9rem;
}

.mobile-filter-btn i:last-child {
    font-size: 0.7rem;
    margin-left: auto;
}

.mobile-dropdowns-container {
    position: relative;
}

.mobile-dropdowns-container .custom-dropdown-modern {
    margin-bottom: 12px;
}

.mobile-dropdowns-container .dropdown-options-modern {
    max-height: 250px !important;
    overflow-y: auto;
    display: block !important;
    opacity: 1 !important;
    transform: translateY(0) !important;
}

/* ========================================
   MOBILE VERTICAL ACTION ICONS - BEAUTIFUL GRADIENT GLOSSY
   ======================================== */
.mobile-icon-btn {
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    font-size: 0.75rem;
    text-decoration: none;
}

/* Shine animation overlay */
.mobile-icon-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
        45deg,
        transparent 30%,
        rgba(255, 255, 255, 0.3) 50%,
        transparent 70%
    );
    transform: rotate(45deg);
    animation: shine 3s infinite;
}

@keyframes shine {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

/* Pulse animation */
@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.mobile-icon-btn:active {
    transform: scale(0.95);
}

/* View button - Blue gradient */
.mobile-icon-view {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.mobile-icon-view:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    animation: pulse 0.6s ease-in-out;
}

/* Edit button - Green gradient */
.mobile-icon-edit {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
}

.mobile-icon-edit:hover {
    background: linear-gradient(135deg, #38ef7d 0%, #11998e 100%);
    box-shadow: 0 6px 20px rgba(56, 239, 125, 0.4);
    animation: pulse 0.6s ease-in-out;
}

/* Delete button - Red gradient */
.mobile-icon-delete {
    background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%);
    color: white;
}

.mobile-icon-delete:hover {
    background: linear-gradient(135deg, #ff6a00 0%, #ee0979 100%);
    box-shadow: 0 6px 20px rgba(238, 9, 121, 0.4);
    animation: pulse 0.6s ease-in-out;
}

/* Glossy effect */
.mobile-icon-btn::after {
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
    border-radius: 10px 10px 0 0;
    pointer-events: none;
}

.mobile-icon-btn i {
    position: relative;
    z-index: 1;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2));
}

/* ========================================
   MOBILE RESPONSIVE - MATCH PRODUCTS PAGE
   ======================================== */
@media (max-width: 768px) {
    /* Reduce row margins on mobile */
    .row.mb-3, .row.mb-4, .row.mb-5 {
        margin-bottom: 12px !important;
    }
    
    /* Hide page header title section on mobile for clean look */
    .page-header-content {
        display: none !important;
    }
    
    /* Make stats grid full width when title is hidden */
    .page-header-card .d-flex {
        justify-content: center !important;
        width: 100% !important;
    }
    
    .page-header-card {
        padding: 16px !important;
    }
    
    /* Show mobile action bar */
    .mobile-action-bar {
        display: flex !important;
    }
    
    /* Show mobile filter section */
    .mobile-filter-section {
        display: block !important;
    }
    
    /* Hide search and filters on mobile */
    .search-card,
    .filters-card {
        display: none !important;
    }
    
    /* Hide bulk actions header on mobile */
    .list-header-modern {
        display: none !important;
    }
    
    /* Stats Grid - 2x2 layout on mobile matching Reviews tab */
    .stats-grid { 
        display: grid !important; 
        grid-template-columns: repeat(2, 1fr) !important; 
        gap: 12px !important; 
        margin-bottom: 0 !important;
        margin-left: 0 !important;
        width: 100% !important;
    }
    
    .stats-card-simple { 
        min-height: 110px !important; 
        padding: 16px 12px !important;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        border-radius: 12px !important;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3) !important;
    }
    
    .stats-card-simple:nth-child(2) {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%) !important;
    }
    
    .stats-card-simple:nth-child(3) {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%) !important;
    }
    
    .stats-card-simple:nth-child(4) {
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%) !important;
    }
    
    .stats-content-simple { 
        text-align: center !important;
        color: white !important;
    }
    
    .stats-content-simple h3 { 
        font-size: 2rem !important; 
        font-weight: 800 !important;
        color: white !important;
        margin-bottom: 8px !important;
    }
    
    /* Show title text on mobile with proper styling */
    .stats-content-simple p { 
        display: block !important;
        font-size: 0.75rem !important; 
        font-weight: 600 !important;
        color: rgba(255, 255, 255, 0.95) !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        margin-bottom: 0 !important;
    }
    
    /* Transform post list to mobile card layout */
    .posts-list-modern {
        padding: 12px 15px !important;
        background: #f8f9fa !important;
    }
    
    .post-item-modern {
        display: flex !important;
        flex-direction: row !important;
        align-items: flex-start !important;
        padding: 12px !important;
        gap: 12px !important;
        border-radius: 12px !important;
        margin-bottom: 12px !important;
        background: white !important;
        border: 2px solid #e9ecef !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05) !important;
        position: relative !important;
        overflow: visible !important;
    }
    
    .post-item-modern::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 3px;
        background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
        transform: scaleY(0);
        transition: transform 0.3s ease;
    }
    
    .post-item-modern:active::before {
        transform: scaleY(1);
    }
    
    /* Show checkbox on mobile */
    .post-checkbox-section {
        display: flex !important;
        align-items: center;
        justify-content: center;
        position: relative !important;
        z-index: 10 !important;
        margin-right: 8px !important;
    }
    
    .checkbox-modern {
        position: relative !important;
        z-index: 10 !important;
    }
    
    /* Post image */
    .post-image-section-list {
        width: 60px !important;
        height: 60px !important;
        flex-shrink: 0 !important;
        position: relative !important;
    }
    
    .post-thumbnail-modern {
        width: 60px !important;
        height: 60px !important;
        border-radius: 10px !important;
        object-fit: cover !important;
    }
    
    /* Post details */
    .post-details-modern {
        flex: 1 !important;
        min-width: 0 !important;
    }
    
    .post-name-modern {
        font-size: 0.95rem !important;
        font-weight: 600 !important;
        margin-bottom: 6px !important;
        line-height: 1.3 !important;
        display: -webkit-box !important;
        -webkit-line-clamp: 2 !important;
        -webkit-box-orient: vertical !important;
        overflow: hidden !important;
    }
    
    .post-meta-modern {
        display: flex !important;
        flex-direction: column !important;
        gap: 4px !important;
        font-size: 0.75rem !important;
    }
    
    .post-meta-modern span {
        display: block !important;
    }
    
    /* Hide desktop actions */
    .post-actions-modern {
        display: none !important;
    }
    
    /* Show mobile vertical action icons */
    .post-actions-mobile-vertical {
        display: flex !important;
        flex-direction: column !important;
        gap: 6px !important;
        position: absolute !important;
        right: 10px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
    }
}

@media (max-width: 576px) {
    .stats-card-simple {
        min-height: 100px !important;
        padding: 14px 10px !important;
    }
    
    .stats-content-simple h3 {
        font-size: 1.8rem !important;
    }
    
    /* Show titles on small mobile too */
    .stats-content-simple p {
        display: block !important;
        font-size: 0.7rem !important;
    }
    
    .post-item-modern {
        padding: 10px !important;
        gap: 10px !important;
    }
    
    .post-image-section-list {
        width: 50px !important;
        height: 50px !important;
    }
    
    .post-thumbnail-modern {
        width: 50px !important;
        height: 50px !important;
    }
    
    .post-name-modern {
        font-size: 0.9rem !important;
    }
    
    .post-meta-modern {
        font-size: 0.7rem !important;
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

<!-- Page Header with Stats -->
<div class="row mb-4">
    <div class="col-12">
        <div class="page-header-card">
            <div class="d-flex align-items-center w-100">
                <div class="page-header-content">
                    <div class="page-header-icon">
                        <i class="fas fa-blog"></i>
                    </div>
                    <div class="page-header-text">
                        <h1 class="page-title">Blog Posts Management</h1>
                        <p class="page-subtitle">Create and manage your blog content</p>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stats-card-simple">
                        <div class="stats-content-simple">
                            <h3><?php echo $total_posts; ?></h3>
                            <p>Total Posts</p>
                        </div>
                    </div>
                    
                    <div class="stats-card-simple">
                        <div class="stats-content-simple">
                            <h3><?php echo $published_count; ?></h3>
                            <p>Published</p>
                        </div>
                    </div>
                    
                    <div class="stats-card-simple">
                        <div class="stats-content-simple">
                            <h3><?php echo $draft_count; ?></h3>
                            <p>Drafts</p>
                        </div>
                    </div>
                    
                    <div class="stats-card-simple">
                        <div class="stats-content-simple">
                            <h3><?php echo $weekly_count; ?></h3>
                            <p>Latest</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modern Search Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="search-card">
            <div class="search-header">
                <div class="search-title">
                    <i class="fas fa-search me-2"></i>Find Blog Posts
                </div>
                <div class="search-actions">
                    <a href="blog-posts.php" class="btn btn-clear-modern">
                        <i class="fas fa-redo me-2"></i>Clear Search
                    </a>
                </div>
            </div>

            <form method="GET" id="searchForm" class="search-form">
                <div class="search-input-group">
                    <div class="search-input-modern">
                        <div class="search-input-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text"
                                   id="searchInput"
                                   name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search by title, description, or topic..."
                                   class="search-field">
                            <button type="submit" class="search-btn-modern">
                                <i class="fas fa-search"></i>
                                <span>Search</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modern Filters Section -->
<div class="row mb-4 filters-row">
    <div class="col-12">
        <div class="filters-card">
            <div class="filters-header">
                <div class="filters-title">
                    <i class="fas fa-filter me-2"></i>Filter Posts
                </div>
            </div>

            <form method="GET" id="filterForm" class="filters-form">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <div class="filters-grid">
                    <!-- Status Filter -->
                    <div class="filter-group">
                        <label class="filter-label">Post Status</label>
                        <div class="custom-dropdown-modern" id="statusDropdown">
                            <div class="dropdown-selected-modern" onclick="toggleModernDropdown('statusDropdown')">
                                <span id="selectedStatus">
                                    <?php 
                                        if ($status_filter === 'published') echo 'Published';
                                        elseif ($status_filter === 'draft') echo 'Draft';
                                        else echo 'All Statuses';
                                    ?>
                                </span>
                                <i class="fas fa-chevron-down dropdown-arrow-modern"></i>
                            </div>
                            <div class="dropdown-options-modern">
                                <div class="dropdown-option-modern <?php echo !$status_filter ? 'selected' : ''; ?>"
                                     onclick="selectStatus('', 'All Statuses')">
                                    <i class="fas fa-list me-2"></i>All Statuses
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'published' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('published', 'Published')">
                                    <span class="status-dot status-delivered"></span>Published
                                </div>
                                <div class="dropdown-option-modern <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>"
                                     onclick="selectStatus('draft', 'Draft')">
                                    <span class="status-dot status-pending"></span>Draft
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($status_filter); ?>">
                    </div>

                    <!-- Add New Post Button -->
                    <div class="filter-group" style="max-width: none;">
                        <label class="filter-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <a href="add-blog-post.php" class="btn btn-add-modern" style="width: auto; padding: 12px 28px;">
                                <i class="fas fa-plus me-2"></i>Add New Post
                            </a>
                            <a href="import-blogs.php" class="btn btn-add-modern" style="width: auto; padding: 12px 28px; background: linear-gradient(135deg, #10b981, #059669);">
                                <i class="fas fa-cloud-upload-alt me-2"></i>Import from Sheets
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mobile Action Bar (Hidden on Desktop) -->
<div class="mobile-action-bar">
    <button class="mobile-action-btn gradient-purple" onclick="window.location.href='import-blogs.php'" title="Import Blogs">
        <div class="mobile-btn-icon">
            <span class="icon-letter">B</span>
        </div>
        <span class="mobile-btn-label">Bulk Import</span>
    </button>
    
    <button class="mobile-action-btn gradient-blue" onclick="window.location.href='add-blog-post.php'" title="Add Blog Post">
        <div class="mobile-btn-icon">
            <span class="icon-letter">A</span>
        </div>
        <span class="mobile-btn-label">Add Post</span>
    </button>
    
    <button class="mobile-action-btn gradient-green" id="mobileSelectBtn" onclick="toggleSelectAllPosts()" title="Select All">
        <div class="mobile-btn-icon">
            <span class="icon-letter">S</span>
        </div>
        <span class="mobile-btn-label">Select All</span>
    </button>
    
    <button class="mobile-action-btn gradient-red" id="mobileDeleteBtn" onclick="deleteSelectedMobile()" disabled title="Delete Selected">
        <div class="mobile-btn-icon">
            <span class="icon-letter">D</span>
        </div>
        <span class="mobile-btn-label">Delete</span>
    </button>
</div>

<!-- Mobile Filter Buttons (Mini) -->
<div class="mobile-filter-section">
    <div class="mobile-filter-buttons">
        <button class="mobile-filter-btn" onclick="toggleModernDropdown('statusDropdown')">
            <i class="fas fa-check-circle"></i>
            <span>Status</span>
            <i class="fas fa-chevron-down"></i>
        </button>
    </div>
    
    <!-- Mobile Dropdowns Container -->
    <div class="mobile-dropdowns-container">
        <div class="custom-dropdown-modern" id="statusDropdown" style="display: none;">
            <div class="dropdown-options-modern" style="position: relative; top: 0; border-radius: 12px; border-top: 2px solid var(--primary-color);">
                <div class="dropdown-option-modern <?php echo !$status_filter ? 'selected' : ''; ?>"
                     onclick="selectStatus('', 'All Statuses')">
                    <i class="fas fa-list me-2"></i>All Statuses
                </div>
                <div class="dropdown-option-modern <?php echo $status_filter === 'published' ? 'selected' : ''; ?>"
                     onclick="selectStatus('published', 'Published')">
                    <span class="status-dot status-delivered"></span>Published
                </div>
                <div class="dropdown-option-modern <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>"
                     onclick="selectStatus('draft', 'Draft')">
                    <span class="status-dot status-pending"></span>Draft
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Blog Posts List -->
<div class="row posts-row">
    <div class="col-12">
        <div class="posts-list-card-modern">
            <div class="list-header-modern">
                <div class="list-header-left">
                    <div class="bulk-actions-modern">
                        <label class="checkbox-modern">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)">
                            <span class="checkmark-modern"></span>
                            <span class="checkbox-label">Select All</span>
                        </label>

                        <button class="btn-delete-selected-modern" id="deleteSelectedBtn" onclick="bulkDelete()" disabled aria-disabled="true" title="Select posts to enable">
                            <i class="fas fa-trash me-2"></i>
                            <span class="btn-delete-text">Delete Selected Posts</span>
                            <span class="selected-count-badge" id="selectedCount">0</span>
                        </button>
                    </div>
                </div>
                <div class="list-header-right">
                    <div class="list-title-modern">
                        <i class="fas fa-newspaper me-2"></i>
                        Blog Posts <span class="product-count-badge" id="postCountBadge">(<?php echo count($blog_posts); ?>)</span>
                    </div>
                </div>
            </div>

            <?php if (!empty($blog_posts)): ?>
                <div class="posts-list-modern" id="postsList">
                    <?php foreach ($blog_posts as $post): ?>
                        <div class="post-item-modern" data-post-id="<?php echo $post['id']; ?>" id="post-<?php echo $post['id']; ?>">
                            <div class="post-checkbox-section">
                                <label class="checkbox-modern">
                                    <input type="checkbox" class="post-checkbox" value="<?php echo $post['id']; ?>" 
                                           data-published="<?php echo $post['is_published']; ?>"
                                           onchange="updateBulkActions(); updateMobileBulkActions();">
                                    <span class="checkmark-modern"></span>
                                </label>
                            </div>

                            <div class="post-image-section-list">
                                <?php if ($post['image_path']): ?>
                                    <img src="../<?php echo BLOG_IMAGES_DIR . $post['image_path']; ?>" 
                                         class="post-thumbnail-modern"
                                         alt="<?php echo htmlspecialchars($post['title']); ?>">
                                <?php else: ?>
                                    <div class="post-thumbnail-modern d-flex align-items-center justify-content-center bg-light">
                                        <i class="fas fa-newspaper text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="post-details-modern">
                                <h6 class="post-name-modern"><?php echo htmlspecialchars($post['title']); ?></h6>
                                <div class="post-meta-modern">
                                    <span class="post-id-modern">
                                        <i class="fas fa-fingerprint me-1"></i>ID: <?php echo $post['id']; ?>
                                    </span>
                                    <?php if ($post['topic']): ?>
                                        <span class="post-topic-modern">
                                            <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($post['topic']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="post-date-modern">
                                        <i class="fas fa-calendar me-1"></i><?php echo date('M d, Y', strtotime($post['created_at'])); ?>
                                    </span>
                                    <?php if ($post['is_published']): ?>
                                        <span class="badge-modern badge-success" style="font-size: 0.7rem; padding: 3px 8px;">
                                            <i class="fas fa-check-circle me-1"></i>Published
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-modern badge-warning" style="font-size: 0.7rem; padding: 3px 8px;">
                                            <i class="fas fa-edit me-1"></i>Draft
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="post-actions-modern">
                                <a href="../blog-post.php?id=<?php echo $post['id']; ?>" target="_blank" class="btn-action-modern btn-view-modern" title="View Post">
                                    <i class="fas fa-eye"></i>
                                    <span>View</span>
                                </a>
                                <a href="edit-blog-post.php?id=<?php echo $post['id']; ?>" class="btn-action-modern btn-edit-modern" title="Edit Post">
                                    <i class="fas fa-edit"></i>
                                    <span>Edit</span>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'This blog post will be permanently deleted. Are you sure?', 'Delete Blog Post')">
                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                    <button type="submit" name="delete_post" class="btn-action-modern btn-delete-modern" title="Delete Post">
                                        <i class="fas fa-trash-alt"></i>
                                        <span>Delete</span>
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Mobile Vertical Action Icons -->
                            <div class="post-actions-mobile-vertical">
                                <a href="../blog-post.php?id=<?php echo $post['id']; ?>" target="_blank" class="mobile-icon-btn mobile-icon-view" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit-blog-post.php?id=<?php echo $post['id']; ?>" class="mobile-icon-btn mobile-icon-edit" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'This blog post will be permanently deleted. Are you sure?', 'Delete Blog Post')">
                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                    <button type="submit" name="delete_post" class="mobile-icon-btn mobile-icon-delete" title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-modern">
                    <div class="empty-icon">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <h4>No blog posts found</h4>
                    <p class="text-muted">No posts match your current filters.</p>
                    <a href="add-blog-post.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add Your First Post
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Modern Dropdown Functions
function toggleModernDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    
    // Check if we're on mobile
    const isMobile = window.innerWidth <= 768;
    
    if (isMobile) {
        // Mobile: toggle display of dropdown container in mobile-dropdowns-container
        const mobileDropdown = document.querySelector('.mobile-dropdowns-container #' + dropdownId);
        if (mobileDropdown) {
            const isVisible = mobileDropdown.style.display === 'block';
            
            // Close all other dropdowns
            document.querySelectorAll('.mobile-dropdowns-container .custom-dropdown-modern').forEach(dd => {
                dd.style.display = 'none';
            });
            
            // Toggle current dropdown
            mobileDropdown.style.display = isVisible ? 'none' : 'block';
        }
    } else {
        // Desktop: use active class
        const isActive = dropdown.classList.contains('active');

        // Close all other dropdowns
        document.querySelectorAll('.custom-dropdown-modern').forEach(dd => {
            if (dd.id !== dropdownId) {
                dd.classList.remove('active');
            }
        });

        // Toggle current dropdown
        if (isActive) {
            dropdown.classList.remove('active');
        } else {
            dropdown.classList.add('active');
        }
    }
}

// Select status
function selectStatus(value, text) {
    const statusInput = document.getElementById('statusInput');
    const statusDropdown = document.getElementById('statusDropdown');
    
    if (statusInput) statusInput.value = value;
    
    // Close dropdown (both desktop and mobile)
    if (statusDropdown) {
        statusDropdown.classList.remove('active');
    }
    
    // Close mobile dropdown
    const mobileStatusDropdown = document.querySelector('.mobile-dropdowns-container #statusDropdown');
    if (mobileStatusDropdown) {
        mobileStatusDropdown.style.display = 'none';
    }

    // Update selected state
    document.querySelectorAll('#statusDropdown .dropdown-option-modern').forEach(option => {
        option.classList.remove('selected');
    });
    event.target.classList.add('selected');

    // Submit form
    document.getElementById('filterForm').submit();
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    const isDropdown = event.target.closest('.custom-dropdown-modern');
    const isFilterBtn = event.target.closest('.mobile-filter-btn');
    
    if (!isDropdown && !isFilterBtn) {
        // Desktop: remove active class
        document.querySelectorAll('.custom-dropdown-modern').forEach(dd => {
            dd.classList.remove('active');
        });
        
        // Mobile: hide dropdowns
        if (window.innerWidth <= 768) {
            document.querySelectorAll('.mobile-dropdowns-container .custom-dropdown-modern').forEach(dd => {
                dd.style.display = 'none';
            });
        }
    }
});

// Mobile Select All / Deselect All functionality for posts
function toggleSelectAllPosts() {
    const checkboxes = document.querySelectorAll('.post-checkbox');
    const selectBtn = document.getElementById('mobileSelectBtn');
    
    // Check if all are currently selected
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    if (allChecked) {
        // Deselect all
        checkboxes.forEach(cb => cb.checked = false);
        selectBtn.classList.remove('active');
        selectBtn.querySelector('.mobile-btn-label').textContent = 'Select All';
    } else {
        // Select all
        checkboxes.forEach(cb => cb.checked = true);
        selectBtn.classList.add('active');
        selectBtn.querySelector('.mobile-btn-label').textContent = 'Deselect All';
    }
    
    updateMobileBulkActions();
    updateBulkActions();
}

function updateMobileBulkActions() {
    const checkedBoxes = document.querySelectorAll('.post-checkbox:checked');
    const deleteBtn = document.getElementById('mobileDeleteBtn');
    
    if (checkedBoxes.length > 0) {
        deleteBtn.disabled = false;
        deleteBtn.querySelector('.mobile-btn-label').textContent = `Delete (${checkedBoxes.length})`;
    } else {
        deleteBtn.disabled = true;
        deleteBtn.querySelector('.mobile-btn-label').textContent = 'Delete';
    }
}

function deleteSelectedMobile() {
    const checkedBoxes = document.querySelectorAll('.post-checkbox:checked');
    if (checkedBoxes.length === 0) return;
    
    bulkDelete();
}

// Bulk Selection Functions
function toggleSelectAll(checkbox) {
    const postCheckboxes = document.querySelectorAll('.post-checkbox');
    postCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
    updateMobileBulkActions();
}

function updateBulkActions() {
    const checkedBoxes = document.querySelectorAll('.post-checkbox:checked');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    const selectedCount = document.getElementById('selectedCount');

    if (selectedCount) {
        selectedCount.textContent = checkedBoxes.length;
    }

    if (checkedBoxes.length > 0) {
        if (deleteBtn) {
            deleteBtn.removeAttribute('disabled');
            deleteBtn.setAttribute('aria-disabled', 'false');
            deleteBtn.classList.add('active');
            deleteBtn.title = 'Delete selected posts';
        }
    } else {
        if (deleteBtn) {
            deleteBtn.setAttribute('disabled', 'disabled');
            deleteBtn.setAttribute('aria-disabled', 'true');
            deleteBtn.classList.remove('active');
            deleteBtn.title = 'Select posts to enable';
        }
    }

    if (selectAllCheckbox) {
        const allCheckboxes = document.querySelectorAll('.post-checkbox');
        selectAllCheckbox.checked = allCheckboxes.length > 0 && checkedBoxes.length === allCheckboxes.length;
    }
}

function getSelectedPostIds() {
    const checkboxes = document.querySelectorAll('.post-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Custom Dialog Functions
function showCustomDialog(title, message, onConfirm) {
    const overlay = document.getElementById('customDialogOverlay');
    const titleEl = document.getElementById('dialogTitle');
    const messageEl = document.getElementById('dialogMessage');
    const confirmBtn = document.getElementById('dialogConfirmBtn');
    
    titleEl.textContent = title;
    messageEl.innerHTML = message;
    overlay.classList.add('active');
    
    // Remove old event listeners
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    // Add new event listener
    newConfirmBtn.addEventListener('click', function() {
        closeCustomDialog();
        if (onConfirm) onConfirm();
    });
    
    // Close on overlay click
    overlay.onclick = function(e) {
        if (e.target === overlay) {
            closeCustomDialog();
        }
    };
    
    // Close on ESC key
    document.addEventListener('keydown', function escHandler(e) {
        if (e.key === 'Escape') {
            closeCustomDialog();
            document.removeEventListener('keydown', escHandler);
        }
    });
}

function closeCustomDialog() {
    const overlay = document.getElementById('customDialogOverlay');
    overlay.classList.remove('active');
}

function handleDeleteConfirm(event, message, title) {
    event.preventDefault();
    const form = event.target;
    
    showCustomDialog(
        title || 'Delete Blog Post',
        message + '<br><br><span style="color: #ef4444; font-weight: 600;">⚠️ This action cannot be undone.</span>',
        function() {
            form.submit();
        }
    );
    
    return false;
}

function bulkDelete() {
    const selectedIds = getSelectedPostIds();
    
    if (selectedIds.length === 0) {
        showCustomDialog(
            'No Selection',
            'Please select at least one post to delete.',
            null
        );
        return;
    }
    
    showCustomDialog(
        'Delete Multiple Posts',
        `Are you sure you want to delete <span class="dialog-count">${selectedIds.length}</span> blog post(s)?<br><br><span style="color: #ef4444; font-weight: 600;">⚠️ This action cannot be undone.</span>`,
        function() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            selectedIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_posts[]';
                input.value = id;
                form.appendChild(input);
            });
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'bulk_delete';
            actionInput.value = '1';
            form.appendChild(actionInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    );
}

function bulkPublish() {
    const selectedIds = getSelectedPostIds();
    
    if (selectedIds.length === 0) {
        alert('Please select at least one post to publish.');
        return;
    }
    
    if (confirm(`Publish ${selectedIds.length} blog post(s)?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_posts[]';
            input.value = id;
            form.appendChild(input);
        });
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'bulk_publish';
        actionInput.value = '1';
        form.appendChild(actionInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function bulkUnpublish() {
    const selectedIds = getSelectedPostIds();
    
    if (selectedIds.length === 0) {
        alert('Please select at least one post to unpublish.');
        return;
    }
    
    if (confirm(`Unpublish ${selectedIds.length} blog post(s)?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_posts[]';
            input.value = id;
            form.appendChild(input);
        });
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'bulk_unpublish';
        actionInput.value = '1';
        form.appendChild(actionInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function clearSelection() {
    document.querySelectorAll('.post-checkbox').forEach(cb => {
        cb.checked = false;
        cb.closest('tr').classList.remove('selected');
    });
    document.getElementById('selectAll').checked = false;
    updateBulkActions();
}
</script>

<!-- Import from Sheets Modal -->
<div class="modal fade" id="importSheetsModal" tabindex="-1" aria-labelledby="importSheetsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); color: white;">
                <h5 class="modal-title" id="importSheetsModalLabel">
                    <i class="fas fa-cloud-upload-alt me-2"></i>Import Blogs from Google Sheets
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: URL Input -->
                <div id="step1-url" class="import-step">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Instructions:</strong> Enter your Google Sheets URL. Make sure the sheet is set to "Anyone with the link can view".
                    </div>
                    
                    <div class="mb-3">
                        <label for="sheetsUrl" class="form-label fw-bold">
                            <i class="fas fa-link me-1"></i>Google Sheets URL
                        </label>
                        <input type="url" class="form-control form-control-lg" id="sheetsUrl" 
                               placeholder="https://docs.google.com/spreadsheets/d/...">
                        <small class="text-muted">Paste the full URL from your browser</small>
                    </div>
                    
                    <button type="button" class="btn btn-primary btn-lg" onclick="loadBlogPreview()">
                        <i class="fas fa-search me-2"></i>Preview Blogs
                    </button>
                </div>
                
                <!-- Step 2: Preview & Selection -->
                <div id="step2-preview" class="import-step" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">
                            <i class="fas fa-list me-2"></i>
                            <span id="blogCount">0</span> Blog(s) Found
                        </h6>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="showStep1()">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </button>
                    </div>
                    
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        Preview your blogs below. Select the ones you want to import. They will be saved as <strong>drafts</strong> by default.
                    </div>
                    
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllBlogs()">
                            <i class="fas fa-check-square me-1"></i>Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllBlogs()">
                            <i class="fas fa-square me-1"></i>Deselect All
                        </button>
                    </div>
                    
                    <div id="blogPreviewContainer" class="blog-preview-list">
                        <!-- Blog previews will be inserted here -->
                    </div>
                </div>
                
                <!-- Loading State -->
                <div id="loadingState" style="display: none;" class="text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted">Loading blogs from Google Sheets...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success btn-lg" id="saveImportBtn" onclick="saveSelectedBlogs()" style="display: none;">
                    <i class="fas fa-save me-2"></i>Import Selected Blogs
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.blog-preview-list {
    max-height: 500px;
    overflow-y: auto;
}

.blog-preview-card {
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
    transition: all 0.3s ease;
    cursor: pointer;
}

.blog-preview-card:hover {
    border-color: var(--primary-color);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.blog-preview-card.selected {
    border-color: #10b981;
    background: #f0fdf4;
}

.blog-preview-card .form-check-input {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.blog-preview-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
}

.blog-preview-meta {
    display: flex;
    gap: 15px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}

.blog-preview-meta span {
    font-size: 0.85rem;
    color: #64748b;
}

.blog-preview-content {
    color: #475569;
    font-size: 0.95rem;
    line-height: 1.6;
}

/* Beautiful Custom Confirmation Dialog */
.custom-dialog-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    z-index: 10000;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.custom-dialog-overlay.active {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.custom-dialog-box {
    background: white;
    border-radius: 24px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 480px;
    width: 100%;
    overflow: hidden;
    animation: slideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(50px) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.dialog-header {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    padding: 30px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.dialog-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(to bottom, rgba(255, 255, 255, 0.2), transparent);
}

.dialog-icon {
    width: 80px;
    height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    animation: pulse 2s infinite;
    position: relative;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.dialog-icon i {
    font-size: 2.5rem;
    color: white;
}

.dialog-title {
    color: white;
    font-size: 1.75rem;
    font-weight: 800;
    margin: 0;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    position: relative;
}

.dialog-body {
    padding: 30px;
    text-align: center;
}

.dialog-message {
    color: #475569;
    font-size: 1.1rem;
    line-height: 1.6;
    margin: 0;
}

.dialog-count {
    display: inline-block;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 700;
    margin: 0 4px;
}

.dialog-actions {
    display: flex;
    gap: 12px;
    padding: 0 30px 30px;
}

.dialog-btn {
    flex: 1;
    padding: 16px 24px;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.dialog-btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.dialog-btn:active::before {
    width: 300px;
    height: 300px;
}

.dialog-btn-cancel {
    background: #f1f5f9;
    color: #64748b;
}

.dialog-btn-cancel:hover {
    background: #e2e8f0;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.dialog-btn-confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}

.dialog-btn-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
}

.dialog-btn-confirm::after {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
        45deg,
        transparent 30%,
        rgba(255, 255, 255, 0.3) 50%,
        transparent 70%
    );
    transform: rotate(45deg);
    animation: shine 3s infinite;
}

@keyframes shine {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .custom-dialog-box {
        max-width: 95%;
        border-radius: 20px;
    }
    
    .dialog-header {
        padding: 25px 20px;
    }
    
    .dialog-icon {
        width: 70px;
        height: 70px;
    }
    
    .dialog-icon i {
        font-size: 2rem;
    }
    
    .dialog-title {
        font-size: 1.5rem;
    }
    
    .dialog-body {
        padding: 25px 20px;
    }
    
    .dialog-message {
        font-size: 1rem;
    }
    
    .dialog-actions {
        flex-direction: column-reverse;
        padding: 0 20px 25px;
    }
    
    .dialog-btn {
        padding: 14px 20px;
    }
}
</style>

<!-- Custom Confirmation Dialog -->
<div id="customDialogOverlay" class="custom-dialog-overlay">
    <div class="custom-dialog-box">
        <div class="dialog-header">
            <div class="dialog-icon">
                <i class="fas fa-trash-alt"></i>
            </div>
            <h2 class="dialog-title" id="dialogTitle">Delete Blog Post</h2>
        </div>
        <div class="dialog-body">
            <p class="dialog-message" id="dialogMessage">Are you sure you want to delete this blog post?</p>
        </div>
        <div class="dialog-actions">
            <button class="dialog-btn dialog-btn-cancel" onclick="closeCustomDialog()">
                <span>Cancel</span>
            </button>
            <button class="dialog-btn dialog-btn-confirm" id="dialogConfirmBtn">
                <span>Yes, Delete</span>
            </button>
        </div>
    </div>
</div>

<script>
let blogsData = [];

function openImportModal() {
    const modal = new bootstrap.Modal(document.getElementById('importSheetsModal'));
    modal.show();
    showStep1();
}

function showStep1() {
    document.getElementById('step1-url').style.display = 'block';
    document.getElementById('step2-preview').style.display = 'none';
    document.getElementById('loadingState').style.display = 'none';
    document.getElementById('saveImportBtn').style.display = 'none';
}

function showStep2() {
    document.getElementById('step1-url').style.display = 'none';
    document.getElementById('step2-preview').style.display = 'block';
    document.getElementById('loadingState').style.display = 'none';
    document.getElementById('saveImportBtn').style.display = 'inline-block';
}

async function loadBlogPreview() {
    const url = document.getElementById('sheetsUrl').value.trim();
    
    if (!url) {
        alert('Please enter a Google Sheets URL');
        return;
    }
    
    if (!url.includes('docs.google.com/spreadsheets')) {
        alert('Please enter a valid Google Sheets URL');
        return;
    }
    
    // Show loading
    document.getElementById('step1-url').style.display = 'none';
    document.getElementById('loadingState').style.display = 'block';
    
    try {
        const response = await fetch('ajax/preview_blogs_from_sheet.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ sheet_url: url })
        });
        
        const result = await response.json();
        
        if (result.success) {
            blogsData = result.blogs;
            displayBlogPreviews(result.blogs);
            document.getElementById('blogCount').textContent = result.total_count;
            showStep2();
        } else {
            alert('Error: ' + result.error + '\n\n' + (result.message || ''));
            showStep1();
        }
    } catch (error) {
        alert('Error loading blogs: ' + error.message);
        showStep1();
    }
}

function displayBlogPreviews(blogs) {
    const container = document.getElementById('blogPreviewContainer');
    container.innerHTML = '';
    
    blogs.forEach((blog, index) => {
        const card = document.createElement('div');
        card.className = 'blog-preview-card';
        card.setAttribute('data-index', index);
        card.onclick = function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('input[type="checkbox"]');
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('selected', checkbox.checked);
            }
        };
        
        card.innerHTML = `
            <div class="d-flex align-items-start gap-3">
                <div class="form-check">
                    <input class="form-check-input blog-checkbox" type="checkbox" value="${index}" id="blog${index}" checked
                           onclick="event.stopPropagation(); this.closest('.blog-preview-card').classList.toggle('selected', this.checked);">
                </div>
                <div class="flex-grow-1">
                    <div class="blog-preview-title">${escapeHtml(blog.title)}</div>
                    <div class="blog-preview-meta">
                        <span><i class="fas fa-tag me-1"></i>${escapeHtml(blog.topic)}</span>
                        <span><i class="fas fa-paragraph me-1"></i>${blog.paragraph_count} paragraphs</span>
                        <span><i class="fas fa-font me-1"></i>${blog.word_count} words</span>
                        ${blog.image_url ? '<span><i class="fas fa-image me-1 text-success"></i>Has image</span>' : ''}
                    </div>
                    <div class="blog-preview-content">
                        <strong>Description:</strong> ${escapeHtml(blog.short_description)}<br>
                        <strong>Preview:</strong> ${escapeHtml(blog.content_preview)}
                    </div>
                </div>
            </div>
        `;
        
        card.classList.add('selected'); // Selected by default
        container.appendChild(card);
    });
}

function selectAllBlogs() {
    document.querySelectorAll('.blog-checkbox').forEach(checkbox => {
        checkbox.checked = true;
        checkbox.closest('.blog-preview-card').classList.add('selected');
    });
}

function deselectAllBlogs() {
    document.querySelectorAll('.blog-checkbox').forEach(checkbox => {
        checkbox.checked = false;
        checkbox.closest('.blog-preview-card').classList.remove('selected');
    });
}

async function saveSelectedBlogs() {
    const selectedCheckboxes = document.querySelectorAll('.blog-checkbox:checked');
    
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one blog to import');
        return;
    }
    
    const selectedBlogs = Array.from(selectedCheckboxes).map(cb => blogsData[parseInt(cb.value)]);
    
    const saveBtn = document.getElementById('saveImportBtn');
    const originalHtml = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
    
    try {
        const response = await fetch('ajax/save_imported_blogs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ blogs: selectedBlogs })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Success!\n\n' + result.message + '\n\nThe blogs have been imported as drafts. You can now edit and publish them.');
            
            // Close modal and reload page
            bootstrap.Modal.getInstance(document.getElementById('importSheetsModal')).hide();
            window.location.reload();
        } else {
            alert('❌ Error: ' + result.error);
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalHtml;
        }
    } catch (error) {
        alert('❌ Error saving blogs: ' + error.message);
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalHtml;
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Update the import button click handler
document.addEventListener('DOMContentLoaded', function() {
    // Find the import from sheets button and add click handler
    const importBtn = document.querySelector('a[href="import-blogs.php"]');
    if (importBtn) {
        importBtn.onclick = function(e) {
            e.preventDefault();
            openImportModal();
        };
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
