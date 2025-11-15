<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$page_title = "Categories Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_category':
                $name = sanitizeInput($_POST['name']);
                
                if (!empty($name)) {
                    $stmt = $db->prepare("INSERT INTO categories (name) VALUES (?)");
                    if ($stmt->execute([$name])) {
                        $success_message = "Category added successfully!";
                    } else {
                        $error_message = "Error adding category.";
                    }
                } else {
                    $error_message = "Category name is required.";
                }
                break;
                
            case 'update_category':
                $category_id = intval($_POST['category_id']);
                $name = sanitizeInput($_POST['name']);
                
                if (!empty($name)) {
                    $stmt = $db->prepare("UPDATE categories SET name = ? WHERE id = ?");
                    if ($stmt->execute([$name, $category_id])) {
                        $success_message = "Category updated successfully!";
                    } else {
                        $error_message = "Error updating category.";
                    }
                } else {
                    $error_message = "Category name is required.";
                }
                break;
                
            case 'delete_category':
                $category_id = intval($_POST['category_id']);
                
                // Check if category has products
                $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
                $stmt->execute([$category_id]);
                $product_count = $stmt->fetchColumn();
                
                if ($product_count > 0) {
                    $error_message = "Cannot delete category. It has {$product_count} products associated with it.";
                } else {
                    $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
                    if ($stmt->execute([$category_id])) {
                        $success_message = "Category deleted successfully!";
                    } else {
                        $error_message = "Error deleting category.";
                    }
                }
                break;
        }
    }
}

