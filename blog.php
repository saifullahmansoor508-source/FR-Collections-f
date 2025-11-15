<?php
$page_title = "Blog";
require_once 'includes/header.php';

// Get search and filter parameters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$sort = isset($_GET['sort']) ? sanitizeInput($_GET['sort']) : 'latest';

// Build query
$query = "SELECT * FROM blog_posts WHERE is_published = 1";
$params = [];

if ($search) {
    $query .= " AND (title LIKE ? OR short_description LIKE ? OR content LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params = [$searchParam, $searchParam, $searchParam];
}

// Add sorting
switch ($sort) {
    case 'oldest':
        $query .= " ORDER BY created_at ASC";
        break;
    case 'title':
        $query .= " ORDER BY title ASC";
        break;
    default: // latest
        $query .= " ORDER BY created_at DESC";
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$blog_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count
$total_posts = count($blog_posts);
?>

<style>
.blog-hero {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    padding: 80px 0 60px;
    position: relative;
    overflow: hidden;
}

.blog-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.blog-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -5%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    border-radius: 50%;
}

.blog-hero-content {
    position: relative;
    z-index: 1;
    color: white;
}

.blog-hero h1 {
    font-size: 3.5rem;
    font-weight: 800;
    margin-bottom: 20px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.blog-hero p {
    font-size: 1.3rem;
    opacity: 0.95;
    margin-bottom: 30px;
}

/* Blog specific spacing */
.shop-filters {
    background: white;
}

.blog-card {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
}

.blog-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(30, 58, 138, 0.05) 0%, rgba(37, 99, 235, 0.05) 100%);
    opacity: 0;
    transition: opacity 0.4s ease;
    z-index: 1;
}

.blog-card:hover::before {
    opacity: 1;
}

.blog-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.15);
}

.blog-card-image {
    position: relative;
    overflow: hidden;
    height: 250px;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
}

.blog-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.blog-card:hover .blog-card-image img {
    transform: scale(1.1) rotate(2deg);
}

.blog-date-badge {
    position: absolute;
    top: 15px;
    right: 15px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 8px 15px;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #1e3a8a;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    z-index: 2;
}

.blog-card-body {
    padding: 30px;
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 2;
}

.blog-card-title {
    font-size: 1.4rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 15px;
    line-height: 1.4;
    transition: color 0.3s ease;
}

.blog-card:hover .blog-card-title {
    color: #2563eb;
}

.blog-card-description {
    color: #64748b;
    font-size: 1rem;
    line-height: 1.6;
    margin-bottom: 20px;
    flex: 1;
}

.blog-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 20px;
    border-top: 2px solid #f1f5f9;
}

.read-more-btn {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: white;
    padding: 10px 25px;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
}

.read-more-btn:hover {
    transform: translateX(5px);
    box-shadow: 0 5px 15px rgba(37, 99, 235, 0.3);
    color: white;
}

.blog-stats {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    padding: 30px;
    border-radius: 20px;
    margin-bottom: 40px;
    display: flex;
    justify-content: space-around;
    align-items: center;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}

.blog-stat-item {
    text-align: center;
}

.blog-stat-number {
    font-size: 2.5rem;
    font-weight: 800;
    color: #1e3a8a;
    display: block;
}

.blog-stat-label {
    color: #64748b;
    font-size: 1rem;
    font-weight: 500;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 20px;
    margin: 40px 0;
}

.empty-state-icon {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 30px;
    font-size: 3rem;
    color: #2563eb;
}

.empty-state h3 {
    color: #1e293b;
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 15px;
}

