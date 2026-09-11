/**
 * PayCow WooCommerce Thank You Page Live Status Poller
 * Safely queries WordPress backend AJAX without exposing API credentials.
 */
(function() {
    'use strict';

    if (typeof paycowThankYouParams === 'undefined') {
        return;
    }

    var params = paycowThankYouParams;
    var orderId = params.orderId;
    var orderKey = params.orderKey;
    var nonce = params.nonce;
    var ajaxUrl = params.ajaxUrl;
    var isPaid = params.isPaid;

    // If order is already paid, no need to poll
    if (isPaid) {
        return;
    }

    var pollInterval = 5000; // 5 seconds
    var maxAttempts = 36;    // 3 minutes total
    var attempts = 0;

    var statusBadge = document.getElementById('paycow-live-status-badge');
    var pollNotice = document.getElementById('paycow-live-poll-indicator');
    var txHashContainer = document.getElementById('paycow-txhash-container');
    var txHashValue = document.getElementById('paycow-txhash-value');

    function checkOrderStatus() {
        attempts++;
        if (attempts > maxAttempts) {
            if (pollNotice) {
                pollNotice.innerHTML = 'Status polling timed out. If you have sent the transfer, your order will update automatically once on-chain block confirmations are reached.';
            }
            return;
        }

        var formData = new FormData();
        formData.append('action', 'paycow_check_order_status');
        formData.append('order_id', orderId);
        formData.append('order_key', orderKey);
        formData.append('nonce', nonce);

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(res) {
            if (!res.ok) throw new Error('Network response not ok');
            return res.json();
        })
        .then(function(data) {
            if (!data || !data.success) {
                return;
            }

            var result = data.data;

            if (result.statusText && statusBadge) {
                statusBadge.textContent = result.statusText;
            }

            if (result.isPaid) {
                if (statusBadge) {
                    statusBadge.className = 'paycow-status-badge paycow-status-paid';
                    statusBadge.textContent = 'PAID (CONFIRMED)';
                }

                if (pollNotice) {
                    pollNotice.innerHTML = '<strong style="color:#15803d;">Payment verified and confirmed on blockchain!</strong>';
                }

                if (result.txHash && txHashContainer && txHashValue) {
                    txHashValue.innerHTML = result.explorerUrl
                        ? '<a href="' + result.explorerUrl + '" target="_blank" rel="noopener noreferrer" style="color:#0284c7; font-weight:600;">' + result.txHash.substring(0, 16) + '...</a>'
                        : result.txHash;
                    txHashContainer.style.display = 'flex';
                }

                // Reload order page once payment is marked complete so WooCommerce updates download links/order notice
                setTimeout(function() {
                    window.location.reload();
                }, 2500);

                return; // Stop polling
            }

            // Schedule next check
            setTimeout(checkOrderStatus, pollInterval);
        })
        .catch(function() {
            // Silently continue polling on network glitch
            setTimeout(checkOrderStatus, pollInterval);
        });
    }

    // Begin polling after initial 3-second delay
    setTimeout(checkOrderStatus, 3000);
})();
