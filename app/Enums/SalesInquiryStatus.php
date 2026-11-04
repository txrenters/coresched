<?php

namespace App\Enums;

/**
 * Where a "Contact sales" inquiry stands, as a super admin works it.
 */
enum SalesInquiryStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get the statuses as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
