export type TeamRole = 'admin' | 'member';

export type Team = {
    id: number;
    name: string;
    slug: string;
    role?: TeamRole;
    roleLabel?: string;
    isCurrent?: boolean;
    /** Branding, only sent by the organization settings endpoint. */
    logoUrl?: string | null;
    welcomeMessage?: string | null;
    websiteUrl?: string | null;
    timezone?: string;
    /** The Twilio number this organization's text messages come from. */
    smsFromNumber?: string | null;
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
    /** The organization's only administrator, so they cannot be demoted or removed. */
    isLastAdmin?: boolean;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type TeamPermissions = {
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
    canManageApiKeys: boolean;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};
