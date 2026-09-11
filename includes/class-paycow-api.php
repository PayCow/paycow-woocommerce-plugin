<?php
/**
 * PayCow REST API Client
 *
 * Communicates with PayCow payment infrastructure using WordPress HTTP API.
 *
 * @package PayCow_Crypto_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayCow_API
 */
class PayCow_API {

    /**
     * Get the configured PayCow API base URL.
     * Fixed to official production endpoint by default; overridable via filter for development.
     *
     * @return string
     */
    public static function get_api_base_url() {
        $default_url = 'https://paycow.net';
        $filtered_url = apply_filters( 'woocommerce_paycow_api_url', $default_url );
        return untrailingslashit( $filtered_url );
    }

    /**
     * Create a payment session on PayCow.
     *
     * @param array  $params     Payment parameters (amount, userId, network, currency, addressSerialNo, idempotencyKey).
     * @param string $api_key    Merchant API Key.
     * @param string $api_secret Merchant API Secret.
     * @return array|WP_Error   Response array or WP_Error on failure.
     */
    public static function create_payment( $params, $api_key, $api_secret ) {
        try {
            $client = new \PayCow\PayCowClient( array(
                'apiKey'    => trim( $api_key ),
                'apiSecret' => trim( $api_secret ),
                'apiUrl'    => self::get_api_base_url(),
            ) );

            $session = $client->payments()->create( $params );
            return $session->toArray();
        } catch ( \PayCow\Exceptions\PayCowException $e ) {
            return new WP_Error( 'paycow_api_error', $e->getMessage() );
        } catch ( \Exception $e ) {
            return new WP_Error( 'paycow_unexpected_error', $e->getMessage() );
        }
    }

    /**
     * Query live payment status from PayCow.
     *
     * @param string $session_id PayCow payment session ID.
     * @param string $api_key    Merchant API Key.
     * @param string $api_secret Merchant API Secret.
     * @return array|WP_Error    Status data or WP_Error.
     */
    public static function get_payment_status( $session_id, $api_key, $api_secret ) {
        if ( empty( $session_id ) || empty( $api_key ) || empty( $api_secret ) ) {
            return new WP_Error( 'missing_params', __( 'Session ID, API Key, and API Secret are required.', 'paycow-crypto-gateway' ) );
        }

        try {
            $client = new \PayCow\PayCowClient( array(
                'apiKey'    => trim( $api_key ),
                'apiSecret' => trim( $api_secret ),
                'apiUrl'    => self::get_api_base_url(),
            ) );

            $status = $client->payments()->getStatus( $session_id );
            return $status->toArray();
        } catch ( \PayCow\Exceptions\PayCowException $e ) {
            return new WP_Error( 'paycow_status_error', $e->getMessage() );
        } catch ( \Exception $e ) {
            return new WP_Error( 'paycow_unexpected_error', $e->getMessage() );
        }
    }
}
