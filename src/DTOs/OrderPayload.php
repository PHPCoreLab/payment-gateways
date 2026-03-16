<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\DTOs;

final class OrderPayload
{
    public function __construct(
        public readonly string  $orderId,
        public readonly int     $amountPaisa,
        public readonly string  $currency      = 'INR',
        public readonly ?string $customerName  = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $returnUrl     = null,
        public readonly ?string $cancelUrl     = null,
        public readonly ?string $description   = null,
        public readonly array   $meta          = [],
    ) {}
}
