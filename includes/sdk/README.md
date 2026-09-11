# PayCow PHP SDK

Official, lightweight PHP SDK for the PayCow Non-Custodial USDT Payment Gateway.

- **Non-Custodial**: Operates with PayCow infrastructure where customer payments settle directly into your self-custody wallet addresses.
- **Dependency-Light**: Built with pure PHP using native cURL, JSON, and Hash extensions (no external framework required).
- **Security-First**: Enforces timing-safe HMAC-SHA256 signature verification and 300-second replay attack protection.
- **PHP Compatibility**: PHP 7.4, 8.0, 8.1, 8.2, 8.3.

---

## Installation

### Via Composer

```bash
composer require paycow/paycow-php-sdk
```

### Standalone (Without Composer)

Include the included `autoload.php` file:

```php
require_once __DIR__ . '/path/to/paycow-php-sdk/autoload.php';
```

---

## Quick Start

### 1. Initialize the Client

```php
use PayCow\PayCowClient;

$paycow = new PayCowClient([
    'api_key'    => 'YOUR_PUBLIC_API_KEY',
    'api_secret' => 'YOUR_PRIVATE_API_SECRET',
]);
```

### 2. Create a Payment Session

```php
$session = $paycow->payments()->create([
    'userId'         => 'order_10042',
    'amount'         => '49.99',
    'network'        => 'TRC20', // 'TRC20' or 'ERC20'
    'currency'       => 'USDT',
    'idempotencyKey' => 'idemp_order_10042',
]);

// Redirect the customer to PayCow's hosted payment interface:
$paymentUrl = $session->getPaymentUrl();
$sessionId  = $session->getSessionId();
$reference  = $session->getPaymentReference();
$payableAmt = $session->getPayableAmount(); // Micro-delta amount
```

### 3. Query Payment Status

```php
$status = $paycow->payments()->getStatus($sessionId);

if ($status->isPaid()) {
    $txHash = $status->getTransactionHash();
    $confirmations = $status->getConfirmations();
    // Fulfill customer order
}
```

### 4. Verify Incoming Webhooks

```php
use PayCow\Exceptions\WebhookVerificationException;

$rawBody = file_get_contents('php://input');
$headers = getallheaders(); // or $_SERVER
$webhookSecret = 'whsec_...';

try {
    $event = $paycow->webhooks()->verify($rawBody, $headers, $webhookSecret);

    if ($event->isTest()) {
        // Handshake test ping from dashboard
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($event->isPaid()) {
        $sessionId = $event->getSessionId();
        $userId = $event->getUserId();
        $txHash = $event->getTransactionHash();
        $amountReceived = $event->getAmountReceived();
        $payableAmount = $event->getPayableAmount();

        // Mark invoice or order paid
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }
} catch (WebhookVerificationException $e) {
    http_response_code(401);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
```

---

## License

MIT License. See LICENSE for details.
