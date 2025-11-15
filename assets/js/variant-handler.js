// Variant handler: update displayed product price when a variant is selected
// This is deliberately defensive: it listens for click/touch and also calls
// existing inline handlers (selectColorVariant/selectSizeVariant) when present.
$(document).ready(function() {
    function formatPrice(num) {
        if (isNaN(num) || num === null) return '';
        return 'Rs ' + Number(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }

    function setDisplayPriceFromVariant(variantPrice) {
        var price = parseFloat(variantPrice);
        if (!isNaN(price)) {
            $('#displayPrice').text(formatPrice(price));
        } else if (typeof basePrice !== 'undefined') {
            $('#displayPrice').text(formatPrice(parseFloat(basePrice)));
        }
    }

    // Use click/touch events but don't block inline handlers.
    // Run a short-timeout fallback after the event so inline onclick (if present)
    // can run first. If the variant wasn't activated by other code, our fallback
    // will apply the active class and update price/image.
    $(document).on('click touchstart', '.color-variant-circle', function(e) {
        // Prevent default to avoid conflicts
        e.preventDefault();
        e.stopPropagation();
        
        var el = this;
        // Let the inline handler run first
        setTimeout(function() {
            // Check if the inline handler already set active class
            if (!$(el).hasClass('active')) {
                $('.color-variant-circle').removeClass('active');
                $(el).addClass('active');
            }

            var variantPrice = $(el).data('variant-price');
            setDisplayPriceFromVariant(variantPrice);

            var bg = $(el).css('background-image');
            var match = bg && bg.match(/url\(["']?(.+?)['"]?\)/);
            if (match && match[1] && !match[1].includes('no-image.jpg')) {
                $('#mainProductImage').attr('src', match[1]);
            }
        }, 10);
    });

    $(document).on('click touchstart', '.size-variant-btn', function(e) {
        // Prevent default to avoid conflicts
        e.preventDefault();
        e.stopPropagation();
        
        var el = this;
        // Let the inline handler run first
        setTimeout(function() {
            // Check if the inline handler already set active class
            if (!$(el).hasClass('active')) {
                $('.size-variant-btn').removeClass('active');
                $(el).addClass('active');
            }

            var variantPrice = $(el).data('variant-price');
            setDisplayPriceFromVariant(variantPrice);
        }, 10);
    });
});