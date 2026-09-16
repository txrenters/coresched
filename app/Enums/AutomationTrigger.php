<?php

namespace App\Enums;

enum AutomationTrigger: string
{
    case Booked = 'booked';
    case BeforeStart = 'before_start';
    case AfterEnd = 'after_end';
    case NoShow = 'no_show';

    /**
     * Get the display label for the trigger.
     */
    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Immediately when a meeting is booked',
            self::BeforeStart => 'Before the meeting starts',
            self::AfterEnd => 'After the meeting ends',
            self::NoShow => 'When the invitee is marked as a no-show',
        };
    }

    /**
     * Determine whether an offset has to be given.
     *
     * Booking and a no-show are moments, so a delay after them is optional --
     * "immediately" is a perfectly good answer. The meeting's own times are only useful
     * with a distance from them.
     */
    public function requiresOffset(): bool
    {
        return $this !== self::Booked && $this !== self::NoShow;
    }

    /**
     * Get the options offered in the editor.
     *
     * @return array<int, array{value: string, label: string, requiresOffset: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $trigger) => [
                'value' => $trigger->value,
                'label' => $trigger->label(),
                'requiresOffset' => $trigger->requiresOffset(),
            ],
            self::cases(),
        );
    }
}
