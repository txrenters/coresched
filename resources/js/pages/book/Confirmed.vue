<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    CalendarX,
    Check,
    Clock,
    ExternalLink,
    Globe,
    MapPin,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

type Props = {
    booking: {
        uid: string;
        status: string;
        eventTypeName: string;
        hostNames: string[];
        inviteeName: string;
        inviteeEmail: string;
        localDate: string;
        localTime: string;
        timezone: string;
        locationLabel: string;
        locationDetail: string | null;
        meetingUrl: string | null;
        notes: string | null;
        guests: string[];
        answers: Array<{ label: string; answer: string | null }>;
        isChangeable: boolean;
        cancellationReason: string | null;
        rescheduleUrl: string;
        cancelUrl: string;
    };
};

const props = defineProps<Props>();

const isCanceled = computed(
    () =>
        props.booking.status === 'canceled' ||
        props.booking.status === 'rescheduled',
);

const isPending = computed(() => props.booking.status === 'pending');

const headline = computed(() => {
    if (isCanceled.value) {
        return 'This booking is canceled';
    }

    return isPending.value ? 'Booking request sent' : 'You are booked';
});

const subline = computed(() => {
    if (isCanceled.value) {
        return 'Nothing is on the calendar any more.';
    }

    return isPending.value
        ? "You'll get a confirmation email once a host approves it."
        : 'A calendar invitation is on its way to your inbox.';
});
</script>

<template>
    <Head :title="headline" />

    <div class="min-h-svh bg-background px-4 py-12 sm:py-20">
        <div
            class="mx-auto w-full max-w-lg overflow-hidden rounded-2xl border border-border bg-card shadow-raised"
        >
            <div class="border-b border-border p-8 text-center">
                <span
                    :class="[
                        'mx-auto flex size-12 items-center justify-center rounded-full',
                        isCanceled || isPending
                            ? 'bg-muted text-muted-foreground'
                            : 'bg-success-muted text-success-muted-foreground',
                    ]"
                >
                    <CalendarX
                        v-if="isCanceled"
                        class="size-6"
                        aria-hidden="true"
                    />
                    <Clock
                        v-else-if="isPending"
                        class="size-6"
                        aria-hidden="true"
                    />
                    <Check v-else class="size-6" aria-hidden="true" />
                </span>

                <h1 class="mt-4 text-xl font-bold tracking-tight">
                    {{ headline }}
                </h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    {{ subline }}
                </p>
            </div>

            <dl class="space-y-5 p-8 text-sm">
                <div>
                    <dt
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Event
                    </dt>
                    <dd class="mt-1 font-semibold" data-wrap-anywhere>
                        {{ booking.eventTypeName }}
                    </dd>
                </div>
                <div>
                    <dt
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Who
                    </dt>
                    <dd class="mt-1" data-wrap-anywhere>
                        {{ booking.hostNames.join(', ') }} and
                        {{ booking.inviteeName }}
                    </dd>
                </div>
                <div>
                    <dt
                        class="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground uppercase"
                    >
                        <Clock class="size-3.5" aria-hidden="true" /> When
                    </dt>
                    <dd
                        class="mt-1 font-medium"
                        :class="{
                            'text-muted-foreground line-through': isCanceled,
                        }"
                        data-numeric
                    >
                        {{ booking.localDate }}<br />
                        {{ booking.localTime }}
                    </dd>
                    <dd
                        class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <Globe class="size-3" aria-hidden="true" />
                        {{ booking.timezone }}
                    </dd>
                </div>
                <div>
                    <dt
                        class="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground uppercase"
                    >
                        <MapPin class="size-3.5" aria-hidden="true" /> Where
                    </dt>
                    <dd class="mt-1">
                        {{ booking.locationLabel }}
                        <a
                            v-if="booking.meetingUrl && !isCanceled"
                            :href="booking.meetingUrl"
                            class="mt-1.5 inline-flex items-center gap-1.5 rounded-md font-medium text-primary underline underline-offset-2 hover:no-underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            data-wrap-anywhere
                        >
                            {{ booking.meetingUrl }}
                            <ExternalLink
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                        </a>
                        <span
                            v-else-if="booking.locationDetail"
                            class="mt-1 block text-muted-foreground"
                            data-wrap-anywhere
                        >
                            {{ booking.locationDetail }}
                        </span>
                    </dd>
                </div>
                <div v-if="booking.guests.length">
                    <dt
                        class="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground uppercase"
                    >
                        <Users class="size-3.5" aria-hidden="true" /> Guests
                    </dt>
                    <dd class="mt-1" data-wrap-anywhere>
                        {{ booking.guests.join(', ') }}
                    </dd>
                </div>
                <div v-for="answer in booking.answers" :key="answer.label">
                    <dt
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        {{ answer.label }}
                    </dt>
                    <dd class="mt-1" data-wrap-anywhere>{{ answer.answer }}</dd>
                </div>
                <div v-if="booking.cancellationReason">
                    <dt
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Reason
                    </dt>
                    <dd class="mt-1" data-wrap-anywhere>
                        {{ booking.cancellationReason }}
                    </dd>
                </div>
            </dl>

            <div
                v-if="booking.isChangeable"
                class="flex flex-col gap-3 border-t border-border bg-muted/50 p-6 sm:flex-row"
            >
                <Button
                    variant="outline"
                    class="flex-1 cursor-pointer bg-card font-semibold"
                    as-child
                >
                    <a :href="booking.rescheduleUrl">Reschedule</a>
                </Button>
                <Button variant="ghost" class="flex-1 cursor-pointer" as-child>
                    <a :href="booking.cancelUrl" data-test="cancel-link">
                        Cancel
                    </a>
                </Button>
            </div>
        </div>
    </div>
</template>
