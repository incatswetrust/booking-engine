<?php

namespace App\Domain\Service;

/**
 * §30: what a booking for this service requires before it can confirm.
 */
enum PaymentMode: string
{
    case None = 'none';
    case Full = 'full';
    case Deposit = 'deposit';
    case PayAfter = 'pay_after';

    /**
     * Whether a booking for this service must go through
     * awaiting_payment before it can confirm (§31) -- Full/Deposit block
     * confirmation on a successful Stripe charge; None/PayAfter confirm
     * immediately like MVP always did (PayAfter still creates a Payment
     * record, just doesn't block confirmation on it being paid yet).
     */
    public function blocksConfirmation(): bool
    {
        return $this === self::Full || $this === self::Deposit;
    }

    public function requiresPayment(): bool
    {
        return $this !== self::None;
    }
}
