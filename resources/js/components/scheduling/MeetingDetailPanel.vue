<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Clock,
    FileText,
    Globe,
    Mail,
    MapPin,
    RotateCcw,
    Smartphone,
    Trash2,
    Users,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { approve } from '@/routes/meetings';
import { update as updateNotes } from '@/routes/meetings/notes';

type Meeting = {
    uid: string;
    status: string;
    statusLabel: string;
    fullDateLabel: string;
    timeWithZone: string;
    eventTypeName: string;
    inviteeName: string;
    inviteeInitials: string;
    inviteeEmail: string;
    inviteePhone: string | null;
    inviteeTimezone: string;
    notes: string | null;
    hostNotes: string | null;
    meetingUrl: string | null;
    locationLabel: string;
    locationDetail: string | null;
    hostNames: string[];
    guests: string[];
    answers: Array<{ label: string; answer: string | null }>;
    timeline: Array<{
        label: string;
        detail: string | null;
        at: string;
        atLabel: string;
    }>;
    canCancel: boolean;
    canApprove: boolean;
    rescheduleUrl: string;
    cancellationReason: string | null;
};

type Props = {
    meeting: Meeting | null;
    teamSlug: string;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'cancel'): void;
    (e: 'decline'): void;
}>();

const tab = ref<'details' | 'notes'>('details');

const notesForm = useForm({ host_notes: '' });

watch(
    () => props.meeting,
    (meeting) => {
        tab.value = 'details';
        notesForm.clearErrors();
        notesForm.host_notes = meeting?.hostNotes ?? '';
    },
);

const saveNotes = () => {
    if (!props.meeting) {
        return;
    }

    notesForm.patch(
        updateNotes({
            current_team: props.teamSlug,
            booking: props.meeting.uid,
        }).url,
        { preserveScroll: true },
    );
};

const approveMeeting = () => {
    if (!props.meeting) {
        return;
    }

    router.post(
        approve({
            current_team: props.teamSlug,
            booking: props.meeting.uid,
        }).url,
        {},
        { preserveScroll: true, onSuccess: () => emit('close') },
    );
};
</script>

