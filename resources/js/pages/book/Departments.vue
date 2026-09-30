<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowRight, Users } from '@lucide/vue';

type Props = {
    page: {
        slug: string;
        name: string;
        description: string | null;
        logoUrl: string | null;
        websiteUrl: string | null;
        websiteName: string | null;
    };
    organizationName: string;
    departments: Array<{
        slug: string;
        name: string;
        description: string | null;
        eventTypeCount: number;
        url: string;
    }>;
};

defineProps<Props>();
</script>

<template>
    <Head :title="page.name" />

    <div class="min-h-svh bg-background px-4 py-12 sm:py-20">
        <div class="mx-auto w-full max-w-2xl">
            <header class="text-center">
                <!--
                  Decorative: the organisation name is rendered right below it,
                  so the logo would only repeat the same information.
                -->
                <img
                    v-if="page.logoUrl"
                    :src="page.logoUrl"
                    alt=""
                    class="mx-auto mb-5 h-16 w-auto max-w-52 object-contain"
                />
                <p class="mb-2 text-sm font-medium text-muted-foreground">
                    {{ organizationName }}
                </p>
                <!-- A page name can be a whole sentence; keep that from dominating. -->
                <h1
                    class="font-bold tracking-tight text-balance"
                    :class="
                        page.name.length > 40
                            ? 'text-2xl sm:text-3xl'
                            : 'text-3xl sm:text-4xl'
                    "
                    data-wrap-anywhere
                >
                    {{ page.name }}
                </h1>
                <p
                    class="mx-auto mt-3 max-w-prose leading-relaxed text-muted-foreground"
                >
                    {{
                        page.description ??
                        'Choose a department to see what you can book with them.'
                    }}
                </p>
            </header>

            <ul
                v-if="departments.length"
                class="mt-10 grid gap-3 sm:grid-cols-2"
            >
                <li v-for="department in departments" :key="department.slug">
                    <a
                        :href="department.url"
                        :data-test="`department-${department.slug}`"
                        class="group flex h-full items-stretch gap-4 rounded-xl border border-border bg-card p-5 shadow-flat transition-colors duration-200 hover:border-primary hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <span class="min-w-0 flex-1">
                            <span
                                class="block font-semibold"
                                data-wrap-anywhere
                            >
                                {{ department.name }}
                            </span>
                            <span
                                v-if="department.description"
                                class="mt-1 block text-sm leading-relaxed text-muted-foreground"
                                data-wrap-anywhere
                            >
                                {{ department.description }}
                            </span>
                            <span
                                class="mt-3 block text-xs font-medium text-muted-foreground"
                                data-numeric
                            >
                                {{ department.eventTypeCount }}
                                {{
                                    department.eventTypeCount === 1
                                        ? 'meeting type'
                                        : 'meeting types'
                                }}
                            </span>
                        </span>
                        <ArrowRight
                            class="mt-0.5 size-4 shrink-0 self-center text-muted-foreground transition-colors duration-200 group-hover:text-primary"
                            aria-hidden="true"
                        />
                    </a>
                </li>
            </ul>

            <div
                v-else
                class="mt-10 rounded-xl border border-dashed border-border px-6 py-12 text-center"
            >
                <Users
                    class="mx-auto size-6 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="mt-3 font-medium">No departments yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    There is nothing open to book on this page yet. Check back
                    soon.
                </p>
            </div>

            <p v-if="page.websiteUrl" class="mt-10 text-center text-sm">
                <a
                    :href="page.websiteUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-md font-medium text-primary underline underline-offset-2 hover:no-underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    {{ page.websiteName ?? page.name }} website
                </a>
            </p>
        </div>
    </div>
</template>
