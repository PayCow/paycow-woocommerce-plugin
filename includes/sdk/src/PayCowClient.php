<?php

namespace PayCow;

use PayCow\Http\CurlHttpClient;
use PayCow\Http\HttpClientInterface;
use PayCow\Payments\PaymentService;
use PayCow\Webhooks\WebhookVerifier;

/**
 * Main client entry point for the PayCow PHP SDK.
 */
class PayCowClient
{
    public const VERSION = '1.0.0';

    protected Config $config;
    protected HttpClientInterface $httpClient;
    protected PaymentService $paymentService;
    protected WebhookVerifier $webhookVerifier;

    /**
     * Constructor.
     *
     * @param array|Config $config Options array (with 'api_key', 'api_secret') or Config instance.
     * @param HttpClientInterface|null $httpClient Custom HTTP client or null for default cURL client.
     */
    public function __construct($config, ?HttpClientInterface $httpClient = null)
    {
        if ($config instanceof Config) {
            $this->config = $config;
        } else {
            $this->config = new Config((array) $config);
        }

        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->paymentService = new PaymentService($this->config, $this->httpClient);
        $this->webhookVerifier = new WebhookVerifier();
    }

    /**
     * Get the payment service.
     *
     * @return PaymentService
     */
    public function payments(): PaymentService
    {
        return $this->paymentService;
    }

    /**
     * Get the webhook verifier.
     *
     * @return WebhookVerifier
     */
    public function webhooks(): WebhookVerifier
    {
        return $this->webhookVerifier;
    }

    /**
     * Get SDK configuration.
     *
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }
}
