<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ImageUp, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import DeleteTeamModal from '@/components/DeleteTeamModal.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { edit, index, update } from '@/routes/teams';
import type { Team, TeamPermissions } from '@/types';

type Props = {
    team: Team;
    permissions: TeamPermissions;
    timezones: string[];
    /**
     * The organization's own Twilio account. The Auth Token never reaches the
     * page; tokenHint is its last four characters. accountSid, tokenHint and
     * numbers are null/empty for anyone who cannot change them.
     */
    sms: {
        configured: boolean;
        accountSid: string | null;
        tokenHint: string | null;
        numbers: Array<{ number: string; label: string }>;
    };
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            {
                title: 'Organizations',
                href: index(),
            },
            {
                title: props.team.name,
                href: edit(props.team.slug),
            },
        ],
    }),
});

const deleteDialogOpen = ref(false);

const pageTitle = computed(() =>
    props.permissions.canUpdateTeam
        ? `Edit ${props.team.name}`
        : `View ${props.team.name}`,
);

/*
  The logo makes this a multipart submit, and Laravel cannot read multipart on
  a PATCH, so the profile posts with a spoofed method.
*/
const profileForm = useForm({
    _method: 'patch',
    name: props.team.name,
    logo: null as File | null,
    remove_logo: false as boolean,
    welcome_message: props.team.welcomeMessage ?? '',
    website_url: props.team.websiteUrl ?? '',
    timezone: props.team.timezone ?? '',
    sms_from_number: props.team.smsFromNumber ?? '',
    twilio_account_sid: props.sms.accountSid ?? '',
    // Write-only: blank means "keep the saved token".
    twilio_auth_token: '',
    remove_twilio: false as boolean,
});

const logoInput = ref<HTMLInputElement | null>(null);
const logoPreview = ref<string | null>(null);

/** What the logo slot should show right now: a new pick, the stored file, or nothing. */
const shownLogo = computed(() => {
    if (logoPreview.value) {
        return logoPreview.value;
    }

    return profileForm.remove_logo ? null : (props.team.logoUrl ?? null);
});

const selectLogo = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (!file) {
        return;
    }

    if (logoPreview.value) {
        URL.revokeObjectURL(logoPreview.value);
    }

    profileForm.logo = file;
    profileForm.remove_logo = false;
    logoPreview.value = URL.createObjectURL(file);
};

const clearLogo = () => {
    if (logoPreview.value) {
        URL.revokeObjectURL(logoPreview.value);
        logoPreview.value = null;
    }

    profileForm.logo = null;
    profileForm.remove_logo = true;

    if (logoInput.value) {
        logoInput.value.value = '';
    }
};

const disconnectTwilio = () => {
    profileForm.remove_twilio = true;
    submitProfile();
};

const submitProfile = () => {
    profileForm.post(update(props.team.slug).url, {
        preserveScroll: true,
        onSuccess: () => {
            if (logoPreview.value) {
                URL.revokeObjectURL(logoPreview.value);
                logoPreview.value = null;
            }

            profileForm.logo = null;
            profileForm.remove_logo = false;
            profileForm.twilio_auth_token = '';
            profileForm.remove_twilio = false;
            profileForm.twilio_account_sid = props.sms.accountSid ?? '';
            profileForm.sms_from_number = props.team.smsFromNumber ?? '';
        },
    });
};
</script>

