<?php

namespace PayCow\Payments;

use PayCow\Config;
use PayCow\Exceptions\ApiException;
use PayCow\Exceptions\PayCowException;
use PayCow\Http\HttpClientInterface;

/**
 * Service managing PayCow payment operations.
 */
class PaymentService
{
    protected Config $config;
    protected HttpClientInterface $httpClient;

    /**
     * Constructor.
     *
     * @param Config $config
     * @param HttpClientInterface $httpClient
     */
    public function __construct(Config $config, HttpClientInterface $httpClient)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    /**
     * Create a non-custodial payment session.
     *
     * @param array $params Required: 'amount', 'userId', 'network'.
     *                      Optional: 'currency', 'addressSerialNo', 'idempotencyKey'.
     * @return PaymentSession
     * @throws PayCowException
     * @throws ApiException
     */
    public function create(array $params): PaymentSession
    {
        if (empty($params['userId'])) {
            throw new PayCowException('Missing required parameter: userId');
        }

        $amount = trim((string) ($params['amount'] ?? ''));
        if ($amount === '' || !is_numeric($amount) || self::compareDecimalStrings($amount, '0') <= 0) {
            throw new PayCowException('Invalid payment amount. Must be a positive numeric decimal value.');
        }

        if (empty($params['network'])) {
            throw new PayCowException('Missing required parameter: network (ERC20 or TRC20)');
        }

        $network = strtoupper(trim((string) $params['network']));
        if ($network !== 'ERC20' && $network !== 'TRC20') {
            throw new PayCowException('Invalid network. PayCow supports ERC20 and TRC20.');
        }

        $body = [
            'userId' => (string) $params['userId'],
            'amount' => $amount,
            'network' => $network,
            'currency' => !empty($params['currency']) ? (string) $params['currency'] : 'USDT',
        ];

        if (!empty($params['addressSerialNo'])) {
            $body['addressSerialNo'] = trim((string) $params['addressSerialNo']);
        } elseif (!empty($params['walletSerial'])) {
            $body['addressSerialNo'] = trim((string) $params['walletSerial']);
        }

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->config->getApiKey(),
            'x-api-secret' => $this->config->getApiSecret(),
            'User-Agent' => $this->config->getUserAgent(),
        ];

        $idempotencyKey = $params['idempotencyKey'] ?? $params['idempotency_key'] ?? null;
        if (!empty($idempotencyKey)) {
            $headers['Idempotency-Key'] = trim((string) $idempotencyKey);
            $body['idempotencyKey'] = trim((string) $idempotencyKey);
        }

        $url = $this->config->getApiBaseUrl() . '/api/payment/create';
        $response = $this->httpClient->request('POST', $url, $headers, json_encode($body), $this->config->getTimeout());

        $data = $response->json();

        if (empty($data['sessionId']) || empty($data['paymentUrl'])) {
            throw new ApiException('Malformed payment response: missing sessionId or paymentUrl', $response->getStatusCode(), $response->getBody());
        }

        return new PaymentSession($data);
    }

    /**
     * Retrieve payment status from PayCow.
     *
     * @param string $sessionId PayCow payment session ID.
     * @return PaymentStatus
     * @throws PayCowException
     * @throws ApiException
     */
    public function getStatus(string $sessionId): PaymentStatus
    {
        $cleanId = trim($sessionId);
        if (empty($cleanId)) {
            throw new PayCowException('Payment sessionId cannot be empty.');
        }

        $url = $this->config->getApiBaseUrl() . '/api/payment/status/' . rawurlencode($cleanId);

        $headers = [
            'x-api-key' => $this->config->getApiKey(),
            'x-api-secret' => $this->config->getApiSecret(),
            'User-Agent' => $this->config->getUserAgent(),
        ];

        $response = $this->httpClient->request('GET', $url, $headers, null, $this->config->getTimeout());
        $data = $response->json();

        return new PaymentStatus($data);
    }

    /**
     * Compare two arbitrary precision decimal strings without floating point conversion.
     *
     * @param string $a
     * @param string $b
     * @return int -1 if $a < $b, 0 if $a == $b, 1 if $a > $b
     */
    public static function compareDecimalStrings(string $a, string $b): int
    {
        $a = trim($a);
        $b = trim($b);

        if ($a === $b) {
            return 0;
        }

        // Split into integer and fractional parts
        $partsA = explode('.', $a, 2);
        $partsB = explode('.', $b, 2);

        $intA = ltrim($partsA[0], '0');
        $intB = ltrim($partsB[0], '0');
        if ($intA === '') $intA = '0';
        if ($intB === '') $intB = '0';

        $lenA = strlen($intA);
        $lenB = strlen($intB);
        if ($lenA > $lenB) return 1;
        if ($lenA < $lenB) return -1;

        $cmpInt = strcmp($intA, $intB);
        if ($cmpInt !== 0) {
            return $cmpInt > 0 ? 1 : -1;
        }

        $fracA = $partsA[1] ?? '';
        $fracB = $partsB[1] ?? '';
        $maxFracLen = max(strlen($fracA), strlen($fracB));
        $fracA = str_pad($fracA, $maxFracLen, '0', STR_PAD_RIGHT);
        $fracB = str_pad($fracB, $maxFracLen, '0', STR_PAD_RIGHT);

        $cmpFrac = strcmp($fracA, $fracB);
        if ($cmpFrac === 0) {
            return 0;
        }
        return $cmpFrac > 0 ? 1 : -1;
    }
}
