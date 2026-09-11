<?php
/**
 * PayCow WooCommerce Payment Gateway Main Class
 *
 * Implements the WC_Payment_Gateway interface for PayCow non-custodial
 * USDT cryptocurrency payments.
 *
 * @package PayCow_Crypto_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Gateway_PayCow
 */
class WC_Gateway_PayCow extends WC_Payment_Gateway {

    /**
     * Webhook handler instance.
     *
     * @var PayCow_Webhook_Handler
     */
    public $webhook_handler;

    /**
     * AJAX handler instance.
     *
     * @var PayCow_Ajax_Handler
     */
    public $ajax_handler;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'paycow';
        $this->icon               = PAYCOW_PLUGIN_URL . 'assets/images/usdt-logo.svg';
        $this->has_fields         = true;
        $this->method_title       = __( 'PayCow Non-Custodial Crypto Gateway', 'paycow-crypto-gateway' );
        $this->method_description = __( 'Accept USDT cryptocurrency payments directly into your self-custody wallet (TRC-20 & ERC-20) with 0% gateway commission and verified on-chain blockchain confirmation.', 'paycow-crypto-gateway' );
        $this->supports           = array( 'products' );

        // Initialize settings fields & load stored options
        $this->init_form_fields();
        $this->init_settings();

        $this->title          = $this->get_option( 'title', __( 'USDT Crypto Payment (PayCow)', 'paycow-crypto-gateway' ) );
        $this->description    = $this->get_option( 'description', __( 'Pay securely with USDT (TRC-20 / ERC-20) directly to merchant wallet with 0% gateway fee.', 'paycow-crypto-gateway' ) );
        $this->api_key        = $this->get_option( 'api_key' );
        $this->api_secret     = $this->get_option( 'api_secret' );
        $this->webhook_secret = $this->get_option( 'webhook_secret' );
        $this->network_mode   = $this->get_option( 'network_mode', 'customer_choice' );
        $this->wallet_serial  = $this->get_option( 'wallet_serial' );
        $this->debug_log      = $this->get_option( 'debug_log', 'no' );

        // Admin settings save action
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Customer Thank You / Order Received page hook
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

        // Enqueue frontend scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

        // Enqueue admin styles on WooCommerce settings page
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

