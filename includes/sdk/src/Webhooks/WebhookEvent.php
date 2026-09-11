<?php

namespace PayCow\Webhooks;

/**
 * Normalized model representing a verified PayCow webhook event.
 */
class WebhookEvent
{
    protected string $id;
    protected string $type;
    protected ?string $apiVersion;
    protected ?string $createdAt;
    protected array $data;
    protected array $raw;

    /**
     * Constructor.
     *
     * @param array $payload
     */
    public function __construct(array $payload)
    {
        $this->id = (string) ($payload['id'] ?? '');
        $this->type = (string) ($payload['type'] ?? '');
        $this->apiVersion = isset($payload['apiVersion']) ? (string) $payload['apiVersion'] : null;
        $this->createdAt = isset($payload['createdAt']) ? (string) $payload['createdAt'] : null;
        $this->data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $this->raw = $payload;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isTest(): bool
    {
        return $this->type === 'webhook.test';
    }

    public function isPaid(): bool
    {
        return $this->type === 'payment.paid';
    }

    public function isExpired(): bool
    {
        return $this->type === 'payment.expired';
    }

    public function isConfirming(): bool
    {
        return $this->type === 'payment.confirming' || $this->type === 'payment.detected';
    }

    public function getApiVersion(): ?string
    {
        return $this->apiVersion;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getSessionId(): ?string
    {
        return isset($this->data['sessionId']) ? (string) $this->data['sessionId'] : null;
    }

    public function getPaymentReference(): ?string
    {
        return isset($this->data['paymentReference']) ? (string) $this->data['paymentReference'] : null;
    }

    public function getUserId(): ?string
    {
        return isset($this->data['userId']) ? (string) $this->data['userId'] : null;
    }

    public function getStatus(): ?string
    {
        return isset($this->data['status']) ? strtoupper((string) $this->data['status']) : null;
    }

    /**
     * Get requested base amount (as decimal string to prevent float precision loss).
     *
     * @return string|null
     */
    public function getAmountRequested(): ?string
    {
        return isset($this->data['amountRequested']) ? (string) $this->data['amountRequested'] : null;
    }

    /**
     * Get payable amount with micro-delta (as decimal string).
     *
     * @return string|null
     */
    public function getPayableAmount(): ?string
    {
        if (isset($this->data['payableAmount'])) {
            return (string) $this->data['payableAmount'];
        }
        return $this->getAmountRequested();
    }

    /**
     * Get amount received on-chain (as decimal string).
     *
     * @return string|null
     */
    public function getAmountReceived(): ?string
    {
        return isset($this->data['amountReceived']) ? (string) $this->data['amountReceived'] : null;
    }

    public function getCurrency(): ?string
    {
        return isset($this->data['currency']) ? strtoupper((string) $this->data['currency']) : 'USDT';
    }

    public function getNetwork(): ?string
    {
        return isset($this->data['network']) ? strtoupper((string) $this->data['network']) : null;
    }

    public function getDepositAddress(): ?string
    {
        return isset($this->data['depositAddress']) ? (string) $this->data['depositAddress'] : null;
    }

    /**
     * Get on-chain blockchain transaction hash.
     *
     * @return string|null
     */
    public function getTransactionHash(): ?string
    {
        return !empty($this->data['txHash']) ? (string) $this->data['txHash'] : null;
    }

    public function getConfirmations(): int
    {
        return isset($this->data['confirmations']) ? (int) $this->data['confirmations'] : 0;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
