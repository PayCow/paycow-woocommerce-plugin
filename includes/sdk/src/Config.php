<?php

namespace PayCow;

use PayCow\Exceptions\PayCowException;

/**
 * PayCow SDK Configuration.
 */
class Config
{
    public const DEFAULT_API_BASE_URL = 'https://paycow.net';
    public const SDK_VERSION = '1.0.0';

    protected string $apiKey;
    protected string $apiSecret;
    protected ?string $webhookSecret;
    protected string $apiBaseUrl;
    protected int $timeout;
    protected string $userAgent;

    /**
     * Constructor.
     *
     * @param array $options Configuration options.
     * @throws PayCowException If required parameters are missing.
     */
    public function __construct(array $options = [])
    {
        $apiKey = $options['api_key'] ?? $options['apiKey'] ?? '';
        $apiSecret = $options['api_secret'] ?? $options['apiSecret'] ?? '';

        if (empty($apiKey) || empty($apiSecret)) {
            throw new PayCowException('PayCow API key and API secret are required.');
        }

        $this->apiKey = trim((string) $apiKey);
        $this->apiSecret = trim((string) $apiSecret);
        $this->webhookSecret = isset($options['webhook_secret']) || isset($options['webhookSecret'])
            ? trim((string) ($options['webhook_secret'] ?? $options['webhookSecret']))
            : null;

        $baseUrl = $options['api_base_url'] ?? $options['apiBaseUrl'] ?? self::DEFAULT_API_BASE_URL;
        $this->apiBaseUrl = rtrim((string) $baseUrl, '/');

        $this->timeout = isset($options['timeout']) ? (int) $options['timeout'] : 20;
        $this->userAgent = 'PayCow-PHP-SDK/' . self::SDK_VERSION;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }
}
