    </main>

    <!-- Footer -->
    <footer class="footer-simple">
        <div class="container">
            <div class="footer-content">
                <div class="footer-left">
                    <h3 class="footer-brand"><?php echo SITE_NAME; ?></h3>
                    <p class="footer-contact">Contact: info@frcollections.pk</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Mobile Cart Icon (Bottom Right) -->
    <div class="mobile-cart-icon d-lg-none">
        <a href="cart.php" class="btn btn-primary rounded-circle">
            <i class="fas fa-shopping-cart"></i>
            <span class="mobile-cart-count" id="mobileCartCount">
                <?php 
                if (isLoggedIn()) {
                    $stmt = $db->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo $result['total'] ?: 0;
                } else {
                    echo '0';
                }
                ?>
            </span>
        </a>
    </div>

    <!-- Scripts -->
    <!-- jQuery is loaded in header -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Slick Carousel JS (requires jQuery) -->
    <script src="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js"></script>
    <!-- AOS Animation Library -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="assets/js/variant-handler.js"></script>
    
    <script>
    // Initialize AOS
    AOS.init({
        duration: 800,
        easing: 'ease-out',
        once: true,
        offset: 100
    });
    </script>
    
    <script>
    // Mobile Menu Functions
    let menuScrollY = 0;
    let touchStartX = 0;
    let touchStartY = 0;
    
    function openMobileMenu() {
        const overlay = document.getElementById('mobileMenuOverlay');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        menuScrollY = window.scrollY;
    }
    
    function closeMobileMenu() {
        const overlay = document.getElementById('mobileMenuOverlay');
        overlay.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    // Close menu when clicking overlay (outside menu)
    document.getElementById('mobileMenuOverlay').addEventListener('click', function(e) {
        if (e.target === this) {
            closeMobileMenu();
        }
    });
    
    // Close menu on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
        }
    });
    
    // Swipe to close functionality
    const menuSidebar = document.getElementById('mobileMenuSidebar');
    
    menuSidebar.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
    }, { passive: true });
    
    menuSidebar.addEventListener('touchmove', function(e) {
        if (!touchStartX || !touchStartY) return;
        
        const touchEndX = e.touches[0].clientX;
        const touchEndY = e.touches[0].clientY;
        
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;
        
        // If horizontal swipe is more than vertical (swipe right to close)
        if (Math.abs(deltaX) > Math.abs(deltaY) && deltaX > 50) {
            closeMobileMenu();
            touchStartX = 0;
            touchStartY = 0;
        }
    }, { passive: true });
    
    menuSidebar.addEventListener('touchend', function() {
        touchStartX = 0;
        touchStartY = 0;
    }, { passive: true });
    
    // Close menu on page scroll (when menu is open)
    let scrollTimeout;
    window.addEventListener('scroll', function() {
        const overlay = document.getElementById('mobileMenuOverlay');
        
        if (overlay.classList.contains('active')) {
            // Check if scroll happened outside the menu
            const currentScrollY = window.scrollY;
            if (Math.abs(currentScrollY - menuScrollY) > 50) {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(function() {
                    closeMobileMenu();
                }, 150);
            }
        }
    }, { passive: true });
    
    // Close menu when clicking anywhere on the page (except the menu itself)
    document.addEventListener('click', function(e) {
        const overlay = document.getElementById('mobileMenuOverlay');
        const sidebar = document.getElementById('mobileMenuSidebar');
        const toggler = document.querySelector('.navbar-toggler');
        
        if (overlay.classList.contains('active')) {
            // Check if click is outside the menu and not on the toggler
            if (!sidebar.contains(e.target) && !toggler.contains(e.target)) {
                closeMobileMenu();
            }
        }
    });
    </script>
</body>
</html>
