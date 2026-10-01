<script setup lang="ts">
import { Head, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Copy, ExternalLink } from '@lucide/vue';
import { toast } from 'vue-sonner';
import PageHeader from '@/components/PageHeader.vue';
import EventTypeForm from '@/components/scheduling/EventTypeForm.vue';
import { Button } from '@/components/ui/button';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { index, update } from '@/routes/scheduling';

type Props = {
    eventType: Record<string, any>;
    publicUrl: string;
    kinds: any[];
    locationTypes: any[];
    questionTypes: any[];
    dateRangeTypes: any[];
    schedules: any[];
    teamMembers: any[];
    isSoloOrganization: boolean;
    groups: Array<{ id: number; name: string; memberNames: string[] }>;
    canAssignOwner: boolean;
    currentUser: { id: number; name: string };
};

const props = defineProps<Props>();

const { teamSlug } = useCurrentTeam();

const form = useForm({
    name: props.eventType.name,
    slug: props.eventType.slug,
    description: props.eventType.description ?? '',
    kind: props.eventType.kind,
    color: props.eventType.color,
    duration_minutes: props.eventType.durationMinutes,
    slot_interval_minutes: props.eventType.slotIntervalMinutes,
    buffer_before_minutes: props.eventType.bufferBeforeMinutes,
    buffer_after_minutes: props.eventType.bufferAfterMinutes,
    daily_booking_limit: props.eventType.dailyBookingLimit,
    seats_per_slot: props.eventType.seatsPerSlot,
    date_range_type: props.eventType.dateRangeType,
    rolling_days: props.eventType.rollingDays,
    range_starts_on: props.eventType.rangeStartsOn,
    range_ends_on: props.eventType.rangeEndsOn,
    location_type: props.eventType.locationType,
    location_detail: props.eventType.locationDetail ?? '',
    availability_schedule_id: props.eventType.availabilityScheduleId,
    user_id: props.eventType.ownerId,
    group_id: props.eventType.groupId,
    is_active: props.eventType.isActive,
    is_hidden: props.eventType.isHidden,
    requires_confirmation: props.eventType.requiresConfirmation,
    host_ids: [...props.eventType.hostIds],
    questions: props.eventType.questions.map((question: any) => ({
        ...question,
    })) as any[],
});

const copyLink = async () => {
    await navigator.clipboard.writeText(props.publicUrl);
    toast.success('Booking link copied');
};

setLayoutProps({
    breadcrumbs: [{ title: 'Scheduling', href: index(teamSlug.value) }],
});
</script>

<template>
    <Head :title="eventType.name" />

    <form
        class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6"
        @submit.prevent="
            form.patch(
                update({
                    current_team: teamSlug,
                    event_type: eventType.slug,
                }).url,
            )
        "
    >
        <PageHeader :title="eventType.name" :description="publicUrl">
            <template #actions>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="copyLink"
                    >
                        <Copy class="size-3.5" /> Copy link
                    </Button>
                    <Button type="button" variant="ghost" size="sm" as-child>
                        <a :href="publicUrl" target="_blank">
                            <ExternalLink class="size-3.5" /> Preview
                        </a>
                    </Button>
                </div>
            </template>
        </PageHeader>

        <EventTypeForm
            :form="form"
            :kinds="kinds"
            :location-types="locationTypes"
            :question-types="questionTypes"
            :date-range-types="dateRangeTypes"
            :schedules="schedules"
            :team-members="teamMembers"
            :is-solo-organization="isSoloOrganization"
            :groups="groups"
            :can-assign-owner="canAssignOwner"
            :current-user="currentUser"
            is-editing
        />

        <div class="flex justify-end gap-3 border-t pt-6">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-event-type"
            >
                Save changes
            </Button>
        </div>
    </form>
</template>
