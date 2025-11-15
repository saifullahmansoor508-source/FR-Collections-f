<?php
$page_title = "About Us";
require_once 'includes/header.php';

// Get site logo
$stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_logo'");
$stmt->execute();
$logo = $stmt->fetchColumn();
?>

<style>
/* Prevent horizontal scroll on about page */
html, body {
    overflow-x: hidden;
    max-width: 100vw;
}

.about-hero-new,
.about-join-section-new {
    overflow-x: hidden;
    max-width: 100vw;
}

.container {
    max-width: 100%;
    overflow-x: hidden;
}

/* Force button styles - ensure they load */
.join-buttons-new {
    display: flex !important;
    gap: 15px !important;
    flex-wrap: wrap !important;
    margin-bottom: 30px !important;
    position: relative !important;
    z-index: 9999 !important;
    pointer-events: auto !important;
}

.btn-join-new {
    padding: 15px 35px !important;
    border-radius: 50px !important;
    font-size: 1rem !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    transition: all 0.3s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid white !important;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2) !important;
    position: relative !important;
    z-index: 9999 !important;
    cursor: pointer !important;
    pointer-events: auto !important;
    user-select: none !important;
    -webkit-tap-highlight-color: transparent !important;
}

.btn-join-new:active {
    transform: scale(0.95) !important;
}

.btn-join-primary-new {
    background: white !important;
    color: #1e3a8a !important;
    border-color: white !important;
}

