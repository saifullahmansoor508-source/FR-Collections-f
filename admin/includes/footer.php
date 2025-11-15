        </main>
    </div>
    
    <!-- Scripts -->
    <!-- jQuery MUST be loaded first -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Then Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Then custom scripts -->
    <script src="../assets/js/main.js"></script>
    
    <script>
        // Verify scripts loaded
        console.log('✓ jQuery loaded:', typeof $ !== 'undefined' ? 'YES' : 'NO');
        console.log('✓ Bootstrap loaded:', typeof bootstrap !== 'undefined' ? 'YES' : 'NO');
    </script>
    
    <script>
        /* ========================================
           SIDEBAR TOGGLE FUNCTIONALITY
           ======================================== */
        
        /**
         * Toggle sidebar open/close
         * Works on both mobile and desktop
         */
        function toggleSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const content = document.querySelector('.admin-content');
            
            // Check if we're on mobile or desktop
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                // Mobile: Toggle sidebar and overlay
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
                
                // Prevent body scroll when sidebar is open
                if (sidebar.classList.contains('show')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            } else {
                // Desktop: Collapse/expand sidebar
                sidebar.classList.toggle('collapsed');
                content.classList.toggle('expanded');
            }
        }
        
        // Admin dropdown toggle
        function toggleAdminDropdown(event) {
            event.stopPropagation();
            const menu = document.getElementById('adminDropdownMenu');
            const isShown = menu.classList.contains('show');
            
            if (isShown) {
                menu.classList.remove('show');
            } else {
                menu.classList.add('show');
            }
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('adminDropdownContainer');
            const menu = document.getElementById('adminDropdownMenu');
            
            if (dropdown && menu && !dropdown.contains(event.target)) {
                menu.classList.remove('show');
            }
        });
        
        /**
         * Close sidebar (used by close button and overlay)
         */
        function closeSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
        
        /**
         * Open sidebar (helper function)
         */
        function openSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (window.innerWidth <= 768) {
                sidebar.classList.add('show');
                overlay.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }
        
        /* ========================================
           EVENT LISTENERS
           ======================================== */
        
        // Close sidebar when pressing ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });
        
        // Handle window resize - reset sidebar state
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                const sidebar = document.getElementById('adminSidebar');
                const overlay = document.getElementById('sidebarOverlay');
                const content = document.querySelector('.admin-content');
                
                // Reset states when switching between mobile/desktop
                if (window.innerWidth > 768) {
                    // Desktop mode - close mobile sidebar
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.style.overflow = '';
                } else {
                    // Mobile mode - reset desktop collapse
                    sidebar.classList.remove('collapsed');
                    content.classList.remove('expanded');
                }
            }, 250);
        });
        
        // Auto-hide alerts
        setTimeout(function() {
            if (typeof $ !== 'undefined') {
                $('.alert').fadeOut();
            }
        }, 5000);
        
        // Confirm delete actions - OLD STYLE (REPLACED)
        function confirmDelete(message = 'Are you sure you want to delete this item?') {
            return confirm(message);
        }
        
        // Expose confirmDelete to window
        window.confirmDelete = confirmDelete;
        
        // New custom confirm handler for forms
        async function handleDeleteConfirm(event, message, title = 'Confirm Delete') {
            event.preventDefault();
            
            // Fallback to native confirm if showConfirm is not available
            if (typeof showConfirm === 'undefined') {
                const confirmed = confirm(message);
                if (confirmed) {
                    event.target.submit();
                }
                return false;
            }
            
            const confirmed = await showConfirm(message, title, {confirmText: 'Yes, Delete', cancelText: 'Cancel', type: 'danger'});
            if (confirmed) {
                event.target.submit();
            }
            return false;
        }
        
        // Expose handleDeleteConfirm to window
        window.handleDeleteConfirm = handleDeleteConfirm;
        
        // Show loading state
        function showLoading(button) {
            const originalText = button.innerHTML;
            button.innerHTML = '<span class="loading"></span> Loading...';
            button.disabled = true;
            
            return function() {
                button.innerHTML = originalText;
                button.disabled = false;
            };
        }
        
        // Expose showLoading to window
        window.showLoading = showLoading;
        
        // Format currency
        function formatCurrency(amount) {
            return 'PKR ' + parseFloat(amount).toLocaleString();
        }
        
        // Expose formatCurrency to window
        window.formatCurrency = formatCurrency;
        
        // Image preview
        function previewImage(input, previewElement) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (typeof $ !== 'undefined') {
                        $(previewElement).attr('src', e.target.result).show();
                    } else {
                        const el = document.querySelector(previewElement);
                        if (el) {
                            el.src = e.target.result;
                            el.style.display = 'block';
                        }
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Expose previewImage to window
        window.previewImage = previewImage;
        
        // Data tables initialization
        if (typeof $ !== 'undefined') {
            $(document).ready(function() {
                // Initialize Bootstrap dropdowns explicitly
                if (typeof bootstrap !== 'undefined') {
                    var dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
                    dropdownElementList.map(function (dropdownToggleEl) {
                        return new bootstrap.Dropdown(dropdownToggleEl);
                    });
                }
                
                // Initialize tooltips
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
                
                // Initialize popovers
                var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
                popoverTriggerList.map(function (popoverTriggerEl) {
                    return new bootstrap.Popover(popoverTriggerEl);
                });
                
                // Auto-resize textareas
                $('textarea').on('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
            });
        } else {
            // Fallback without jQuery
            document.addEventListener('DOMContentLoaded', function() {
                // Initialize Bootstrap dropdowns explicitly
                if (typeof bootstrap !== 'undefined') {
                    var dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
                    dropdownElementList.map(function (dropdownToggleEl) {
                        return new bootstrap.Dropdown(dropdownToggleEl);
                    });
                }
                
                // Initialize tooltips
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
                
                // Initialize popovers
                var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
                popoverTriggerList.map(function (popoverTriggerEl) {
                    return new bootstrap.Popover(popoverTriggerEl);
                });
                
                // Auto-resize textareas
                document.querySelectorAll('textarea').forEach(function(textarea) {
                    textarea.addEventListener('input', function() {
                        this.style.height = 'auto';
                        this.style.height = (this.scrollHeight) + 'px';
                    });
                });
            });
        }
    </script>
    
    <!-- Admin Mobile Responsive JavaScript -->
    <script src="js/admin-mobile-responsive.js"></script>
</body>
</html>
