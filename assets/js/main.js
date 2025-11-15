// FR Collections Main JavaScript

$(document).ready(function() {
    // Update cart count on page load
    updateCartCount();
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Cart functionality
function updateCartCount() {
    // Get the base URL by checking the current path
    const currentPath = window.location.pathname;
    const basePath = currentPath.includes('/admin/') ? '../ajax/get_cart_count.php' : 'ajax/get_cart_count.php';
    
    $.get(basePath, function(data) {
        const count = data.count || 0;
        // Update all cart count badges
        $('#cart-count').text(count);
        $('#mobileCartCount').text(count);
        $('#mobile-cart-count').text(count);
        $('#drawer-cart-count').text(count);
    }).fail(function(xhr, status, error) {
        console.error('Cart count update failed:', error);
        $('#cart-count').text('0');
        $('#mobileCartCount').text('0');
        $('#mobile-cart-count').text('0');
        $('#drawer-cart-count').text('0');
    });
}

function addToCart(productId, variantId = null, quantity = 1, buttonElement = null) {
    // Ensure variantId is null if not provided or is 0
    const validVariantId = variantId && variantId > 0 ? variantId : null;
    
    // Find the button that was clicked - use event.currentTarget if available
    let button = buttonElement;
    if (!button && typeof event !== 'undefined' && event.currentTarget) {
        button = $(event.currentTarget);
    }
    if (!button || !button.length) {
        // Fallback: find by product ID
        button = $(`.btn-cart[onclick*="addToCart(${productId})"]`).first();
    }
    
    $.post('ajax/add_to_cart.php', {
        product_id: productId,
        variant_id: validVariantId,
        quantity: quantity
    }, function(data) {
        if(data.success) {
            updateCartCount();
            
            // Show appropriate notification
            showNotification('Added to Cart', 'success');
            
            // Instantly update button to "In Cart" state
            if(button && button.length) {
                // Update button appearance
                button.html('<i class="fas fa-check-circle me-1"></i>In Cart');
                button.removeClass('btn-cart').addClass('btn-in-cart added-to-cart');
                
                // Change onclick behavior to go to cart page
                button.attr('onclick', "event.stopPropagation(); window.location.href='cart.php'");
            }
        } else {
            showNotification(data.message || 'Error adding to cart', 'error');
        }
    }, 'json').fail(function() {
        showNotification('Error adding to cart', 'error');
    });
}

function removeFromCart(cartId) {
    // Check if we're on cart page and use enhanced modal if available
    if (typeof showConfirmationModal === 'function' && typeof cartItemsData !== 'undefined') {
        // Use cart page's enhanced delete function
        const itemName = cartItemsData[cartId] ? cartItemsData[cartId].name : 'this item';
        
        showConfirmationModal(
            `Are you sure you want to remove <strong>${itemName}</strong> from your cart?`,
            function() {
                $.post('ajax/remove_from_cart.php', {
                    cart_id: cartId
                }, function(data) {
                    if(data.success) {
                        showNotification('Item removed from cart', 'success');
                        
                        // Remove from DOM with animation - handle both regular and warning items
                        const itemRows = document.querySelectorAll(`[data-cart-id="${cartId}"], [data-warning-item-id="${cartId}"]`);
                        itemRows.forEach(row => {
                            row.style.transition = 'all 0.4s ease';
                            row.style.opacity = '0';
                            row.style.transform = 'translateX(-30px) scale(0.9)';
                            setTimeout(() => {
                                row.remove();
                                
                                // Update cart count
                                updateCartCount();
                                
                                // Remove from local data if exists
                                if (typeof cartItemsData !== 'undefined') {
                                    delete cartItemsData[cartId];
                                }
                                
                                // Update order summary if function exists
                                if (typeof updateOrderSummary === 'function') {
                                    updateOrderSummary();
                                }
                                
                                // Check if warning section is now empty
                                setTimeout(() => {
                                    const remainingWarningItems = document.querySelectorAll('[data-warning-item-id]');
                                    if (remainingWarningItems.length === 0) {
                                        const warningSection = document.querySelector('.missing-variants-section');
                                        if (warningSection) {
                                            warningSection.style.transition = 'all 0.5s ease';
                                            warningSection.style.opacity = '0';
                                            warningSection.style.transform = 'scale(0.95)';
                                            setTimeout(() => {
                                                warningSection.remove();
                                                
                                                // Check if entire cart is empty after warning section removal
                                                if (typeof checkIfCartEmpty === 'function') {
                                                    setTimeout(() => checkIfCartEmpty(), 100);
                                                }
                                            }, 500);
                                        }
                                    } else {
                                        // Still have warning items, just check cart status
                                        if (typeof checkIfCartEmpty === 'function') {
                                            checkIfCartEmpty();
                                        }
                                    }
                                    
                                    // Update warning section controls if function exists
                                    if (typeof updateWarningSelectedCount === 'function') {
                                        updateWarningSelectedCount();
                                    }
                                }, 100);
                            }, 400);
                        });
                    } else {
                        showNotification(data.message || 'Error removing from cart', 'error');
                    }
                }, 'json');
            }
        );
    } else {
        // Simple confirmation for non-cart pages
        if (confirm('Are you sure you want to remove this item from your cart?')) {
            $.post('ajax/remove_from_cart.php', {
                cart_id: cartId
            }, function(data) {
                if(data.success) {
                    updateCartCount();
                    showNotification('Item removed from cart', 'success');
                    location.reload();
                } else {
                    showNotification(data.message || 'Error removing from cart', 'error');
                }
            }, 'json');
        }
    }
}

function updateCartQuantity(cartId, quantity) {
    $.post('ajax/update_cart_quantity.php', {
        cart_id: cartId,
        quantity: quantity
    }, function(data) {
        if(data.success) {
            updateCartCount();
            
            // If on cart page, update summary without reload
            if (typeof updateOrderSummary === 'function') {
                // Update local data
                if (typeof cartItemsData !== 'undefined' && cartItemsData[cartId]) {
                    cartItemsData[cartId].quantity = quantity;
                }
                updateOrderSummary();
                showNotification('Quantity updated', 'success');
            } else {
                // Other pages can reload
                location.reload();
            }
        } else {
            showNotification(data.message || 'Error updating quantity', 'error');
        }
    }, 'json');
}

// Wishlist functionality
function toggleWishlist(productId) {
    $.post('ajax/toggle_wishlist.php', {
        product_id: productId
    }, function(data) {
        if(data.success) {
            const icon = $(`[data-product-id="${productId}"] i`);
            if(data.added) {
                icon.removeClass('far').addClass('fas');
                showNotification('Added to wishlist!', 'success');
            } else {
                icon.removeClass('fas').addClass('far');
                showNotification('Removed from wishlist!', 'info');
            }
        } else {
            showNotification(data.message || 'Error updating wishlist', 'error');
        }
    }, 'json');
}

// Wishlist button toggle for product page (with text and color change)
function toggleWishlistButton(productId) {
    $.post('ajax/toggle_wishlist.php', {
        product_id: productId
    }, function(data) {
        if(data.success) {
            const btn = $('#wishlistBtn');
            const icon = btn.find('i');
            
            if(data.added) {
                // Product added to wishlist
                icon.removeClass('far').addClass('fas');
                btn.html('<i class="fas fa-heart me-2"></i>Remove from Wishlist');
                btn.css({
                    'background': '#ef4444',
                    'color': 'white',
                    'border-color': '#ef4444'
                });
                btn.addClass('in-wishlist');
                showNotification('Added to wishlist!', 'success');
            } else {
                // Product removed from wishlist
                icon.removeClass('fas').addClass('far');
                btn.html('<i class="far fa-heart me-2"></i>Add to Wishlist');
                btn.css({
                    'background': 'white',
                    'color': '#1e3a8a',
                    'border-color': '#1e3a8a'
                });
                btn.removeClass('in-wishlist');
                showNotification('Removed from wishlist!', 'info');
            }
        } else {
            showNotification(data.message || 'Error updating wishlist', 'error');
        }
    }, 'json');
}

// Favorites functionality
function toggleFavorite(productId) {
    $.post('ajax/toggle_favorite.php', {
        product_id: productId
    }, function(data) {
        if(data.success) {
            const icon = $(`.favorite-btn[data-product-id="${productId}"] i`);
            if(data.action === 'added') {
                icon.removeClass('far').addClass('fas');
                showNotification('Added to favorites!', 'success');
            } else {
                icon.removeClass('fas').addClass('far');
                showNotification('Removed from favorites!', 'info');
            }
        } else {
            showNotification(data.message || 'Error updating favorites', 'error');
        }
    }, 'json');
}

// Product gallery
function initProductGallery() {
    $('.gallery-thumbs img').click(function() {
        const newSrc = $(this).attr('src');
        $('.gallery-main img').attr('src', newSrc);
        $('.gallery-thumbs img').removeClass('active');
        $(this).addClass('active');
    });
}

// Quantity selector
function initQuantitySelector() {
    $('.quantity-minus').click(function() {
        const input = $(this).siblings('input');
        const currentVal = parseInt(input.val()) || 1;
        if(currentVal > 1) {
            input.val(currentVal - 1);
        }
    });
    
    $('.quantity-plus').click(function() {
        const input = $(this).siblings('input');
        const currentVal = parseInt(input.val()) || 1;
        input.val(currentVal + 1);
    });
}

// Product variants
function initVariantSelector() {
    $('.variant-option').click(function() {
        const variantType = $(this).data('variant-type');
        $(`.variant-option[data-variant-type="${variantType}"]`).removeClass('selected');
        $(this).addClass('selected');
        
        // Update price if variant has different price
        const variantPrice = $(this).data('variant-price');
        if(variantPrice) {
            $('.product-price').text('PKR ' + variantPrice.toLocaleString());
        }
    });
    
    $('.color-variant').click(function() {
        $('.color-variant').removeClass('selected');
        $(this).addClass('selected');
        
        // Update main image if color variant has image
        const variantImage = $(this).data('variant-image');
        if(variantImage) {
            $('.gallery-main img').attr('src', variantImage);
        }
    });
}

// Reviews
// The review submission is handled in product.php to avoid page reload

// Coupon code
function applyCoupon() {
    const couponCode = $('#couponCode').val();
    if(!couponCode) {
        showNotification('Please enter a coupon code', 'error');
        return;
    }
    
    $.post('ajax/apply_coupon.php', {
        coupon_code: couponCode
    }, function(data) {
        if(data.success) {
            showNotification('Coupon applied successfully!', 'success');
            location.reload();
        } else {
            showNotification(data.message || 'Invalid coupon code', 'error');
        }
    }, 'json');
}

// Search functionality
function searchProducts() {
    const searchTerm = $('#searchInput').val();
    const category = $('#categoryFilter').val();
    const priceSort = $('#priceSort').val();
    
    const params = new URLSearchParams();
    if(searchTerm) params.append('search', searchTerm);
    if(category && category !== 'All Categories') params.append('category', category);
    if(priceSort) params.append('sort', priceSort);
    
    window.location.href = 'shop.php?' + params.toString();
}

// Affiliate withdrawal
function submitWithdrawal() {
    const amount = $('#withdrawAmount').val();
    const method = $('#withdrawMethod').val();
    const accountNumber = $('#withdrawAccount').val();
    
    if(!amount || !method || !accountNumber) {
        showNotification('Please fill all fields', 'error');
        return;
    }
    
    if(parseFloat(amount) < 100) {
        showNotification('Minimum withdrawal amount is PKR 100', 'error');
        return;
    }
    
    $.post('ajax/submit_withdrawal.php', {
        amount: amount,
        method: method,
        account_number: accountNumber
    }, function(data) {
        if(data.success) {
            showNotification('Withdrawal request submitted!', 'success');
            $('#withdrawalModal').modal('hide');
            location.reload();
        } else {
            showNotification(data.message || 'Error submitting withdrawal', 'error');
        }
    }, 'json');
}

// Order management
async function cancelOrder(orderId) {
    if(await showConfirm('Are you sure you want to cancel this order?', 'Cancel Order', {confirmText: 'Yes, Cancel', cancelText: 'No', type: 'warning'})) {
        $.post('ajax/cancel_order.php', {
            order_id: orderId
        }, function(data) {
            if(data.success) {
                showToast('Order canceled successfully', 'success');
                location.reload();
            } else {
                showAlert(data.message || 'Error canceling order', 'error');
            }
        }, 'json');
    }
}

// Notifications
function showNotification(message, type = 'info') {
    const alertClass = type === 'success' ? 'alert-success' : 
                      type === 'error' ? 'alert-danger' : 
                      type === 'warning' ? 'alert-warning' : 'alert-info';
    
    const notification = $(`
        <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
             style="top: 100px; right: 20px; z-index: 9999; min-width: 300px;">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `);
    
    $('body').append(notification);
    
    setTimeout(() => {
        notification.alert('close');
    }, 5000);
}

// Form validation
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function validatePhone(phone) {
    const re = /^\+92[0-9]{10}$/;
    return re.test(phone);
}

// Image upload preview
function previewImage(input, previewElement) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            $(previewElement).attr('src', e.target.result).show();
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Category carousel
function initCategoryCarousel() {
    $('.category-carousel').slick({
        slidesToShow: 4,
        slidesToScroll: 1,
        autoplay: true,
        autoplaySpeed: 3000,
        infinite: true,
        arrows: true,
        dots: false,
        responsive: [
            {
                breakpoint: 992,
                settings: {
                    slidesToShow: 3
                }
            },
            {
                breakpoint: 768,
                settings: {
                    slidesToShow: 2
                }
            },
            {
                breakpoint: 576,
                settings: {
                    slidesToShow: 1
                }
            }
        ]
    });
}

// Initialize components on page load
$(document).ready(function() {
    initProductGallery();
    initQuantitySelector();
    initVariantSelector();
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 5000);
    
    // Initialize category carousel if exists
    if($('.category-carousel').length) {
        initCategoryCarousel();
    }
    
    // Search on enter key
    $('#searchInput').keypress(function(e) {
        if(e.which == 13) {
            searchProducts();
        }
    });
    
    // Phone number formatting
    $('input[type="tel"]').on('input', function() {
        let value = $(this).val();
        if(value && !value.startsWith('+92')) {
            $(this).val('+92' + value.replace(/^\+92/, ''));
        }
    });
});

// Smooth scrolling for anchor links
$('a[href^="#"]').on('click', function(event) {
    var target = $(this.getAttribute('href'));
    if( target.length ) {
        event.preventDefault();
        $('html, body').stop().animate({
            scrollTop: target.offset().top - 100
        }, 1000);
    }
});

// Back to top button
$(window).scroll(function() {
    if ($(this).scrollTop() > 100) {
        $('#backToTop').fadeIn();
    } else {
        $('#backToTop').fadeOut();
    }
});

$('#backToTop').click(function() {
    $('html, body').animate({scrollTop : 0}, 800);
    return false;
});

// Lazy loading for images
$('img[data-src]').each(function() {
    const img = $(this);
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const lazyImg = entry.target;
                lazyImg.src = lazyImg.dataset.src;
                lazyImg.classList.remove('lazy');
                observer.unobserve(lazyImg);
            }
        });
    });
    observer.observe(this);
});
