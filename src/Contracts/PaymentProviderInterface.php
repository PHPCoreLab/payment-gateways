<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Contracts;

use PHPCoreLab\PaymentGateways\DTOs\OrderPayload;
use PHPCoreLab\PaymentGateways\DTOs\OrderResult;
use PHPCoreLab\PaymentGateways\DTOs\PaymentEvent;
use PHPCoreLab\PaymentGateways\DTOs\PaymentResult;
use PHPCoreLab\PaymentGateways\DTOs\RefundResult;

interface PaymentProviderInterface
{
    /**
     * Step 1 — Create an order on the provider.
     * Returns data the frontend needs to launch the payment flow.
     */
    public function createOrder(OrderPayload $payload): OrderResult;

    /**
     * Step 2 — Verify and confirm payment after the frontend completes it.
     * $data is the raw payload sent back by the provider to the frontend,
     * which the frontend forwards to this method as-is.
     *
     * @param array<string, mixed> $data
     */
    public function verifyPayment(string $orderId, array $data): PaymentResult;

    /**
     * Initiate a full or partial refund.
     */
    public function refund(string $providerPaymentId, int $amountPaisa): RefundResult;

    /**
     * Parse and verify an inbound webhook from the provider.
     * Implementations MUST verify the signature / checksum.
     *
     * @param array<string, mixed> $headers
     */
    public function parseWebhook(string $rawBody, array $headers): PaymentEvent;

    /**
     * Human-readable provider name used in logs and errors.
     */
    public function getName(): string;
}
