<?php

namespace App\Enums;

/**
 * Which of an organization's bookings an API key's webhook hears about.
 */
enum WebhookScope: string
{
    /** Only bookings opened from the caller's own links, which carry its reference. */
    case Referenced = 'referenced';

    /** Every booking the organization takes. */
    case All = 'all';

    /**
     * Get the display label for the scope.
     */
    public function label(): string
    {
        return match ($this) {
            self::Referenced => 'Only bookings from our links',
            self::All => 'Every booking in the organization',
        };
    }

    /**
     * Whether a booking falls within the scope.
     */
    public function covers(?string $reference): bool
    {
        return $this === self::All || $reference !== null;
    }
}
