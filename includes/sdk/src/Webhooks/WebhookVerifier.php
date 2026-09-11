<?php

namespace PayCow\Webhooks;

use PayCow\Exceptions\WebhookVerificationException;

/**
 * Verifies authenticity and freshness of incoming PayCow webhooks.
 */
class WebhookVerifier
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify an incoming webhook and return a typed WebhookEvent.
     *
     * @param string $rawBody Unparsed raw HTTP request body (e.g. file_get_contents('php://input')).
     * @param array $headers Headers array (or $_SERVER array).
     * @param string $webhookSecret Secret string starting with 'whsec_'.
     * @param int $toleranceSeconds Replay protection tolerance in seconds (default 300).
     * @return WebhookEvent
     * @throws WebhookVerificationException If verification fails.
     */
    public function verify(string $rawBody, array $headers, string $webhookSecret, int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS): WebhookEvent
    {
        $cleanSecret = trim($webhookSecret);
        if (empty($cleanSecret)) {
            throw new WebhookVerificationException('Webhook signing secret cannot be empty.');
        }

        if (empty($rawBody)) {
            throw new WebhookVerificationException('Webhook payload body is empty.');
        }

        $signature = $this->getHeaderValue($headers, ['PayCow-Signature', 'HTTP_PAYCOW_SIGNATURE']);
        $timestamp = $this->getHeaderValue($headers, ['PayCow-Timestamp', 'HTTP_PAYCOW_TIMESTAMP']);

        if ($signature === null || $timestamp === null) {
            throw new WebhookVerificationException('Missing required PayCow webhook headers (PayCow-Signature or PayCow-Timestamp).');
        }

        // 1. Verify Replay Protection Time Window (300 seconds)
        $currentTime = time();
        $tsNumeric = (int) $timestamp;

        if ($tsNumeric <= 0 || abs($currentTime - $tsNumeric) > $toleranceSeconds) {
            throw new WebhookVerificationException(sprintf(
                'Webhook timestamp (%d) is outside the %d-second tolerance window (current server time: %d).',
                $tsNumeric,
                $toleranceSeconds,
                $currentTime
            ));
        }

        // 2. Verify Signature Format (Mandatory v1= prefix and 64-char hex)
        $cleanSig = trim($signature);
        if (substr($cleanSig, 0, 3) !== 'v1=') {
            throw new WebhookVerificationException('Invalid signature format: missing required v1= prefix.');
        }

        $receivedHash = substr($cleanSig, 3);
        if (strlen($receivedHash) !== 64 || !ctype_xdigit($receivedHash)) {
            throw new WebhookVerificationException('Invalid signature format: expected 64-character hexadecimal HMAC.');
        }

        // 3. Compute HMAC-SHA256 over timestamp.rawBody
        $signedPayload = "{$timestamp}.{$rawBody}";
        $expectedHash = hash_hmac('sha256', $signedPayload, $cleanSecret);

        // 4. Timing-safe comparison to prevent timing side-channel attacks
        if (!hash_equals($expectedHash, $receivedHash)) {
            throw new WebhookVerificationException('HMAC-SHA256 signature mismatch. Webhook rejected.');
        }

        // 5. Parse JSON payload
        $payload = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            throw new WebhookVerificationException('Malformed JSON payload: ' . json_last_error_msg());
        }

        if (empty($payload['type'])) {
            throw new WebhookVerificationException('Malformed webhook payload: missing event type.');
        }

        return new WebhookEvent($payload);
    }

    /**
     * Case-insensitive header lookup supporting both standard HTTP header keys and PHP $_SERVER format.
     *
     * @param array $headers
     * @param array $searchKeys
     * @return string|null
     */
    protected function getHeaderValue(array $headers, array $searchKeys): ?string
    {
        // First check direct matches
        foreach ($searchKeys as $key) {
            if (isset($headers[$key]) && is_string($headers[$key])) {
                return trim($headers[$key]);
            }
        }

        // Normalize array keys to lowercase and stripped of HTTP_
        $normalized = [];
        foreach ($headers as $k => $v) {
            if (!is_string($v)) continue;
            $cleanKey = strtolower(str_replace(['_', '-'], '', $k));
            if (substr($cleanKey, 0, 4) === 'http') {
                $cleanKey = substr($cleanKey, 4);
            }
            $normalized[$cleanKey] = trim((string) $v);
        }

        foreach ($searchKeys as $key) {
            $searchClean = strtolower(str_replace(['_', '-'], '', $key));
            if (substr($searchClean, 0, 4) === 'http') {
                $searchClean = substr($searchClean, 4);
            }
            if (isset($normalized[$searchClean])) {
                return $normalized[$searchClean];
            }
        }

        return null;
    }
}