// Get all categories with product count
$stmt = $db->prepare("
    SELECT c.*, COUNT(p.id) as product_count 
    FROM categories c 
    LEFT JOIN products p ON c.id = p.category_id 
    GROUP BY c.id 
    ORDER BY c.name ASC
");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats for header
$total_categories = count($categories);
$active_count = $total_categories; // All categories are considered active
$total_products = 0;
foreach ($categories as $c) {
    $total_products += (isset($c['product_count']) ? $c['product_count'] : 0);
}

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header with Stats (Affiliates-like) -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header-card">
                <div class="page-header-content">
                    <div class="page-header-icon">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="page-header-text">
                        <h1 class="page-title">Categories Management</h1>
                        <p class="page-subtitle">Manage product categories</p>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stats-card-simple">
                        <div class="stats-content-simple">
                            <h3><?php echo number_format($total_categories); ?></h3>
                            <p>Total Categories</p>
                        </div>
                    </div>
                    
                    <div class="stats-card-simple">
                        <div class="stats-content-simple">
                            <h3><?php echo number_format($active_count); ?></h3>
                            <p>Active Categories</p>
                        </div>
                    </div>
                    
                    <div class="stats-card-simple">
                        <div class="stats-content-simple">
                            <h3><?php echo number_format($total_products); ?></h3>
                            <p>Total Products</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Categories -->
    <div class="row">
        <div class="col-12">
            <div class="categories-container">
                <div class="users-header">
                    <div class="users-header-content">
                        <div class="users-icon"><i class="fas fa-layer-group"></i></div>
                        <div class="users-title">
                            <h4>Categories</h4>
                            <span class="users-count"><?php echo $total_categories; ?> found</span>
                        </div>
                    </div>
                    <div class="users-actions">
                        <button class="btn btn-add-category-modern" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="fas fa-plus me-2"></i>Add Category
                        </button>
                        <button class="btn btn-refresh-modern" onclick="location.reload()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                    </div>
                </div>
                <?php if (empty($categories)): ?>
                    <div class="empty-users">
                        <div class="empty-users-icon"><i class="fas fa-tags"></i></div>
                        <h5>No categories found</h5>
                        <p>Add your first category to get started.</p>
                        <button class="btn btn-primary-modern" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="fas fa-plus me-2"></i>Add Category
                        </button>
                    </div>
                <?php else: ?>
                    <!-- Desktop Table View -->
                    <div class="table-responsive-modern desktop-table">
                        <table class="categories-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Products</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                    <tr class="order-row">
                                        <td>
                                            <div class="cat-name"><?php echo htmlspecialchars($category['name']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge-count"><?php echo ($category['product_count'] ?? 0); ?> products</span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($category['created_at'])); ?></td>
                                        <td>
                                            <button class="btn-icon-modern btn-edit-modern" title="Edit" onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <?php if (($category['product_count'] ?? 0) == 0): ?>
                                                <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'Are you sure you want to delete this category?', 'Delete Category')">
                                                    <input type="hidden" name="action" value="delete_category">
                                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                    <button type="submit" class="btn-icon-modern btn-delete-modern" title="Delete"><i class="fas fa-trash"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn-icon-modern btn-delete-modern" disabled title="Cannot delete category with products"><i class="fas fa-trash"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile List View -->
                    <div class="users-list-container mobile-list">
                        <div class="users-list">
                            <?php foreach ($categories as $category): ?>
                                <div class="user-list-item" data-category-id="<?php echo $category['id']; ?>">
                                    <div class="user-list-avatar">
                                        <i class="fas fa-tag"></i>
                                    </div>
                                    
                                    <div class="user-list-content">
                                        <div class="user-list-header" onclick="toggleCategoryDetails(<?php echo $category['id']; ?>)">
                                            <div class="user-header-info">
                                                <div class="user-info-primary">
                                                    <span class="user-name"><?php echo htmlspecialchars($category['name']); ?></span>
                                                    <div class="user-badges">
                                                        <span class="status-badge badge-count-mobile">
                                                            <i class="fas fa-box me-1"></i><?php echo ($category['product_count'] ?? 0); ?> products
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                <div class="user-info-secondary">
                                                    <span class="user-joined">
                                                        <i class="fas fa-calendar-plus me-1"></i>
                                                        <?php echo date('M d, Y', strtotime($category['created_at'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="user-expand-icon">
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                        
                                        <div class="user-list-details collapsed" id="category-details-<?php echo $category['id']; ?>">
                                            <div class="user-actions-mobile">
                                                <button class="btn-icon btn-view-user" onclick="event.stopPropagation(); editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <?php if (($category['product_count'] ?? 0) == 0): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'Are you sure you want to delete this category?', 'Delete Category')">
                                                        <input type="hidden" name="action" value="delete_category">
                                                        <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                        <button type="submit" class="btn-icon btn-delete-user" title="Delete"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                <?php else: ?>
                                                    <button class="btn-icon btn-delete-user" disabled title="Cannot delete category with products"><i class="fas fa-trash"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="user-list-actions desktop-only">
                                        <button class="btn-icon btn-view-user" onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <?php if (($category['product_count'] ?? 0) == 0): ?>
                                            <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'Are you sure you want to delete this category?', 'Delete Category')">
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                <button type="submit" class="btn-icon btn-delete-user" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn-icon btn-delete-user" disabled title="Cannot delete category with products"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_category">
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required placeholder="Enter category name">
                    </div>
                    
                    <p class="text-muted small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Enter a unique name for your category. This will be visible to customers.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_category">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required placeholder="Enter category name">
                    </div>
                    
                    <p class="text-muted small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Update the category name. This will be visible to customers.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCategory(category) {
    document.getElementById('edit_category_id').value = category.id;
    document.getElementById('edit_name').value = category.name;
    
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

// Toggle category details dropdown (mobile)
function toggleCategoryDetails(categoryId) {
    const detailsElement = document.getElementById('category-details-' + categoryId);
    const categoryItem = document.querySelector('[data-category-id="' + categoryId + '"]');
    const expandIcon = categoryItem.querySelector('.user-expand-icon i');
    
    if (detailsElement.classList.contains('collapsed')) {
        detailsElement.classList.remove('collapsed');
        detailsElement.classList.add('expanded');
        expandIcon.style.transform = 'rotate(180deg)';
    } else {
        detailsElement.classList.remove('expanded');
        detailsElement.classList.add('collapsed');
        expandIcon.style.transform = 'rotate(0deg)';
    }
}
</script>

<script>
// Enhance interactions similar to Affiliates tab
document.addEventListener('DOMContentLoaded', function() {
    if (window.$) { $('[title]').tooltip && $('[title]').tooltip(); }

    document.querySelectorAll('.btn-icon-modern').forEach(function(btn) {
        btn.addEventListener('click', function() {
            btn.classList.add('btn-clicked');
            setTimeout(function(){ btn.classList.remove('btn-clicked'); }, 150);
        });
    });
});
</script>

<style>
/* Header card (same style family as Affiliates) */
.page-header-card { background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border-radius: 12px; padding: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; }
.page-header-card::before { content:''; position:absolute; top:0; right:0; width:120px; height:120px; background:linear-gradient(135deg, rgba(0,88,163,.07) 0%, rgba(255,107,0,.07) 100%); border-radius:50%; transform: translate(80px,-80px); }
.page-header-content { display:flex; align-items:center; position:relative; z-index:1; }
.page-header-icon { width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.15rem; margin-right:12px; box-shadow:0 4px 14px rgba(0,88,163,.2); }
.page-header-text h1 { font-size:1.2rem; font-weight:600; color:#2d3748; margin-bottom:2px; }
.page-header-text p { color:#718096; font-size:.82rem; margin:0; }
.page-header-stats { display:flex; gap:14px; align-items:center; position:relative; z-index:1; }
.stat-item { text-align:center; }
.stat-number { font-size:1.1rem; font-weight:600; color:var(--primary-color); line-height:1; }
.stat-label { font-size:.68rem; color:#718096; font-weight:600; text-transform:uppercase; letter-spacing:.2px; margin-top:2px; }

/* Dashboard-like stat boxes (shared look) */
.stats-grid {
    display: flex;
    gap: 16px;
    align-items: stretch;
    flex-wrap: wrap;
    justify-content: center;
}
.stat-card { background:#fff; border:1px solid #e9ecef; border-radius:12px; padding:12px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); transition:all .3s ease; }
.stat-card:hover { transform: translateY(-2px); box-shadow:0 8px 18px rgba(0,0,0,.1); }
.stat-card-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); box-shadow:0 4px 12px rgba(0,88,163,.3); }
.stat-card-icon.success { background:linear-gradient(135deg, #10b981, #059669); box-shadow:0 4px 12px rgba(16,185,129,.3); }
.stat-card-icon.accent { background:linear-gradient(135deg, #f59e0b, #d97706); box-shadow:0 4px 12px rgba(245,158,11,.3); }
.stat-card-content { display:flex; flex-direction:column; }
.stat-card-number { font-size:1.05rem; font-weight:700; color:#1f2937; }
.stat-card-label { font-size:.75rem; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
.stat-card.action { justify-content:center; }

/* Container and header reused styles */
.categories-container { background:#fff; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.08); overflow:hidden; }
.users-header { background:linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding:24px 32px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #dee2e6; }
.users-header-content { display:flex; align-items:center; }
.users-icon { width:48px; height:48px; border-radius:12px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.25rem; margin-right:16px; box-shadow:0 4px 12px rgba(0,88,163,.3); }
.users-title h4 { margin:0; font-size:1.25rem; font-weight:600; color:#2d3748; }
.users-count { display:block; font-size:.9rem; color:#718096; font-weight:500; margin-top:2px; }
.users-actions { display:flex; gap:12px; }
.btn-add-category-modern { background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); color:#fff; border:none; padding:10px 20px; border-radius:10px; font-size:.9rem; font-weight:600; cursor:pointer; transition:all .3s ease; box-shadow:0 4px 12px rgba(0,88,163,.3); }
.btn-add-category-modern:hover { transform: translateY(-2px); box-shadow:0 6px 16px rgba(0,88,163,.4); color:#fff; }
.btn-refresh-modern { background:linear-gradient(135deg, #10b981, #059669); color:#fff; border:none; padding:10px 20px; border-radius:10px; font-size:.9rem; font-weight:600; cursor:pointer; transition:all .3s ease; box-shadow:0 4px 12px rgba(16,185,129,.3); }
.btn-refresh-modern:hover { transform: translateY(-2px); box-shadow:0 6px 16px rgba(16,185,129,.4); color:#fff; }
.btn-export-modern { background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); color:#fff; border:none; padding:10px 14px; border-radius:10px; font-size:.85rem; font-weight:600; cursor:pointer; transition:all .3s ease; box-shadow:0 4px 12px rgba(0,88,163,.3); }
.btn-export-modern:hover { transform: translateY(-2px); box-shadow:0 6px 16px rgba(0,88,163,.4); color:#fff; }

/* Modern table styling (inspired by Orders) */
.table-responsive-modern { overflow-x:auto; }
.categories-table { width:100%; border-collapse:collapse; }
.categories-table thead th { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 18px 16px; text-align:left; font-weight:600; color:#374151; font-size:.85rem; text-transform:uppercase; letter-spacing:.5px; border-bottom:2px solid #dee2e6; position:sticky; top:0; z-index:1; }
.categories-table tbody td { padding:18px 16px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.order-row { transition: all .3s ease; }
.order-row:hover { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); transform: translateX(4px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); }
.cat-name { font-weight:700; color:#2d3748; }
.badge-count { background: linear-gradient(135deg, #3b82f6, #2563eb); color:#fff; padding:4px 10px; border-radius:12px; font-size:.75rem; font-weight:600; box-shadow:0 2px 6px rgba(59,130,246,.25); }
.status-badge { padding:6px 12px; border-radius:20px; font-size:.75rem; font-weight:700; display:inline-flex; align-items:center; gap:6px; }
.status-active { background: linear-gradient(135deg, #10b981, #059669); color:#fff; }
.status-inactive { background: linear-gradient(135deg, #9ca3af, #6b7280); color:#fff; }

/* Icon action buttons */
.btn-icon-modern { width:38px; height:38px; border-radius:10px; border:none; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; transition:all .3s ease; position:relative; overflow:hidden; color:#fff; margin-right:6px; }
.btn-icon-modern::before { content:''; position:absolute; top:50%; left:50%; width:0; height:0; background:rgba(255,255,255,.25); border-radius:50%; transform:translate(-50%,-50%); transition:all .3s ease; }
.btn-clicked::before { width:200px; height:200px; }
.btn-edit-modern { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 2px 8px rgba(59,130,246,.3); }
.btn-edit-modern:hover { transform: translateY(-2px) scale(1.04); box-shadow:0 6px 16px rgba(59,130,246,.4); }
.btn-delete-modern { background: linear-gradient(135deg, #ef4444, #dc2626); box-shadow: 0 2px 8px rgba(239,68,68,.3); }
.btn-delete-modern:hover { transform: translateY(-2px) scale(1.04); box-shadow:0 6px 16px rgba(239,68,68,.4); }
.btn-delete-modern:disabled { opacity:.5; cursor:not-allowed; }

/* Responsive */
@media (max-width:768px){ .page-header-card,.categories-container{ margin:0 -15px; border-radius:0; } .users-header{ flex-direction:column; gap:16px; text-align:center; padding:20px 24px; } .users-actions{ justify-content:center; } .categories-table thead th{ padding:12px 10px; } .categories-table tbody td{ padding:12px 10px; } }

/* Simple Stats Cards */
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

.stats-content-simple i {
    position: absolute;
    top: 20px;
    right: 20px;
}

/* ========================================
   USER LIST STYLES (FROM USERS.PHP)
   ======================================== */
.users-list-container { padding: 24px; }
.users-list { display: flex; flex-direction: column; gap: 16px; }
.user-list-item { display: flex; align-items: center; gap: 16px; padding: 20px; background: white; border: 2px solid #e9ecef; border-radius: 16px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); position: relative; overflow: hidden; }
.user-list-item::before { content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 3px; background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); transform: scaleY(0); transition: transform 0.3s ease; }
.user-list-item:hover { transform: translateX(4px); box-shadow: 0 8px 24px rgba(0, 88, 163, 0.15); border-color: var(--primary-color); }
.user-list-item:hover::before { transform: scaleY(1); }
.user-list-avatar { width: 50px; height: 50px; border-radius: 12px; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.3rem; flex-shrink: 0; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
.user-list-content { flex: 1; display: flex; flex-direction: column; gap: 12px; }
.user-list-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.user-header-info { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.user-expand-icon { display: none; width: 32px; height: 32px; border-radius: 8px; background: #f3f4f6; align-items: center; justify-content: center; flex-shrink: 0; transition: background 0.3s ease; }
.user-expand-icon i { color: #6b7280; font-size: 0.9rem; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.user-list-header:hover .user-expand-icon { background: #e5e7eb; }
.user-info-primary { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.user-info-primary .user-name { font-size: 1.1rem; font-weight: 700; color: #2d3748; }
.user-badges { display: flex; gap: 6px; }
.user-info-secondary { display: flex; align-items: center; gap: 16px; }
.user-joined { color: #718096; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; }
.user-list-details { display: flex; flex-wrap: wrap; gap: 20px; padding-top: 12px; border-top: 1px solid #f1f5f9; }
.user-list-details.collapsed { }
.user-list-details.expanded { }
.user-actions-mobile { display: none; width: 100%; gap: 8px; padding-top: 12px; border-top: 1px solid #f1f5f9; margin-top: 8px; }
.desktop-only { display: flex; }
.user-list-actions { display: flex; gap: 8px; align-items: center; }
.btn-icon { width: 40px; height: 40px; border-radius: 10px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; transition: all 0.3s ease; }
.btn-view-user { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
.btn-view-user:hover { transform: translateY(-2px) scale(1.05); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4); }
.btn-delete-user { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
.btn-delete-user:hover { transform: translateY(-2px) scale(1.05); box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4); }
.btn-delete-user:disabled { opacity: 0.5; cursor: not-allowed; }
.badge-count-mobile { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
.empty-users { text-align: center; padding: 80px 40px; }
.empty-users-icon { font-size: 4rem; color: #cbd5e0; margin-bottom: 24px; }
.btn-primary-modern { background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); color: #fff; border: none; padding: 12px 24px; border-radius: 10px; font-size: 0.9rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3); }
.btn-primary-modern:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4); color: #fff; }

/* Hide mobile list on desktop */
.mobile-list { display: none; }

/* Mobile Responsive */
@media (max-width: 768px) {
    /* Hide users header section on mobile */
    .users-header { display: none !important; }
    
    /* Adjust stats cards to display in a row */
    .stats-grid { display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 8px !important; width: 100%; }
    .stats-card-simple { min-height: 120px !important; padding: 16px 8px !important; aspect-ratio: 1 / 1 !important; display: flex !important; align-items: center !important; justify-content: center !important; }
    .stats-content-simple h3 { font-size: 1.5rem !important; margin-bottom: 6px !important; }
    .stats-content-simple p { font-size: 0.75rem !important; margin-bottom: 0 !important; }
    
    /* Hide desktop table, show mobile list */
    .desktop-table { display: none !important; }
    .mobile-list { display: block !important; }
    
    .users-list-container { padding: 12px 15px; background: #f8f9fa; }
    .users-list { animation: fadeInUp 0.5s ease; }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    
    .user-list-item { display: flex !important; flex-direction: row; flex-wrap: nowrap; align-items: flex-start; padding: 12px; gap: 12px; border-radius: 12px; margin-bottom: 12px; animation: slideIn 0.3s ease; animation-fill-mode: both; }
    @keyframes slideIn { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }
    
    .user-list-item:nth-child(1) { animation-delay: 0.05s; }
    .user-list-item:nth-child(2) { animation-delay: 0.1s; }
    .user-list-item:nth-child(3) { animation-delay: 0.15s; }
    .user-list-item:nth-child(4) { animation-delay: 0.2s; }
    .user-list-item:nth-child(5) { animation-delay: 0.25s; }
    .user-list-item:nth-child(n+6) { animation-delay: 0.3s; }
    
    .user-list-avatar { width: 45px; height: 45px; font-size: 1.2rem; flex-shrink: 0; }
    .user-list-content { flex: 1; min-width: 0; gap: 0; }
    .user-expand-icon { display: flex !important; -webkit-tap-highlight-color: transparent; }
    .user-expand-icon:active { background: #d1d5db; transform: scale(0.95); }
    .user-list-header { cursor: pointer; padding: 0; -webkit-tap-highlight-color: transparent; }
    .user-header-info { gap: 6px; }
    .user-info-primary { width: 100%; flex-direction: column; align-items: flex-start; gap: 6px; }
    .user-info-primary .user-name { font-size: 1rem; font-weight: 600; }
    .user-badges { flex-wrap: wrap; gap: 4px; }
    .user-info-secondary { width: 100%; }
    .user-joined { font-size: 0.8rem; }
    .user-list-details { flex-direction: column; gap: 8px; padding-top: 12px; margin-top: 0; }
    .user-list-details.collapsed { display: none !important; }
    .user-list-details.expanded { display: flex; animation: expandDetails 0.3s ease; }
    @keyframes expandDetails { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .user-list-actions.desktop-only { display: none !important; }
    .user-actions-mobile { display: flex !important; justify-content: center; gap: 12px; }
    .btn-icon { width: 38px; height: 38px; font-size: 0.9rem; }
    .btn-icon:active { transform: scale(0.9); }
}

@media (max-width: 576px) {
    .stats-grid { gap: 6px !important; }
    .stats-card-simple { min-height: 100px !important; padding: 12px 6px !important; }
    .stats-content-simple h3 { font-size: 1.2rem !important; }
    .stats-content-simple p { font-size: 0.65rem !important; }
    .user-list-item { padding: 10px; gap: 10px; }
    .user-list-avatar { width: 40px; height: 40px; font-size: 1.1rem; }
    .user-info-primary .user-name { font-size: 0.95rem; }
    .btn-icon { width: 36px; height: 36px; font-size: 0.85rem; }
}
</style>

<?php require_once 'includes/footer.php'; ?>