<template>
    <Sheet
        :open="meeting !== null"
        @update:open="(value) => !value && emit('close')"
    >
        <SheetContent
            v-if="meeting"
            side="right"
            class="w-full gap-0 overflow-y-auto sm:max-w-md"
            data-test="meeting-detail-panel"
        >
            <SheetHeader class="gap-1">
                <p
                    v-if="meeting.status === 'pending'"
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <Clock class="size-3.5" /> Pending approval
                </p>
                <p
                    v-else-if="meeting.status !== 'confirmed'"
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <RotateCcw class="size-3.5" /> {{ meeting.statusLabel }}
                </p>

                <SheetTitle>{{ meeting.eventTypeName }}</SheetTitle>

                <p class="text-sm text-muted-foreground">
                    {{ meeting.fullDateLabel }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ meeting.timeWithZone }}
                </p>

                <div v-if="meeting.canApprove" class="mt-3 flex gap-2">
                    <Button
                        size="sm"
                        class="rounded-full"
                        data-test="panel-approve"
                        @click="approveMeeting"
                    >
                        <Check class="size-3.5" /> Approve
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-full text-destructive hover:text-destructive"
                        data-test="panel-decline"
                        @click="emit('decline')"
                    >
                        <Trash2 class="size-3.5" /> Decline
                    </Button>
                </div>

                <div v-if="meeting.canCancel" class="mt-3 flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        as-child
                    >
                        <a :href="meeting.rescheduleUrl" target="_blank">
                            <RotateCcw class="size-3.5" /> Reschedule
                        </a>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-full text-destructive hover:text-destructive"
                        data-test="panel-cancel"
                        @click="emit('cancel')"
                    >
                        <Trash2 class="size-3.5" /> Cancel
                    </Button>
                </div>
            </SheetHeader>

            <div class="border-b px-4">
                <nav class="flex gap-4" aria-label="Meeting detail">
                    <button
                        v-for="option in [
                            { value: 'details', label: 'Details' },
                            { value: 'notes', label: 'Notes' },
                        ]"
                        :key="option.value"
                        type="button"
                        :data-test="`meeting-tab-${option.value}`"
                        :class="[
                            'cursor-pointer border-b-2 px-1 pb-2 text-sm transition-colors',
                            tab === option.value
                                ? 'border-primary font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ]"
                        @click="tab = option.value as 'details' | 'notes'"
                    >
                        {{ option.label }}
                    </button>
                </nav>
            </div>

            <div v-if="tab === 'details'" class="flex flex-col gap-6 px-4 py-5">
                <section>
                    <h3 class="mb-3 font-semibold">Invitees</h3>

                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-8 items-center justify-center rounded-full bg-muted text-xs font-medium"
                        >
                            {{ meeting.inviteeInitials }}
                        </span>
                        <span>{{ meeting.inviteeName }}</span>
                    </div>

                    <dl class="mt-4 space-y-2.5 text-sm">
                        <div class="flex items-center gap-2.5">
                            <Mail
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <a
                                :href="`mailto:${meeting.inviteeEmail}`"
                                class="truncate hover:underline"
                            >
                                {{ meeting.inviteeEmail }}
                            </a>
                        </div>
                        <div
                            v-if="meeting.inviteePhone"
                            class="flex items-center gap-2.5"
                        >
                            <Smartphone
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {{ meeting.inviteePhone }}
                        </div>
                        <div class="flex items-center gap-2.5">
                            <Globe
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {{ meeting.inviteeTimezone }}
                        </div>
                        <div
                            v-if="meeting.guests.length"
                            class="flex items-start gap-2.5"
                        >
                            <Users
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            <span>{{ meeting.guests.join(', ') }}</span>
                        </div>
                    </dl>
                </section>

                <section
                    v-if="meeting.answers.length || meeting.notes"
                    class="border-t pt-5"
                >
                    <h3 class="mb-3 font-semibold">Questions</h3>

                    <div class="space-y-4 text-sm">
                        <div v-if="meeting.notes">
                            <p class="font-medium">
                                Please share anything that will help prepare for
                                our meeting.
                            </p>
                            <p class="mt-0.5 text-muted-foreground">
                                {{ meeting.notes }}
                            </p>
                        </div>
                        <div
                            v-for="answer in meeting.answers"
                            :key="answer.label"
                        >
                            <p class="font-medium">{{ answer.label }}</p>
                            <p class="mt-0.5 text-muted-foreground">
                                {{ answer.answer }}
                            </p>
                        </div>
                    </div>
                </section>

                <section v-if="meeting.timeline.length" class="border-t pt-5">
                    <h3 class="mb-3 font-semibold">Timeline</h3>

                    <ol class="space-y-4 text-sm">
                        <li
                            v-for="entry in meeting.timeline"
                            :key="`${entry.label}-${entry.at}`"
                            class="relative flex gap-3 pl-1"
                            data-test="timeline-entry"
                        >
                            <span
                                class="mt-1.5 size-2 shrink-0 rounded-full bg-primary"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">
                                <span class="block font-medium">
                                    {{ entry.label }}
                                </span>
                                <span
                                    v-if="entry.detail"
                                    class="block text-muted-foreground"
                                >
                                    {{ entry.detail }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    data-numeric
                                >
                                    {{ entry.atLabel }}
                                </span>
                            </span>
                        </li>
                    </ol>
                </section>

                <section class="border-t pt-5">
                    <h3 class="mb-3 font-semibold">Location</h3>

                    <div class="flex items-start gap-2.5 text-sm">
                        <MapPin
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="min-w-0">
                            <a
                                v-if="meeting.meetingUrl"
                                :href="meeting.meetingUrl"
                                target="_blank"
                                class="break-all text-primary underline"
                            >
                                {{ meeting.meetingUrl }}
                            </a>
                            <template v-else-if="meeting.locationDetail">
                                {{ meeting.locationLabel }} &middot;
                                {{ meeting.locationDetail }}
                            </template>
                            <template v-else>No location added</template>
                        </span>
                    </div>
                </section>

                <section class="border-t pt-5">
                    <h3 class="mb-3 font-semibold">Hosts</h3>

                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="host in meeting.hostNames"
                            :key="host"
                            class="flex items-center gap-2.5"
                        >
                            <span
                                class="flex size-8 items-center justify-center rounded-full bg-muted text-xs font-medium"
                            >
                                {{ host.charAt(0) }}
                            </span>
                            {{ host }}
                        </li>
                    </ul>
                </section>

                <section
                    v-if="meeting.cancellationReason"
                    class="border-t pt-5 text-sm"
                >
                    <h3 class="mb-1 font-semibold">Cancellation reason</h3>
                    <p class="text-muted-foreground">
                        {{ meeting.cancellationReason }}
                    </p>
                </section>
            </div>

            <div v-else class="flex flex-col gap-3 px-4 py-5">
                <p
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <FileText class="size-3.5" />
                    Only hosts can see these notes.
                </p>

                <Textarea
                    v-model="notesForm.host_notes"
                    rows="10"
                    placeholder="Jot down anything worth remembering about this meeting."
                    data-test="host-notes"
                    :aria-invalid="Boolean(notesForm.errors.host_notes)"
                />
                <InputError :message="notesForm.errors.host_notes" />

                <Button
                    class="self-start"
                    :disabled="notesForm.processing"
                    data-test="save-host-notes"
                    @click="saveNotes"
                >
                    Save notes
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
