<?php
/**
 * Plugin Name: PayCow Crypto Payment Gateway for WooCommerce
 * Plugin URI: https://paycow.net/docs
 * Description: Accept non-custodial USDT (TRC-20 & ERC-20) cryptocurrency payments with 0% gateway commission directly into your self-custody wallets.
 * Version: 1.0.0
 * Author: PayCow
 * Author URI: https://paycow.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: paycow-crypto-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.8
 *
 * @package PayCow_Crypto_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'PAYCOW_VERSION', '1.0.0' );
define( 'PAYCOW_PLUGIN_FILE', __FILE__ );
define( 'PAYCOW_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PAYCOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS and WooCommerce Blocks compatibility.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        // High-Performance Order Storage (HPOS) declaration
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );

        // Cart & Checkout Blocks declaration
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

/**
 * Load plugin localization textdomain.
 */
add_action( 'init', function() {
    load_plugin_textdomain( 'paycow-crypto-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Check requirements and initialize the gateway.
 */
add_action( 'plugins_loaded', function() {
    // Ensure WooCommerce is active
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'paycow_woocommerce_missing_notice' );
        return;
    }

    // Include PayCow PHP SDK
    require_once PAYCOW_PLUGIN_PATH . 'includes/sdk/autoload.php';

    // Include required class files
    require_once PAYCOW_PLUGIN_PATH . 'includes/class-paycow-api.php';
    require_once PAYCOW_PLUGIN_PATH . 'includes/class-paycow-webhook.php';
    require_once PAYCOW_PLUGIN_PATH . 'includes/class-paycow-ajax-handler.php';
    require_once PAYCOW_PLUGIN_PATH . 'includes/class-wc-gateway-paycow.php';

    // Register PayCow gateway into WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'paycow_register_gateway' );
}, 11 );

/**
 * Register PayCow gateway in WooCommerce gateways list.
 *
 * @param array $gateways Registered gateways.
 * @return array
 */
function paycow_register_gateway( $gateways ) {
    $gateways[] = 'WC_Gateway_PayCow';
    return $gateways;
}

/**
 * Register WooCommerce Blocks payment method integration.
 */
add_action( 'woocommerce_blocks_loaded', function() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    require_once PAYCOW_PLUGIN_PATH . 'includes/class-paycow-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new PayCow_Blocks_Support() );
        }
    );
} );

/**
 * Display admin notice if WooCommerce is inactive.
 */
function paycow_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong><?php esc_html_e( 'PayCow Crypto Payment Gateway', 'paycow-crypto-gateway' ); ?></strong>:
            <?php esc_html_e( 'WooCommerce must be installed and active for PayCow to work.', 'paycow-crypto-gateway' ); ?>
        </p>
    </div>
    <?php
}

/**
 * Add quick action links on Plugins admin list table.
 *
 * @param array $links Existing action links.
 * @return array
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paycow' ),
        esc_html__( 'Settings', 'paycow-crypto-gateway' )
    );
    $docs_link = sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
        esc_url( 'https://paycow.net/docs' ),
        esc_html__( 'Docs', 'paycow-crypto-gateway' )
    );

    array_unshift( $links, $settings_link, $docs_link );
    return $links;
} );
