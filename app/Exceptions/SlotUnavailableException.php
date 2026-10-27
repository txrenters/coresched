<?php

namespace App\Exceptions;

use RuntimeException;

class SlotUnavailableException extends RuntimeException
{
    /**
     * Create an exception for a slot that was taken before it could be booked.
     */
    public static function taken(): self
    {
        return new self('That time is no longer available.');
    }

    /**
     * Create an exception for an organization a super admin has disabled.
     */
    public static function organizationDisabled(): self
    {
        return new self('This organization is not taking bookings right now.');
    }
}
