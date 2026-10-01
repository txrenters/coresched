import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Highlight the item for every URL under these paths, not just an exact match. */
    activePrefix?: string | string[];
    /**
     * Not an Inertia page. Opens in a new tab through a plain anchor, rather
     * than an Inertia visit that would receive HTML it cannot render.
     */
    external?: boolean;
};
