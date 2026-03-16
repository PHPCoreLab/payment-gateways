<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Tests\Adapters;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPCoreLab\PaymentGateways\Adapters\Razorpay\RazorpayAdapter;
use PHPCoreLab\PaymentGateways\DTOs\OrderPayload;
use PHPCoreLab\PaymentGateways\DTOs\PaymentStatus;
use PHPCoreLab\PaymentGateways\Exceptions\WebhookVerificationException;

final class RazorpayAdapterTest extends TestCase
{
    private function makeAdapter(array $responses): RazorpayAdapter
    {
        $mock    = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $adapter = new RazorpayAdapter(['key_id' => 'test_key', 'key_secret' => 'test_secret']);

        $ref = new \ReflectionProperty(RazorpayAdapter::class, 'http');
        $ref->setValue($adapter, new Client(['handler' => $handler]));

        return $adapter;
    }

    public function testCreateOrderReturnsOrderResult(): void
    {
        $adapter = $this->makeAdapter([
            new Response(200, [], json_encode([
                'id'       => 'order_test123',
                'amount'   => 5000,
                'currency' => 'INR',
            ])),
        ]);

        $result = $adapter->createOrder(new OrderPayload(orderId: 'ORD-001', amountPaisa: 5000));

        $this->assertSame('ORD-001', $result->orderId);
        $this->assertSame('order_test123', $result->providerOrderId);
        $this->assertNotNull($result->sdkPayload);
    }

    public function testCreateOrderSdkPayloadContainsKeyAndOrderId(): void
    {
        $adapter = $this->makeAdapter([
            new Response(200, [], json_encode([
                'id'       => 'order_abc',
                'amount'   => 10000,
                'currency' => 'INR',
            ])),
        ]);

        $result  = $adapter->createOrder(new OrderPayload(orderId: 'ORD-002', amountPaisa: 10000));
        $payload = $result->sdkPayload;

        $this->assertIsArray($payload);

        $this->assertSame('test_key', $payload['key']);
        $this->assertSame('order_abc', $payload['order_id']);
    }

    public function testVerifyPaymentReturnSuccessOnValidSignature(): void
    {
        $secret    = 'test_secret';
        $orderId   = 'order_abc';
        $paymentId = 'pay_xyz';
        $signature = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);

        $adapter = $this->makeAdapter([
            new Response(200, [], json_encode([
                'id'     => $paymentId,
                'amount' => 5000,
                'status' => 'captured',
            ])),
        ]);

        $result = $adapter->verifyPayment('ORD-001', [
            'razorpay_order_id'   => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature'  => $signature,
        ]);

        $this->assertSame(PaymentStatus::Success, $result->status);
        $this->assertTrue($result->isSuccessful());
    }

    public function testVerifyPaymentThrowsOnBadSignature(): void
    {
        $this->expectException(WebhookVerificationException::class);

        $adapter = $this->makeAdapter([]);
        $adapter->verifyPayment('ORD-001', [
            'razorpay_order_id'   => 'order_abc',
            'razorpay_payment_id' => 'pay_xyz',
            'razorpay_signature'  => 'invalid_signature',
        ]);
    }

    public function testWebhookParsesSuccessEvent(): void
    {
        $secret = 'test_secret';
        $body   = json_encode([
            'event'   => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id'             => 'pay_xyz',
                        'order_id'       => 'order_abc',
                        'amount'         => 5000,
                        'notes'          => ['order_id' => 'ORD-001'],
                        'acquirer_data'  => [],
                    ],
                ],
            ],
        ]);
        $signature = hash_hmac('sha256', $body, $secret);
        $adapter   = new RazorpayAdapter(['key_id' => 'test_key', 'key_secret' => $secret]);

        $event = $adapter->parseWebhook($body, ['X-Razorpay-Signature' => [$signature]]);

        $this->assertSame(PaymentStatus::Success, $event->status);
        $this->assertSame('ORD-001', $event->orderId);
    }

    public function testWebhookThrowsOnBadSignature(): void
    {
        $this->expectException(WebhookVerificationException::class);

        $adapter = new RazorpayAdapter(['key_id' => 'test_key', 'key_secret' => 'test_secret']);
        $adapter->parseWebhook('{}', ['X-Razorpay-Signature' => ['bad_signature']]);
    }
}
