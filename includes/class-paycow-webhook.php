<?php
/**
 * PayCow Webhook Handler
 *
 * Listens for incoming on-chain state change webhooks, verifies HMAC-SHA256
 * signatures, enforces replay attack protection, and updates WooCommerce orders
 * idempotently using standard WooCommerce CRUD APIs.
 *
 * @package PayCow_Crypto_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayCow_Webhook_Handler
 */
class PayCow_Webhook_Handler {

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

        // Register WooCommerce API webhook listener (?wc-api=paycow_webhook)
        add_action( 'woocommerce_api_paycow_webhook', array( $this, 'process_incoming_webhook' ) );
    }

    /**
     * Process incoming PayCow webhook request.
     */
    public function process_incoming_webhook() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            $this->send_response( 405, array( 'error' => 'Method Not Allowed' ) );
        }

        $raw_body = file_get_contents( 'php://input' );
        if ( empty( $raw_body ) ) {
            $this->log( 'Webhook rejected: Empty request body.' );
            $this->send_response( 400, array( 'error' => 'Empty payload' ) );
        }

        $signature = isset( $_SERVER['HTTP_PAYCOW_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PAYCOW_SIGNATURE'] ) ) : '';
        $timestamp = isset( $_SERVER['HTTP_PAYCOW_TIMESTAMP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PAYCOW_TIMESTAMP'] ) ) : '';
        $event_id  = isset( $_SERVER['HTTP_PAYCOW_EVENT_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PAYCOW_EVENT_ID'] ) ) : '';

        if ( empty( $signature ) || empty( $timestamp ) ) {
            $this->log( 'Webhook rejected: Missing PayCow-Signature or PayCow-Timestamp header.' );
            $this->send_response( 400, array( 'error' => 'Missing signature headers' ) );
        }

        $webhook_secret = $this->gateway->get_option( 'webhook_secret' );
        if ( empty( $webhook_secret ) ) {
            $this->log( 'Webhook error: Webhook signing secret not configured in WooCommerce settings.' );
            $this->send_response( 500, array( 'error' => 'Webhook secret unconfigured on store' ) );
        }

        // 1. Verify HMAC-SHA256 signature and replay tolerance via embedded PayCow SDK WebhookVerifier
        $headers = $_SERVER;
        if ( function_exists( 'getallheaders' ) ) {
            $all_headers = getallheaders();
            if ( is_array( $all_headers ) ) {
                $headers = array_merge( $headers, $all_headers );
            }
        }

        try {
            $verifier = new \PayCow\Webhooks\WebhookVerifier();
            $event    = $verifier->verify( $raw_body, $headers, $webhook_secret );
        } catch ( \PayCow\Exceptions\WebhookVerificationException $e ) {
            $this->log( 'Webhook rejected: ' . $e->getMessage() );
            $this->send_response( 401, array( 'error' => $e->getMessage() ) );
        } catch ( \Exception $e ) {
            $this->log( 'Webhook verification error: ' . $e->getMessage() );
            $this->send_response( 500, array( 'error' => 'Internal webhook verification error' ) );
        }

        $event_type = sanitize_text_field( $event->getType() );
        $event_id   = sanitize_text_field( $event->getId() );
        if ( empty( $event_id ) && ! empty( $_SERVER['HTTP_PAYCOW_EVENT_ID'] ) ) {
            $event_id = sanitize_text_field( wp_unslash( $_SERVER['HTTP_PAYCOW_EVENT_ID'] ) );
        }
        $data = $event->getData();
        $this->log( sprintf( 'Webhook verified: %s (Event ID: %s)', $event_type, $event_id ) );

        // 2. Handle webhook.test event (Test ping from PayCow dashboard)
        if ( $event->isTest() ) {
            $this->log( 'Handled webhook.test event successfully.' );
            $this->send_response( 200, array(
                'status'  => 'ok',
                'message' => 'PayCow test webhook verified and processed successfully',
            ) );
        }

        // 3. Extract data and lookup WooCommerce order
        $session_id = $event->getSessionId();
        if ( empty( $session_id ) ) {
            $this->log( 'Webhook rejected: Missing data.sessionId in payload.' );
            $this->send_response( 400, array( 'error' => 'Missing sessionId in payload' ) );
        }

        $user_id = $event->getUserId() ?? '';

        $order = $this->find_order( $user_id, $session_id );
        if ( ! $order ) {
            $this->log( sprintf( 'Webhook error: Order not found for session %s (userId: %s).', $session_id, $user_id ) );
            $this->send_response( 404, array( 'error' => 'Order not found' ) );
        }

        // 4. Verify Session Match against Order Meta
        $stored_session_id = trim( (string) $order->get_meta( '_paycow_session_id', true ) );
        if ( ! empty( $stored_session_id ) && $stored_session_id !== $session_id ) {
            $this->log( sprintf( 'Security Alert: Webhook session %s does not match stored order session %s.', $session_id, $stored_session_id ) );
            $this->send_response( 400, array( 'error' => 'Session mismatch for order' ) );
        }

        // 5. Verify paymentReference against Order Meta
        $stored_reference  = trim( (string) $order->get_meta( '_paycow_payment_reference', true ) );
        $webhook_reference = isset( $data['paymentReference'] ) ? trim( sanitize_text_field( $data['paymentReference'] ) ) : '';
        if ( ! empty( $stored_reference ) && ! empty( $webhook_reference ) && $stored_reference !== $webhook_reference ) {
            $this->log( sprintf( 'Security Alert: Webhook payment reference "%s" does not match stored order reference "%s".', $webhook_reference, $stored_reference ) );
            $this->send_response( 400, array( 'error' => 'Payment reference mismatch for order' ) );
        }

        // 6. Verify network against Order Meta
        $stored_network  = strtoupper( trim( (string) $order->get_meta( '_paycow_network', true ) ) );
        $webhook_network = isset( $data['network'] ) ? strtoupper( trim( sanitize_text_field( $data['network'] ) ) ) : '';
        if ( ! empty( $stored_network ) && ! empty( $webhook_network ) && $stored_network !== $webhook_network ) {
            $this->log( sprintf( 'Security Alert: Webhook network "%s" does not match stored order network "%s".', $webhook_network, $stored_network ) );
            $this->send_response( 400, array( 'error' => 'Network mismatch for order' ) );
        }

        // 7. Webhook Idempotency Check: Don't re-process duplicate event IDs
        $processed_events = (array) $order->get_meta( '_paycow_processed_events', true );
        if ( ! empty( $event_id ) && in_array( $event_id, $processed_events, true ) ) {
            $this->log( sprintf( 'Webhook event %s already processed for order #%d. Skipping duplicate.', $event_id, $order->get_id() ) );
            $this->send_response( 200, array( 'status' => 'duplicate_ignored' ) );
        }

        // 8. Route based on verified event type
        switch ( $event_type ) {
            case 'payment.paid':
                $this->handle_payment_paid( $order, $data, $event_id, $processed_events );
                break;

            case 'payment.confirming':
            case 'payment.detected':
                $this->handle_payment_progress( $order, $data, $event_type, $event_id, $processed_events );
                break;

            case 'payment.expired':
                $this->handle_payment_expired( $order, $data, $event_id, $processed_events );
                break;

            case 'payment.rejected':
                $this->handle_payment_rejected( $order, $data, $event_id, $processed_events );
                break;

            default:
                $this->log( sprintf( 'Ignored unhandled event type: %s', $event_type ) );
                break;
        }

        $this->send_response( 200, array(
            'status'   => 'success',
            'eventId'  => $event_id,
            'orderId'  => $order->get_id(),
        ) );
    }

    /**
     * Handle payment.paid event.
     *
     * @param WC_Order $order            Order object.
     * @param array    $data             Payload data.
     * @param string   $event_id         Event ID.
     * @param array    $processed_events List of previously processed events.
     */
    protected function handle_payment_paid( $order, $data, $event_id, $processed_events ) {
        $amount_received  = isset( $data['amountReceived'] ) ? trim( (string) $data['amountReceived'] ) : '0';
        $payable_amount   = isset( $data['payableAmount'] ) ? trim( (string) $data['payableAmount'] ) : ( isset( $data['amountRequested'] ) ? trim( (string) $data['amountRequested'] ) : '0' );
        $amount_requested = isset( $data['amountRequested'] ) ? trim( (string) $data['amountRequested'] ) : '0';
        $tx_hash          = ! empty( $data['txHash'] ) ? sanitize_text_field( $data['txHash'] ) : '';
        $network          = ! empty( $data['network'] ) ? sanitize_text_field( $data['network'] ) : 'TRC20';
        $deposit_address  = ! empty( $data['depositAddress'] ) ? sanitize_text_field( $data['depositAddress'] ) : '';
        $confirmations    = isset( $data['confirmations'] ) ? intval( $data['confirmations'] ) : 0;
        $currency         = ! empty( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : 'USDT';

        // Security check: Verify Currency
        if ( 'USDT' !== strtoupper( $currency ) ) {
            $order->add_order_note( sprintf(
                __( 'PayCow Alert: Unexpected payment currency received: %s. Expected: USDT.', 'paycow-crypto-gateway' ),
                esc_html( $currency )
            ) );
            $order->update_status( 'on-hold' );
            $this->record_processed_event( $order, $event_id, $processed_events );
            return;
        }

        // Strict Amount verification: Require amountReceived >= payableAmount using SDK decimal comparator
        // Note: PayCow uses payableAmount (with allocated micro-delta) as target
        $target_required = ( \PayCow\Payments\PaymentService::compareDecimalStrings( $payable_amount, '0' ) > 0 ) ? $payable_amount : $amount_requested;

        if ( \PayCow\Payments\PaymentService::compareDecimalStrings( $amount_received, $target_required ) < 0 ) {
            $order->add_order_note( sprintf(
                __( 'PayCow Warning: Underpayment detected! Customer paid %1$s USDT, but required amount was %2$s USDT. Order placed on hold. Transaction: %3$s.', 'paycow-crypto-gateway' ),
                esc_html( $amount_received ),
                esc_html( $target_required ),
                esc_html( $tx_hash )
            ) );
            $order->update_status( 'on-hold' );
            $this->record_processed_event( $order, $event_id, $processed_events );
            return;
        }

        // Idempotency: If order is already paid, do not trigger redundant processing
        if ( $order->is_paid() ) {
            $this->log( sprintf( 'Order #%d is already marked as paid. Ignoring duplicate payment.paid.', $order->get_id() ) );
            $this->record_processed_event( $order, $event_id, $processed_events );
            return;
        }

        // Check if customer overpaid
        if ( \PayCow\Payments\PaymentService::compareDecimalStrings( $amount_received, $target_required ) > 0 ) {
            $order->add_order_note( sprintf(
                __( 'PayCow Notice: Overpayment received! Customer paid %1$s USDT (required was %2$s USDT). Surplus settled directly into merchant wallet.', 'paycow-crypto-gateway' ),
                esc_html( $amount_received ),
                esc_html( $target_required )
            ) );
        }

        // Record on-chain metadata via WooCommerce CRUD
        $order->set_transaction_id( $tx_hash );
        $order->update_meta_data( '_paycow_status', 'PAID' );
        $order->update_meta_data( '_paycow_amount_received', $amount_received );
        $order->update_meta_data( '_paycow_payable_amount', $payable_amount );
        $order->update_meta_data( '_paycow_network', $network );
        $order->update_meta_data( '_paycow_deposit_address', $deposit_address );
        $order->update_meta_data( '_paycow_tx_hash', $tx_hash );
        $order->update_meta_data( '_paycow_confirmations', $confirmations );

        // Add detailed payment receipt note
        $note = sprintf(
            __( 'PayCow payment confirmed on blockchain.%1$sStatus: PAID%1$sAmount Received: %2$s USDT%1$sNetwork: %3$s%1$sDeposit Address: %4$s%1$sTransaction Hash: %5$s%1$sConfirmations: %6$d', 'paycow-crypto-gateway' ),
            PHP_EOL,
            esc_html( $amount_received ),
            esc_html( $network ),
            esc_html( $deposit_address ),
            esc_html( $tx_hash ),
            $confirmations
        );
        $order->add_order_note( $note );

        // Mark payment complete using standard WooCommerce mechanism
        $order->payment_complete( $tx_hash );

        $this->record_processed_event( $order, $event_id, $processed_events );
        $this->log( sprintf( 'Order #%d successfully marked as completed/paid via PayCow.', $order->get_id() ) );
    }

    /**
     * Handle payment.detected and payment.confirming events.
     *
     * @param WC_Order $order            Order object.
     * @param array    $data             Payload data.
     * @param string   $event_type       Event type.
     * @param string   $event_id         Event ID.
     * @param array    $processed_events List of previously processed events.
     */
    protected function handle_payment_progress( $order, $data, $event_type, $event_id, $processed_events ) {
        if ( $order->is_paid() ) {
            $this->record_processed_event( $order, $event_id, $processed_events );
            return;
        }

        $confirmations = isset( $data['confirmations'] ) ? intval( $data['confirmations'] ) : 0;
        $tx_hash       = ! empty( $data['txHash'] ) ? sanitize_text_field( $data['txHash'] ) : '';
        $network       = ! empty( $data['network'] ) ? sanitize_text_field( $data['network'] ) : '';

        $order->update_meta_data( '_paycow_status', strtoupper( str_replace( 'payment.', '', $event_type ) ) );
        if ( ! empty( $tx_hash ) ) {
            $order->update_meta_data( '_paycow_tx_hash', $tx_hash );
        }
        $order->update_meta_data( '_paycow_confirmations', $confirmations );

        if ( 'payment.detected' === $event_type ) {
            $order->add_order_note( sprintf(
                __( 'PayCow: Deposit detected in blockchain mempool. TxHash: %s. Awaiting block confirmations.', 'paycow-crypto-gateway' ),
                esc_html( $tx_hash )
            ) );
        } else {
            $order->add_order_note( sprintf(
                __( 'PayCow: Deposit confirming on %1$s blockchain. Current confirmations: %2$d.', 'paycow-crypto-gateway' ),
                esc_html( $network ),
                $confirmations
            ) );
        }

        $this->record_processed_event( $order, $event_id, $processed_events );
    }

    /**
     * Handle payment.expired event.
     *
     * @param WC_Order $order            Order object.
     * @param array    $data             Payload data.
     * @param string   $event_id         Event ID.
     * @param array    $processed_events List of previously processed events.
     */
    protected function handle_payment_expired( $order, $data, $event_id, $processed_events ) {
        if ( ! $order->is_paid() && in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
            $order->update_meta_data( '_paycow_status', 'EXPIRED' );
            $order->update_status( 'cancelled', __( 'PayCow payment invoice expired after 30 minutes without on-chain deposit.', 'paycow-crypto-gateway' ) );
        }
        $this->record_processed_event( $order, $event_id, $processed_events );
    }

    /**
     * Handle payment.rejected event.
     *
     * @param WC_Order $order            Order object.
     * @param array    $data             Payload data.
     * @param string   $event_id         Event ID.
     * @param array    $processed_events List of previously processed events.
     */
    protected function handle_payment_rejected( $order, $data, $event_id, $processed_events ) {
        if ( ! $order->is_paid() && in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
            $order->update_meta_data( '_paycow_status', 'REJECTED' );
            $order->update_status( 'failed', __( 'PayCow payment was rejected or invalidated on blockchain.', 'paycow-crypto-gateway' ) );
        }
        $this->record_processed_event( $order, $event_id, $processed_events );
    }

    /**
     * Find order by userId or session ID.
     * Uses HPOS-compatible WooCommerce CRUD queries.
     *
     * @param string $user_id    userId sent to PayCow (e.g., '123' or 'wc_order_123').
     * @param string $session_id PayCow session ID.
     * @return WC_Order|false
     */
    protected function find_order( $user_id, $session_id ) {
        // 1. Try resolving order ID from userId string
        $clean_id = absint( preg_replace( '/^wc_order_/', '', $user_id ) );
        if ( $clean_id > 0 ) {
            $order = wc_get_order( $clean_id );
            if ( $order ) {
                return $order;
            }
        }

        // 2. Search by session ID meta using HPOS-compatible wc_get_orders
        if ( ! empty( $session_id ) ) {
            $orders = wc_get_orders( array(
                'limit'        => 1,
                'meta_key'     => '_paycow_session_id',
                'meta_value'   => sanitize_text_field( $session_id ),
                'meta_compare' => '=',
            ) );

            if ( ! empty( $orders ) && is_array( $orders ) ) {
                return $orders[0];
            }
        }

        return false;
    }

    /**
     * Record processed event ID to order to guarantee idempotency.
     *
     * @param WC_Order $order            Order object.
     * @param string   $event_id         PayCow event ID.
     * @param array    $processed_events List of events already processed.
     */
    protected function record_processed_event( $order, $event_id, $processed_events ) {
        if ( ! empty( $event_id ) && ! in_array( $event_id, $processed_events, true ) ) {
            $processed_events[] = $event_id;
            $order->update_meta_data( '_paycow_processed_events', $processed_events );
        }
        $order->save();
    }

    /**
     * Verify PayCow webhook signature using embedded SDK WebhookVerifier.
     *
     * @param string $raw_body       Raw HTTP request body.
     * @param string $timestamp      PayCow-Timestamp header value.
     * @param string $signature      PayCow-Signature header value (format: v1=<hex>).
     * @param string $webhook_secret Secret configured for merchant (format: whsec_...).
     * @return bool True if valid, false otherwise.
     */
    public function verify_signature( $raw_body, $timestamp, $signature, $webhook_secret ) {
        try {
            $verifier = new \PayCow\Webhooks\WebhookVerifier();
            $headers  = array(
                'PayCow-Signature' => $signature,
                'PayCow-Timestamp' => $timestamp,
            );
            $verifier->verify( $raw_body, $headers, $webhook_secret );
            return true;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    /**
     * Log messages if debug logging is enabled.
     *
     * @param string $message Message to log.
     */
    protected function log( $message ) {
        if ( 'yes' === $this->gateway->get_option( 'debug_log' ) ) {
            $logger = wc_get_logger();
            $logger->info( $message, array( 'source' => 'paycow-webhook' ) );
        }
    }

    /**
     * Send JSON response and terminate.
     *
     * @param int   $status_code HTTP status code.
     * @param array $data        Data to encode as JSON.
     */
    protected function send_response( $status_code, $data ) {
        status_header( $status_code );
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( $data );
        exit;
    }
}