.empty-state p {
    color: #64748b;
    font-size: 1.1rem;
    margin-bottom: 30px;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.blog-card {
    animation: fadeInUp 0.6s ease forwards;
    opacity: 0;
}

.blog-card:nth-child(1) { animation-delay: 0.1s; }
.blog-card:nth-child(2) { animation-delay: 0.2s; }
.blog-card:nth-child(3) { animation-delay: 0.3s; }
.blog-card:nth-child(4) { animation-delay: 0.4s; }
.blog-card:nth-child(5) { animation-delay: 0.5s; }
.blog-card:nth-child(6) { animation-delay: 0.6s; }

@media (max-width: 768px) {
    .blog-hero h1 {
        font-size: 2.5rem;
    }
    
    .blog-hero p {
        font-size: 1.1rem;
    }
    
    .search-filter-bar {
        padding: 20px;
    }
    
    .blog-stats {
        flex-direction: column;
        gap: 20px;
    }
}
</style>

<!-- Hero Section -->
<div class="blog-hero">
    <div class="container">
        <div class="blog-hero-content text-center">
            <h1>
                <i class="fas fa-blog me-3"></i>Our Blog
            </h1>
            <p>Discover the latest trends, insights, and stories from <?php echo SITE_NAME; ?></p>
        </div>
    </div>
</div>

<!-- Search and Filter Section - Shop Style -->
<section class="shop-filters py-4">
    <div class="container">
        <div class="filters-row">
            <!-- Search Bar -->
            <div class="search-wrapper">
                <input type="text" 
                       id="searchInput"
                       class="search-input-modern" 
                       placeholder="Search articles, topics, or keywords..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       oninput="performSearch()">
                <button type="button" class="search-btn-modern">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            
            <!-- Sort Filter -->
            <div class="filter-dropdown-wrapper">
                <div class="custom-dropdown" id="sortDropdown">
                    <div class="dropdown-selected" onclick="toggleDropdown('sortDropdown')">
                        <span id="selectedSort">
                            <?php 
                                if ($sort === 'oldest') echo 'Oldest First';
                                elseif ($sort === 'title') echo 'Title A-Z';
                                else echo 'Latest First';
                            ?>
                        </span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </div>
                    <div class="dropdown-options">
                        <div class="dropdown-option <?php echo ($sort === 'latest') ? 'selected' : ''; ?>" 
                             onclick="selectSort('latest', 'Latest First')">Latest First</div>
                        <div class="dropdown-option <?php echo ($sort === 'oldest') ? 'selected' : ''; ?>" 
                             onclick="selectSort('oldest', 'Oldest First')">Oldest First</div>
                        <div class="dropdown-option <?php echo ($sort === 'title') ? 'selected' : ''; ?>" 
                             onclick="selectSort('title', 'Title A-Z')">Title A-Z</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container">
    
    <!-- Blog Stats -->
    <?php if ($total_posts > 0): ?>
    <div class="blog-stats">
        <div class="blog-stat-item">
            <span class="blog-stat-number"><?php echo $total_posts; ?></span>
            <span class="blog-stat-label">Total Articles</span>
        </div>
        <div class="blog-stat-item">
            <span class="blog-stat-number">
                <i class="fas fa-fire" style="color: #f59e0b;"></i>
            </span>
            <span class="blog-stat-label">Fresh Content</span>
        </div>
        <div class="blog-stat-item">
            <span class="blog-stat-number">
                <i class="fas fa-heart" style="color: #ef4444;"></i>
            </span>
            <span class="blog-stat-label">Reader Favorites</span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Blog Posts Grid -->
    <div class="row g-4 mb-5">
        <?php if (!empty($blog_posts)): ?>
            <?php foreach ($blog_posts as $post): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="blog-card">
                        <div class="blog-card-image">
                            <?php if ($post['image_path']): ?>
                                <img src="<?php echo BLOG_IMAGES_DIR . $post['image_path']; ?>" 
                                     alt="<?php echo htmlspecialchars($post['title']); ?>">
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center h-100">
                                    <i class="fas fa-newspaper" style="font-size: 4rem; color: #cbd5e1;"></i>
                                </div>
                            <?php endif; ?>
                            <div class="blog-date-badge">
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?php echo date('M d, Y', strtotime($post['created_at'])); ?>
                            </div>
                        </div>
                        
                        <div class="blog-card-body">
                            <?php if ($post['topic']): ?>
                                <div class="mb-2">
                                    <span class="badge" style="background: linear-gradient(135deg, #2563eb, #1e3a8a); color: white; padding: 6px 14px; border-radius: 50px; font-size: 0.75rem; font-weight: 600;">
                                        <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars(html_entity_decode($post['topic'], ENT_QUOTES, 'UTF-8')); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <h3 class="blog-card-title">
                                <?php echo htmlspecialchars($post['title']); ?>
                            </h3>
                            
                            <?php if ($post['short_description']): ?>
                                <p class="blog-card-description">
                                    <?php echo htmlspecialchars(substr($post['short_description'], 0, 120)) . (strlen($post['short_description']) > 120 ? '...' : ''); ?>
                                </p>
                            <?php endif; ?>
                            
                            <div class="blog-card-footer">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fas fa-clock" style="color: #94a3b8;"></i>
                                    <small style="color: #64748b;">5 min read</small>
                                </div>
                                <a href="blog-post.php?id=<?php echo $post['id']; ?>" class="read-more-btn">
                                    Read More
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3>No Articles Found</h3>
                    <p>We couldn't find any articles matching your search. Try different keywords or browse our products!</p>
                    <a href="shop.php" class="btn btn-primary btn-lg" style="border-radius: 50px; padding: 15px 40px;">
                        <i class="fas fa-shopping-bag me-2"></i>Browse Products
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
let searchTimeout;
let currentSort = '<?php echo htmlspecialchars($sort); ?>';

// Real-time search functionality
function performSearch() {
    clearTimeout(searchTimeout);
    const searchTerm = document.getElementById('searchInput').value;
    
    searchTimeout = setTimeout(() => {
        updateBlogPosts(searchTerm, currentSort);
    }, 300);
}

// Update blog posts via page reload
function updateBlogPosts(search, sort) {
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (sort) params.append('sort', sort);
    
    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.location.href = newUrl;
}

// Toggle dropdown
function toggleDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    const isActive = dropdown.classList.contains('active');
    
    // Close all other dropdowns
    document.querySelectorAll('.custom-dropdown').forEach(dd => {
        if (dd.id !== dropdownId) {
            dd.classList.remove('active');
        }
    });
    
    if (isActive) {
        dropdown.classList.remove('active');
    } else {
        dropdown.classList.add('active');
    }
}

// Select sort option
function selectSort(sortValue, sortText) {
    currentSort = sortValue;
    document.getElementById('selectedSort').textContent = sortText;
    document.getElementById('sortDropdown').classList.remove('active');
    
    // Update selected state
    document.querySelectorAll('#sortDropdown .dropdown-option').forEach(option => {
        option.classList.remove('selected');
        if (option.onclick.toString().includes(sortValue)) {
            option.classList.add('selected');
        }
    });
    
    // Update page
    const searchTerm = document.getElementById('searchInput').value;
    updateBlogPosts(searchTerm, currentSort);
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.custom-dropdown')) {
        document.querySelectorAll('.custom-dropdown').forEach(dropdown => {
            dropdown.classList.remove('active');
        });
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set initial sort text
    const sortTexts = {
        'latest': 'Latest First',
        'oldest': 'Oldest First',
        'title': 'Title A-Z'
    };
    document.getElementById('selectedSort').textContent = sortTexts[currentSort] || 'Latest First';
    
    // Add event listener for Enter key
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performSearch();
            }
        });
    }
});

// Add loading animation when clicking read more
document.querySelectorAll('.read-more-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
