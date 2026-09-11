<?php

namespace PayCow\Payments;

/**
 * Immutable model representing payment status details returned by GET /api/payment/status/:id.
 */
class PaymentStatus
{
    protected string $sessionId;
    protected string $userId;
    protected string $amountRequested;
    protected string $payableAmount;
    protected string $amountReceived;
    protected string $currency;
    protected string $depositAddress;
    protected string $depositNetwork;
    protected string $status;
    protected int $confirmations;
    protected string $paymentReference;
    protected ?string $txHash;
    protected string $expiresAt;
    protected ?string $createdAt;
    protected array $raw;

    /**
     * Constructor.
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->sessionId = (string) ($data['sessionId'] ?? '');
        $this->userId = (string) ($data['userId'] ?? '');
        // Keep string values for safe decimal comparisons
        $this->amountRequested = isset($data['amountRequested']) ? (string) $data['amountRequested'] : '0';
        $this->payableAmount = isset($data['payableAmount']) ? (string) $data['payableAmount'] : $this->amountRequested;
        $this->amountReceived = isset($data['amountReceived']) ? (string) $data['amountReceived'] : '0';
        $this->currency = (string) ($data['currencyInfo'] ?? $data['currency'] ?? 'USDT');
        $this->depositAddress = (string) ($data['depositAddress'] ?? '');
        $this->depositNetwork = strtoupper((string) ($data['depositNetwork'] ?? ''));
        $this->status = strtoupper((string) ($data['status'] ?? 'PENDING'));
        $this->confirmations = (int) ($data['confirmations'] ?? 0);
        $this->paymentReference = (string) ($data['paymentReference'] ?? '');
        $this->txHash = !empty($data['txHash']) ? (string) $data['txHash'] : null;
        $this->expiresAt = (string) ($data['expiresAt'] ?? '');
        $this->createdAt = isset($data['createdAt']) ? (string) $data['createdAt'] : null;
        $this->raw = $data;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getAmountRequested(): string
    {
        return $this->amountRequested;
    }

    public function getPayableAmount(): string
    {
        return $this->payableAmount;
    }

    public function getAmountReceived(): string
    {
        return $this->amountReceived;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDepositAddress(): string
    {
        return $this->depositAddress;
    }

    public function getDepositNetwork(): string
    {
        return $this->depositNetwork;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPaid(): bool
    {
        return $this->status === 'PAID';
    }

    public function isExpired(): bool
    {
        return $this->status === 'EXPIRED';
    }

    public function isConfirming(): bool
    {
        return $this->status === 'CONFIRMING' || $this->status === 'DETECTED';
    }

    public function getConfirmations(): int
    {
        return $this->confirmations;
    }

    public function getPaymentReference(): string
    {
        return $this->paymentReference;
    }

    public function getTransactionHash(): ?string
    {
        return $this->txHash;
    }

    public function getExpiresAt(): string
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
