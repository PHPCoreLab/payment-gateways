# phpcorelab/payment-gateways

[![Latest Version](https://img.shields.io/packagist/v/phpcorelab/payment-gateways)](https://packagist.org/packages/phpcorelab/payment-gateways)
[![PHP Version](https://img.shields.io/packagist/php-v/phpcorelab/payment-gateways)](https://packagist.org/packages/phpcorelab/payment-gateways)
[![License](https://img.shields.io/packagist/l/phpcorelab/payment-gateways)](LICENSE)

> Provider-agnostic PHP payment gateway library.
> Unified API for Razorpay, PhonePe, PayU, Paytm and Juspay.
> Switch providers with a single config change — no code changes required.

## Installation

```bash
composer require phpcorelab/payment-gateways
```

Requires PHP 8.1+.

## Supported Providers

| Provider | Create Order | Verify Payment | Refund | Webhook |
|----------|:---:|:---:|:---:|:---:|
| Razorpay | ✅ | ✅ | ✅ | ✅ |
| PhonePe  | ✅ | ✅ | ✅ | ✅ |
| PayU     | ✅ | ✅ | ✅ | ✅ |
| Paytm    | 🚧 | 🚧 | 🚧 | 🚧 |
| Juspay   | ✅ | ✅ | ✅ | ✅ |

## How It Works

```
Frontend                    Backend                     Provider
   |                           |                            |
   |--- POST /payment/order --> |                            |
   |                           |--- createOrder() --------> |
   |                           |<-- OrderResult ----------- |
   |<-- { sdk_payload } -------|                            |
   |                           |                            |
   |--- [user pays via SDK] -------------------------------->|
   |<-- [provider sends back payment data] ----------------|
   |                           |                            |
   |--- POST /payment/confirm->|                            |
   |                           |--- verifyPayment() ------> |
   |                           |<-- PaymentResult --------- |
   |<-- { status: SUCCESS } ---|                            |
```

## Quick Start

```php
use PHPCoreLab\PaymentGateways\PaymentGateway;
use PHPCoreLab\PaymentGateways\Core\GatewayConfig;
use PHPCoreLab\PaymentGateways\DTOs\OrderPayload;
use PHPCoreLab\PaymentGateways\DTOs\PaymentStatus;

$gateway = new PaymentGateway(
    GatewayConfig::fromArray([
        'active_provider' => 'razorpay',  // change this to switch provider
        'environment'     => 'sandbox',   // 'sandbox' | 'live'
        'providers'       => [
            'razorpay' => [
                'sandbox_key_id'     => $_ENV['RAZORPAY_SANDBOX_KEY_ID'],
                'sandbox_key_secret' => $_ENV['RAZORPAY_SANDBOX_KEY_SECRET'],
                'live_key_id'        => $_ENV['RAZORPAY_LIVE_KEY_ID'],
                'live_key_secret'    => $_ENV['RAZORPAY_LIVE_KEY_SECRET'],
            ],
            // ... other providers
        ],
    ])
);

// Step 1 — Create order, get payload for frontend
$order = $gateway->createOrder(new OrderPayload(
    orderId:       'ORD-001',
    amountPaisa:   49900,       // INR 499.00 — always in paise
    customerName:  'Rahul Sharma',
    customerEmail: 'rahul@example.com',
    customerPhone: '9876543210',
    returnUrl:     'https://yourapp.com/payment/return',
    description:   'Order #ORD-001',
));

// Send to frontend:
// $order->sdkPayload  → for JS SDK providers (Razorpay)
// $order->paymentUrl  → for redirect-based providers (PhonePe, PayU)

// Step 2 — After frontend payment, verify it
$result = $gateway->verifyPayment('ORD-001', $request->all());

if ($result->status === PaymentStatus::Success) {
    // fulfil the order
}

// Refund
$refund = $gateway->refund($result->providerPaymentId, 49900);

// Handle webhook
$event = $gateway->handleWebhook(
    file_get_contents('php://input'),
    getallheaders()
);
```

## Frontend Integration by Provider

### Razorpay (JS SDK modal)
```javascript
const options = JSON.parse(sdkPayload);
options.handler = function(response) {
    // POST response to /payment/confirm/:orderId
    fetch(`/payment/confirm/${orderId}`, {
        method: 'POST',
        body: JSON.stringify(response)
    });
};
const rzp = new Razorpay(options);
rzp.open();
```

### PhonePe / PayU (redirect)
```javascript
// Simply redirect the user to payment_url
window.location.href = paymentUrl;
// Provider redirects back to your return_url after payment
```

### Juspay (SDK or redirect)
```javascript
// Use client_auth_token from sdk_payload with Juspay Hypercheckout SDK
// or redirect to payment_url
```

## Laravel Integration

### AppServiceProvider
```php
use PHPCoreLab\PaymentGateways\PaymentGateway;
use PHPCoreLab\PaymentGateways\Core\GatewayConfig;

$this->app->singleton(PaymentGateway::class, fn() =>
    new PaymentGateway(GatewayConfig::fromArray(config('payment-gateways')))
);
```

### Routes
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/payment/order',               [PaymentController::class, 'createOrder']);
    Route::post('/payment/confirm/{orderId}',   [PaymentController::class, 'confirmPayment']);
    Route::post('/payment/refund/{paymentId}',  [PaymentController::class, 'refund']);
});
Route::post('/payment/webhook',                 [PaymentController::class, 'webhook']);
```

## DTOs Reference

| Class | Key Properties |
|-------|----------------|
| `OrderPayload` | `orderId`, `amountPaisa`, `customerName`, `customerEmail`, `returnUrl` |
| `OrderResult`  | `orderId`, `providerOrderId`, `paymentUrl`, `sdkPayload` |
| `PaymentResult`| `orderId`, `providerPaymentId`, `status`, `amountPaisa`, `providerRef` |
| `RefundResult` | `refundId`, `success`, `message` |
| `PaymentEvent` | `orderId`, `providerPaymentId`, `status`, `amountPaisa`, `providerRef` |

`PaymentStatus` enum: `Pending · Success · Failed · Expired · Refunded · Cancelled`

## Exceptions

| Exception | When thrown |
|-----------|-------------|
| `ProviderException` | HTTP / API call to provider fails |
| `WebhookVerificationException` | Signature or checksum mismatch |
| `ProviderNotFoundException` | Provider name not registered |

All extend `PaymentGatewayException extends RuntimeException`.

## Running Tests

```bash
composer install
composer test
```


## License

MIT — [PHPCoreLab](https://github.com/PHPCoreLab)