.btn-join-primary-new:hover {
    background: #10b981 !important;
    color: white !important;
    border-color: #10b981 !important;
    transform: translateY(-4px) scale(1.03) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.btn-join-secondary-new {
    background: transparent !important;
    color: white !important;
    border-color: white !important;
}

.btn-join-secondary-new:hover {
    background: white !important;
    color: #1e3a8a !important;
    transform: translateY(-4px) scale(1.03) !important;
    box-shadow: 0 8px 25px rgba(255, 255, 255, 0.4) !important;
}

/* CRITICAL: Disable all background/decorative elements from blocking clicks */
.about-join-section-new {
    position: relative !important;
    overflow: visible !important;
}

.join-animated-bg {
    pointer-events: none !important;
    z-index: 0 !important;
}

.join-animated-bg * {
    pointer-events: none !important;
}

.join-floating-shape {
    pointer-events: none !important;
}

/* Allow clicks on content area */
.container {
    position: relative !important;
    z-index: 10 !important;
    pointer-events: auto !important;
}

.row {
    pointer-events: auto !important;
}

.col-lg-6 {
    pointer-events: auto !important;
}

.join-content-wrapper {
    position: relative !important;
    z-index: 10000 !important;
    pointer-events: auto !important;
}

/* Disable clicks on right side visual elements */
.join-visual-wrapper {
    position: relative !important;
    height: 500px !important;
    z-index: 1 !important;
    pointer-events: none !important;
}

.join-visual-wrapper * {
    pointer-events: none !important;
}

.join-circle-bg,
.join-icon-large,
.join-floating-icon {
    pointer-events: none !important;
}
</style>

<!-- About Hero Section - Redesigned -->
<section class="about-hero-new">
    <div class="hero-animated-bg">
        <div class="floating-shape shape-1"></div>
        <div class="floating-shape shape-2"></div>
        <div class="floating-shape shape-3"></div>
        <div class="floating-shape shape-4"></div>
    </div>
    
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 mb-4 mb-lg-0">
                <div class="hero-content-wrapper" data-aos="fade-right">
                    <div class="hero-badge">
                        <i class="fas fa-star"></i>
                        <span>Trusted by Thousands</span>
                    </div>
                    <h1 class="hero-main-title">
                        Our <span class="gradient-text">Story</span>
                    </h1>
                    <p class="hero-description">
                        From a small idea to a thriving community - discover the journey behind FR Collections. We're more than just an online store; we're a family dedicated to bringing you quality, trust, and exceptional service.
                    </p>
                    <div class="hero-stats-row">
                        <div class="hero-stat-item">
                            <div class="stat-number" data-target="9785">0</div>
                            <div class="stat-label">Happy Customers</div>
                        </div>
                        <div class="hero-stat-item">
                            <div class="stat-number" data-target="562">0</div>
                            <div class="stat-label">Products</div>
                        </div>
                        <div class="hero-stat-item">
                            <div class="stat-number" data-target="50">0</div>
                            <div class="stat-label">Categories</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="hero-visual-wrapper" data-aos="fade-left">
                    <div class="hero-image-container">
                        <div class="hero-circle-bg"></div>
                        <div class="hero-logo-large">
                            <?php if($logo && file_exists($logo)): ?>
                                <img src="<?php echo $logo; ?>" alt="FR Collections" class="hero-revolving-logo">
                            <?php else: ?>
                                <img src="assets/images/logo.png" alt="FR Collections" class="hero-revolving-logo">
                            <?php endif; ?>
                        </div>
                        <div class="floating-icon icon-1">
                            <i class="fas fa-shopping-bag"></i>
                        </div>
                        <div class="floating-icon icon-2">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="floating-icon icon-3">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="floating-icon icon-4">
                            <i class="fas fa-thumbs-up"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="hero-wave">
        <svg viewBox="0 0 1440 120" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0 0L60 10C120 20 240 40 360 46.7C480 53 600 47 720 43.3C840 40 960 40 1080 46.7C1200 53 1320 67 1380 73.3L1440 80V120H1380C1320 120 1200 120 1080 120C960 120 840 120 720 120C600 120 480 120 360 120C240 120 120 120 60 120H0V0Z" fill="white"/>
        </svg>
    </div>
</section>

<!-- About Content Section -->
<section class="about-content-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <h2 class="about-section-title text-center">About Us</h2>
                <div class="about-text-content">
                    <p>Welcome to FR Collections - your one-stop online store for everything you need. From fashion and accessories to lifestyle essentials, we bring you a wide variety of products under one trusted platform. We aspire to become a leading and trusted online marketplace where customers can shop confidently, knowing that their trust is our greatest asset.</p>
                    
                    <p>FR Collections was founded with a vision to provide reliable, affordable, and high-quality products to customers who value both style and trust. We aim to make online shopping easy, safe, and enjoyable for everyone. Our Mission is to make shopping safe, simple, and reliable for everyone by offering products that combine quality, affordability, and trust.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Statistics Section -->
<section class="about-stats-section">
    <div class="container">
        <div class="row">
            <div class="col-6 col-md-3 mb-4">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-blue">
                        <i class="fas fa-smile"></i>
                    </div>
                    <h3 class="stat-number stat-number-blue" data-target="9785">0</h3>
                    <p class="stat-label">Happy Customers</p>
                </div>
            </div>
            
            <div class="col-6 col-md-3 mb-4">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-blue">
                        <i class="fas fa-box-open"></i>
                    </div>
                    <h3 class="stat-number stat-number-blue" data-target="562">0</h3>
                    <p class="stat-label">Quality Products</p>
                </div>
            </div>
            
            <div class="col-6 col-md-3 mb-4">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-blue">
                        <i class="fas fa-globe"></i>
                    </div>
                    <h3 class="stat-number stat-number-blue" data-target="50">0</h3>
                    <p class="stat-label">Categories</p>
                </div>
            </div>
            
            <div class="col-6 col-md-3 mb-4">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-blue">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <h3 class="stat-number stat-number-blue" data-target="500">0</h3>
                    <p class="stat-label">Partners</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Core Values Section -->
<section class="about-values-section">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="values-section-title">Our Core Values</h2>
        </div>
        
        <div class="row">
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="value-card-new">
                    <div class="value-icon value-icon-blue">
                        <i class="fas fa-star"></i>
                    </div>
                    <h4 class="value-title">Quality First</h4>
                    <p class="value-description">We never compromise on quality. Every product in our collection undergoes rigorous testing to ensure it meets our high standards.</p>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="value-card-new">
                    <div class="value-icon value-icon-blue">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h4 class="value-title">Customer Love</h4>
                    <p class="value-description">Our customers are at the heart of everything we do. We go above and beyond to create exceptional experiences.</p>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="value-card-new">
                    <div class="value-icon value-icon-blue">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <h4 class="value-title">Innovation</h4>
                    <p class="value-description">We're constantly exploring new ideas and technologies to improve our products and services.</p>
                </div>
            </div>
        </div>
        
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="value-card-new">
                    <div class="value-icon value-icon-blue">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <h4 class="value-title">Integrity</h4>
                    <p class="value-description">We believe in doing business the right way - with honesty, transparency, and respect for all.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Join Community Section - Enhanced -->
<section class="about-join-section-new" style="position: relative; pointer-events: auto; z-index: 1;">
    <!-- Animated Background -->
    <div class="join-animated-bg" style="pointer-events: none; z-index: 0;">
        <div class="join-floating-shape shape-1"></div>
        <div class="join-floating-shape shape-2"></div>
        <div class="join-floating-shape shape-3"></div>
        <div class="join-floating-shape shape-4"></div>
        <div class="join-floating-shape shape-5"></div>
    </div>
    
    <div class="container">
        <div class="row align-items-center">
            <!-- Left Side - Content -->
            <div class="col-lg-6 mb-4 mb-lg-0" data-aos="fade-right">
                <div class="join-content-wrapper">
                    <div class="join-badge">
                        <i class="fas fa-users"></i>
                        <span>Join Thousands</span>
                    </div>
                    <h2 class="join-main-title">
                        Ready to Join Our <span class="join-gradient-text">Community?</span>
                    </h2>
                    <p class="join-description">
                        Become part of the FR Collections family today and discover products you'll love, or grow with us as a partner. Experience quality, trust, and exceptional service.
                    </p>
                    
                    <div class="join-buttons-new" style="position: relative; z-index: 9999; pointer-events: auto;">
                        <a href="shop.php" 
                           class="btn-join-new btn-join-primary-new" 
                           onclick="window.location.href='shop.php'; return false;"
                           style="position: relative; z-index: 9999; pointer-events: auto; cursor: pointer; display: inline-flex;">
                            <i class="fas fa-shopping-bag me-2"></i>
                            Start Shopping
                        </a>
                        <a href="affiliate.php" 
                           class="btn-join-new btn-join-secondary-new"
                           onclick="window.location.href='affiliate.php'; return false;"
                           style="position: relative; z-index: 9999; pointer-events: auto; cursor: pointer; display: inline-flex;">
                            <i class="fas fa-handshake me-2"></i>
                            Join as Partner
                        </a>
                    </div>
                    
                    <!-- Features List -->
                    <div class="join-features">
                        <div class="join-feature-item">
                            <i class="fas fa-check-circle"></i>
                            <span>Exclusive Deals</span>
                        </div>
                        <div class="join-feature-item">
                            <i class="fas fa-check-circle"></i>
                            <span>Fast Delivery</span>
                        </div>
                        <div class="join-feature-item">
                            <i class="fas fa-check-circle"></i>
                            <span>24/7 Support</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Side - Visual Elements -->
            <div class="col-lg-6" data-aos="fade-left">
                <div class="join-visual-wrapper">
                    <div class="join-circle-bg"></div>
                    <div class="join-icon-large">
                        <i class="fas fa-rocket"></i>
                    </div>
                    
                    <!-- Floating Icons -->
                    <div class="join-floating-icon icon-1">
                        <i class="fas fa-gift"></i>
                    </div>
                    <div class="join-floating-icon icon-2">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="join-floating-icon icon-3">
                        <i class="fas fa-heart"></i>
                    </div>
                    <div class="join-floating-icon icon-4">
                        <i class="fas fa-trophy"></i>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="join-stat-card stat-1">
                        <div class="stat-icon-mini">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-num" data-target="9785">0</div>
                            <div class="stat-text">Members</div>
                        </div>
                    </div>
                    
                    <div class="join-stat-card stat-2">
                        <div class="stat-icon-mini">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-num" data-target="562">0</div>
                            <div class="stat-text">Products</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
console.log('✅ JavaScript ACTIVE');

// Number increment animation for stats
function animateStats() {
    const stats = document.querySelectorAll('[data-target]');
    
    stats.forEach(stat => {
        const target = parseInt(stat.getAttribute('data-target'));
        const duration = 2000; // 2 seconds
        const increment = target / (duration / 16); // 60fps
        let current = 0;
        
        const updateNumber = () => {
            current += increment;
            if (current < target) {
                stat.textContent = Math.floor(current);
                requestAnimationFrame(updateNumber);
            } else {
                stat.textContent = target;
            }
        };
        
        updateNumber();
    });
}

// Intersection Observer to trigger animation when stats are visible
function initStatsAnimation() {
    const statsSection = document.querySelector('.about-stats-section');
    
    if (!statsSection) {
        // If stats section doesn't exist, try hero stats
        const heroStats = document.querySelector('.hero-stats-row');
        if (heroStats && !heroStats.classList.contains('animated')) {
            heroStats.classList.add('animated');
            animateStats();
        }
        return;
    }
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.classList.contains('animated')) {
                entry.target.classList.add('animated');
                animateStats();
            }
        });
    }, {
        threshold: 0.3
    });
    
    observer.observe(statsSection);
}

