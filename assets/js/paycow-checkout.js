/**
 * PayCow WooCommerce Classic Checkout Script
 */
(function() {
    'use strict';

    function initPayCowCardSelector() {
        var cards = document.querySelectorAll('.paycow-network-card');
        if (!cards.length) return;

        cards.forEach(function(card) {
            card.addEventListener('click', function() {
                var radio = card.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    cards.forEach(function(c) { c.classList.remove('selected'); });
                    card.classList.add('selected');
                }
            });

            var radio = card.querySelector('input[type="radio"]');
            if (radio && radio.checked) {
                card.classList.add('selected');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPayCowCardSelector);
    } else {
        initPayCowCardSelector();
    }

    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', initPayCowCardSelector);
    }
})();
