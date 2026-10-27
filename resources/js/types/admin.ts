/** The steps an organization needs before it is really running. */
export type SetupStep =
    'calendar' | 'eventTypes' | 'twilio' | 'smsNumber' | 'booked';

export type OpenAsMember = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
};

/**
 * What a super admin sees about an organization (App\Services\Admin\
 * OrganizationSummary). Never sent to organization admins.
 */
export type OrganizationAdminSummary = {
    /** Formatted date a super admin disabled it; null while active. */
    disabledAt: string | null;
    disabledReason: string | null;
    plan: string | null;
    planLabel: string | null;
    planNote: string | null;
    mailFrom: string | null;
    bookingMailFrom: string;
    lastBookedAt: string | null;
    setup: Record<SetupStep, boolean>;
    openAs: OpenAsMember[];
};

export type ConsoleOptions = {
    plans: Array<{ value: string; label: string }>;
    planFilter: string;
    senders: { default: string; organization: string[] };
};