// Initialize stats animation on page load
document.addEventListener('DOMContentLoaded', function() {
    initStatsAnimation();
    
    // Also animate hero stats immediately on load
    setTimeout(() => {
        const heroStats = document.querySelectorAll('.hero-stats-row [data-target]');
        if (heroStats.length > 0) {
            heroStats.forEach(stat => {
                const target = parseInt(stat.getAttribute('data-target'));
                const duration = 2000;
                const increment = target / (duration / 16);
                let current = 0;
                
                const updateNumber = () => {
                    current += increment;
                    if (current < target) {
                        stat.textContent = Math.floor(current);
                        requestAnimationFrame(updateNumber);
                    } else {
                        stat.textContent = target;
                    }
                };
                
                updateNumber();
            });
        }
    }, 500);
});

// Multiple attempts to fix buttons
function initButtons() {
    console.log('🔍 Initializing buttons...');
    
    const buttons = document.querySelectorAll('.btn-join-new');
    console.log('📊 Found: ' + buttons.length + ' buttons');
    
    if (buttons.length === 0) {
        console.error('❌ NO BUTTONS FOUND!');
        return;
    }
    
    buttons.forEach(function(btn, i) {
        console.log('Button ' + (i+1) + ' href:', btn.href);
        
        // FORCE styles
        btn.style.cssText = 'position: relative !important; z-index: 999999 !important; pointer-events: auto !important; cursor: pointer !important; display: inline-flex !important;';
        
        // Force all parents
        let parent = btn.parentElement;
        let depth = 0;
        while (parent && depth < 10) {
            parent.style.pointerEvents = 'auto';
            parent.style.position = 'relative';
            if (parent.classList.contains('join-content-wrapper')) {
                parent.style.zIndex = '100000';
            }
            parent = parent.parentElement;
            depth++;
        }
        
        // Test hover
        btn.onmouseenter = function() {
            console.log('✅ HOVER on button ' + (i+1));
            this.style.transform = 'translateY(-4px) scale(1.03)';
            this.style.background = i === 0 ? '#10b981' : 'white';
        };
        
        btn.onmouseleave = function() {
            this.style.transform = 'none';
            this.style.background = i === 0 ? 'white' : 'transparent';
        };
        
        // Test click
        btn.onclick = function(e) {
            console.log('🎯 BUTTON ' + (i+1) + ' CLICKED!');
            console.log('Going to:', this.href);
            window.location.href = this.href;
            return false;
        };
    });
    
    // Disable ALL background elements
    const backgrounds = document.querySelectorAll('.join-animated-bg, .join-floating-shape, .join-visual-wrapper, .join-circle-bg, .join-icon-large, .join-floating-icon');
    backgrounds.forEach(function(el) {
        el.style.pointerEvents = 'none';
        el.style.zIndex = '0';
    });
    
    console.log('✅ COMPLETE');
}

// Try multiple times
setTimeout(initButtons, 50);
setTimeout(initButtons, 200);
setTimeout(initButtons, 500);
</script>

<?php require_once 'includes/footer.php'; ?>
