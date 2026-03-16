<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Adapters\PhonePe;

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

final class PhonePeAdapter implements PaymentProviderInterface
{
    private const BASE_URLS = [
        'sandbox' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/',
        'live'    => 'https://api.phonepe.com/apis/hermes/',
    ];

    private Client $http;
    private string $merchantId;
    private string $saltKey;
    private int    $saltIndex;

    public function __construct(array $config, Environment $environment = Environment::Sandbox)
    {
        if ($environment->isLive()) {
            $this->merchantId = $config['live_merchant_id'] ?? $config['merchant_id'] ?? throw new \InvalidArgumentException('phonepe live_merchant_id required');
            $this->saltKey    = $config['live_salt_key']    ?? $config['salt_key']    ?? throw new \InvalidArgumentException('phonepe live_salt_key required');
            $this->saltIndex  = (int) ($config['live_salt_index'] ?? $config['salt_index'] ?? 1);
        } else {
            $this->merchantId = $config['sandbox_merchant_id'] ?? $config['merchant_id'] ?? throw new \InvalidArgumentException('phonepe sandbox_merchant_id required');
            $this->saltKey    = $config['sandbox_salt_key']    ?? $config['salt_key']    ?? throw new \InvalidArgumentException('phonepe sandbox_salt_key required');
            $this->saltIndex  = (int) ($config['sandbox_salt_index'] ?? $config['salt_index'] ?? 1);
        }

        $this->http = new Client([
            'base_uri' => self::BASE_URLS[$environment->value],
            'timeout'  => 10,
        ]);
    }

    public function createOrder(OrderPayload $payload): OrderResult
    {
        $body = [
            'merchantId'            => $this->merchantId,
            'merchantTransactionId' => $payload->orderId,
            'amount'                => $payload->amountPaisa,
            'redirectUrl'           => $payload->returnUrl ?? '',
            'redirectMode'          => 'REDIRECT',
            'paymentInstrument'     => ['type' => 'PAY_PAGE'],
        ];

        $encoded  = base64_encode(json_encode($body));
        $checksum = hash('sha256', $encoded . '/pg/v1/pay' . $this->saltKey) . '###' . $this->saltIndex;

        try {
            $response = $this->http->post('pg/v1/pay', [
                'json'    => ['request' => $encoded],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-VERIFY'     => $checksum,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (!($data['success'] ?? false)) {
                throw new ProviderException($data['message'] ?? 'Order creation failed', $this->getName(), $data);
            }

            $redirectUrl = $data['data']['instrumentResponse']['redirectInfo']['url'] ?? null;

            return new OrderResult(
                orderId:         $payload->orderId,
                providerOrderId: $payload->orderId,
                amountPaisa:     $payload->amountPaisa,
                currency:        $payload->currency,
                paymentUrl:      $redirectUrl,
                raw:             $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function verifyPayment(string $orderId, array $data): PaymentResult
    {
        $path     = "/pg/v1/status/{$this->merchantId}/{$orderId}";
        $checksum = hash('sha256', $path . $this->saltKey) . '###' . $this->saltIndex;

        try {
            $response = $this->http->get("pg/v1/status/{$this->merchantId}/{$orderId}", [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-VERIFY'      => $checksum,
                    'X-MERCHANT-ID' => $this->merchantId,
                ],
            ]);

            $res   = json_decode((string) $response->getBody(), true);
            $code  = $res['code'] ?? '';
            $state = match ($code) {
                'PAYMENT_SUCCESS'       => PaymentStatus::Success,
                'PAYMENT_ERROR',
                'TRANSACTION_NOT_FOUND' => PaymentStatus::Failed,
                default                 => PaymentStatus::Pending,
            };

            return new PaymentResult(
                orderId:           $orderId,
                providerOrderId:   $orderId,
                providerPaymentId: $res['data']['transactionId'] ?? '',
                status:            $state,
                amountPaisa:       $res['data']['amount']        ?? 0,
                providerRef:       $res['data']['providerReferenceId'] ?? null,
                raw:               $res,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function refund(string $providerPaymentId, int $amountPaisa): RefundResult
    {
        $body     = [
            'merchantId'            => $this->merchantId,
            'merchantTransactionId' => 'REFUND_' . $providerPaymentId . '_' . time(),
            'originalTransactionId' => $providerPaymentId,
            'amount'                => $amountPaisa,
            'callbackUrl'           => '',
        ];
        $encoded  = base64_encode(json_encode($body));
        $checksum = hash('sha256', $encoded . '/pg/v1/refund' . $this->saltKey) . '###' . $this->saltIndex;

        try {
            $response = $this->http->post('pg/v1/refund', [
                'json'    => ['request' => $encoded],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-VERIFY'     => $checksum,
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);

            return new RefundResult(
                refundId: $data['data']['merchantTransactionId'] ?? 'unknown',
                success:  (bool) ($data['success'] ?? false),
                message:  $data['message'] ?? null,
                raw:      $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentEvent
    {
        $xVerify    = $headers['X-VERIFY'][0] ?? $headers['x-verify'][0] ?? '';
        [$received] = explode('###', $xVerify . '###');
        $expected   = hash('sha256', $rawBody . $this->saltKey);

        if (!hash_equals($expected, $received)) {
            throw new WebhookVerificationException('PhonePe webhook checksum mismatch.');
        }

        $outer   = json_decode($rawBody, true);
        $decoded = json_decode(base64_decode($outer['response'] ?? ''), true);
        $code    = $decoded['code'] ?? '';
        $state   = match ($code) {
            'PAYMENT_SUCCESS' => PaymentStatus::Success,
            'PAYMENT_ERROR'   => PaymentStatus::Failed,
            default           => PaymentStatus::Pending,
        };

        return new PaymentEvent(
            orderId:           $decoded['data']['merchantTransactionId'] ?? '',
            providerOrderId:   $decoded['data']['merchantTransactionId'] ?? '',
            providerPaymentId: $decoded['data']['transactionId']         ?? '',
            status:            $state,
            amountPaisa:       $decoded['data']['amount']                ?? 0,
            providerRef:       $decoded['data']['providerReferenceId']   ?? null,
            raw:               $decoded,
        );
    }

    public function getName(): string { return 'phonepe'; }
}
