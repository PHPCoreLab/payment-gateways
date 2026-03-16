<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Adapters\Juspay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPCoreLab\PaymentGateways\Contracts\PaymentProviderInterface;
use PHPCoreLab\PaymentGateways\DTOs\OrderPayload;
use PHPCoreLab\PaymentGateways\DTOs\OrderResult;
use PHPCoreLab\PaymentGateways\DTOs\PaymentEvent;
use PHPCoreLab\PaymentGateways\DTOs\PaymentResult;
use PHPCoreLab\PaymentGateways\DTOs\PaymentStatus;
use PHPCoreLab\PaymentGateways\DTOs\RefundResult;
use PHPCoreLab\PaymentGateways\Enums\Environment;
use PHPCoreLab\PaymentGateways\Exceptions\ProviderException;
use PHPCoreLab\PaymentGateways\Exceptions\WebhookVerificationException;

final class JuspayAdapter implements PaymentProviderInterface
{
    private const BASE_URLS = [
        'sandbox' => 'https://sandbox.juspay.in/',
        'live'    => 'https://api.juspay.in/',
    ];

    private Client $http;
    private string $apiKey;
    private string $merchantId;
    private string $webhookUsername;
    private string $webhookPassword;

    public function __construct(array $config, Environment $environment = Environment::Sandbox)
    {
        if ($environment->isLive()) {
            $this->apiKey          = $config['live_api_key']          ?? $config['api_key']          ?? throw new \InvalidArgumentException('juspay live_api_key required');
            $this->merchantId      = $config['live_merchant_id']      ?? $config['merchant_id']      ?? throw new \InvalidArgumentException('juspay live_merchant_id required');
            $this->webhookUsername = $config['live_webhook_username']  ?? $config['webhook_username'] ?? '';
            $this->webhookPassword = $config['live_webhook_password']  ?? $config['webhook_password'] ?? '';
        } else {
            $this->apiKey          = $config['sandbox_api_key']          ?? $config['api_key']          ?? throw new \InvalidArgumentException('juspay sandbox_api_key required');
            $this->merchantId      = $config['sandbox_merchant_id']      ?? $config['merchant_id']      ?? throw new \InvalidArgumentException('juspay sandbox_merchant_id required');
            $this->webhookUsername = $config['sandbox_webhook_username']  ?? $config['webhook_username'] ?? '';
            $this->webhookPassword = $config['sandbox_webhook_password']  ?? $config['webhook_password'] ?? '';
        }

        $this->http = new Client([
            'base_uri' => self::BASE_URLS[$environment->value],
            'auth'     => [$this->apiKey, ''],
            'headers'  => [
                'Content-Type' => 'application/json',
                'x-merchantid' => $this->merchantId,
            ],
            'timeout'  => 10,
        ]);
    }

    public function createOrder(OrderPayload $payload): OrderResult
    {
        try {
            $response = $this->http->post('session', [
                'json' => [
                    'order_id'               => $payload->orderId,
                    'amount'                 => $payload->amountPaisa / 100,
                    'currency'               => $payload->currency,
                    'customer_id'            => $payload->customerPhone ?? $payload->orderId,
                    'customer_email'         => $payload->customerEmail ?? 'customer@example.com',
                    'customer_phone'         => $payload->customerPhone ?? '9999999999',
                    'payment_page_client_id' => $this->merchantId,
                    'return_url'             => $payload->returnUrl ?? '',
                    'description'            => $payload->description ?? 'Payment',
                    'action'                 => 'paymentPage',
                    'options'                => ['get_client_auth_token' => true],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (isset($data['error_code'])) {
                throw new ProviderException($data['error_message'] ?? 'Session creation failed', $this->getName(), $data);
            }

            return new OrderResult(
                orderId:         $payload->orderId,
                providerOrderId: $data['order_id']        ?? $payload->orderId,
                amountPaisa:     $payload->amountPaisa,
                currency:        $payload->currency,
                paymentUrl:      $data['payment_links']['web'] ?? null,
                sdkPayload:      json_encode([
                    'client_auth_token' => $data['client_auth_token'] ?? null,
                    'order_id'          => $data['order_id']          ?? null,
                    'merchant_id'       => $this->merchantId,
                ]),
                raw: $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function verifyPayment(string $orderId, array $data): PaymentResult
    {
        try {
            $response = $this->http->get("orders/{$orderId}");
            $res      = json_decode((string) $response->getBody(), true);

            $status = match (strtoupper($res['status'] ?? '')) {
                'CHARGED'                 => PaymentStatus::Success,
                'AUTHENTICATION_FAILED',
                'AUTHORIZATION_FAILED',
                'JUSPAY_DECLINED'         => PaymentStatus::Failed,
                'EXPIRED'                 => PaymentStatus::Expired,
                default                   => PaymentStatus::Pending,
            };

            $txn = $res['txn_detail'] ?? [];

            return new PaymentResult(
                orderId:           $orderId,
                providerOrderId:   $res['order_id'] ?? $orderId,
                providerPaymentId: $txn['txn_id']   ?? '',
                status:            $status,
                amountPaisa:       (int) round((float) ($res['amount'] ?? 0) * 100),
                providerRef:       $txn['rrn']       ?? null,
                raw:               $res,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function refund(string $providerPaymentId, int $amountPaisa): RefundResult
    {
        try {
            $response = $this->http->post("orders/{$providerPaymentId}/refunds", [
                'json' => [
                    'unique_request_id' => 'REFUND_' . $providerPaymentId . '_' . time(),
                    'amount'            => $amountPaisa / 100,
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);

            return new RefundResult(
                refundId: $data['id'] ?? ('REFUND_' . $providerPaymentId),
                success:  isset($data['id']),
                raw:      $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentEvent
    {
        $authHeader = $headers['Authorization'][0] ?? $headers['authorization'][0] ?? '';
        $decoded    = base64_decode(str_replace('Basic ', '', $authHeader));
        [$user, $pass] = explode(':', $decoded . ':', 2);

        if (
            !hash_equals($this->webhookUsername, $user) ||
            !hash_equals($this->webhookPassword, rtrim($pass, ':'))
        ) {
            throw new WebhookVerificationException('Juspay webhook auth mismatch.');
        }

        $data   = json_decode($rawBody, true);
        $order  = $data['content']['order'] ?? [];
        $status = match (strtoupper($order['status'] ?? '')) {
            'CHARGED'                 => PaymentStatus::Success,
            'AUTHENTICATION_FAILED',
            'AUTHORIZATION_FAILED',
            'JUSPAY_DECLINED'         => PaymentStatus::Failed,
            'EXPIRED'                 => PaymentStatus::Expired,
            default                   => PaymentStatus::Pending,
        };

        $txn = $order['txn_detail'] ?? [];

        return new PaymentEvent(
            orderId:           $order['order_id'] ?? '',
            providerOrderId:   $order['order_id'] ?? '',
            providerPaymentId: $txn['txn_id']     ?? '',
            status:            $status,
            amountPaisa:       (int) round((float) ($order['amount'] ?? 0) * 100),
            providerRef:       $txn['rrn']         ?? null,
            raw:               $data,
        );
    }

    public function getName(): string { return 'juspay'; }
}