<template>
    <Head :title="pageTitle" />

    <h1 class="sr-only">{{ pageTitle }}</h1>

    <div class="flex flex-col space-y-10">
        <!-- Organization Name Section -->
        <div v-if="permissions.canUpdateTeam" class="space-y-6">
            <Heading
                variant="small"
                title="Organization settings"
                description="Update your organization name and settings"
            />

            <form class="space-y-6" @submit.prevent="submitProfile">
                <!-- Logo -->
                <div class="grid gap-2">
                    <Label for="logo">Logo</Label>
                    <p class="text-xs text-muted-foreground">
                        Shown on your public booking page. PNG, JPG or WebP, up
                        to 2 MB.
                    </p>
                    <div class="mt-1 flex flex-wrap items-center gap-4">
                        <span
                            class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-muted"
                        >
                            <img
                                v-if="shownLogo"
                                :src="shownLogo"
                                :alt="`${team.name} logo`"
                                class="size-full object-contain"
                            />
                            <ImageUp
                                v-else
                                class="size-5 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </span>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                class="cursor-pointer"
                                @click="logoInput?.click()"
                            >
                                {{ shownLogo ? 'Replace' : 'Upload' }}
                            </Button>
                            <Button
                                v-if="shownLogo"
                                type="button"
                                variant="ghost"
                                class="cursor-pointer text-destructive hover:text-destructive"
                                @click="clearLogo"
                            >
                                <Trash2 class="size-4" aria-hidden="true" />
                                Remove
                            </Button>
                        </div>

                        <input
                            id="logo"
                            ref="logoInput"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="sr-only"
                            data-test="team-logo-input"
                            @change="selectLogo"
                        />
                    </div>
                    <InputError :message="profileForm.errors.logo" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Organization name</Label>
                    <Input
                        id="name"
                        v-model="profileForm.name"
                        name="name"
                        data-test="team-name-input"
                        required
                        :aria-invalid="Boolean(profileForm.errors.name)"
                    />
                    <InputError :message="profileForm.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="welcome_message">Welcome message</Label>
                    <Textarea
                        id="welcome_message"
                        v-model="profileForm.welcome_message"
                        rows="3"
                        aria-describedby="welcome_message-help"
                        data-test="team-welcome-input"
                    />
                    <p
                        id="welcome_message-help"
                        class="text-xs text-muted-foreground"
                    >
                        Greets people at the top of your booking page.
                    </p>
                    <InputError :message="profileForm.errors.welcome_message" />
                </div>

                <div class="grid gap-2">
                    <Label for="website_url">Website</Label>
                    <Input
                        id="website_url"
                        v-model="profileForm.website_url"
                        type="url"
                        inputmode="url"
                        placeholder="https://example.com"
                        data-test="team-website-input"
                        :aria-invalid="Boolean(profileForm.errors.website_url)"
                    />
                    <InputError :message="profileForm.errors.website_url" />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Default time zone</Label>
                    <Select v-model="profileForm.timezone">
                        <SelectTrigger
                            id="timezone"
                            class="w-full cursor-pointer sm:max-w-sm"
                            data-test="team-timezone-select"
                        >
                            <SelectValue placeholder="Choose a time zone" />
                        </SelectTrigger>
                        <SelectContent class="max-h-72">
                            <SelectItem
                                v-for="zone in timezones"
                                :key="zone"
                                :value="zone"
                            >
                                {{ zone }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="profileForm.errors.timezone" />
                </div>

                <fieldset
                    class="grid gap-4 rounded-lg border border-border p-4"
                    data-test="team-twilio-settings"
                >
                    <legend class="px-1 text-sm font-medium">
                        Text messages (Twilio)
                    </legend>
                    <p class="-mt-1 text-sm text-muted-foreground">
                        Workflows text through this organization's own Twilio
                        account. Find the Account SID and Auth Token on the
                        Twilio Console's account dashboard. Without them, this
                        organization's workflows send email only.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="twilio-account-sid">Account SID</Label>
                            <Input
                                id="twilio-account-sid"
                                v-model="profileForm.twilio_account_sid"
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="AC…"
                                data-test="team-twilio-sid"
                            />
                            <InputError
                                :message="profileForm.errors.twilio_account_sid"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="twilio-auth-token">Auth Token</Label>
                            <Input
                                id="twilio-auth-token"
                                v-model="profileForm.twilio_auth_token"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="
                                    sms.tokenHint
                                        ? `Saved, ends in ${sms.tokenHint}`
                                        : 'Your Twilio Auth Token'
                                "
                                data-test="team-twilio-token"
                            />
                            <p
                                v-if="sms.tokenHint"
                                class="text-xs text-muted-foreground"
                            >
                                Leave blank to keep the saved token.
                            </p>
                            <InputError
                                :message="profileForm.errors.twilio_auth_token"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="sms-from-number">Text messages from</Label>
                        <Select
                            v-if="sms.numbers.length"
                            v-model="profileForm.sms_from_number"
                        >
                            <SelectTrigger
                                id="sms-from-number"
                                class="w-full cursor-pointer sm:max-w-sm"
                                data-test="team-sms-number-select"
                            >
                                <SelectValue placeholder="Choose a number" />
                            </SelectTrigger>
                            <SelectContent class="max-h-72">
                                <SelectItem
                                    v-for="number in sms.numbers"
                                    :key="number.number"
                                    :value="number.number"
                                >
                                    {{ number.label }} — {{ number.number }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-else class="text-sm text-muted-foreground">
                            {{
                                sms.configured
                                    ? 'No numbers were found on this Twilio account.'
                                    : 'Save your Twilio account first, then choose which of its numbers to text from.'
                            }}
                        </p>
                        <InputError
                            :message="profileForm.errors.sms_from_number"
                        />
                    </div>

                    <div v-if="sms.configured">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="cursor-pointer"
                            data-test="team-twilio-disconnect"
                            :disabled="profileForm.processing"
                            @click="disconnectTwilio"
                        >
                            Disconnect Twilio
                        </Button>
                    </div>
                </fieldset>

                <div class="flex items-center gap-4">
                    <Button
                        type="submit"
                        class="cursor-pointer font-semibold"
                        data-test="team-save-button"
                        :disabled="profileForm.processing"
                    >
                        {{ profileForm.processing ? 'Saving…' : 'Save' }}
                    </Button>
                </div>
            </form>
        </div>

        <div v-else class="space-y-6">
            <Heading variant="small" :title="team.name" />
        </div>

        <!-- Danger Zone -->
        <div v-if="permissions.canDeleteTeam" class="space-y-6">
            <Heading
                variant="small"
                title="Delete organization"
                description="Permanently delete your organization"
            />
            <div
                class="space-y-4 rounded-lg border border-destructive/25 bg-destructive/5 p-4"
            >
                <div class="relative space-y-0.5 text-destructive">
                    <p class="font-medium">Warning</p>
                    <p class="text-sm">
                        Please proceed with caution, this cannot be undone.
                    </p>
                </div>
                <Button
                    data-test="delete-team-button"
                    variant="destructive"
                    @click="deleteDialogOpen = true"
                    >Delete organization</Button
                >
            </div>
        </div>
    </div>

    <DeleteTeamModal
        v-if="permissions.canDeleteTeam"
        :team="team"
        :open="deleteDialogOpen"
        @update:open="deleteDialogOpen = $event"
    />
</template>
