<?php

namespace App\Enums;

/**
 * The plan a super admin has put an organization on.
 *
 * A label for now: nothing in the app is switched on or off by plan. The tiers
 * mirror the public pricing page (resources/js/pages/Pricing.vue), plus Trial.
 */
enum TeamPlan: string
{
    case Trial = 'trial';
    case Team = 'team';
    case Business = 'business';
    case Enterprise = 'enterprise';

    /**
     * Get the display label for the plan.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get the plans as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $plan) => ['value' => $plan->value, 'label' => $plan->label()],
            self::cases(),
        );
    }
}
