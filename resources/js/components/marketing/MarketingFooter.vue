<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home, login, pricing, privacy, terms } from '@/routes';

const name = usePage().props.name;

const columns = [
    {
        heading: 'Product',
        links: [
            { label: 'Features', href: `${home().url}#features` },
            { label: 'Pricing', href: pricing().url },
            { label: 'Contact sales', href: `${pricing().url}#contact` },
        ],
    },
    {
        heading: 'Legal',
        links: [
            { label: 'Terms of Service', href: terms().url },
            { label: 'Privacy Policy', href: privacy().url },
        ],
    },
    {
        heading: 'Account',
        links: [{ label: 'Log in', href: login().url }],
    },
];
</script>

<template>
    <footer class="border-t border-border bg-card/40">
        <div class="mx-auto w-full max-w-6xl px-4 sm:px-6">
            <div
                class="grid gap-10 py-12 text-sm sm:grid-cols-2 lg:grid-cols-5"
            >
                <div class="lg:col-span-2">
                    <div class="flex items-center gap-2.5">
                        <AppLogoIcon class="size-7" />
                        <span class="text-base font-semibold text-foreground">
                            {{ name }}
                        </span>
                    </div>
                    <p class="mt-3 max-w-xs leading-6 text-muted-foreground">
                        Scheduling software for service teams. One link, the
                        right person, every calendar in sync.
                    </p>
                </div>

                <nav
                    v-for="column in columns"
                    :key="column.heading"
                    :aria-label="column.heading"
                >
                    <h2
                        class="text-xs font-semibold tracking-wider text-foreground uppercase"
                    >
                        {{ column.heading }}
                    </h2>
                    <ul class="mt-4 space-y-3">
                        <li v-for="link in column.links" :key="link.label">
                            <Link
                                :href="link.href"
                                class="text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {{ link.label }}
                            </Link>
                        </li>
                    </ul>
                </nav>
            </div>

            <div
                class="flex flex-col gap-2 border-t border-border py-6 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
            >
                <p>
                    &copy; {{ new Date().getFullYear() }} {{ name }}. All rights
                    reserved.
                </p>
                <p>
                    Developed and maintained by
                    <a
                        href="https://corepathbpo.com"
                        target="_blank"
                        rel="noopener"
                        class="font-medium text-foreground underline-offset-4 hover:underline"
                        >corepathbpo.com</a
                    >
                </p>
            </div>
        </div>
    </footer>
</template>
