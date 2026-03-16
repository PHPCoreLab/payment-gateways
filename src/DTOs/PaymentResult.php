<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\DTOs;

final class PaymentResult
{
    public function __construct(
        public readonly string        $orderId,
        public readonly string        $providerOrderId,
        public readonly string        $providerPaymentId,
        public readonly PaymentStatus $status,
        public readonly int           $amountPaisa,
        public readonly ?string       $providerRef   = null,
        public readonly ?string       $failureReason = null,
        public readonly array         $raw           = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Success;
    }
}
