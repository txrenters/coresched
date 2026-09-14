<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    CalendarClock,
    CalendarX,
    Check,
    Hourglass,
    RefreshCw,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { index as meetingsIndex } from '@/routes/meetings';
import {
    destroy as destroyNotification,
    index as notificationsIndex,
    readAll,
    update as markRead,
} from '@/routes/notifications';

type Notification = {
    id: string;
    type: string;
    title: string;
    body: string;
    bookingUid: string | null;
    whenLabel: string | null;
    teamSlug: string | null;
    readAt: string | null;
    createdAt: string;
};

const page = usePage();

const open = ref(false);
const loading = ref(false);
const loaded = ref(false);
const failed = ref(false);
const notifications = ref<Notification[]>([]);

/** The badge count comes from shared props so it is right on every page load. */
const sharedUnread = computed(
    () => (page.props.unreadNotifications as number | undefined) ?? 0,
);

const unreadCount = computed(() =>
    loaded.value
        ? notifications.value.filter((item) => item.readAt === null).length
        : sharedUnread.value,
);

const badgeLabel = computed(() =>
    unreadCount.value > 9 ? '9+' : String(unreadCount.value),
);

const iconFor = (type: string) => {
    if (type === 'booking.pending') {
        return Hourglass;
    }

    if (type === 'booking.canceled' || type === 'booking.declined') {
        return CalendarX;
    }

    return type === 'booking.rescheduled' ? RefreshCw : CalendarClock;
};

const load = async () => {
    loading.value = true;
    failed.value = false;

    /*
     * A dropped connection, a server error, or an expired session (which
     * redirects to the HTML login page) must not read as "all caught up".
     */
    try {
        const response = await fetch(notificationsIndex().url, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            failed.value = true;

            return;
        }

        const payload = await response.json();
        notifications.value = payload.notifications ?? [];
        loaded.value = true;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

const onOpenChange = (value: boolean) => {
    open.value = value;

    if (value) {
        void load();
    }
};

const markOneRead = (notification: Notification) => {
    if (notification.readAt !== null) {
        return;
    }

    // Reflect it straight away; the request only persists what is already shown.
    notification.readAt = new Date().toISOString();

    router.patch(
        markRead(notification.id).url,
        {},
        { preserveScroll: true, preserveState: true },
    );
};

const markEveryRead = () => {
    const now = new Date().toISOString();
    notifications.value.forEach((item) => (item.readAt ??= now));

    router.post(
        readAll().url,
        {},
        { preserveScroll: true, preserveState: true },
    );
};

const dismiss = (notification: Notification) => {
    notifications.value = notifications.value.filter(
        (item) => item.id !== notification.id,
    );

    router.delete(destroyNotification(notification.id).url, {
        preserveScroll: true,
        preserveState: true,
    });
};

const openMeeting = (notification: Notification) => {
    markOneRead(notification);

    if (notification.teamSlug) {
        open.value = false;
        router.visit(meetingsIndex(notification.teamSlug).url);
    }
};
</script>

<template>
    <DropdownMenu :open="open" @update:open="onOpenChange">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative size-9 cursor-pointer"
                :aria-label="
                    unreadCount > 0
                        ? `Notifications, ${unreadCount} unread`
                        : 'Notifications'
                "
                data-test="notification-bell"
            >
                <Bell class="size-4" aria-hidden="true" />
                <span
                    v-if="unreadCount > 0"
                    class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground"
                    data-numeric
                    aria-hidden="true"
                >
                    {{ badgeLabel }}
                </span>
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-88 p-0">
            <div
                class="flex items-center justify-between gap-2 border-b border-border px-4 py-3"
            >
                <p class="text-sm font-semibold">Notifications</p>
                <Button
                    v-if="unreadCount > 0"
                    variant="ghost"
                    size="sm"
                    class="h-7 cursor-pointer text-xs font-medium"
                    data-test="notification-read-all"
                    @click="markEveryRead"
                >
                    Mark all read
                </Button>
            </div>

            <div v-if="loading && !loaded" class="space-y-2 p-4">
                <Skeleton
                    v-for="index in 3"
                    :key="index"
                    class="h-14 w-full animate-pulse rounded-lg"
                />
                <span class="sr-only">Loading notifications</span>
            </div>

            <div
                v-else-if="failed && !loaded"
                class="px-4 py-10 text-center"
                role="alert"
                data-test="notifications-error"
            >
                <TriangleAlert
                    class="mx-auto size-5 text-destructive"
                    aria-hidden="true"
                />
                <p class="mt-2 text-sm font-medium">
                    Notifications could not be loaded
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-3 cursor-pointer"
                    :disabled="loading"
                    @click="load"
                >
                    Try again
                </Button>
            </div>

            <ul
                v-else-if="notifications.length"
                class="max-h-96 divide-y divide-border overflow-y-auto"
            >
                <li
                    v-for="notification in notifications"
                    :key="notification.id"
                    class="relative"
                    :data-test="`notification-${notification.id}`"
                >
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-start gap-3 py-3 pr-10 pl-4 text-left transition-colors duration-200 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:-outline-offset-2 focus-visible:outline-none"
                        :class="
                            notification.readAt === null ? 'bg-accent/40' : ''
                        "
                        @click="openMeeting(notification)"
                    >
                        <component
                            :is="iconFor(notification.type)"
                            class="mt-0.5 size-4 shrink-0"
                            :class="
                                notification.readAt === null
                                    ? 'text-primary'
                                    : 'text-muted-foreground'
                            "
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                {{ notification.title }}
                                <span
                                    v-if="notification.readAt === null"
                                    class="size-1.5 shrink-0 rounded-full bg-primary"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="notification.readAt === null"
                                    class="sr-only"
                                >
                                    Unread
                                </span>
                            </span>
                            <span
                                class="mt-0.5 block text-sm text-muted-foreground"
                                data-wrap-anywhere
                            >
                                {{ notification.body }}
                            </span>
                            <span
                                v-if="notification.whenLabel"
                                class="mt-1 block text-xs text-muted-foreground"
                                data-numeric
                            >
                                {{ notification.whenLabel }}
                            </span>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="absolute top-3 right-2 inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors duration-200 hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :aria-label="`Dismiss: ${notification.title}`"
                        @click.stop="dismiss(notification)"
                    >
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </li>
            </ul>

            <div v-else class="px-4 py-10 text-center">
                <Check
                    class="mx-auto size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="mt-2 text-sm font-medium">You are all caught up</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    New bookings and changes show up here.
                </p>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
