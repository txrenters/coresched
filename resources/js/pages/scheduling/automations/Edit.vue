<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    Bold,
    Italic,
    Link2,
    List,
    ListOrdered,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { index, store, update } from '@/routes/automations';

type Option = { value: number; label: string };

type Automation = {
    id: number;
    name: string;
    trigger: string;
    offsetMinutes: number | null;
    channels: string[];
    recipient: string;
    recipientEmails: string[];
    recipientPhones: string[];
    subject: string | null;
    body: string | null;
    smsBody: string | null;
    isActive: boolean;
    eventTypeIds: number[];
};

type Props = {
    automation: Automation | null;
    eventTypes: Option[];
    triggerOptions: Array<{
        value: string;
        label: string;
        requiresOffset: boolean;
    }>;
    recipientOptions: Array<{
        value: string;
        label: string;
        needsAddress: boolean;
    }>;
    channelOptions: Array<{ value: string; label: string }>;
    variables: Array<{ name: string; description: string }>;
    /** What text messages need beyond the wording: an account, and a number. */
    sms: {
        configured: boolean;
        fromNumber: string | null;
        settingsUrl: string;
    };
};

const props = defineProps<Props>();

const { teamSlug } = useCurrentTeam();

/**
 * Show the offset back in the largest unit it divides into cleanly, so a
 * workflow saved as "1 day" does not reopen as "1440 minutes".
 */
const splitOffset = (minutes: number | null) => {
    if (!minutes) {
        return { value: minutes === 0 ? 0 : 1, unit: 'hours' as const };
    }

    if (minutes % 1440 === 0) {
        return { value: minutes / 1440, unit: 'days' as const };
    }

    if (minutes % 60 === 0) {
        return { value: minutes / 60, unit: 'hours' as const };
    }

    return { value: minutes, unit: 'minutes' as const };
};

const initialOffset = splitOffset(props.automation?.offsetMinutes ?? null);

const offsetValue = ref(initialOffset.value);
const offsetUnit = ref<'minutes' | 'hours' | 'days'>(initialOffset.unit);

const form = useForm({
    name: props.automation?.name ?? '',
    trigger: props.automation?.trigger ?? 'booked',
    offset_minutes: props.automation?.offsetMinutes ?? null,
    channels: [...(props.automation?.channels ?? ['email'])],
    recipient: props.automation?.recipient ?? 'someone',
    recipient_emails: [...(props.automation?.recipientEmails ?? [])],
    recipient_phones: [...(props.automation?.recipientPhones ?? [])],
    subject: props.automation?.subject ?? '',
    body: props.automation?.body ?? '',
    sms_body: props.automation?.smsBody ?? '',
    is_active: props.automation?.isActive ?? true,
    event_type_ids: [...(props.automation?.eventTypeIds ?? [])],
});

const requiresOffset = computed(
    () =>
        props.triggerOptions.find((option) => option.value === form.trigger)
            ?.requiresOffset ?? false,
);

const needsAddress = computed(
    () =>
        props.recipientOptions.find((option) => option.value === form.recipient)
            ?.needsAddress ?? false,
);

const sendsEmail = computed(() => form.channels.includes('email'));
const sendsText = computed(() => form.channels.includes('sms'));

/** Text messages go nowhere until an admin picks the number they come from. */
const textIsReady = computed(
    () => props.sms.configured && Boolean(props.sms.fromNumber),
);

const toggleChannel = (channel: string, checked: boolean) => {
    form.channels = checked
        ? [...form.channels, channel]
        : form.channels.filter((current) => current !== channel);
};

const appliesToAll = computed(() => form.event_type_ids.length === 0);

const toggleEventType = (id: number, checked: boolean) => {
    form.event_type_ids = checked
        ? [...form.event_type_ids, id]
        : form.event_type_ids.filter((current) => current !== id);
};

/**
 * A list's own rule reports on its key, one bad entry on `key.N`; show
 * whichever the server sent, since only the list itself has a field.
 */
const listErrorFor = (key: string) => {
    const errors = form.errors as Record<string, string>;

    return (
        errors[key] ??
        Object.entries(errors).find(([field]) =>
            field.startsWith(`${key}.`),
        )?.[1]
    );
};

