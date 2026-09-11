<?php

namespace PayCow\Payments;

/**
 * Immutable model representing a created PayCow payment session.
 */
class PaymentSession
{
    protected string $sessionId;
    protected string $paymentUrl;
    protected string $paymentReference;
    protected string $amountRequested;
    protected string $payableAmount;
    protected string $depositAddress;
    protected ?string $addressSerialNo;
    protected ?string $walletLabel;
    protected string $expiresAt;
    protected bool $isIdempotentReplay;
    protected array $raw;

    /**
     * Constructor.
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->sessionId = (string) ($data['sessionId'] ?? '');
        $this->paymentUrl = (string) ($data['paymentUrl'] ?? '');
        $this->paymentReference = (string) ($data['paymentReference'] ?? '');
        // Keep string representation for precision-safe money handling
        $this->amountRequested = isset($data['amountRequested']) ? (string) $data['amountRequested'] : '0';
        $this->payableAmount = isset($data['payableAmount']) ? (string) $data['payableAmount'] : (isset($data['amount']) ? (string) $data['amount'] : $this->amountRequested);
        $this->depositAddress = (string) ($data['depositAddress'] ?? '');
        $this->addressSerialNo = isset($data['addressSerialNo']) ? (string) $data['addressSerialNo'] : null;
        $this->walletLabel = isset($data['walletLabel']) ? (string) $data['walletLabel'] : null;
        $this->expiresAt = (string) ($data['expiresAt'] ?? '');
        $this->isIdempotentReplay = !empty($data['isIdempotentReplay']);
        $this->raw = $data;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getPaymentUrl(): string
    {
        return $this->paymentUrl;
    }

    public function getPaymentReference(): string
    {
        return $this->paymentReference;
    }

    public function getAmountRequested(): string
    {
        return $this->amountRequested;
    }

    public function getPayableAmount(): string
    {
        return $this->payableAmount;
    }

    public function getDepositAddress(): string
    {
        return $this->depositAddress;
    }

    public function getAddressSerialNo(): ?string
    {
        return $this->addressSerialNo;
    }

    public function getWalletLabel(): ?string
    {
        return $this->walletLabel;
    }

    public function getExpiresAt(): string
    {
        return $this->expiresAt;
    }

    public function isIdempotentReplay(): bool
    {
        return $this->isIdempotentReplay;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
