<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\DTOs;

enum PaymentStatus: string
{
    case Pending   = 'PENDING';
    case Success   = 'SUCCESS';
    case Failed    = 'FAILED';
    case Expired   = 'EXPIRED';
    case Refunded  = 'REFUNDED';
    case Cancelled = 'CANCELLED';
}
