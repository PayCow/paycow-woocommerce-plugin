/**
 * PayCow WooCommerce Checkout Block Integration
 * Compatible with WooCommerce Blocks >= 2.6.0
 */
(function(wc, wp) {
    'use strict';

    if (!wc || !wc.wcBlocksRegistry || !wp || !wp.element || !wp.htmlEntities) {
        return;
    }

    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var decodeEntities = wp.htmlEntities.decodeEntities;

    var settings = window.wcSettings ? window.wcSettings.getSetting('paycow_data', {}) : {};

    var title = decodeEntities(settings.title || 'USDT Crypto Payment (PayCow)');
    var description = decodeEntities(settings.description || 'Pay securely with USDT (TRC-20 / ERC-20) directly to merchant wallet with 0% gateway fee.');
    var networkMode = settings.networkMode || 'customer_choice';
    var iconUrl = settings.iconUrl || '';
    var tronIconUrl = settings.tronIconUrl || '';
    var ethIconUrl = settings.ethIconUrl || '';

    var PayCowLabel = function() {
        return el('span', { className: 'paycow-block-label-container', style: { display: 'flex', alignItems: 'center', gap: '8px' } },
            iconUrl ? el('img', { src: iconUrl, alt: 'PayCow', style: { maxHeight: '24px', verticalAlign: 'middle' } }) : null,
            el('span', { className: 'paycow-block-label-text', style: { fontWeight: '600' } }, title)
        );
    };

    var PayCowContent = function(props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;

        var initialNetwork = networkMode === 'customer_choice' ? 'TRC20' : networkMode;
        var selectedState = useState(initialNetwork);
        var selectedNetwork = selectedState[0];
        var setSelectedNetwork = selectedState[1];

        // Register checkout event to inject payment data
        useEffect(function() {
            if (!eventRegistration || !eventRegistration.onPaymentProcessing) {
                return;
            }

            var unsubscribe = eventRegistration.onPaymentProcessing(function() {
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            paycow_network: selectedNetwork
                        }
                    }
                };
            });

            return function() {
                if (typeof unsubscribe === 'function') {
                    unsubscribe();
                }
            };
        }, [eventRegistration, emitResponse, selectedNetwork]);

        var children = [
            el('p', { key: 'desc', className: 'paycow-gateway-description' }, description)
        ];

        if (networkMode === 'customer_choice') {
            children.push(
                el('div', { key: 'net-title', className: 'paycow-network-selector-title' }, 'Select Settlement Network:')
            );

            var trc20Card = el('label', {
                key: 'trc20',
                className: 'paycow-network-card' + (selectedNetwork === 'TRC20' ? ' selected' : ''),
                onClick: function() { setSelectedNetwork('TRC20'); }
            },
                el('input', {
                    type: 'radio',
                    name: 'paycow_block_network',
                    value: 'TRC20',
                    checked: selectedNetwork === 'TRC20',
                    onChange: function() { setSelectedNetwork('TRC20'); }
                }),
                tronIconUrl ? el('img', { src: tronIconUrl, className: 'paycow-network-icon', alt: 'TRON' }) : null,
                el('div', { className: 'paycow-network-info' },
                    el('span', { className: 'paycow-network-name' }, 'TRON (TRC-20)'),
                    el('span', { className: 'paycow-network-sub' }, 'Low network fees, fast blockchain confirmation')
                )
            );

            var erc20Card = el('label', {
                key: 'erc20',
                className: 'paycow-network-card' + (selectedNetwork === 'ERC20' ? ' selected' : ''),
                onClick: function() { setSelectedNetwork('ERC20'); }
            },
                el('input', {
                    type: 'radio',
                    name: 'paycow_block_network',
                    value: 'ERC20',
                    checked: selectedNetwork === 'ERC20',
                    onChange: function() { setSelectedNetwork('ERC20'); }
                }),
                ethIconUrl ? el('img', { src: ethIconUrl, className: 'paycow-network-icon', alt: 'Ethereum' }) : null,
                el('div', { className: 'paycow-network-info' },
                    el('span', { className: 'paycow-network-name' }, 'Ethereum (ERC-20)'),
                    el('span', { className: 'paycow-network-sub' }, 'Ethereum network')
                )
            );

            children.push(
                el('div', { key: 'net-options', className: 'paycow-network-options' }, trc20Card, erc20Card)
            );
        }

        children.push(
            el('div', { key: 'sec-note', className: 'paycow-security-note' },
                el('span', { className: 'paycow-security-tag' }, 'Non-Custodial'),
                el('span', null, 'Payment settles directly into the merchant wallet. Zero custody risk.')
            )
        );

        return el('div', { className: 'paycow-payment-fields' }, children);
    };

    registerPaymentMethod({
        name: 'paycow',
        label: el(PayCowLabel, null),
        content: el(PayCowContent, null),
        edit: el(PayCowContent, null),
        canMakePayment: function() { return true; },
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products']
        }
    });

})(window.wc, window.wp);
