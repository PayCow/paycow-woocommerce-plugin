<?php
/**
 * PayCow Secure AJAX Handler
 *
 * Handles client-side live status polling from the Thank You page securely.
 * Server-side PHP calls the PayCow REST API with merchant credentials, ensuring
 * that API Key and Secret are NEVER exposed to the frontend browser.
 *
 * @package PayCow_Crypto_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayCow_Ajax_Handler
 */
class PayCow_Ajax_Handler {

    /**
     * Gateway instance.
     *
     * @var WC_Gateway_PayCow
     */
    protected $gateway;

    /**
     * Constructor.
     *
     * @param WC_Gateway_PayCow $gateway Gateway instance.
     */
    public function __construct( $gateway ) {
        $this->gateway = $gateway;

        // Register AJAX actions for both logged-in and guest customers
        add_action( 'wp_ajax_paycow_check_order_status', array( $this, 'check_order_status' ) );
        add_action( 'wp_ajax_nopriv_paycow_check_order_status', array( $this, 'check_order_status' ) );
    }

    /**
     * Check order payment status securely.
     */
    public function check_order_status() {
        $order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

        if ( ! $order_id || empty( $order_key ) ) {
            wp_send_json_error( array( 'message' => 'Invalid order parameters.' ), 400 );
        }

        // Verify WordPress security nonce tied to this specific order
        check_ajax_referer( 'paycow_order_status_' . $order_id, 'nonce' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Order not found.' ), 404 );
        }

        // Validate order ownership / authorization via unique order key
        if ( $order->get_order_key() !== $order_key ) {
            wp_send_json_error( array( 'message' => 'Unauthorized order access.' ), 403 );
        }

        $network = $order->get_meta( '_paycow_network', true );
        $tx_hash = $order->get_transaction_id();

        // 1. If order is already completed or paid, respond immediately
        if ( $order->is_paid() ) {
            wp_send_json_success( array(
                'isPaid'        => true,
                'status'        => 'PAID',
                'statusText'    => __( 'PAID (CONFIRMED)', 'paycow-crypto-gateway' ),
                'txHash'        => $tx_hash,
                'explorerUrl'   => $this->get_explorer_url( $network, $tx_hash ),
                'confirmations' => intval( $order->get_meta( '_paycow_confirmations', true ) ),
            ) );
        }

        $session_id = $order->get_meta( '_paycow_session_id', true );
        if ( empty( $session_id ) ) {
            wp_send_json_success( array(
                'isPaid'     => false,
                'status'     => 'PENDING',
                'statusText' => __( 'AWAITING PAYMENT', 'paycow-crypto-gateway' ),
            ) );
        }

        // 2. Perform server-side PayCow API status query (credentials kept strictly on server)
        $api_key    = $this->gateway->get_option( 'api_key' );
        $api_secret = $this->gateway->get_option( 'api_secret' );

        if ( empty( $api_key ) || empty( $api_secret ) ) {
            wp_send_json_error( array( 'message' => 'Gateway credentials missing.' ), 500 );
        }

        $status_data = PayCow_API::get_payment_status( $session_id, $api_key, $api_secret );

        if ( is_wp_error( $status_data ) ) {
            // Return current order state gracefully if API query temporary network failure
            wp_send_json_success( array(
                'isPaid'     => false,
                'status'     => 'PENDING',
                'statusText' => __( 'AWAITING PAYMENT', 'paycow-crypto-gateway' ),
            ) );
        }

        $remote_status = isset( $status_data['status'] ) ? strtoupper( trim( $status_data['status'] ) ) : 'PENDING';
        $confs         = isset( $status_data['confirmations'] ) ? intval( $status_data['confirmations'] ) : 0;
        $remote_tx     = ! empty( $status_data['txHash'] ) ? sanitize_text_field( $status_data['txHash'] ) : '';

        // 3. Reconcile order if PayCow confirmed settlement
        if ( 'PAID' === $remote_status && ! $order->is_paid() ) {
            $amount_received = isset( $status_data['amountReceived'] ) ? trim( (string) $status_data['amountReceived'] ) : '0';
            $deposit_address = ! empty( $status_data['depositAddress'] ) ? sanitize_text_field( $status_data['depositAddress'] ) : '';
            $deposit_net     = ! empty( $status_data['depositNetwork'] ) ? sanitize_text_field( $status_data['depositNetwork'] ) : $network;
            $payable_amount  = isset( $status_data['payableAmount'] ) ? trim( (string) $status_data['payableAmount'] ) : trim( (string) $order->get_meta( '_paycow_payable_amount', true ) );
            if ( empty( $payable_amount ) ) {
                $payable_amount = trim( (string) $order->get_total() );
            }
            $target_required = ( \PayCow\Payments\PaymentService::compareDecimalStrings( $payable_amount, '0' ) > 0 ) ? $payable_amount : trim( (string) $order->get_total() );

            // Strict currency check if returned by API
            if ( ! empty( $status_data['currency'] ) && 'USDT' !== strtoupper( trim( $status_data['currency'] ) ) ) {
                $order->update_status( 'on-hold', sprintf(
                    __( 'PayCow Alert: Unexpected currency %s in status check. Expected: USDT.', 'paycow-crypto-gateway' ),
                    esc_html( $status_data['currency'] )
                ) );
                wp_send_json_error( array( 'message' => 'Currency mismatch in payment status.' ), 400 );
            }

            // Strict underpayment check: Require amountReceived >= target_required
            if ( \PayCow\Payments\PaymentService::compareDecimalStrings( $amount_received, $target_required ) < 0 ) {
                $order->update_status( 'on-hold', sprintf(
                    __( 'PayCow Warning: Underpayment detected! Customer paid %1$s USDT, required: %2$s USDT. TxHash: %3$s', 'paycow-crypto-gateway' ),
                    esc_html( $amount_received ),
                    esc_html( $target_required ),
                    esc_html( $remote_tx )
                ) );
                wp_send_json_success( array(
                    'isPaid'        => false,
                    'status'        => 'ON-HOLD',
                    'statusText'    => __( 'UNDERPAYMENT DETECTED (ON-HOLD)', 'paycow-crypto-gateway' ),
                    'txHash'        => $remote_tx,
                    'explorerUrl'   => $this->get_explorer_url( $network, $remote_tx ),
                    'confirmations' => $confs,
                ) );
            }

            // Verify paymentReference if present in status response
            $stored_ref = trim( (string) $order->get_meta( '_paycow_payment_reference', true ) );
            $remote_ref = isset( $status_data['paymentReference'] ) ? trim( sanitize_text_field( $status_data['paymentReference'] ) ) : '';
            if ( ! empty( $stored_ref ) && ! empty( $remote_ref ) && $stored_ref !== $remote_ref ) {
                wp_send_json_error( array( 'message' => 'Payment reference mismatch.' ), 400 );
            }

            // Verify network if present in status response
            $stored_net = strtoupper( trim( (string) $order->get_meta( '_paycow_network', true ) ) );
            $remote_net = ! empty( $status_data['network'] ) ? strtoupper( trim( sanitize_text_field( $status_data['network'] ) ) ) : ( ! empty( $status_data['depositNetwork'] ) ? strtoupper( trim( sanitize_text_field( $status_data['depositNetwork'] ) ) ) : '' );
            if ( ! empty( $stored_net ) && ! empty( $remote_net ) && $stored_net !== $remote_net ) {
                wp_send_json_error( array( 'message' => 'Network mismatch.' ), 400 );
            }

            $order->set_transaction_id( $remote_tx );
            $order->update_meta_data( '_paycow_status', 'PAID' );
            $order->update_meta_data( '_paycow_amount_received', $amount_received );
            $order->update_meta_data( '_paycow_tx_hash', $remote_tx );
            $order->update_meta_data( '_paycow_confirmations', $confs );

            $note = sprintf(
                __( 'PayCow payment confirmed via live status reconciliation.%1$sStatus: PAID%1$sAmount: %2$s USDT%1$sNetwork: %3$s%1$sTxHash: %4$s%1$sConfirmations: %5$d', 'paycow-crypto-gateway' ),
                PHP_EOL,
                esc_html( $amount_received ),
                esc_html( $deposit_net ),
                esc_html( $remote_tx ),
                $confs
            );
            $order->add_order_note( $note );
            $order->payment_complete( $remote_tx );

            wp_send_json_success( array(
                'isPaid'        => true,
                'status'        => 'PAID',
                'statusText'    => __( 'PAID (CONFIRMED)', 'paycow-crypto-gateway' ),
                'txHash'        => $remote_tx,
                'explorerUrl'   => $this->get_explorer_url( $deposit_net, $remote_tx ),
                'confirmations' => $confs,
            ) );
        }

        // 4. Return progress state
        $status_label = __( 'AWAITING PAYMENT', 'paycow-crypto-gateway' );
        if ( 'CONFIRMING' === $remote_status ) {
            $status_label = sprintf( __( 'CONFIRMING (%d CONFIRMATIONS)', 'paycow-crypto-gateway' ), $confs );
        } elseif ( 'DETECTED' === $remote_status ) {
            $status_label = __( 'DETECTED IN MEMPOOL', 'paycow-crypto-gateway' );
        } elseif ( 'EXPIRED' === $remote_status ) {
            $status_label = __( 'INVOICE EXPIRED', 'paycow-crypto-gateway' );
        }

        wp_send_json_success( array(
            'isPaid'        => false,
            'status'        => $remote_status,
            'statusText'    => $status_label,
            'txHash'        => $remote_tx,
            'explorerUrl'   => $this->get_explorer_url( $network, $remote_tx ),
            'confirmations' => $confs,
        ) );
    }

    /**
     * Build block explorer URL for a given transaction hash and network.
     *
     * @param string $network Blockchain network (TRC20 or ERC20).
     * @param string $tx_hash Transaction hash.
     * @return string
     */
    protected function get_explorer_url( $network, $tx_hash ) {
        if ( empty( $tx_hash ) ) {
            return '';
        }

        $clean_net = strtoupper( trim( $network ) );
        if ( 'TRC20' === $clean_net ) {
            return 'https://tronscan.org/#/transaction/' . rawurlencode( $tx_hash );
        } else {
            return 'https://etherscan.io/tx/' . rawurlencode( $tx_hash );
        }
    }
}
