<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Adapters\PayU;

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

final class PayUAdapter implements PaymentProviderInterface
{
    private const BASE_URLS = [
        'sandbox' => 'https://test.payu.in/',
        'live'    => 'https://info.payu.in/',
    ];

    private const PAYMENT_URLS = [
        'sandbox' => 'https://test.payu.in/_payment',
        'live'    => 'https://secure.payu.in/_payment',
    ];

    private Client $http;
    private string $merchantKey;
    private string $merchantSalt;
    private string $paymentUrl;

    public function __construct(array $config, Environment $environment = Environment::Sandbox)
    {
        if ($environment->isLive()) {
            $this->merchantKey  = $config['live_merchant_key']  ?? $config['merchant_key']  ?? throw new \InvalidArgumentException('payu live_merchant_key required');
            $this->merchantSalt = $config['live_merchant_salt'] ?? $config['merchant_salt'] ?? throw new \InvalidArgumentException('payu live_merchant_salt required');
        } else {
            $this->merchantKey  = $config['sandbox_merchant_key']  ?? $config['merchant_key']  ?? throw new \InvalidArgumentException('payu sandbox_merchant_key required');
            $this->merchantSalt = $config['sandbox_merchant_salt'] ?? $config['merchant_salt'] ?? throw new \InvalidArgumentException('payu sandbox_merchant_salt required');
        }

        $this->paymentUrl = self::PAYMENT_URLS[$environment->value];
        $this->http       = new Client([
            'base_uri' => self::BASE_URLS[$environment->value],
            'timeout'  => 10,
        ]);
    }

    public function createOrder(OrderPayload $payload): OrderResult
    {
        $amount      = number_format($payload->amountPaisa / 100, 2, '.', '');
        $productInfo = $payload->description ?? 'Payment';
        $firstName   = $payload->customerName  ?? '';
        $email       = $payload->customerEmail ?? '';

        $hashString = implode('|', [
            $this->merchantKey,
            $payload->orderId,
            $amount,
            $productInfo,
            $firstName,
            $email,
            '', '', '', '', '', // udf1-5
            '', '', '', '', '', // reserved
            $this->merchantSalt,
        ]);
        $hash = hash('sha512', $hashString);

        $sdkPayload = [
            'action'      => $this->paymentUrl,
            'key'         => $this->merchantKey,
            'txnid'       => $payload->orderId,
            'amount'      => $amount,
            'productinfo' => $productInfo,
            'firstname'   => $firstName,
            'email'       => $email,
            'phone'       => $payload->customerPhone ?? '',
            'surl'        => $payload->returnUrl  ?? '',
            'furl'        => $payload->cancelUrl  ?? $payload->returnUrl ?? '',
            'hash'        => $hash,
        ];

        return new OrderResult(
            orderId:         $payload->orderId,
            providerOrderId: $payload->orderId,
            amountPaisa:     $payload->amountPaisa,
            currency:        $payload->currency,
            paymentUrl:      $this->paymentUrl,
            sdkPayload:      json_encode($sdkPayload),
            raw:             $sdkPayload,
        );
    }

    public function verifyPayment(string $orderId, array $data): PaymentResult
    {
        $hashString = implode('|', [
            $this->merchantSalt,
            $data['status']      ?? '',
            $data['udf5']        ?? '',
            $data['udf4']        ?? '',
            $data['udf3']        ?? '',
            $data['udf2']        ?? '',
            $data['udf1']        ?? '',
            $data['email']       ?? '',
            $data['firstname']   ?? '',
            $data['productinfo'] ?? '',
            $data['amount']      ?? '',
            $data['txnid']       ?? '',
            $this->merchantKey,
        ]);

        if (!hash_equals(hash('sha512', $hashString), $data['hash'] ?? '')) {
            throw new WebhookVerificationException('PayU payment hash mismatch.');
        }

        $status = match (strtolower($data['status'] ?? '')) {
            'success' => PaymentStatus::Success,
            'failure' => PaymentStatus::Failed,
            default   => PaymentStatus::Pending,
        };

        return new PaymentResult(
            orderId:           $orderId,
            providerOrderId:   $data['txnid']       ?? $orderId,
            providerPaymentId: $data['mihpayid']     ?? '',
            status:            $status,
            amountPaisa:       (int) round((float) ($data['amount'] ?? 0) * 100),
            providerRef:       $data['bank_ref_num'] ?? null,
            failureReason:     $data['field9']       ?? null,
            raw:               $data,
        );
    }

    public function refund(string $providerPaymentId, int $amountPaisa): RefundResult
    {
        $amount  = number_format($amountPaisa / 100, 2, '.', '');
        $command = 'cancel_refund_transaction';
        $hash    = hash('sha512', $this->merchantKey . '|' . $command . '|' . $providerPaymentId . '|' . $this->merchantSalt);

        try {
            $response = $this->http->post('merchant/postservice?form=2', [
                'json' => [
                    'key'     => $this->merchantKey,
                    'command' => $command,
                    'var1'    => $providerPaymentId,
                    'var2'    => $amount,
                    'hash'    => $hash,
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);

            return new RefundResult(
                refundId: $data['refundId'] ?? $providerPaymentId,
                success:  ($data['status'] ?? 0) == 1,
                message:  $data['msg']      ?? null,
                raw:      $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentEvent
    {
        parse_str($rawBody, $data);

        $hashString = implode('|', [
            $this->merchantSalt,
            $data['status']      ?? '',
            $data['udf5']        ?? '',
            $data['udf4']        ?? '',
            $data['udf3']        ?? '',
            $data['udf2']        ?? '',
            $data['udf1']        ?? '',
            $data['email']       ?? '',
            $data['firstname']   ?? '',
            $data['productinfo'] ?? '',
            $data['amount']      ?? '',
            $data['txnid']       ?? '',
            $this->merchantKey,
        ]);

        if (!hash_equals(hash('sha512', $hashString), $data['hash'] ?? '')) {
            throw new WebhookVerificationException('PayU webhook hash mismatch.');
        }

        $status = match (strtolower($data['status'] ?? '')) {
            'success' => PaymentStatus::Success,
            'failure' => PaymentStatus::Failed,
            default   => PaymentStatus::Pending,
        };

        return new PaymentEvent(
            orderId:           $data['txnid']        ?? '',
            providerOrderId:   $data['txnid']        ?? '',
            providerPaymentId: $data['mihpayid']     ?? '',
            status:            $status,
            amountPaisa:       (int) round((float) ($data['amount'] ?? 0) * 100),
            providerRef:       $data['bank_ref_num'] ?? null,
            raw:               $data,
        );
    }

    public function getName(): string { return 'payu'; }
}