const emailDraft = ref('');
const emailError = ref<string | null>(null);

/** Take the address that has been typed and add it to the list. */
const addEmail = () => {
    const email = emailDraft.value.trim().toLowerCase();

    if (!email) {
        return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        emailError.value = 'Enter a valid email address.';

        return;
    }

    if (form.recipient_emails.includes(email)) {
        emailError.value = 'That address is already on the list.';

        return;
    }

    form.recipient_emails.push(email);
    emailDraft.value = '';
    emailError.value = null;
};

const phoneDraft = ref('');
const phoneError = ref<string | null>(null);

/** Take the number that has been typed and add it to the list. */
const addPhone = () => {
    const phone = phoneDraft.value.trim();
    const digits = phone.replace(/[^0-9]/g, '');

    if (!phone) {
        return;
    }

    const dialable =
        (phone.startsWith('+') && digits.length > 0) ||
        digits.length === 10 ||
        (digits.length === 11 && digits.startsWith('1'));

    if (!dialable) {
        phoneError.value =
            'Enter a number as ten digits, or with its country code.';

        return;
    }

    if (form.recipient_phones.includes(phone)) {
        phoneError.value = 'That number is already on the list.';

        return;
    }

    form.recipient_phones.push(phone);
    phoneDraft.value = '';
    phoneError.value = null;
};

/** Write a variable the way it is typed into a message. */
const token = (name: string) => `{{ ${name} }}`;

const subjectPlaceholder = `New booking: ${token('event_name')}`;

const bodyField = ref<InstanceType<typeof Textarea> | null>(null);
const smsField = ref<InstanceType<typeof Textarea> | null>(null);

/** Which message the variable buttons write into. */
const activeField = ref<'body' | 'sms_body'>('body');

const fieldElement = (field: 'body' | 'sms_body') =>
    (
        (field === 'body' ? bodyField.value : smsField.value) as unknown as {
            $el?: HTMLTextAreaElement;
        }
    )?.$el ?? null;

/**
 * Rewrite whatever is selected in a message, leaving the result selected so a
 * second click on the same button is aimed at the same words.
 */
const replaceSelection = (
    field: 'body' | 'sms_body',
    rewrite: (selected: string) => string,
) => {
    const element = fieldElement(field);
    const current = field === 'body' ? form.body : form.sms_body;

    if (!element) {
        form[field] = current + rewrite('');

        return;
    }

    const start = element.selectionStart ?? current.length;
    const end = element.selectionEnd ?? current.length;
    const written = rewrite(current.slice(start, end));

    form[field] = current.slice(0, start) + written + current.slice(end);

    requestAnimationFrame(() => {
        element.focus();
        element.setSelectionRange(start, start + written.length);
    });
};

/** Drop a variable into whichever message was last being written. */
const insertVariable = (name: string) => {
    const field = sendsEmail.value ? activeField.value : 'sms_body';

    replaceSelection(field, () => token(name));
};

/** Wrap the selection in the markdown that formats it. */
const wrap = (marker: string, placeholder: string) =>
    replaceSelection(
        'body',
        (selected) => `${marker}${selected || placeholder}${marker}`,
    );

const addLink = () =>
    replaceSelection(
        'body',
        (selected) => `[${selected || 'link text'}](https://)`,
    );

/**
 * Turn each selected line into a list item. Markdown numbers an ordered list
 * itself, so every line can be written as "1." and still come out counting.
 */
const addList = (ordered: boolean) =>
    replaceSelection('body', (selected) =>
        (selected || 'First item')
            .split('\n')
            .map(
                (line) =>
                    (ordered ? '1. ' : '- ') +
                    line.replace(/^[-*]\s+|^\d+\.\s+/, ''),
            )
            .join('\n'),
    );

/** Twilio bills per 160 character segment, so the count is worth showing. */
const smsSegments = computed(() =>
    Math.max(1, Math.ceil(form.sms_body.length / 160)),
);

