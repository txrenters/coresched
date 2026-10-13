<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    KeyRound,
    Pencil,
    Trash2,
    TriangleAlert,
    Webhook,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, forget, index, store, update } from '@/routes/api-keys';

/**
 * Keys our other applications use to reach the booking API.
 *
 * The secret is readable exactly once, right after it is issued, so the page
 * is built around copying it then rather than finding it later.
 */
const copied = ref(false);

/** The key whose settings are open, if any. */
const editing = ref<number | null>(null);

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
    /** Present only on the request that gave a key a webhook signing secret. */
    issuedWebhookSecret?: { name: string; secret: string } | null;
    webhookScopes: { value: string; label: string }[];
    keys: {
        id: number;
        name: string;
        prefix: string;
        createdBy: string | null;
        createdAt: string | null;
        lastUsedAt: string | null;
        revokedAt: string | null;
        webhookUrl: string | null;
        webhookScope: string;
        canWrite: boolean;
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
                interfaces. A key can also name a webhook endpoint, to be told
                about bookings as they are made, moved or cancelled.
            </p>
        </div>

        <div
            v-if="issuedWebhookSecret"
            class="flex flex-col gap-3 rounded-xl border border-primary/40 bg-primary/5 p-4"
        >
            <p class="flex items-center gap-2 text-sm font-medium">
                <TriangleAlert class="size-4 shrink-0 text-primary" />
                Copy the webhook signing secret for “{{
                    issuedWebhookSecret.name
                }}” now — it is not shown again.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <code
                    class="min-w-0 flex-1 truncate rounded-lg border border-border bg-card px-3 py-2 font-mono text-sm"
                >
                    {{ issuedWebhookSecret.secret }}
                </code>
                <Button
                    variant="outline"
                    size="sm"
                    @click="copy(issuedWebhookSecret.secret)"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Give it to the receiving application. Every delivery carries an
                <code class="font-mono">X-Bookings-Signature</code> header:
                sha256 HMAC of “timestamp.body” with this secret.
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
                            · last used {{ when(key.lastUsedAt) }}
                            <span v-if="key.createdBy">
                                · issued by {{ key.createdBy }}</span
                            >
                        </p>
                        <p
                            v-if="key.webhookUrl"
                            class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <Webhook class="size-3.5 shrink-0" />
                            <span class="truncate">{{ key.webhookUrl }}</span>
                            <span class="shrink-0">
                                ·
                                {{
                                    webhookScopes.find(
                                        (scope) =>
                                            scope.value === key.webhookScope,
                                    )?.label
                                }}
                            </span>
                        </p>
                    </div>
                    <div v-if="!key.revokedAt" class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="
                                editing = editing === key.id ? null : key.id
                            "
                        >
                            <Pencil class="size-4" />
                            Edit
                        </Button>
                        <Form
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
                    </div>

                    <!-- Revoking is what stops it working; this clears the row. -->
                    <Form
                        v-else
                        v-bind="forget.form(key.id)"
                        #default="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            :disabled="processing"
                        >
                            <Trash2 class="size-4" />
                            Delete
                        </Button>
                    </Form>

                    <Form
                        v-if="editing === key.id && !key.revokedAt"
                        v-bind="update.form(key.id)"
                        :options="{ preserveScroll: true }"
                        class="flex w-full flex-col gap-3 rounded-lg border border-border bg-muted/40 p-3"
                        @success="editing = null"
                        #default="{ processing, errors }"
                    >
                        <div class="grid gap-2">
                            <Label :for="`name-${key.id}`">Name</Label>
                            <Input
                                :id="`name-${key.id}`"
                                name="name"
                                :default-value="key.name"
                                class="max-w-sm"
                                required
                            />
                            <p
                                v-if="errors.name"
                                class="text-sm text-destructive"
                            >
                                {{ errors.name }}
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`webhook-url-${key.id}`">
                                Webhook endpoint
                            </Label>
                            <Input
                                :id="`webhook-url-${key.id}`"
                                name="webhook_url"
                                type="url"
                                :default-value="key.webhookUrl ?? ''"
                                placeholder="https://example.com/api/bookings/webhook"
                            />
                            <p class="text-xs text-muted-foreground">
                                Leave empty to stop webhooks for this key.
                            </p>
                            <p
                                v-if="errors.webhook_url"
                                class="text-sm text-destructive"
                            >
                                {{ errors.webhook_url }}
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`webhook-scope-${key.id}`">
                                Report
                            </Label>
                            <select
                                :id="`webhook-scope-${key.id}`"
                                name="webhook_scope"
                                class="h-9 max-w-sm rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                <option
                                    v-for="scope in webhookScopes"
                                    :key="scope.value"
                                    :value="scope.value"
                                    :selected="scope.value === key.webhookScope"
                                >
                                    {{ scope.label }}
                                </option>
                            </select>
                            <p
                                v-if="errors.webhook_scope"
                                class="text-sm text-destructive"
                            >
                                {{ errors.webhook_scope }}
                            </p>
                        </div>
                        <label
                            v-if="key.webhookUrl"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                name="rotate_secret"
                                value="1"
                                class="size-4 rounded border-input"
                            />
                            Make a new signing secret (the old one stops
                            verifying at once)
                        </label>
                        <label class="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="can_write"
                                value="1"
                                :checked="key.canWrite"
                                class="mt-0.5 size-4 rounded border-input"
                            />
                            <span>
                                Allow writes: put people on a meeting's rota and
                                save their hours.
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    Only for an application that keeps its own
                                    people in step with this one, such as the
                                    CRM. Reading never needs it.
                                </span>
                            </span>
                        </label>
                        <div class="flex gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="processing"
                            >
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="editing = null"
                            >
                                Cancel
                            </Button>
                        </div>
                    </Form>
                </li>
            </ul>
        </div>
    </div>
</template>
