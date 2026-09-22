<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index, store } from '@/routes/api-keys';

/**
 * Keys our other applications use to reach the booking API.
 *
 * The secret is readable exactly once, right after it is issued, so the page
 * is built around copying it then rather than finding it later.
 */
const copied = ref(false);

async function copy(token: string): Promise<void> {
    await navigator.clipboard.writeText(token);
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 2000);
}

function when(value: string | null): string {
    return value ? new Date(value).toLocaleString() : 'Never';
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'API keys',
                href: index(),
            },
        ],
    },
});

defineProps<{
    /** Present only on the request that issued a key. */
    issued?: { name: string; token: string } | null;
    canIssueSystemWide?: boolean;
    /** Only a super admin chooses; everyone else is scoped to their own. */
    organizations?: { id: number; name: string }[];
    keys: {
        id: number;
        name: string;
        organization: string | null;
        prefix: string;
        createdBy: string | null;
        createdAt: string | null;
        lastUsedAt: string | null;
        revokedAt: string | null;
    }[];
}>();
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head title="API keys" />

        <div>
            <h2 class="text-lg font-medium">API keys</h2>
            <p class="text-sm text-pretty text-muted-foreground">
                Keys let our other applications — the client portal and the
                mobile apps — show your booking pages inside their own
                interfaces.
            </p>
        </div>

        <div
            v-if="issued"
            class="flex flex-col gap-3 rounded-xl border border-primary/40 bg-primary/5 p-4"
        >
            <p class="flex items-center gap-2 text-sm font-medium">
                <TriangleAlert class="size-4 shrink-0 text-primary" />
                Copy “{{ issued.name }}” now — it is not shown again.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <code
                    class="min-w-0 flex-1 truncate rounded-lg border border-border bg-card px-3 py-2 font-mono text-sm"
                >
                    {{ issued.token }}
                </code>
                <Button variant="outline" size="sm" @click="copy(issued.token)">
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Only a hash is stored, so nobody — including us — can read it
                back. Lost keys are replaced, not recovered.
            </p>
        </div>

        <Form
            v-bind="store.form()"
            #default="{ processing, errors }"
            class="flex flex-col gap-3"
        >
            <div class="grid gap-2">
                <Label for="name">Name this key</Label>
                <Input
                    id="name"
                    name="name"
                    placeholder="Client portal"
                    class="max-w-sm"
                    required
                />
                <p v-if="errors.name" class="text-sm text-destructive">
                    {{ errors.name }}
                </p>
            </div>
            <div
                v-if="canIssueSystemWide && (organizations?.length ?? 0) > 0"
                class="grid gap-2"
            >
                <Label for="team_id">Organization</Label>
                <select
                    id="team_id"
                    name="team_id"
                    class="h-9 max-w-sm rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option
                        v-for="organization in organizations"
                        :key="organization.id"
                        :value="organization.id"
                    >
                        {{ organization.name }}
                    </option>
                </select>
            </div>

            <label
                v-if="canIssueSystemWide"
                class="flex w-fit items-center gap-2 text-sm"
            >
                <input type="checkbox" name="system_wide" value="1" />
                Let this key read every organization
            </label>
            <Button type="submit" class="w-fit" :disabled="processing">
                <KeyRound class="size-4" />
                Issue a key
            </Button>
        </Form>

        <div class="flex flex-col gap-2">
            <p v-if="keys.length === 0" class="text-sm text-muted-foreground">
                No keys yet.
            </p>

            <ul v-else class="divide-y divide-border rounded-xl border">
                <li
                    v-for="key in keys"
                    :key="key.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 text-sm font-medium">
                            {{ key.name }}
                            <span
                                v-if="key.revokedAt"
                                class="rounded-full border border-border px-2 py-0.5 text-xs font-normal text-muted-foreground"
                            >
                                Revoked
                            </span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            <code class="font-mono">{{ key.prefix }}…</code>
                            <span v-if="key.organization">
                                · {{ key.organization }}</span
                            >
                            <span v-else> · every organization</span>
                            · last used {{ when(key.lastUsedAt) }}
                            <span v-if="key.createdBy">
                                · issued by {{ key.createdBy }}</span
                            >
                        </p>
                    </div>
                    <Form
                        v-if="!key.revokedAt"
                        v-bind="destroy.form(key.id)"
                        #default="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            :disabled="processing"
                        >
                            Revoke
                        </Button>
                    </Form>
                </li>
            </ul>
        </div>
    </div>
</template>
