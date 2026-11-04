<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home, login, pricing } from '@/routes';

const name = usePage().props.name;

const links = [
    { label: 'Features', href: `${home().url}#features` },
    { label: 'Pricing', href: pricing().url },
    { label: 'Contact sales', href: `${pricing().url}#contact` },
    { label: 'Log in', href: login().url },
];
</script>

<template>
    <footer class="border-t border-border">
        <div
            class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-10 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
            <div>
                <div class="flex items-center gap-2.5">
                    <AppLogoIcon class="size-6" />
                    <span class="font-semibold text-foreground">{{
                        name
                    }}</span>
                </div>
                <p class="mt-2">Scheduling software for service teams.</p>
            </div>

            <nav class="flex flex-wrap gap-x-6 gap-y-2" aria-label="Footer">
                <Link
                    v-for="link in links"
                    :key="link.label"
                    :href="link.href"
                    class="hover:text-foreground"
                >
                    {{ link.label }}
                </Link>
            </nav>

            <p>&copy; {{ new Date().getFullYear() }} {{ name }}</p>
        </div>
    </footer>
</template>
