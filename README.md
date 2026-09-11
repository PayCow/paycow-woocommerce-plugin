# PayCow Non-Custodial Crypto Payment Gateway for WooCommerce

Accept direct, non-custodial **USDT (TRC-20 and ERC-20)** cryptocurrency payments on your WooCommerce store with **0% gateway commission**. Payments settle directly into your own on-chain wallets without intermediaries.

---

## Key Features

- **Non-Custodial**: Funds transfer directly to your self-custody wallet addresses. PayCow never holds your private keys, funds, or merchant balances.
- **0% Gateway Commission**: Zero cut taken from your cryptocurrency sales.
- **Dual Network Support**: Accept low-fee TRON (TRC-20) and Ethereum (ERC-20) USDT payments.
- **Modern WooCommerce Blocks Support**: Fully compatible with the modern WooCommerce Cart & Checkout Blocks (Gutenberg React checkout) as well as classic shortcode checkout.
- **High-Performance Order Storage (HPOS) Ready**: Fully compliant with WooCommerce custom order tables.
- **On-Chain Confirmation Verification**: Cryptographically secured HMAC-SHA256 webhooks with 300-second replay attack protection and automated status reconciliation on the order confirmation page.
- **Anti-Collision Micro-Amounts**: Leverages PayCow's micro-delta allocation system to eliminate invoice collisions on shared deposit addresses.

---

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher (Tested up to 8.8)
- PHP 7.4 or higher
- cURL and OpenSSL extensions enabled in PHP
- SSL (HTTPS) certificate installed on your store (required for secure webhook delivery)
- A PayCow merchant account ([https://paycow.net](https://paycow.net))

---

## Installation

### Method 1: Upload via WordPress Admin (Recommended)

1. Download `paycow-crypto-payment-gateway.zip` from your PayCow Merchant Dashboard or [https://paycow.net/docs](https://paycow.net/docs).
2. In your WordPress admin panel, navigate to **Plugins &rarr; Add New &rarr; Upload Plugin**.
3. Choose the downloaded ZIP file and click **Install Now**.
4. Once uploaded, click **Activate Plugin**.

### Method 2: Manual Installation via SFTP

1. Extract `paycow-crypto-payment-gateway.zip` on your computer.
2. Upload the `paycow-crypto-payment-gateway` directory to your WordPress installation's `/wp-content/plugins/` directory.
3. In WordPress admin, go to **Plugins &rarr; Installed Plugins** and click **Activate** under **PayCow Crypto Payment Gateway for WooCommerce**.

---

## Configuration

1. In WordPress Admin, navigate to **WooCommerce &rarr; Settings &rarr; Payments**.
2. Locate **PayCow Non-Custodial Crypto Gateway** in the list and click **Manage** (or **Finish set up**).
3. Configure the following settings:
   - **Enable/Disable**: Check to enable PayCow as an active payment method at checkout.
   - **Title**: Customer-facing payment method title (default: *USDT Crypto Payment (PayCow)*).
   - **Description**: Customer-facing explanation displayed on the checkout page.
   - **Merchant API Key**: Paste your Public API Key from PayCow Dashboard (**Developer &rarr; API Keys**).
   - **Merchant API Secret**: Paste your Private API Secret.
   - **Webhook Signing Secret**: Paste your signing secret (`whsec_...`) from PayCow Dashboard (**Developer &rarr; Webhooks**).
   - **Supported Network Mode**:
     - *Customer Choice at Checkout*: Allows customers to choose TRON (TRC-20) or Ethereum (ERC-20).
     - *TRON (TRC-20 Only)*: Limits checkout to TRON network (recommended for lowest customer gas fees).
     - *Ethereum (ERC-20 Only)*: Limits checkout to Ethereum ERC-20.
   - **Specific Wallet Serial ID (Optional)**: If you manage multiple wallets in your PayCow account, enter a specific wallet serial (e.g., `WAL-1001`). Leave empty to automatically use your primary default wallet.
   - **Debug Logging**: Check this box to log API and Webhook transactions to `WooCommerce -> Status -> Logs` for troubleshooting.
4. Click **Save changes**.

---

## Setting Up Webhooks

To receive automated notifications when payments are confirmed on-chain:

1. Copy the **Store Webhook URL** displayed at the bottom of the PayCow settings screen in WooCommerce. The URL will look like:
   `https://example.com/?wc-api=paycow_webhook`
2. Log into your **PayCow Merchant Dashboard** ([https://paycow.net/dashboard](https://paycow.net/dashboard)).
3. Navigate to **Developer &rarr; Webhooks**.
4. Paste the URL into the **Webhook URL** field and save.
5. Copy the generated **HMAC-SHA256 Signing Secret** (`whsec_...`) into the **Webhook Signing Secret** field in your WooCommerce settings and save.
6. In the PayCow Dashboard, click **Send Test Webhook**. Check your WooCommerce status logs to confirm receipt of the `webhook.test` event.

---

## How Checkout Works

1. Customer chooses **USDT Crypto Payment (PayCow)** at checkout and selects their preferred network (TRC-20 or ERC-20).
2. When the customer clicks **Place Order**, WooCommerce creates a unique order with an Idempotency Key and redirects the customer to PayCow's secure hosted payment terminal.
3. Customer transfers the required USDT to the displayed non-custodial address or scans the QR code with their crypto wallet.
4. PayCow's blockchain listener detects the transaction on-chain and dispatches cryptographically signed webhooks to your store (`payment.detected` &rarr; `payment.confirming` &rarr; `payment.paid`).
5. Upon confirmation, your store automatically marks the order as paid via `$order->payment_complete($txHash)` and records on-chain block explorer links.

---

## Security Best Practices

- **Zero Client-Side Exposure**: API keys and secrets are stored exclusively on the WordPress server. The frontend order tracking script uses WordPress nonces and order-key verification without ever exposing PayCow credentials.
- **HMAC-SHA256 Verification**: Every incoming webhook is cryptographically verified against your signing secret using timing-safe comparison (`hash_equals()`).
- **Replay Protection**: Webhook timestamps must be within 300 seconds of current server time to prevent replay attacks.
- **Idempotent Execution**: Duplicate delivery of webhooks is tracked via `_paycow_processed_events` to prevent duplicate order updates.

---

## Developer Filters

- `woocommerce_paycow_api_url`: Override the PayCow API endpoint (useful for local development or staging):
  ```php
  add_filter( 'woocommerce_paycow_api_url', function( $url ) {
      return 'https://paycow.net';
  } );
  ```

---

## License

GPLv2 or later. See `LICENSE` for details.
