<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\DTOs;

final class OrderResult
{
    public function __construct(
        public readonly string  $orderId,
        public readonly string  $providerOrderId,
        public readonly int     $amountPaisa,
        public readonly string  $currency,
        public readonly ?string $paymentUrl  = null,
        public readonly ?string $sdkPayload  = null,
        public readonly array   $raw         = [],
    ) {}
}