        // Instantiate Webhook and AJAX handlers
        $this->webhook_handler = new PayCow_Webhook_Handler( $this );
        $this->ajax_handler    = new PayCow_Ajax_Handler( $this );
    }

    /**
     * Define admin settings form fields.
     */
    public function init_form_fields() {
        $webhook_url = WC()->api_request_url( 'paycow_webhook' );

        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'paycow-crypto-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable PayCow Crypto Payment Gateway', 'paycow-crypto-gateway' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'paycow-crypto-gateway' ),
                'type'        => 'text',
                'description' => __( 'Payment method title displayed to customers on checkout.', 'paycow-crypto-gateway' ),
                'default'     => __( 'USDT Crypto Payment (PayCow)', 'paycow-crypto-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'paycow-crypto-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description displayed to customers on checkout.', 'paycow-crypto-gateway' ),
                'default'     => __( 'Pay securely with USDT (TRC-20 / ERC-20) directly to merchant wallet with 0% gateway fee.', 'paycow-crypto-gateway' ),
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __( 'Merchant API Key', 'paycow-crypto-gateway' ),
                'type'        => 'text',
                'description' => __( 'Your PayCow Public API Key from the merchant dashboard (Developer -> API Keys).', 'paycow-crypto-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'api_secret' => array(
                'title'       => __( 'Merchant API Secret', 'paycow-crypto-gateway' ),
                'type'        => 'password',
                'description' => __( 'Your PayCow Private API Secret from the merchant dashboard.', 'paycow-crypto-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_secret' => array(
                'title'       => __( 'Webhook Signing Secret', 'paycow-crypto-gateway' ),
                'type'        => 'password',
                'description' => __( 'Your HMAC-SHA256 Webhook Secret (starts with whsec_) generated in PayCow Dashboard (Developer -> Webhooks).', 'paycow-crypto-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'network_mode' => array(
                'title'       => __( 'Supported Network Mode', 'paycow-crypto-gateway' ),
                'type'        => 'select',
                'description' => __( 'Choose whether customers can choose their network at checkout or enforce a specific blockchain.', 'paycow-crypto-gateway' ),
                'default'     => 'customer_choice',
                'desc_tip'    => true,
                'options'     => array(
                    'customer_choice' => __( 'Customer Choice at Checkout (TRC-20 & ERC-20)', 'paycow-crypto-gateway' ),
                    'TRC20'           => __( 'TRON (TRC-20 Only) - Low Network Fees', 'paycow-crypto-gateway' ),
                    'ERC20'           => __( 'Ethereum (ERC-20 Only)', 'paycow-crypto-gateway' ),
                ),
            ),
            'wallet_serial' => array(
                'title'       => __( 'Specific Wallet Serial ID (Optional)', 'paycow-crypto-gateway' ),
                'type'        => 'text',
                'description' => __( 'Optional wallet serial number (e.g. WAL-1001). Leave blank to automatically use your primary configured wallet in PayCow.', 'paycow-crypto-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'debug_log' => array(
                'title'       => __( 'Debug Logging', 'paycow-crypto-gateway' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging for API and Webhook requests', 'paycow-crypto-gateway' ),
                'default'     => 'no',
                'description' => sprintf(
                    __( 'Log events inside %s', 'paycow-crypto-gateway' ),
                    '<code>WooCommerce &rarr; Status &rarr; Logs</code>'
                ),
            ),
            'webhook_info' => array(
                'title'       => __( 'Store Webhook URL', 'paycow-crypto-gateway' ),
                'type'        => 'title',
                'description' => sprintf(
                    '<div class="paycow-webhook-box">
                        <div class="paycow-webhook-title">%1$s</div>
                        <div class="paycow-webhook-desc">%2$s</div>
                        <div class="paycow-copy-group">
                            <input type="text" id="paycow_webhook_copy_input" class="paycow-copy-input" value="%3$s" readonly />
                            <button type="button" class="button button-secondary paycow-copy-btn" onclick="navigator.clipboard.writeText(document.getElementById(\'paycow_webhook_copy_input\').value); this.innerText=\'%4$s\';">%5$s</button>
                        </div>
                    </div>',
                    esc_html__( 'Webhook Delivery Endpoint', 'paycow-crypto-gateway' ),
                    esc_html__( 'Copy this URL and paste it into your PayCow Merchant Dashboard under Developer -> Webhooks to receive automated on-chain payment confirmations upon blockchain verification:', 'paycow-crypto-gateway' ),
                    esc_url( $webhook_url ),
                    esc_html__( 'Copied!', 'paycow-crypto-gateway' ),
                    esc_html__( 'Copy URL', 'paycow-crypto-gateway' )
                ),
            ),
        );
    }

    /**
     * Render custom admin settings page header.
     */
    public function admin_options() {
        ?>
        <div class="paycow-admin-header">
            <div>
                <h2 class="paycow-admin-title"><?php esc_html_e( 'PayCow Non-Custodial USDT Gateway', 'paycow-crypto-gateway' ); ?></h2>
                <p class="paycow-admin-desc"><?php esc_html_e( 'Accept TRC-20 and ERC-20 payments directly to your self-custody wallet with 0% gateway commission.', 'paycow-crypto-gateway' ); ?></p>
            </div>
            <span class="paycow-admin-tag"><?php esc_html_e( 'Non-Custodial', 'paycow-crypto-gateway' ); ?></span>
        </div>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }

    /**
     * Enqueue frontend styles and scripts on checkout page.
     */
    public function payment_scripts() {
        if ( ! is_checkout() && ! is_order_received_page() ) {
            return;
        }

        if ( 'no' === $this->enabled ) {
            return;
        }

        wp_enqueue_style(
            'paycow-checkout-style',
            PAYCOW_PLUGIN_URL . 'assets/css/paycow-checkout.css',
            array(),
            PAYCOW_VERSION
        );

        if ( is_checkout() && ! is_order_received_page() ) {
            wp_enqueue_script(
                'paycow-checkout-script',
                PAYCOW_PLUGIN_URL . 'assets/js/paycow-checkout.js',
                array( 'jquery' ),
                PAYCOW_VERSION,
                true
            );
        }
    }

    /**
     * Enqueue admin styles on WooCommerce settings page.
     *
     * @param string $hook Admin page hook.
     */
    public function admin_scripts( $hook ) {
        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'paycow-admin-style',
            PAYCOW_PLUGIN_URL . 'assets/css/paycow-admin.css',
            array(),
            PAYCOW_VERSION
        );
    }

    /**
     * Render payment fields at checkout (Classic Checkout).
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p class="paycow-gateway-description">' . wp_kses_post( $this->description ) . '</p>';
        }

        if ( 'customer_choice' === $this->network_mode ) {
            $tron_icon = PAYCOW_PLUGIN_URL . 'assets/images/tron-badge.svg';
            $eth_icon  = PAYCOW_PLUGIN_URL . 'assets/images/eth-badge.svg';
            ?>
            <div class="paycow-payment-fields">
                <div class="paycow-network-selector-title"><?php esc_html_e( 'Select Settlement Network:', 'paycow-crypto-gateway' ); ?></div>
                <div class="paycow-network-options">
                    <label class="paycow-network-card selected">
                        <input type="radio" name="paycow_network" value="TRC20" checked="checked" />
                        <img src="<?php echo esc_url( $tron_icon ); ?>" class="paycow-network-icon" alt="TRON" />
                        <div class="paycow-network-info">
                            <span class="paycow-network-name"><?php esc_html_e( 'TRON (TRC-20)', 'paycow-crypto-gateway' ); ?></span>
                            <span class="paycow-network-sub"><?php esc_html_e( 'Low network fees, fast blockchain confirmation', 'paycow-crypto-gateway' ); ?></span>
                        </div>
                    </label>
                    <label class="paycow-network-card">
                        <input type="radio" name="paycow_network" value="ERC20" />
                        <img src="<?php echo esc_url( $eth_icon ); ?>" class="paycow-network-icon" alt="Ethereum" />
                        <div class="paycow-network-info">
                            <span class="paycow-network-name"><?php esc_html_e( 'Ethereum (ERC-20)', 'paycow-crypto-gateway' ); ?></span>
                            <span class="paycow-network-sub"><?php esc_html_e( 'Ethereum network', 'paycow-crypto-gateway' ); ?></span>
                        </div>
                    </label>
                </div>
            </div>
            <?php
        }

        ?>
        <div class="paycow-security-note">
            <span class="paycow-security-tag"><?php esc_html_e( 'Non-Custodial', 'paycow-crypto-gateway' ); ?></span>
            <span><?php esc_html_e( 'Payment settles directly into the merchant wallet. Zero custody risk.', 'paycow-crypto-gateway' ); ?></span>
        </div>
        <?php
    }

    /**
     * Validate checkout input fields.
     */
    public function validate_fields() {
        if ( 'customer_choice' === $this->network_mode ) {
            $network = isset( $_POST['paycow_network'] ) ? sanitize_text_field( wp_unslash( $_POST['paycow_network'] ) ) : '';
            if ( ! empty( $network ) && ! in_array( $network, array( 'TRC20', 'ERC20' ), true ) ) {
                wc_add_notice( __( 'Please select a valid payment network (TRC-20 or ERC-20).', 'paycow-crypto-gateway' ), 'error' );
                return false;
            }
        }
        return true;
    }

    /**
     * Process checkout payment.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Unable to load order for payment processing.', 'paycow-crypto-gateway' ), 'error' );
            return array( 'result' => 'fail' );
        }

        if ( empty( $this->api_key ) || empty( $this->api_secret ) ) {
            $this->log( 'Payment failed: PayCow API Key or API Secret is missing in settings.' );
            wc_add_notice( __( 'Payment processing error: Gateway configuration incomplete. Please contact store support.', 'paycow-crypto-gateway' ), 'error' );
            return array( 'result' => 'fail' );
        }

        // Determine requested blockchain network
        $network = 'TRC20';
        if ( 'customer_choice' === $this->network_mode ) {
            // Check classic checkout POST or blocks checkout meta
            if ( ! empty( $_POST['paycow_network'] ) ) {
                $network = sanitize_text_field( wp_unslash( $_POST['paycow_network'] ) );
            } elseif ( ! empty( $_POST['paymentMethodData']['paycow_network'] ) ) {
                $network = sanitize_text_field( wp_unslash( $_POST['paymentMethodData']['paycow_network'] ) );
            }
        } else {
            $network = $this->network_mode;
        }

        $network = in_array( strtoupper( $network ), array( 'TRC20', 'ERC20' ), true ) ? strtoupper( $network ) : 'TRC20';

        // 1. Strict Currency Validation: PayCow processes native USDT
        $order_currency = strtoupper( (string) $order->get_currency() );
        if ( 'USDT' !== $order_currency ) {
            $currency_error = sprintf(
                __( 'PayCow payment gateway only supports USDT currency. Order is billed in %s. Automatic currency conversion is not supported. Please select USDT currency or choose an alternative payment method.', 'paycow-crypto-gateway' ),
                esc_html( $order_currency )
            );
            $this->log( sprintf( 'Order #%d checkout rejected: Currency %s is not USDT.', $order->get_id(), $order_currency ) );
            wc_add_notice( $currency_error, 'error' );
            return array( 'result' => 'fail' );
        }

        // 2. Exact Decimal String Amount Validation
        $order_total = trim( (string) $order->get_total() );
        if ( empty( $order_total ) || ! is_numeric( $order_total ) || \PayCow\Payments\PaymentService::compareDecimalStrings( $order_total, '0' ) <= 0 ) {
            wc_add_notice( __( 'Invalid order amount for crypto checkout.', 'paycow-crypto-gateway' ), 'error' );
            return array( 'result' => 'fail' );
        }

        // Construct unique Idempotency Key
        $idempotency_key = sprintf( 'wc_order_%d_%s', $order->get_id(), substr( $order->get_order_key(), 0, 16 ) );

        $params = array(
            'amount'          => $order_total,
            'userId'          => (string) $order->get_id(),
            'network'         => $network,
            'currency'        => 'USDT',
            'idempotencyKey'  => $idempotency_key,
        );

        if ( ! empty( $this->wallet_serial ) ) {
            $params['addressSerialNo'] = trim( $this->wallet_serial );
        }

        $this->log( sprintf( 'Initiating PayCow session for order #%d (Amount: %s USDT, Network: %s)', $order->get_id(), $order_total, $network ) );

        $response = PayCow_API::create_payment( $params, $this->api_key, $this->api_secret );

        if ( is_wp_error( $response ) ) {
            $this->log( sprintf( 'PayCow session creation failed for order #%d: %s', $order->get_id(), $response->get_error_message() ) );
            wc_add_notice( sprintf( __( 'Payment Gateway Error: %s', 'paycow-crypto-gateway' ), $response->get_error_message() ), 'error' );
            return array( 'result' => 'fail' );
        }

        // Store PayCow session details into Order Metadata using WooCommerce CRUD API
        $session_id        = sanitize_text_field( $response['sessionId'] );
        $payment_url       = esc_url_raw( $response['paymentUrl'] );
        $payment_reference = sanitize_text_field( $response['paymentReference'] );
        $payable_amount    = isset( $response['payableAmount'] ) ? trim( (string) $response['payableAmount'] ) : $order_total;
        $deposit_address   = sanitize_text_field( $response['depositAddress'] );
        $expires_at        = sanitize_text_field( $response['expiresAt'] );

        $order->update_meta_data( '_paycow_session_id', $session_id );
        $order->update_meta_data( '_paycow_payment_url', $payment_url );
        $order->update_meta_data( '_paycow_payment_reference', $payment_reference );
        $order->update_meta_data( '_paycow_payable_amount', $payable_amount );
        $order->update_meta_data( '_paycow_deposit_address', $deposit_address );
        $order->update_meta_data( '_paycow_network', $network );
        $order->update_meta_data( '_paycow_expires_at', $expires_at );
        $order->update_meta_data( '_paycow_status', 'PENDING' );

        $note = sprintf(
            __( 'PayCow non-custodial payment session created.%1$sSession ID: %2$s%1$sPayable Amount: %3$s USDT%1$sNetwork: %4$s%1$sDeposit Address: %5$s%1$sReference: %6$s', 'paycow-crypto-gateway' ),
            PHP_EOL,
            $session_id,
            $payable_amount,
            $network,
            $deposit_address,
            $payment_reference
        );
        $order->add_order_note( $note );
        $order->save();

        // Empty customer cart
        WC()->cart->empty_cart();

        // Redirect shopper to PayCow hosted payment interface
        return array(
            'result'   => 'success',
            'redirect' => $payment_url,
        );
    }

    /**
     * Render payment details card and live status poller on the Thank You page.
     *
     * @param int $order_id Order ID.
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $session_id      = $order->get_meta( '_paycow_session_id', true );
        $payable_amount  = $order->get_meta( '_paycow_payable_amount', true );
        $deposit_address = $order->get_meta( '_paycow_deposit_address', true );
        $network         = $order->get_meta( '_paycow_network', true );
        $reference       = $order->get_meta( '_paycow_payment_reference', true );
        $tx_hash         = $order->get_transaction_id();
        $is_paid         = $order->is_paid();
        $status          = $order->get_meta( '_paycow_status', true );
        $status_label    = $is_paid ? __( 'PAID (CONFIRMED)', 'paycow-crypto-gateway' ) : ( ! empty( $status ) ? esc_html( $status ) : __( 'AWAITING PAYMENT', 'paycow-crypto-gateway' ) );

        $explorer_url = '';
        if ( ! empty( $tx_hash ) ) {
            $explorer_url = 'TRC20' === strtoupper( $network )
                ? 'https://tronscan.org/#/transaction/' . rawurlencode( $tx_hash )
                : 'https://etherscan.io/tx/' . rawurlencode( $tx_hash );
        }

        // Localize script for secure background AJAX polling
        wp_enqueue_script(
            'paycow-thankyou-script',
            PAYCOW_PLUGIN_URL . 'assets/js/paycow-thankyou.js',
            array(),
            PAYCOW_VERSION,
            true
        );

        wp_localize_script(
            'paycow-thankyou-script',
            'paycowThankYouParams',
            array(
                'orderId'  => $order->get_id(),
                'orderKey' => $order->get_order_key(),
                'nonce'    => wp_create_nonce( 'paycow_order_status_' . $order->get_id() ),
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'isPaid'   => $is_paid,
            )
        );

        ?>
        <div class="paycow-order-card">
            <div class="paycow-order-card-header">
                <div class="paycow-order-title"><?php esc_html_e( 'PayCow Crypto Payment Details', 'paycow-crypto-gateway' ); ?></div>
                <span id="paycow-live-status-badge" class="paycow-status-badge <?php echo $is_paid ? 'paycow-status-paid' : 'paycow-status-pending'; ?>">
                    <?php echo esc_html( $status_label ); ?>
                </span>
            </div>
            <div class="paycow-order-grid">
                <?php if ( ! empty( $payable_amount ) ) : ?>
                    <div class="paycow-order-item">
                        <span class="paycow-item-label"><?php esc_html_e( 'Payable Amount', 'paycow-crypto-gateway' ); ?></span>
                        <span class="paycow-item-value"><?php echo esc_html( $payable_amount ); ?> USDT</span>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $network ) ) : ?>
                    <div class="paycow-order-item">
                        <span class="paycow-item-label"><?php esc_html_e( 'Network', 'paycow-crypto-gateway' ); ?></span>
                        <span class="paycow-item-value"><?php echo esc_html( $network ); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $deposit_address ) ) : ?>
                    <div class="paycow-order-item">
                        <span class="paycow-item-label"><?php esc_html_e( 'Settlement Address', 'paycow-crypto-gateway' ); ?></span>
                        <span class="paycow-item-value"><?php echo esc_html( $deposit_address ); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $reference ) ) : ?>
                    <div class="paycow-order-item">
                        <span class="paycow-item-label"><?php esc_html_e( 'Payment Reference', 'paycow-crypto-gateway' ); ?></span>
                        <span class="paycow-item-value"><?php echo esc_html( $reference ); ?></span>
                    </div>
                <?php endif; ?>

                <div id="paycow-txhash-container" class="paycow-order-item" style="<?php echo empty( $tx_hash ) ? 'display:none;' : ''; ?>">
                    <span class="paycow-item-label"><?php esc_html_e( 'Blockchain TxHash', 'paycow-crypto-gateway' ); ?></span>
                    <span id="paycow-txhash-value" class="paycow-item-value">
                        <?php if ( ! empty( $explorer_url ) ) : ?>
                            <a href="<?php echo esc_url( $explorer_url ); ?>" target="_blank" rel="noopener noreferrer" style="color:#0284c7; font-weight:600;">
                                <?php echo esc_html( substr( $tx_hash, 0, 16 ) . '...' ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $tx_hash ); ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <?php if ( ! $is_paid ) : ?>
                <div class="paycow-live-poll" id="paycow-live-poll-indicator">
                    <div class="paycow-pulse-dot"></div>
                    <span><?php esc_html_e( 'Monitoring blockchain confirmations in real time. Order will auto-complete once confirmed.', 'paycow-crypto-gateway' ); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Log messages if debug logging is enabled.
     *
     * @param string $message Message to log.
     */
    public function log( $message ) {
        if ( 'yes' === $this->debug_log ) {
            $logger = wc_get_logger();
            $logger->info( $message, array( 'source' => 'paycow-gateway' ) );
        }
    }
}
