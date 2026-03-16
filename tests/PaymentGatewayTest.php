<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Tests;

use PHPUnit\Framework\TestCase;
use PHPCoreLab\PaymentGateways\Contracts\PaymentProviderInterface;
use PHPCoreLab\PaymentGateways\Core\GatewayConfig;
use PHPCoreLab\PaymentGateways\DTOs\OrderPayload;
use PHPCoreLab\PaymentGateways\DTOs\OrderResult;
use PHPCoreLab\PaymentGateways\DTOs\PaymentEvent;
use PHPCoreLab\PaymentGateways\DTOs\PaymentResult;
use PHPCoreLab\PaymentGateways\DTOs\PaymentStatus;
use PHPCoreLab\PaymentGateways\DTOs\RefundResult;
use PHPCoreLab\PaymentGateways\Exceptions\ProviderNotFoundException;
use PHPCoreLab\PaymentGateways\PaymentGateway;

final class PaymentGatewayTest extends TestCase
{
    private function makeConfig(string $active = 'mock_a'): GatewayConfig
    {
        return GatewayConfig::fromArray([
            'active_provider' => $active,
            'providers'       => [
                'razorpay' => ['key_id' => 'x', 'key_secret' => 'x'],
                'phonepe'  => ['merchant_id' => 'x', 'salt_key' => 'x'],
                'payu'     => ['merchant_key' => 'x', 'merchant_salt' => 'x'],
                'paytm'    => ['mid' => 'x', 'merchant_key' => 'x'],
                'juspay'   => ['api_key' => 'x', 'merchant_id' => 'x'],
            ],
        ]);
    }

    private function mockProvider(string $name): PaymentProviderInterface
    {
        return new class ($name) implements PaymentProviderInterface {
            public function __construct(private readonly string $providerName) {}

            public function createOrder(OrderPayload $payload): OrderResult
            {
                return new OrderResult(
                    orderId:         $payload->orderId,
                    providerOrderId: 'prov_' . $this->providerName,
                    amountPaisa:     $payload->amountPaisa,
                    currency:        $payload->currency,
                    sdkPayload:      ['provider' => $this->providerName],
                );
            }

            public function verifyPayment(string $orderId, array $data): PaymentResult
            {
                return new PaymentResult(
                    orderId:           $orderId,
                    providerOrderId:   'prov_' . $this->providerName,
                    providerPaymentId: 'pay_' . $this->providerName,
                    status:            PaymentStatus::Success,
                    amountPaisa:       5000,
                );
            }

            public function refund(string $providerPaymentId, int $amountPaisa): RefundResult
            {
                return new RefundResult(refundId: 'ref_' . $this->providerName, success: true);
            }

            public function parseWebhook(string $rawBody, array $headers): PaymentEvent
            {
                return new PaymentEvent(
                    orderId:           'ord_1',
                    providerOrderId:   'prov_1',
                    providerPaymentId: 'pay_1',
                    status:            PaymentStatus::Success,
                    amountPaisa:       5000,
                );
            }

            public function getName(): string { return $this->providerName; }
        };
    }

    public function testCreateOrderUsesActiveProvider(): void
    {
        $gateway = new PaymentGateway($this->makeConfig());
        $gateway->registerProvider('mock_a', $this->mockProvider('mock_a'));

        $result = $gateway->createOrder(new OrderPayload(orderId: 'ORD-001', amountPaisa: 5000));

        $this->assertSame('ORD-001', $result->orderId);
        $this->assertSame('prov_mock_a', $result->providerOrderId);
    }

    public function testVerifyPaymentDelegatesToActiveProvider(): void
    {
        $gateway = new PaymentGateway($this->makeConfig());
        $gateway->registerProvider('mock_a', $this->mockProvider('mock_a'));

        $result = $gateway->verifyPayment('ORD-001', []);

        $this->assertSame(PaymentStatus::Success, $result->status);
        $this->assertTrue($result->isSuccessful());
    }

    public function testRefundDelegatesToActiveProvider(): void
    {
        $gateway = new PaymentGateway($this->makeConfig());
        $gateway->registerProvider('mock_a', $this->mockProvider('mock_a'));

        $result = $gateway->refund('pay_mock_a', 5000);

        $this->assertTrue($result->success);
        $this->assertSame('ref_mock_a', $result->refundId);
    }

    public function testSwitchProviderChangesActiveAdapter(): void
    {
        $gateway = new PaymentGateway($this->makeConfig('mock_a'));
        $gateway->registerProvider('mock_a', $this->mockProvider('mock_a'));
        $gateway->registerProvider('mock_b', $this->mockProvider('mock_b'));

        $gateway->switchProvider('mock_b');
        $result = $gateway->createOrder(new OrderPayload(orderId: 'ORD-002', amountPaisa: 1000));

        $this->assertSame('prov_mock_b', $result->providerOrderId);
    }

    public function testSwitchToUnregisteredProviderThrows(): void
    {
        $this->expectException(ProviderNotFoundException::class);

        $gateway = new PaymentGateway($this->makeConfig());
        $gateway->switchProvider('nonexistent');
    }

    public function testAvailableProvidersIncludesAllBuiltIns(): void
    {
        $gateway   = new PaymentGateway($this->makeConfig());
        $providers = $gateway->availableProviders();

        $this->assertContains('razorpay', $providers);
        $this->assertContains('phonepe',  $providers);
        $this->assertContains('payu',     $providers);
        $this->assertContains('paytm',    $providers);
        $this->assertContains('juspay',   $providers);
    }

    public function testHandleWebhookDelegatesToActiveProvider(): void
    {
        $gateway = new PaymentGateway($this->makeConfig());
        $gateway->registerProvider('mock_a', $this->mockProvider('mock_a'));

        $event = $gateway->handleWebhook('{}', []);

        $this->assertSame(PaymentStatus::Success, $event->status);
    }

    public function testGetEnvironmentReturnsConfiguredEnvironment(): void
    {
        $gateway = new PaymentGateway($this->makeConfig());

        $this->assertSame('sandbox', $gateway->getEnvironment()->value);
    }
}
