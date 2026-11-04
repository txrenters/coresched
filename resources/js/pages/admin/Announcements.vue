<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Megaphone } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    endBanner,
    index as announcementsIndex,
    store,
} from '@/routes/admin/announcements';

type Announcement = {
    id: number;
    title: string;
    body: string;
    recipientsCount: number;
    authorName: string | null;
    sentAt: string | null;
    bannerLive: boolean;
    bannerUntil: string | null;
};

defineProps<{
    announcements: Announcement[];
    recipientsCount: number;
    bannerDays: number[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Announcements', href: announcementsIndex() }],
    },
});

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <Head title="Announcements" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Announcements"
            description="Tell everyone on the platform something at once. It lands in each person's notification bell, and can also show as a banner across the app."
        />

        <Form
            v-slot="{ errors, processing }"
            v-bind="store.form()"
            reset-on-success
            class="max-w-2xl space-y-5 rounded-xl border border-border bg-card p-5 shadow-flat"
            data-test="announcement-form"
        >
            <div class="grid gap-2">
                <Label for="announcement-title">Title</Label>
                <Input
                    id="announcement-title"
                    name="title"
                    maxlength="120"
                    required
                    placeholder="CoreSched is moving to coresched.com"
                />
                <InputError :message="errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="announcement-body">Message</Label>
                <Textarea
                    id="announcement-body"
                    name="body"
                    rows="4"
                    maxlength="1000"
                    required
                    placeholder="Tonight at 6 pm Central we move to coresched.com. Your links keep working; you'll just sign in once on the new address."
                />
                <InputError :message="errors.body" />
            </div>

            <div class="grid gap-2">
                <Label for="announcement-banner">Banner across the app</Label>
                <select
                    id="announcement-banner"
                    name="banner_days"
                    :class="selectClass"
                >
                    <option value="">No banner, bell only</option>
                    <option
                        v-for="days in bannerDays"
                        :key="days"
                        :value="days"
                    >
                        Show for {{ days === 1 ? '1 day' : `${days} days` }}
                    </option>
                </select>
                <InputError :message="errors.banner_days" />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    Goes to {{ recipientsCount }}
                    {{ recipientsCount === 1 ? 'person' : 'people' }}. Super
                    admins and the demo are left out. No email is sent.
                </p>
                <Button
                    class="cursor-pointer font-semibold"
                    :disabled="processing"
                    data-test="send-announcement"
                >
                    <Spinner v-if="processing" />
                    Send announcement
                </Button>
            </div>
        </Form>

        <section aria-labelledby="sent-heading" class="max-w-2xl">
            <h2 id="sent-heading" class="text-base font-semibold">Sent</h2>

            <ul v-if="announcements.length" class="mt-3 flex flex-col gap-3">
                <li
                    v-for="announcement in announcements"
                    :key="announcement.id"
                    class="rounded-xl border border-border bg-card p-5 shadow-flat"
                    data-test="announcement"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex flex-wrap items-center gap-2 font-medium"
                            >
                                {{ announcement.title }}
                                <span
                                    v-if="announcement.bannerLive"
                                    class="rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground"
                                >
                                    Banner on
                                </span>
                            </p>
                            <p
                                class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                                data-wrap-anywhere
                            >
                                {{ announcement.body }}
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                {{ announcement.sentAt }}
                                <template v-if="announcement.authorName">
                                    · by {{ announcement.authorName }}
                                </template>
                                · {{ announcement.recipientsCount }}
                                {{
                                    announcement.recipientsCount === 1
                                        ? 'person'
                                        : 'people'
                                }}
                                <template v-if="announcement.bannerLive">
                                    · banner until
                                    {{ announcement.bannerUntil }}
                                </template>
                            </p>
                        </div>
                        <Button
                            v-if="announcement.bannerLive"
                            variant="outline"
                            size="sm"
                            class="cursor-pointer"
                            as-child
                        >
                            <Link
                                :href="endBanner(announcement.id)"
                                as="button"
                                preserve-scroll
                                data-test="end-banner"
                            >
                                End banner
                            </Link>
                        </Button>
                    </div>
                </li>
            </ul>

            <div
                v-else
                class="mt-3 rounded-xl border border-dashed border-border p-10 text-center"
            >
                <Megaphone
                    class="mx-auto size-6 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="mt-3 font-medium">No announcements yet</p>
            </div>
        </section>
    </div>
</template>
