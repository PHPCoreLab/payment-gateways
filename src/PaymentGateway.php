<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPCoreLab\PaymentGateways\Adapters\Razorpay\RazorpayAdapter;
use PHPCoreLab\PaymentGateways\Adapters\PhonePe\PhonePeAdapter;
use PHPCoreLab\PaymentGateways\Adapters\PayU\PayUAdapter;
use PHPCoreLab\PaymentGateways\Adapters\Paytm\PaytmAdapter;
use PHPCoreLab\PaymentGateways\Adapters\Juspay\JuspayAdapter;
use PHPCoreLab\PaymentGateways\Contracts\PaymentProviderInterface;
use PHPCoreLab\PaymentGateways\Core\GatewayConfig;
use PHPCoreLab\PaymentGateways\Core\ProviderRegistry;
use PHPCoreLab\PaymentGateways\DTOs\OrderPayload;
use PHPCoreLab\PaymentGateways\DTOs\OrderResult;
use PHPCoreLab\PaymentGateways\DTOs\PaymentEvent;
use PHPCoreLab\PaymentGateways\DTOs\PaymentResult;
use PHPCoreLab\PaymentGateways\DTOs\RefundResult;
use PHPCoreLab\PaymentGateways\Enums\Environment;

class PaymentGateway
{
    private ProviderRegistry $registry;
    private LoggerInterface  $logger;

    public function __construct(
        private readonly GatewayConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger   = $logger ?? new NullLogger();
        $this->registry = new ProviderRegistry();
        $this->registerBuiltInAdapters();
    }

    // -------------------------------------------------------------------------
    // Core operations
    // -------------------------------------------------------------------------

    /**
     * Step 1 — Create order. Returns data for the frontend to launch payment.
     */
    public function createOrder(OrderPayload $payload): OrderResult
    {
        $this->logger->info('Creating payment order', [
            'provider'    => $this->config->getActiveProvider(),
            'environment' => $this->config->getEnvironment()->value,
            'order_id'    => $payload->orderId,
            'amount'      => $payload->amountPaisa,
        ]);

        return $this->activeProvider()->createOrder($payload);
    }

    /**
     * Step 2 — Verify payment after frontend completes it.
     * $data is the raw payload sent by the provider to the frontend,
     * forwarded to this method as-is.
     *
     * @param array<string, mixed> $data
     */
    public function verifyPayment(string $orderId, array $data): PaymentResult
    {
        return $this->activeProvider()->verifyPayment($orderId, $data);
    }

    /**
     * Initiate a full or partial refund.
     */
    public function refund(string $providerPaymentId, int $amountPaisa): RefundResult
    {
        return $this->activeProvider()->refund($providerPaymentId, $amountPaisa);
    }

    /**
     * Parse and verify an inbound provider webhook.
     *
     * @param array<string, mixed> $headers
     */
    public function handleWebhook(string $rawBody, array $headers): PaymentEvent
    {
        return $this->activeProvider()->parseWebhook($rawBody, $headers);
    }

    // -------------------------------------------------------------------------
    // Provider management
    // -------------------------------------------------------------------------

    public function registerProvider(string $name, PaymentProviderInterface|callable $provider): void
    {
        $this->registry->register($name, $provider);
    }

    public function switchProvider(string $name): void
    {
        $this->registry->resolve($name);
        $this->config->setActiveProvider($name);
        $this->logger->info("Payment gateway switched to: {$name}", [
            'environment' => $this->config->getEnvironment()->value,
        ]);
    }

    public function activeProvider(): PaymentProviderInterface
    {
        return $this->registry->resolve($this->config->getActiveProvider());
    }

    public function getEnvironment(): Environment
    {
        return $this->config->getEnvironment();
    }

    /** @return string[] */
    public function availableProviders(): array
    {
        return $this->registry->registered();
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function registerBuiltInAdapters(): void
    {
        $env = $this->config->getEnvironment();

        $this->registry->register('razorpay', fn() => new RazorpayAdapter($this->config->getProviderConfig('razorpay'), $env));
        $this->registry->register('phonepe',  fn() => new PhonePeAdapter($this->config->getProviderConfig('phonepe'),  $env));
        $this->registry->register('payu',     fn() => new PayUAdapter($this->config->getProviderConfig('payu'),        $env));
        $this->registry->register('paytm',    fn() => new PaytmAdapter($this->config->getProviderConfig('paytm'),      $env));
        $this->registry->register('juspay',   fn() => new JuspayAdapter($this->config->getProviderConfig('juspay'),    $env));
    }
}
