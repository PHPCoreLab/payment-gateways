<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Live    = 'live';

    public function isSandbox(): bool { return $this === self::Sandbox; }
    public function isLive(): bool    { return $this === self::Live; }
}
