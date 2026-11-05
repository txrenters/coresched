import type { Auth } from '@/types/auth';
import type { Team } from '@/types/teams';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            canCreateTeam: boolean;
            canSwitchTeams: boolean;
            canViewUsers: boolean;
            canViewOrganizations: boolean;
            canManageApiKeys: boolean;
            canManageOrganization: boolean;
            /** Set while a super admin is signed in as this user. */
            impersonator: { name: string } | null;
            /** Set while a visitor is exploring the shared public demo. */
            isDemo: boolean;
            /** The newest announcement whose banner is still up. */
            announcement: { id: number; title: string; body: string } | null;
            /** Set on the public demo's booking pages. */
            isDemoBookingPage: boolean;
            /** Umami settings, for signed-out visitors when configured. */
            analytics: { scriptUrl: string; websiteId: string } | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
