<?php
/**
 * PayCow WooCommerce Blocks Support
 *
 * Extends AbstractPaymentMethodType to integrate PayCow into WooCommerce
 * Cart & Checkout Blocks (Gutenberg React-based checkout).
 *
 * @package PayCow_Crypto_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class PayCow_Blocks_Support
 */
final class PayCow_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment method name defined by payment methods extending this class.
     *
     * @var string
     */
    protected $name = 'paycow';

    /**
     * Gateway settings.
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_paycow_settings', array() );

        $script_path = PAYCOW_PLUGIN_URL . 'assets/js/paycow-blocks.js';
        $style_path  = PAYCOW_PLUGIN_URL . 'assets/css/paycow-checkout.css';

        wp_register_script(
            'paycow-blocks-integration',
            $script_path,
            array(
                'wc-blocks-registry',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            PAYCOW_VERSION,
            true
        );

        wp_register_style(
            'paycow-blocks-style',
            $style_path,
            array(),
            PAYCOW_VERSION
        );
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    /**
     * Returns an array of script handles to enqueue in the frontend context.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        wp_enqueue_style( 'paycow-blocks-style' );
        return array( 'paycow-blocks-integration' );
    }

    /**
     * An array of key, value pairs of data made available to the payment on the client side.
     * Note: API Key and API Secret are STRICTLY omitted to prevent credential exposure.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->get_setting( 'title', __( 'USDT Crypto Payment (PayCow)', 'paycow-crypto-gateway' ) ),
            'description' => $this->get_setting( 'description', __( 'Pay securely with USDT (TRC-20 / ERC-20) directly to merchant wallet with 0% gateway fee.', 'paycow-crypto-gateway' ) ),
            'networkMode' => $this->get_setting( 'network_mode', 'customer_choice' ),
            'supports'    => array( 'products' ),
            'iconUrl'     => PAYCOW_PLUGIN_URL . 'assets/images/usdt-logo.svg',
            'tronIconUrl' => PAYCOW_PLUGIN_URL . 'assets/images/tron-badge.svg',
            'ethIconUrl'  => PAYCOW_PLUGIN_URL . 'assets/images/eth-badge.svg',
        );
    }
}
