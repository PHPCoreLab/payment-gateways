<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\DTOs;

final class RefundResult
{
    public function __construct(
        public readonly string  $refundId,
        public readonly bool    $success,
        public readonly ?string $message = null,
        public readonly array   $raw     = [],
    ) {}
}