const submit = () => {
    const minutesPerUnit = { minutes: 1, hours: 60, days: 1440 };

    form.offset_minutes = requiresOffset.value
        ? Number(offsetValue.value || 0) * minutesPerUnit[offsetUnit.value]
        : null;

    if (props.automation) {
        form.patch(
            update({
                current_team: teamSlug.value,
                automation: props.automation.id,
            }).url,
        );

        return;
    }

    form.post(store(teamSlug.value).url);
};

setLayoutProps({
    breadcrumbs: [{ title: 'Workflows', href: index(teamSlug.value) }],
});
</script>

<template>
    <Head :title="automation ? automation.name : 'New workflow'" />

    <form
        class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6"
        @submit.prevent="submit"
    >
        <PageHeader
            :title="automation ? automation.name : 'New workflow'"
            description="Pick what it applies to, when it fires, and what it sends."
        >
            <template #actions>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 text-sm">
                        <Switch
                            :model-value="form.is_active"
                            data-test="automation-active"
                            @update:model-value="
                                (value) => (form.is_active = Boolean(value))
                            "
                        />
                        On
                    </label>
                    <Button type="button" variant="outline" as-child>
                        <Link :href="index(teamSlug)">Cancel</Link>
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-automation"
                    >
                        {{ automation ? 'Save workflow' : 'Create workflow' }}
                    </Button>
                </div>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <section
                    class="rounded-lg border border-border bg-card p-5 shadow-flat"
                >
                    <div class="grid gap-2">
                        <Label for="automation-name">Name</Label>
                        <Input
                            id="automation-name"
                            v-model="form.name"
                            placeholder="Email reminder to someone else"
                            data-test="automation-name"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                </section>

                <section
                    class="rounded-lg border border-border bg-card p-5 shadow-flat"
                >
                    <h2 class="font-medium">
                        Which event types will this apply to?
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Tick none and it applies to every event type in this
                        organization, including ones added later.
                    </p>

                    <p
                        v-if="appliesToAll"
                        class="mt-3 rounded-md bg-muted px-3 py-2 text-sm"
                    >
                        Applies to all event types.
                    </p>

                    <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                        <li
                            v-for="eventType in eventTypes"
                            :key="eventType.value"
                        >
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox
                                    :model-value="
                                        form.event_type_ids.includes(
                                            eventType.value,
                                        )
                                    "
                                    :data-test="`event-type-${eventType.value}`"
                                    @update:model-value="
                                        (value) =>
                                            toggleEventType(
                                                eventType.value,
                                                Boolean(value),
                                            )
                                    "
                                />
                                {{ eventType.label }}
                            </label>
                        </li>
                    </ul>
                    <InputError :message="form.errors.event_type_ids" />
                </section>

                <section
                    class="rounded-lg border border-border bg-card p-5 shadow-flat"
                >
                    <h2 class="font-medium">When this happens</h2>

                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="automation-trigger">Trigger</Label>
                            <select
                                id="automation-trigger"
                                v-model="form.trigger"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                data-test="automation-trigger"
                            >
                                <option
                                    v-for="option in triggerOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.trigger" />
                        </div>

                        <div v-if="requiresOffset" class="grid gap-2">
                            <Label for="automation-offset">How long</Label>
                            <div class="flex gap-2">
                                <Input
                                    id="automation-offset"
                                    v-model="offsetValue"
                                    type="number"
                                    min="0"
                                    class="w-24"
                                    data-test="automation-offset"
                                />
                                <select
                                    v-model="offsetUnit"
                                    class="h-9 flex-1 rounded-md border border-input bg-transparent px-3 text-sm"
                                    data-test="automation-offset-unit"
                                    aria-label="Unit"
                                >
                                    <option value="minutes">minutes</option>
                                    <option value="hours">hours</option>
                                    <option value="days">days</option>
                                </select>
                            </div>
                            <InputError :message="form.errors.offset_minutes" />
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-lg border border-border bg-card p-5 shadow-flat"
                >
                    <h2 class="font-medium">Do this</h2>

                    <div class="mt-3 grid gap-4">
                        <div class="grid gap-2">
                            <Label>Send</Label>
                            <div class="flex flex-wrap gap-4">
                                <label
                                    v-for="option in channelOptions"
                                    :key="option.value"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.channels.includes(option.value)
                                        "
                                        :data-test="`channel-${option.value}`"
                                        @update:model-value="
                                            (value) =>
                                                toggleChannel(
                                                    option.value,
                                                    Boolean(value),
                                                )
                                        "
                                    />
                                    {{ option.label }}
                                </label>
                            </div>
                            <InputError :message="form.errors.channels" />
                        </div>

                        <div class="grid gap-2 sm:max-w-sm">
                            <Label for="automation-recipient">Send to</Label>
                            <select
                                id="automation-recipient"
                                v-model="form.recipient"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                data-test="automation-recipient"
                            >
                                <option
                                    v-for="option in recipientOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.recipient" />
                        </div>

                        <div
                            v-if="sendsEmail"
                            class="grid gap-4 rounded-md border border-border p-4"
                        >
                            <h3 class="text-sm font-medium">Email</h3>

                            <div v-if="needsAddress" class="grid gap-2">
                                <Label for="automation-email">
                                    Email addresses
                                </Label>
                                <div class="flex gap-2">
                                    <Input
                                        id="automation-email"
                                        v-model="emailDraft"
                                        type="email"
                                        inputmode="email"
                                        placeholder="leasing@texasrenters.com"
                                        data-test="automation-email"
                                        @keydown.enter.prevent="addEmail"
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        data-test="add-automation-email"
                                        @click="addEmail"
                                    >
                                        Add
                                    </Button>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Everyone here gets their own copy, so no one
                                    sees the rest of the list.
                                </p>
                                <InputError
                                    :message="
                                        emailError ??
                                        listErrorFor('recipient_emails')
                                    "
                                />

                                <ul
                                    v-if="form.recipient_emails.length"
                                    class="flex flex-wrap gap-2"
                                >
                                    <li
                                        v-for="(
                                            email, index
                                        ) in form.recipient_emails"
                                        :key="email"
                                        class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-border bg-muted py-1 pr-1 pl-3 text-sm"
                                    >
                                        <span class="min-w-0 truncate">
                                            {{ email }}
                                        </span>
                                        <button
                                            type="button"
                                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :aria-label="`Remove ${email}`"
                                            @click="
                                                form.recipient_emails.splice(
                                                    index,
                                                    1,
                                                )
                                            "
                                        >
                                            <X
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <div class="grid gap-2">
                                <Label for="automation-subject">Subject</Label>
                                <Input
                                    id="automation-subject"
                                    v-model="form.subject"
                                    :placeholder="subjectPlaceholder"
                                    data-test="automation-subject"
                                />
                                <InputError :message="form.errors.subject" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="automation-body">Message</Label>
                                <div
                                    class="overflow-hidden rounded-md border border-input"
                                >
                                    <div
                                        class="flex items-center gap-1 border-b border-input bg-muted/50 px-1.5 py-1"
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-7"
                                            aria-label="Bold"
                                            data-test="format-bold"
                                            @click="wrap('**', 'bold text')"
                                        >
                                            <Bold class="size-3.5" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-7"
                                            aria-label="Italic"
                                            data-test="format-italic"
                                            @click="wrap('*', 'italic text')"
                                        >
                                            <Italic class="size-3.5" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-7"
                                            aria-label="Link"
                                            data-test="format-link"
                                            @click="addLink"
                                        >
                                            <Link2 class="size-3.5" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-7"
                                            aria-label="Bulleted list"
                                            data-test="format-list"
                                            @click="addList(false)"
                                        >
                                            <List class="size-3.5" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-7"
                                            aria-label="Numbered list"
                                            data-test="format-ordered-list"
                                            @click="addList(true)"
                                        >
                                            <ListOrdered class="size-3.5" />
                                        </Button>
                                    </div>
                                    <Textarea
                                        id="automation-body"
                                        ref="bodyField"
                                        v-model="form.body"
                                        rows="10"
                                        class="rounded-none border-0 shadow-none focus-visible:ring-0"
                                        data-test="automation-body"
                                        @focus="activeField = 'body'"
                                    />
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Formatting is markdown, so you can type it
                                    by hand too: **bold**, *italic*,
                                    [text](link) and lines starting with - for a
                                    list.
                                </p>
                                <InputError :message="form.errors.body" />
                            </div>
                        </div>

                        <div
                            v-if="sendsText"
                            class="grid gap-4 rounded-md border border-border p-4"
                        >
                            <h3 class="text-sm font-medium">Text message</h3>

                            <p
                                v-if="!textIsReady"
                                class="flex items-start gap-2 rounded-md bg-muted px-3 py-2 text-sm"
                                data-test="sms-not-ready"
                            >
                                <TriangleAlert
                                    class="mt-0.5 size-4 shrink-0 text-destructive"
                                />
                                <span v-if="!sms.configured">
                                    Twilio is not set up on this installation,
                                    so nothing can be texted yet.
                                </span>
                                <span v-else>
                                    This organization has not chosen a number to
                                    text from.
                                    <a
                                        :href="sms.settingsUrl"
                                        class="underline underline-offset-4"
                                    >
                                        Pick one in the organization settings.
                                    </a>
                                </span>
                            </p>

                            <div v-if="needsAddress" class="grid gap-2">
                                <Label for="automation-phone">
                                    Phone numbers
                                </Label>
                                <div class="flex gap-2">
                                    <Input
                                        id="automation-phone"
                                        v-model="phoneDraft"
                                        type="tel"
                                        inputmode="tel"
                                        placeholder="(512) 555-0100"
                                        data-test="automation-phone"
                                        @keydown.enter.prevent="addPhone"
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        data-test="add-automation-phone"
                                        @click="addPhone"
                                    >
                                        Add
                                    </Button>
                                </div>
                                <InputError
                                    :message="
                                        phoneError ??
                                        listErrorFor('recipient_phones')
                                    "
                                />

                                <ul
                                    v-if="form.recipient_phones.length"
                                    class="flex flex-wrap gap-2"
                                >
                                    <li
                                        v-for="(
                                            phone, index
                                        ) in form.recipient_phones"
                                        :key="phone"
                                        class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-border bg-muted py-1 pr-1 pl-3 text-sm"
                                    >
                                        <span class="min-w-0 truncate">
                                            {{ phone }}
                                        </span>
                                        <button
                                            type="button"
                                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :aria-label="`Remove ${phone}`"
                                            @click="
                                                form.recipient_phones.splice(
                                                    index,
                                                    1,
                                                )
                                            "
                                        >
                                            <X
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <div class="grid gap-2">
                                <Label for="automation-sms-body">Message</Label>
                                <Textarea
                                    id="automation-sms-body"
                                    ref="smsField"
                                    v-model="form.sms_body"
                                    rows="4"
                                    data-test="automation-sms-body"
                                    @focus="activeField = 'sms_body'"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Plain text, no formatting.
                                    {{ form.sms_body.length }} characters,
                                    {{ smsSegments }}
                                    {{
                                        smsSegments === 1
                                            ? 'segment'
                                            : 'segments'
                                    }}.
                                </p>
                                <InputError :message="form.errors.sms_body" />
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <aside
                class="h-fit rounded-lg border border-border bg-card p-5 shadow-flat"
            >
                <h2 class="font-medium">Variables</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Click one to drop it into the message you are writing. It is
                    replaced with the meeting's own details when it goes out.
                </p>

                <ul class="mt-4 flex flex-col gap-2">
                    <li v-for="variable in variables" :key="variable.name">
                        <button
                            type="button"
                            class="w-full rounded-md border border-border px-3 py-2 text-left transition-colors hover:bg-accent hover:text-accent-foreground"
                            :data-test="`variable-${variable.name}`"
                            @click="insertVariable(variable.name)"
                        >
                            <code class="text-xs">
                                {{ token(variable.name) }}
                            </code>
                            <span
                                class="mt-0.5 block text-xs text-muted-foreground"
                            >
                                {{ variable.description }}
                            </span>
                        </button>
                    </li>
                </ul>
            </aside>
        </div>
    </form>
</template>
