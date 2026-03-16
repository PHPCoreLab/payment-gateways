<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Adapters\Paytm;

use GuzzleHttp\Client;
use PHPCoreLab\PaymentGateways\Contracts\PaymentProviderInterface;
use PHPCoreLab\PaymentGateways\DTOs\OrderPayload;
use PHPCoreLab\PaymentGateways\DTOs\OrderResult;
use PHPCoreLab\PaymentGateways\DTOs\PaymentEvent;
use PHPCoreLab\PaymentGateways\DTOs\PaymentResult;
use PHPCoreLab\PaymentGateways\DTOs\PaymentStatus;
use PHPCoreLab\PaymentGateways\DTOs\RefundResult;
use PHPCoreLab\PaymentGateways\Enums\Environment;
use PHPCoreLab\PaymentGateways\Exceptions\ProviderException;

/**
 * Paytm Payment Gateway Adapter.
 *
 * Sandbox : https://securegw-stage.paytm.in/
 * Live    : https://securegw.paytm.in/
 *
 * Paytm requires a JWT signed with the merchant key for every API call.
 * Full implementation stub — structure is ready for JWT integration.
 *
 * @see https://developer.paytm.com/docs/
 */
final class PaytmAdapter implements PaymentProviderInterface
{
    private const BASE_URLS = [
        'sandbox' => 'https://securegw-stage.paytm.in/',
        'live'    => 'https://securegw.paytm.in/',
    ];

    private Client $http;
    private string $mid;
    private string $merchantKey;

    public function __construct(array $config, Environment $environment = Environment::Sandbox)
    {
        if ($environment->isLive()) {
            $this->mid         = $config['live_mid']          ?? $config['mid']          ?? throw new \InvalidArgumentException('paytm live_mid required');
            $this->merchantKey = $config['live_merchant_key'] ?? $config['merchant_key'] ?? throw new \InvalidArgumentException('paytm live_merchant_key required');
        } else {
            $this->mid         = $config['sandbox_mid']          ?? $config['mid']          ?? throw new \InvalidArgumentException('paytm sandbox_mid required');
            $this->merchantKey = $config['sandbox_merchant_key'] ?? $config['merchant_key'] ?? throw new \InvalidArgumentException('paytm sandbox_merchant_key required');
        }

        $this->http = new Client([
            'base_uri' => self::BASE_URLS[$environment->value],
            'timeout'  => 10,
        ]);
    }

    public function createOrder(OrderPayload $payload): OrderResult
    {
        throw new ProviderException(
            'Paytm createOrder requires JWT token generation. Implement generateJwtToken() using merchant key.',
            $this->getName()
        );
    }

    public function verifyPayment(string $orderId, array $data): PaymentResult
    {
        throw new ProviderException('Paytm verifyPayment not yet implemented.', $this->getName());
    }

    public function refund(string $providerPaymentId, int $amountPaisa): RefundResult
    {
        throw new ProviderException('Paytm refund not yet implemented.', $this->getName());
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentEvent
    {
        throw new ProviderException('Paytm parseWebhook not yet implemented.', $this->getName());
    }

    public function getName(): string { return 'paytm'; }
}
