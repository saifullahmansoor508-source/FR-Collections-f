<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$page_title = "Contact Messages";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete_message':
                $message_id = intval($_POST['message_id']);
                $stmt = $db->prepare("DELETE FROM contact_messages WHERE id = ?");
                if ($stmt->execute([$message_id])) {
                    $success_message = "Message deleted successfully!";
                } else {
                    $error_message = "Error deleting message.";
                }
                break;
                
            case 'delete_multiple':
                if (isset($_POST['message_ids']) && is_array($_POST['message_ids'])) {
                    $message_ids = array_map('intval', $_POST['message_ids']);
                    $placeholders = str_repeat('?,', count($message_ids) - 1) . '?';
                    $stmt = $db->prepare("DELETE FROM contact_messages WHERE id IN ($placeholders)");
                    if ($stmt->execute($message_ids)) {
                        $success_message = count($message_ids) . " message(s) deleted successfully!";
                    } else {
                        $error_message = "Error deleting messages.";
                    }
                }
                break;
        }
    }
}

// Get filters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(full_name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get all contact messages
$stmt = $db->prepare("SELECT * FROM contact_messages $where_clause ORDER BY created_at DESC");
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$stmt = $db->prepare("SELECT COUNT(*) FROM contact_messages");
$stmt->execute();
$total_messages = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM contact_messages WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$today_messages = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM contact_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->execute();
$week_messages = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM contact_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stmt->execute();
$month_messages = $stmt->fetchColumn();

require_once 'includes/header.php';
?>

<?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" style="border-radius: 12px; border-left: 4px solid #10b981;">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius: 12px; border-left: 4px solid #ef4444;">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header with Stats -->
<div class="row mb-4">
    <div class="col-12">
        <div class="page-header-card">
            <div class="page-header-content">
                <div class="page-header-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="page-header-text">
                    <h1 class="page-title">Contact Messages</h1>
                    <p class="page-subtitle">Manage and respond to customer inquiries</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($total_messages); ?></h3>
                        <p>Total Messages</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($today_messages); ?></h3>
                        <p>Today</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($week_messages); ?></h3>
                        <p>This Week</p>
                    </div>
                </div>
                
                <div class="stats-card-simple">
                    <div class="stats-content-simple">
                        <h3><?php echo number_format($month_messages); ?></h3>
                        <p>This Month</p>
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
                    <i class="fas fa-search me-2"></i>Find Messages
                </div>
                <div class="search-actions">
                    <a href="contact.php" class="btn btn-clear-modern">
                        <i class="fas fa-redo me-2"></i>Clear Search
                    </a>
                </div>
            </div>

            <form method="GET" class="search-form">
                <div class="search-input-group">
                    <div class="search-input-modern">
                        <div class="search-input-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="search-field" placeholder="Search by name, email, subject, or message...">
                            <button type="submit" class="search-btn-modern">
                                <i class="fas fa-search"></i>
                                <span>Search</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="search-filters">
                    <a href="contact.php" class="btn btn-clear-modern">Clear</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mobile Action Bar (Hidden on Desktop) -->
<div class="mobile-action-bar">
    <button class="mobile-action-btn gradient-green" id="mobileSelectBtn" onclick="toggleSelectAllMessages()" title="Select All">
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

