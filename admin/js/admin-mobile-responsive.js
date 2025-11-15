/**
 * ========================================
 * ADMIN PANEL - MASTER MOBILE RESPONSIVE JAVASCRIPT
 * ========================================
 * 
 * This JavaScript file provides consistent mobile-responsive functionality
 * for all admin pages with:
 * - Collapsible card dropdowns
 * - Smooth animations
 * - Touch-optimized interactions
 * - State management
 * 
 * Include this file in all admin pages for consistent behavior
 */

(function() {
    'use strict';
    
    /**
     * Toggle item details (collapsible dropdown)
     * @param {string|number} itemId - The ID of the item to toggle
     */
    window.toggleItemDetails = function(itemId) {
        const detailsElement = document.getElementById('item-details-' + itemId);
        const cardHeader = document.querySelector('[data-item-id="' + itemId + '"]');
        const expandIcon = cardHeader ? cardHeader.querySelector('.item-expand-icon i') : null;
        
        if (!detailsElement) {
            console.warn('Details element not found for item:', itemId);
            return;
        }
        
        const isExpanded = detailsElement.classList.contains('expanded');
        
        if (isExpanded) {
            // Collapse
            detailsElement.classList.remove('expanded');
            detailsElement.style.maxHeight = '0';
            if (expandIcon) {
                expandIcon.style.transform = 'rotate(0deg)';
            }
        } else {
            // Expand
            detailsElement.classList.add('expanded');
            detailsElement.style.maxHeight = detailsElement.scrollHeight + 'px';
            if (expandIcon) {
                expandIcon.style.transform = 'rotate(180deg)';
            }
            
            // Add animation class
            detailsElement.classList.add('animate-slide-down');
            setTimeout(() => {
                detailsElement.classList.remove('animate-slide-down');
            }, 300);
        }
    };
    
    /**
     * Collapse all expanded items
     */
    window.collapseAllItems = function() {
        const expandedDetails = document.querySelectorAll('.item-details.expanded');
        expandedDetails.forEach(details => {
            details.classList.remove('expanded');
            details.style.maxHeight = '0';
            
            const itemId = details.id.replace('item-details-', '');
            const cardHeader = document.querySelector('[data-item-id="' + itemId + '"]');
            const expandIcon = cardHeader ? cardHeader.querySelector('.item-expand-icon i') : null;
            
            if (expandIcon) {
                expandIcon.style.transform = 'rotate(0deg)';
            }
        });
    };
    
    /**
     * Expand all items
     */
    window.expandAllItems = function() {
        const allDetails = document.querySelectorAll('.item-details');
        allDetails.forEach(details => {
            if (!details.classList.contains('expanded')) {
                details.classList.add('expanded');
                details.style.maxHeight = details.scrollHeight + 'px';
                
                const itemId = details.id.replace('item-details-', '');
                const cardHeader = document.querySelector('[data-item-id="' + itemId + '"]');
                const expandIcon = cardHeader ? cardHeader.querySelector('.item-expand-icon i') : null;
                
                if (expandIcon) {
                    expandIcon.style.transform = 'rotate(180deg)';
                }
            }
        });
    };
    
    /**
     * Initialize stat cards with animation
     */
    function initStatCards() {
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.4s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    }
    
    /**
     * Initialize item cards with animation
     */
    function initItemCards() {
        const itemCards = document.querySelectorAll('.item-card');
        itemCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateX(-20px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.3s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateX(0)';
            }, index * 50);
        });
    }
    
    /**
     * Handle window resize for responsive adjustments
     */
    function handleResize() {
        const expandedDetails = document.querySelectorAll('.item-details.expanded');
        expandedDetails.forEach(details => {
            // Recalculate max-height on resize
            details.style.maxHeight = details.scrollHeight + 'px';
        });
    }
    
    /**
     * Initialize touch gestures for mobile
     */
    function initTouchGestures() {
        let touchStartX = 0;
        let touchStartY = 0;
        
        document.addEventListener('touchstart', function(e) {
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
        }, {passive: true});
        
        document.addEventListener('touchend', function(e) {
            const touchEndX = e.changedTouches[0].clientX;
            const touchEndY = e.changedTouches[0].clientY;
            
            const diffX = touchStartX - touchEndX;
            const diffY = touchStartY - touchEndY;
            
            // Detect swipe gestures (optional feature)
            if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 50) {
                if (diffX > 0) {
                    // Swipe left
                    console.log('Swipe left detected');
                } else {
                    // Swipe right
                    console.log('Swipe right detected');
                }
            }
        }, {passive: true});
    }
    
    /**
     * Add smooth scroll behavior
     */
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href !== '#' && href !== '#!') {
                    const target = document.querySelector(href);
                    if (target) {
                        e.preventDefault();
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }
            });
        });
    }
    
    /**
     * Initialize search functionality
     */
    function initSearch() {
        const searchInputs = document.querySelectorAll('.search-input, .mobile-search-input');
        searchInputs.forEach(input => {
            input.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const itemCards = document.querySelectorAll('.item-card');
                
                itemCards.forEach(card => {
                    const text = card.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        card.style.display = '';
                        card.classList.add('animate-fade-in');
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
    }
    
    /**
     * Initialize checkbox selection
     */
    function initCheckboxSelection() {
        const selectAllCheckbox = document.getElementById('select-all');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.item-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateSelectedCount();
            });
        }
        
        // Individual checkboxes
        const itemCheckboxes = document.querySelectorAll('.item-checkbox');
        itemCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });
    }
    
    /**
     * Update selected items count
     */
    function updateSelectedCount() {
        const checkedBoxes = document.querySelectorAll('.item-checkbox:checked');
        const count = checkedBoxes.length;
        const countElement = document.getElementById('selected-count');
        
        if (countElement) {
            countElement.textContent = count;
        }
        
        // Show/hide bulk actions
        const bulkActions = document.getElementById('bulk-actions');
        if (bulkActions) {
            if (count > 0) {
                bulkActions.style.display = 'flex';
                bulkActions.classList.add('animate-slide-down');
            } else {
                bulkActions.style.display = 'none';
            }
        }
    }
    
    /**
     * Initialize tooltips
     */
    function initTooltips() {
        const tooltipElements = document.querySelectorAll('[data-tooltip]');
        tooltipElements.forEach(element => {
            element.addEventListener('mouseenter', function() {
                const tooltipText = this.getAttribute('data-tooltip');
                const tooltip = document.createElement('div');
                tooltip.className = 'custom-tooltip';
                tooltip.textContent = tooltipText;
                tooltip.style.position = 'absolute';
                tooltip.style.background = 'rgba(0, 0, 0, 0.8)';
                tooltip.style.color = 'white';
                tooltip.style.padding = '6px 12px';
                tooltip.style.borderRadius = '6px';
                tooltip.style.fontSize = '0.813rem';
                tooltip.style.zIndex = '9999';
                tooltip.style.pointerEvents = 'none';
                tooltip.style.whiteSpace = 'nowrap';
                
                document.body.appendChild(tooltip);
                
                const rect = this.getBoundingClientRect();
                tooltip.style.top = (rect.top - tooltip.offsetHeight - 8) + 'px';
                tooltip.style.left = (rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)) + 'px';
                
                this._tooltip = tooltip;
            });
            
            element.addEventListener('mouseleave', function() {
                if (this._tooltip) {
                    this._tooltip.remove();
                    this._tooltip = null;
                }
            });
        });
    }
    
    /**
     * Initialize loading states
     */
    function initLoadingStates() {
        window.showLoading = function(buttonElement) {
            if (buttonElement) {
                buttonElement.disabled = true;
                buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
            }
        };
        
        window.hideLoading = function(buttonElement, originalText) {
            if (buttonElement) {
                buttonElement.disabled = false;
                buttonElement.innerHTML = originalText;
            }
        };
    }
    
    /**
     * Initialize notification system
     */
    function initNotifications() {
        window.showNotification = function(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type} animate-slide-down`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 16px 20px;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                z-index: 10000;
                max-width: 400px;
                font-size: 0.938rem;
                font-weight: 500;
            `;
            
            const colors = {
                success: 'linear-gradient(135deg, #10b981, #059669)',
                error: 'linear-gradient(135deg, #ef4444, #dc2626)',
                warning: 'linear-gradient(135deg, #f59e0b, #d97706)',
                info: 'linear-gradient(135deg, #3b82f6, #2563eb)'
            };
            
            notification.style.background = colors[type] || colors.info;
            notification.style.color = 'white';
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(400px)';
                notification.style.transition = 'all 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        };
    }
    
    /**
     * Initialize on DOM ready
     */
    function init() {
        // Wait for DOM to be fully loaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
            return;
        }
        
        console.log('🚀 Admin Mobile Responsive JS initialized');
        
        // Initialize all features
        initStatCards();
        initItemCards();
        initTouchGestures();
        initSmoothScroll();
        initSearch();
        initCheckboxSelection();
        initTooltips();
        initLoadingStates();
        initNotifications();
        
        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(handleResize, 250);
        });
        
        // Log initialization
        console.log('✅ All features initialized');
    }
    
    // Start initialization
    init();
    
})();
