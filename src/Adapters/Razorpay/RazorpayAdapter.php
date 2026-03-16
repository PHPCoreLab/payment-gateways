<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Adapters\Razorpay;

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

final class RazorpayAdapter implements PaymentProviderInterface
{
    private const BASE_URL = 'https://api.razorpay.com/v1/';

    private Client $http;
    private string $keyId;
    private string $keySecret;

    public function __construct(array $config, Environment $environment = Environment::Sandbox)
    {
        if ($environment->isLive()) {
            $this->keyId     = $config['live_key_id']     ?? $config['key_id']     ?? throw new \InvalidArgumentException('razorpay live_key_id required');
            $this->keySecret = $config['live_key_secret'] ?? $config['key_secret'] ?? throw new \InvalidArgumentException('razorpay live_key_secret required');
        } else {
            $this->keyId     = $config['sandbox_key_id']     ?? $config['key_id']     ?? throw new \InvalidArgumentException('razorpay sandbox_key_id required');
            $this->keySecret = $config['sandbox_key_secret'] ?? $config['key_secret'] ?? throw new \InvalidArgumentException('razorpay sandbox_key_secret required');
        }

        $this->http = new Client([
            'base_uri' => self::BASE_URL,
            'auth'     => [$this->keyId, $this->keySecret],
            'headers'  => ['Content-Type' => 'application/json'],
            'timeout'  => 10,
        ]);
    }

    public function createOrder(OrderPayload $payload): OrderResult
    {
        try {
            $response = $this->http->post('orders', ['json' => [
                'amount'          => $payload->amountPaisa,
                'currency'        => $payload->currency,
                'receipt'         => $payload->orderId,
                'payment_capture' => 1,
                'notes'           => ['order_id' => $payload->orderId],
            ]]);

            $data = json_decode((string) $response->getBody(), true);

            return new OrderResult(
                orderId:         $payload->orderId,
                providerOrderId: $data['id'],
                amountPaisa:     $data['amount'],
                currency:        $data['currency'],
                sdkPayload:      [
                    'key'      => $this->keyId,
                    'amount'   => $data['amount'],
                    'currency' => $data['currency'],
                    'order_id' => $data['id'],
                    'name'     => $payload->description ?? 'Payment',
                    'prefill'  => [
                        'name'    => $payload->customerName,
                        'email'   => $payload->customerEmail,
                        'contact' => $payload->customerPhone,
                    ],
                ],
                raw: $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function verifyPayment(string $orderId, array $data): PaymentResult
    {
        $expectedSignature = hash_hmac(
            'sha256',
            ($data['razorpay_order_id'] ?? '') . '|' . ($data['razorpay_payment_id'] ?? ''),
            $this->keySecret
        );

        if (!hash_equals($expectedSignature, $data['razorpay_signature'] ?? '')) {
            throw new WebhookVerificationException('Razorpay payment signature mismatch.');
        }

        try {
            $response = $this->http->get("payments/{$data['razorpay_payment_id']}");
            $payment  = json_decode((string) $response->getBody(), true);

            return new PaymentResult(
                orderId:           $orderId,
                providerOrderId:   $data['razorpay_order_id'],
                providerPaymentId: $data['razorpay_payment_id'],
                status:            $payment['status'] === 'captured' ? PaymentStatus::Success : PaymentStatus::Failed,
                amountPaisa:       $payment['amount'],
                providerRef:       $payment['acquirer_data']['rrn'] ?? null,
                raw:               $payment,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function refund(string $providerPaymentId, int $amountPaisa): RefundResult
    {
        try {
            $response = $this->http->post("payments/{$providerPaymentId}/refund", [
                'json' => ['amount' => $amountPaisa],
            ]);
            $data = json_decode((string) $response->getBody(), true);

            return new RefundResult(refundId: $data['id'], success: true, raw: $data);
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentEvent
    {
        $signature = $headers['X-Razorpay-Signature'][0] ?? $headers['x-razorpay-signature'][0] ?? '';
        $expected  = hash_hmac('sha256', $rawBody, $this->keySecret);

        if (!hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('Razorpay webhook signature mismatch.');
        }

        $data    = json_decode($rawBody, true);
        $payment = $data['payload']['payment']['entity'] ?? [];
        $state   = match ($data['event'] ?? '') {
            'payment.captured' => PaymentStatus::Success,
            'payment.failed'   => PaymentStatus::Failed,
            default            => PaymentStatus::Pending,
        };

        return new PaymentEvent(
            orderId:           $payment['notes']['order_id'] ?? '',
            providerOrderId:   $payment['order_id']          ?? '',
            providerPaymentId: $payment['id']                ?? '',
            status:            $state,
            amountPaisa:       $payment['amount']            ?? 0,
            providerRef:       $payment['acquirer_data']['rrn'] ?? null,
            raw:               $data,
        );
    }

    public function getName(): string { return 'razorpay'; }
}