<!-- Messages Grid -->
<div class="row">
    <div class="col-12">
        <div class="reviews-container">
            <div class="reviews-header">
                <div class="reviews-header-content">
                    <div class="reviews-icon">
                        <i class="fas fa-envelope-open-text"></i>
                    </div>
                    <div class="reviews-title">
                        <h4>Customer Messages</h4>
                        <span class="reviews-count"><?php echo count($messages); ?> found</span>
                    </div>
                </div>
                <div class="reviews-actions">
                    <button class="btn btn-select-all-modern" onclick="toggleSelectAllDesktop()">
                        <i class="fas fa-check-square me-2"></i>Select All
                    </button>
                    <button class="btn btn-delete-all-modern" id="desktopDeleteBtn" onclick="deleteSelectedDesktop()" disabled>
                        <i class="fas fa-trash-alt me-2"></i>Delete Selected
                    </button>
                    <button class="btn btn-refresh-modern" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh
                    </button>
                </div>
            </div>

            <?php if (empty($messages)): ?>
                <div class="empty-reviews">
                    <div class="empty-reviews-icon"><i class="fas fa-envelope"></i></div>
                    <h5>No messages found</h5>
                    <p>Try adjusting your search terms.</p>
                    <a href="contact.php" class="btn btn-primary-modern"><i class="fas fa-redo me-2"></i>Clear Search</a>
                </div>
            <?php else: ?>
                <!-- Desktop Grid View -->
                <div class="reviews-grid desktop-grid">
                    <?php foreach ($messages as $message): ?>
                        <div class="review-card">
                            <label class="checkbox-modern desktop-checkbox">
                                <input type="checkbox" class="message-checkbox-desktop" value="<?php echo $message['id']; ?>" onchange="updateDesktopBulkActions();">
                                <span class="checkmark-modern"></span>
                            </label>
                            <div class="review-card-header">
                                <div class="review-user-info">
                                    <div class="user-avatar-review">
                                        <?php echo strtoupper(substr($message['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="user-details-review">
                                        <div class="user-name-review"><?php echo htmlspecialchars($message['full_name']); ?></div>
                                        <div class="review-date">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            <?php echo date('M d, Y', strtotime($message['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="review-status-badge">
                                    <span class="status-badge-review approved">
                                        <i class="fas fa-envelope"></i> Received
                                    </span>
                                </div>
                            </div>

                            <div class="review-card-body">
                                <div class="product-info-review">
                                    <i class="fas fa-envelope me-2" style="color: #8b5cf6;"></i>
                                    <span class="product-name-review"><?php echo htmlspecialchars($message['email']); ?></span>
                                </div>

                                <div class="rating-display">
                                    <i class="fas fa-tag" style="color: #6b7280;"></i>
                                    <span class="rating-text"><?php echo htmlspecialchars($message['subject']); ?></span>
                                </div>

                                <div class="review-comment">
                                    <?php if (!empty($message['message'])): ?>
                                        <?php if (strlen($message['message']) > 150): ?>
                                            <p class="comment-text"><?php echo htmlspecialchars(substr($message['message'], 0, 150)); ?>...</p>
                                            <button class="btn-read-more" onclick="showFullMessage('<?php echo addslashes(htmlspecialchars($message['message'])); ?>', '<?php echo addslashes(htmlspecialchars($message['full_name'])); ?>', '<?php echo addslashes(htmlspecialchars($message['email'])); ?>', '<?php echo addslashes(htmlspecialchars($message['subject'])); ?>', '<?php echo date('M d, Y', strtotime($message['created_at'])); ?>')">
                                                <i class="fas fa-book-open me-1"></i>Read Full Message
                                            </button>
                                        <?php else: ?>
                                            <p class="comment-text"><?php echo htmlspecialchars($message['message']); ?></p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="comment-text text-muted"><i>No message content</i></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="review-card-footer">
                                <div class="review-actions">
                                    <button type="button" class="btn-action-review btn-approve" title="View Details"
                                            onclick="showFullMessage('<?php echo addslashes(htmlspecialchars($message['message'])); ?>', '<?php echo addslashes(htmlspecialchars($message['full_name'])); ?>', '<?php echo addslashes(htmlspecialchars($message['email'])); ?>', '<?php echo addslashes(htmlspecialchars($message['subject'])); ?>', '<?php echo date('M d, Y', strtotime($message['created_at'])); ?>')">
                                        <i class="fas fa-eye"></i>
                                        <span>View</span>
                                    </button>
                                    
                                    <a href="mailto:<?php echo htmlspecialchars($message['email']); ?>" class="btn-action-review btn-unapprove" title="Reply">
                                        <i class="fas fa-reply"></i>
                                        <span>Reply</span>
                                    </a>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return handleDeleteConfirm(event, 'This message will be permanently deleted. Are you sure?', 'Delete Message')">
                                        <input type="hidden" name="action" value="delete_message">
                                        <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                                        <button type="submit" class="btn-action-review btn-delete" title="Delete Message">
                                            <i class="fas fa-trash-alt"></i>
                                            <span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Mobile List View -->
                <div class="users-list-container mobile-list">
                    <div class="users-list">
                        <?php foreach ($messages as $message): ?>
                            <div class="user-list-item" data-message-id="<?php echo $message['id']; ?>">
                                <div class="message-checkbox-section">
                                    <label class="checkbox-modern">
                                        <input type="checkbox" class="message-checkbox" value="<?php echo $message['id']; ?>" onchange="updateMobileBulkActions();">
                                        <span class="checkmark-modern"></span>
                                    </label>
                                </div>
                                
                                <div class="user-list-avatar" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                                    <?php echo strtoupper(substr($message['full_name'], 0, 1)); ?>
                                </div>
                                
                                <div class="user-list-content">
                                    <div class="user-list-header" onclick="toggleMessageDetails(<?php echo $message['id']; ?>)">
                                        <div class="user-header-info">
                                            <div class="user-info-primary">
                                                <span class="user-name"><?php echo htmlspecialchars($message['full_name']); ?></span>
                                                <div class="user-badges">
                                                    <span class="status-badge approved-badge">
                                                        <i class="fas fa-envelope me-1"></i>Received
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="user-info-secondary">
                                                <span class="user-joined">
                                                    <i class="far fa-calendar-alt me-1"></i><?php echo date('M d, Y', strtotime($message['created_at'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="user-expand-icon">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    
                                    <div class="user-list-details collapsed" id="message-details-<?php echo $message['id']; ?>">
                                        <div class="review-product-mobile">
                                            <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($message['email']); ?>
                                        </div>
                                        <div class="review-product-mobile">
                                            <i class="fas fa-tag me-2"></i><?php echo htmlspecialchars($message['subject']); ?>
                                        </div>
                                        <div class="review-comment-mobile">
                                            <?php echo htmlspecialchars(substr($message['message'], 0, 100)); ?><?php echo strlen($message['message']) > 100 ? '...' : ''; ?>
                                        </div>
                                        <div class="user-actions-mobile">
                                            <a href="mailto:<?php echo htmlspecialchars($message['email']); ?>" class="btn-icon btn-view-user" title="Reply">
                                                <i class="fas fa-reply"></i>
                                            </a>
                                            <button type="button" class="btn-icon btn-delete-user" onclick="deleteSingleMessage(<?php echo $message['id']; ?>, '<?php echo htmlspecialchars($message['full_name']); ?>')" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Full Message Modal -->
<div class="modal fade" id="fullMessageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0058a3 0%, #ff6b00 100%); color: white; border: none; padding: 25px 30px;">
                <h5 class="modal-title" style="font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-envelope-open-text"></i><span>Message Details</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 30px;">
                <div class="review-modal-content">
                    <div class="modal-review-header">
                        <div class="modal-user-info">
                            <div class="modal-avatar" id="modalAvatar"></div>
                            <div>
                                <h6 class="modal-user-name" id="modalUserName"></h6>
                                <p class="modal-product-name" id="modalUserEmail"></p>
                                <p class="modal-product-name" id="modalTimestamp"></p>
                            </div>
                        </div>
                        <div class="modal-rating">
                            <i class="fas fa-tag me-2" style="color: #6b7280;"></i>
                            <span id="modalSubject"></span>
                        </div>
                    </div>
                    <div class="modal-comment-text" id="fullMessageContent"></div>
                </div>
            </div>
            <div class="modal-footer" style="background: #f8f9fa; border-top: 2px solid #e5e7eb; padding: 20px 30px;">
                <a href="#" class="btn btn-primary-modern" id="modalReplyBtn">
                    <i class="fas fa-reply me-2"></i>Reply
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 12px 25px; font-weight: 600;">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Show full message in modal
function showFullMessage(message, userName, userEmail, subject, timestamp) {
    document.getElementById('fullMessageContent').innerHTML = message.replace(/\n/g, '<br>');
    document.getElementById('modalUserName').textContent = userName;
    document.getElementById('modalUserEmail').innerHTML = '<i class="fas fa-envelope me-2"></i>' + userEmail;
    document.getElementById('modalSubject').textContent = subject;
    document.getElementById('modalTimestamp').innerHTML = '<i class="far fa-calendar-alt me-2"></i>' + timestamp;
    document.getElementById('modalAvatar').textContent = userName.charAt(0).toUpperCase();
    
    // Set reply button
    document.getElementById('modalReplyBtn').href = 'mailto:' + userEmail + '?subject=Re: ' + encodeURIComponent(subject);
    
    new bootstrap.Modal(document.getElementById('fullMessageModal')).show();
}

// Card hover effects
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.review-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.classList.add('card-hover');
        });
        card.addEventListener('mouseleave', function() {
            this.classList.remove('card-hover');
        });
    });
});

// Mobile functions
function toggleSelectAllMessages() {
    const checkboxes = document.querySelectorAll('.message-checkbox');
    const selectBtn = document.getElementById('mobileSelectBtn');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    if (allChecked) {
        checkboxes.forEach(cb => cb.checked = false);
        selectBtn.classList.remove('active');
        selectBtn.querySelector('.mobile-btn-label').textContent = 'Select All';
    } else {
        checkboxes.forEach(cb => cb.checked = true);
        selectBtn.classList.add('active');
        selectBtn.querySelector('.mobile-btn-label').textContent = 'Deselect All';
    }
    updateMobileBulkActions();
}

function updateMobileBulkActions() {
    const checkedBoxes = document.querySelectorAll('.message-checkbox:checked');
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
    const checkedBoxes = document.querySelectorAll('.message-checkbox:checked');
    if (checkedBoxes.length === 0) return;
    
    if (confirm(`Delete ${checkedBoxes.length} message(s)? This cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        checkedBoxes.forEach(checkbox => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'message_ids[]';
            input.value = checkbox.value;
            form.appendChild(input);
        });
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_multiple';
        form.appendChild(actionInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteSingleMessage(messageId, userName) {
    if (confirm(`Delete message from ${userName}? This cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_message';
        form.appendChild(actionInput);
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'message_id';
        idInput.value = messageId;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleMessageDetails(messageId) {
    const detailsElement = document.getElementById('message-details-' + messageId);
    const messageItem = document.querySelector('[data-message-id="' + messageId + '"]');
    const expandIcon = messageItem.querySelector('.user-expand-icon i');
    
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

// Desktop functions
function toggleSelectAllDesktop() {
    const checkboxes = document.querySelectorAll('.message-checkbox-desktop');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    checkboxes.forEach(cb => cb.checked = !allChecked);
    updateDesktopBulkActions();
}

function updateDesktopBulkActions() {
    const checkedBoxes = document.querySelectorAll('.message-checkbox-desktop:checked');
    const deleteBtn = document.getElementById('desktopDeleteBtn');
    
    if (checkedBoxes.length > 0) {
        deleteBtn.disabled = false;
    } else {
        deleteBtn.disabled = true;
    }
}

function deleteSelectedDesktop() {
    const checkedBoxes = document.querySelectorAll('.message-checkbox-desktop:checked');
    if (checkedBoxes.length === 0) return;
    
    if (confirm(`Delete ${checkedBoxes.length} message(s)? This cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        checkedBoxes.forEach(checkbox => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'message_ids[]';
            input.value = checkbox.value;
            form.appendChild(input);
        });
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_multiple';
        form.appendChild(actionInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<style>
/* ===== COPY ALL STYLES FROM REVIEWS PAGE ===== */

/* Modern Dropdowns */
.custom-dropdown-modern { position: relative; width: 100%; min-width: 220px; }
.dropdown-selected-modern { background:#fff; border:2px solid #e5e7eb; border-radius:12px; padding:12px 14px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; font-size:.95rem; font-weight:500; color:#1f2937; transition:all .3s ease; box-shadow:0 1px 3px rgba(0,0,0,.05); }
.dropdown-selected-modern:hover { border-color: var(--primary-color); box-shadow: 0 4px 12px rgba(0,88,163,.15); }
.custom-dropdown-modern.active .dropdown-selected-modern { border-color: var(--primary-color); box-shadow:0 0 0 3px rgba(0,88,163,.1); border-radius:12px 12px 0 0; }
.dropdown-arrow-modern { font-size:.8rem; color:#6b7280; transition: transform .3s ease; }
.custom-dropdown-modern.active .dropdown-arrow-modern { transform: rotate(180deg); color: var(--primary-color); }
.dropdown-options-modern { position:absolute; top:calc(100% - 2px); left:0; right:0; background:#fff; border:2px solid var(--primary-color); border-top:none; border-radius:0 0 12px 12px; box-shadow:0 10px 25px rgba(0,0,0,.15); z-index:1000; max-height:0; overflow:hidden; opacity:0; transform:translateY(-10px); transition: all .3s cubic-bezier(0.4, 0, 0.2, 1); }
.custom-dropdown-modern.active .dropdown-options-modern { max-height:300px; opacity:1; transform:translateY(0); overflow-y:auto; }
.dropdown-option-modern { padding:12px 18px; cursor:pointer; font-size:.9rem; color:#374151; transition:all .2s ease; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; }
.dropdown-option-modern:last-child { border-bottom:none; }
.dropdown-option-modern:hover { background:#f9fafb; padding-left:24px; }
.dropdown-option-modern.selected { background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); color:#fff; font-weight:600; }

/* Header card */
.page-header-card { background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border-radius: 12px; padding: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; min-height: 1px; }
.page-header-card::before { content:''; position:absolute; top:0; right:0; width:120px; height:120px; background:linear-gradient(135deg, rgba(0,88,163,.07) 0%, rgba(255,107,0,.07) 100%); border-radius:50%; transform: translate(80px,-80px); }
.page-header-content { display:flex; align-items:center; position:relative; z-index:1; }
.page-header-icon { width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.15rem; margin-right:12px; box-shadow:0 4px 14px rgba(0,88,163,.2); }
.page-header-text h1 { font-size:1.2rem; font-weight:600; color:#2d3748; margin-bottom:2px; }
.page-title { font-size:1.2rem; font-weight:600; color:#2d3748; margin-bottom:2px; }
.page-subtitle { color:#718096; font-size:.82rem; margin:0; }

/* Stats Grid */
.stats-grid {
    display: flex;
    gap: 16px;
    align-items: stretch;
    flex-wrap: wrap;
    justify-content: center;
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

/* Search card */
.search-card { background:#fff; border-radius:16px; padding:32px; box-shadow:0 4px 20px rgba(0,0,0,.08); margin-bottom:24px; }
.search-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid #e9ecef; }
.search-title { font-size:1.25rem; font-weight:600; color:#2d3748; margin:0; }
.btn-clear-modern { background:#fff; border:2px solid #e9ecef; color:#6b7280; padding:8px 16px; border-radius:8px; text-decoration:none; font-size:.9rem; font-weight:500; transition:all .3s ease; }
.btn-clear-modern:hover { background:#f8f9fa; border-color:#d1d5db; color:#374151; text-decoration:none; }
.search-input-group { display:flex; gap:16px; align-items:flex-end; }
.search-input-modern { flex:1; }
.search-input-wrapper { position:relative; display:flex; align-items:center; }
.search-field { width:100%; border:2px solid #e5e7eb; border-radius:12px; padding:14px 60px 14px 50px; font-size:.95rem; transition:all .3s ease; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.05); }
.search-field:focus { outline:none; border-color:var(--primary-color); box-shadow:0 0 0 3px rgba(0,88,163,.1); }
.search-icon { position:absolute; left:16px; color:#9ca3af; font-size:1rem; z-index:1; }
.search-btn-modern { position:absolute; right:8px; background:linear-gradient(135deg, var(--primary-color), var(--accent-color)); border:none; border-radius:8px; width:36px; height:36px; display:flex; align-items:center; justify-content:center; color:#fff; cursor:pointer; transition:all .3s ease; box-shadow:0 2px 8px rgba(0,88,163,.2); }
.search-btn-modern span { display:none; }
.search-btn-modern:hover { transform:scale(1.05); box-shadow:0 4px 12px rgba(0,88,163,.3); }
.search-filters { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px; }

/* Reviews Container */
.reviews-container {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.reviews-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 24px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
}

.reviews-header-content {
    display: flex;
    align-items: center;
}

.reviews-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.25rem;
    margin-right: 16px;
    box-shadow: 0 4px 12px rgba(0,88,163,.3);
}

.reviews-title h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
}

.reviews-count {
    display: block;
    font-size: .9rem;
    color: #718096;
    font-weight: 500;
    margin-top: 2px;
}

.reviews-actions {
    display: flex;
    gap: 12px;
}

.btn-refresh-modern {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .3s ease;
    box-shadow: 0 4px 12px rgba(16,185,129,.3);
}

.btn-refresh-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16,185,129,.4);
    color: #fff;
}

/* Reviews Grid */
.reviews-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
    padding: 25px;
}

.review-card {
    background: white;
    border-radius: 16px;
    border: 2px solid #e5e7eb;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.review-card.pending-review {
    border-left: 4px solid #f59e0b;
    background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
}

.review-card:hover, .review-card.card-hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    border-color: var(--primary-color);
}

.review-card-header {
    padding: 20px;
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.review-user-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar-review {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.3rem;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.user-name-review {
    font-weight: 700;
    color: #1f2937;
    font-size: 1.05rem;
}

.review-date {
    color: #6b7280;
    font-size: 0.85rem;
    margin-top: 2px;
}

.status-badge-review {
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-badge-review.approved {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.status-badge-review.pending {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.review-card-body {
    padding: 20px;
}

.product-info-review {
    background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
    padding: 10px 15px;
    border-radius: 10px;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    border: 1px solid #c084fc;
}

.product-name-review {
    font-weight: 600;
    color: #6b21a8;
    font-size: 0.95rem;
}

.rating-display {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 15px;
}

.rating-display .star-filled {
    color: #fbbf24;
    font-size: 1.2rem;
}

.rating-display .star-empty {
    color: #d1d5db;
    font-size: 1.2rem;
}

.rating-text {
    margin-left: 8px;
    font-weight: 700;
    color: #1f2937;
    font-size: 1rem;
}

.review-comment {
    background: #f9fafb;
    padding: 15px;
    border-radius: 12px;
    border-left: 3px solid var(--primary-color);
}

.comment-text {
    color: #374151;
    line-height: 1.6;
    margin: 0;
    font-size: 0.95rem;
}

.btn-read-more {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    margin-top: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
}

.btn-read-more:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 88, 163, 0.4);
}

.review-card-footer {
    padding: 15px 20px;
    background: #f9fafb;
    border-top: 2px solid #e5e7eb;
}

.review-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-action-review {
    flex: 1;
    min-width: 120px;
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-approve {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-approve:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
}

.btn-unapprove {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.btn-unapprove:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
}

.btn-delete {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-delete:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

/* Empty State */
.empty-reviews {
    text-align: center;
    padding: 80px 40px;
}

.empty-reviews-icon {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 24px;
}

.empty-reviews h5 {
    color: #6b7280;
    font-weight: 700;
    margin-bottom: 10px;
}

.empty-reviews p {
    color: #9ca3af;
    margin-bottom: 20px;
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: #fff;
    border: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: all .3s ease;
    box-shadow: 0 4px 12px rgba(0,88,163,.3);
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,88,163,.4);
    color: #fff;
    text-decoration: none;
}

/* Modal Styles */
.review-modal-content {
    background: #f9fafb;
    border-radius: 12px;
    padding: 20px;
}

.modal-review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e5e7eb;
}

.modal-user-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.modal-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.5rem;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.modal-user-name {
    font-weight: 700;
    color: #1f2937;
    font-size: 1.2rem;
    margin-bottom: 5px;
}

.modal-product-name {
    color: #6b7280;
    font-size: 0.95rem;
    margin-bottom: 2px;
}

.modal-rating {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    padding: 10px 20px;
    border-radius: 50px;
    font-weight: 700;
    color: #92400e;
    font-size: 1.1rem;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.modal-comment-text {
    background: white;
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid var(--primary-color);
    line-height: 1.7;
    color: #374151;
    font-size: 1rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

/* Responsive */
@media (max-width: 768px) {
    .reviews-grid {
        grid-template-columns: 1fr;
        padding: 15px;
    }
    
    .review-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .review-actions {
        flex-direction: column;
    }
    
    .btn-action-review {
        min-width: 100%;
    }
    
    .search-input-group {
        flex-direction: column;
    }
    
    .search-filters {
        flex-direction: column;
        align-items: stretch;
    }
    
    .stats-grid {
        flex-direction: column;
    }
    
    .stats-card-simple {
        min-height: 100px;
    }
}

/* Custom color variables */
:root {
    --primary-color: #0058A3;
    --accent-color: #FF6B00;
}

/* Desktop Button Styles */
.btn-select-all-modern {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .3s ease;
    box-shadow: 0 4px 12px rgba(139,92,246,.3);
}

.btn-select-all-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(139,92,246,.4);
    color: #fff;
}

.btn-delete-all-modern {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .3s ease;
    box-shadow: 0 4px 12px rgba(239,68,68,.3);
}

.btn-delete-all-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(239,68,68,.4);
    color: #fff;
}

.btn-delete-all-modern:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.desktop-checkbox {
    position: absolute;
    top: 20px;
    left: 20px;
    z-index: 10;
}

.review-card {
    position: relative;
}

/* ========================================
   MOBILE ACTION BAR & LIST VIEW
   ======================================== */
.mobile-action-bar { display: none; gap: 8px; padding: 12px 15px; background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); margin-bottom: 16px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.mobile-action-btn { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 10px 12px; border: none; border-radius: 12px; background: white; cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; min-width: 70px; flex-shrink: 0; }
.mobile-action-btn::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.3) 50%, transparent 70%); animation: mobileShine 3s infinite; z-index: 0; }
@keyframes mobileShine { 0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); } 100% { transform: translateX(100%) translateY(100%) rotate(45deg); } }
.mobile-btn-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; position: relative; z-index: 1; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); transition: all 0.3s ease; }
.icon-letter { font-size: 1.3rem; font-weight: 800; color: white; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); }
.mobile-btn-label { font-size: 0.7rem; font-weight: 600; color: #374151; position: relative; z-index: 1; white-space: nowrap; }
.gradient-green .mobile-btn-icon { background: linear-gradient(135deg, #10B981 0%, #059669 100%); }
.gradient-red .mobile-btn-icon { background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%); }
.mobile-action-btn:active { transform: scale(0.95); }
.mobile-action-btn:active .mobile-btn-icon { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25); }
.mobile-action-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.mobile-action-btn.active { background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%); }
.mobile-action-btn.active .mobile-btn-label { color: var(--primary-color); font-weight: 700; }

/* Mobile List Styles */
.users-list-container { padding: 24px; }
.users-list { display: flex; flex-direction: column; gap: 16px; }
.user-list-item { display: flex; align-items: center; gap: 16px; padding: 20px; background: white; border: 2px solid #e9ecef; border-radius: 16px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); position: relative; overflow: hidden; }
.user-list-item::before { content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 3px; background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); transform: scaleY(0); transition: transform 0.3s ease; }
.user-list-item:active::before { transform: scaleY(1); }
.user-list-avatar { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.3rem; flex-shrink: 0; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); }
.user-list-content { flex: 1; display: flex; flex-direction: column; gap: 12px; }
.user-list-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.user-header-info { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.user-expand-icon { display: none; width: 32px; height: 32px; border-radius: 8px; background: #f3f4f6; align-items: center; justify-content: center; flex-shrink: 0; transition: background 0.3s ease; }
.user-expand-icon i { color: #6b7280; font-size: 0.9rem; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.user-info-primary { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.user-info-primary .user-name { font-size: 1.1rem; font-weight: 700; color: #2d3748; }
.user-badges { display: flex; gap: 6px; }
.approved-badge { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
.user-info-secondary { display: flex; align-items: center; gap: 16px; }
.user-joined { color: #718096; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; }
.user-list-details { display: flex; flex-wrap: wrap; gap: 20px; padding-top: 12px; border-top: 1px solid #f1f5f9; }
.user-list-details.collapsed { }
.user-list-details.expanded { }
.user-actions-mobile { display: none; width: 100%; gap: 8px; padding-top: 12px; border-top: 1px solid #f1f5f9; margin-top: 8px; }
.review-product-mobile { color: #8b5cf6; font-weight: 600; font-size: 0.9rem; margin-bottom: 8px; }
.review-comment-mobile { background: #f9fafb; padding: 10px; border-radius: 8px; font-size: 0.85rem; color: #374151; line-height: 1.5; }
.btn-icon { width: 40px; height: 40px; border-radius: 10px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; transition: all 0.3s ease; text-decoration: none; }
.btn-view-user { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
.btn-delete-user { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }

/* Checkbox styles */
.message-checkbox-section { display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.checkbox-modern { position: relative; display: flex; align-items: center; cursor: pointer; user-select: none; }
.checkbox-modern input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
.checkmark-modern { height: 22px; width: 22px; background-color: #fff; border: 2px solid #d1d5db; border-radius: 6px; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
.checkbox-modern input:checked ~ .checkmark-modern { background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); border-color: transparent; }
.checkmark-modern:after { content: ""; display: none; }
.checkbox-modern input:checked ~ .checkmark-modern:after { display: block; width: 5px; height: 10px; border: solid white; border-width: 0 2px 2px 0; transform: rotate(45deg); }

.mobile-list { display: none; }

@media (max-width: 768px) {
    .mobile-action-bar { display: flex !important; }
    .message-checkbox-section { display: flex !important; }
    .reviews-header, .search-card { display: none !important; }
    
    /* Keep page-header-card visible but hide only the header content */
    .page-header-card { 
        display: block !important; 
        padding: 12px !important;
        margin-bottom: 16px !important;
    }
    .page-header-content { display: none !important; }
    
    /* Stats Grid - 2x2 layout on mobile */
    .stats-grid { 
        display: grid !important; 
        grid-template-columns: repeat(2, 1fr) !important; 
        gap: 12px !important; 
        margin-bottom: 0 !important;
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
    
    .stats-content-simple p { 
        font-size: 0.75rem !important; 
        font-weight: 600 !important;
        color: rgba(255, 255, 255, 0.95) !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
    }
    
    .desktop-grid { display: none !important; }
    .mobile-list { display: block !important; }
    .users-list-container { padding: 12px 15px; background: #f8f9fa; }
    .user-list-item { padding: 12px; gap: 12px; }
    .user-list-avatar { width: 45px; height: 45px; font-size: 1.2rem; }
    .user-expand-icon { display: flex !important; }
    .user-list-header { cursor: pointer; }
    .user-info-primary { flex-direction: column; align-items: flex-start; gap: 6px; }
    .user-list-details.collapsed { display: none !important; }
    .user-list-details.expanded { display: flex; flex-direction: column; }
    .user-actions-mobile { display: flex !important; justify-content: center; }
}
</style>

<?php require_once 'includes/footer.php'; ?>