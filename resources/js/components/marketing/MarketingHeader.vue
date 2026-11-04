<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, pricing } from '@/routes';
import { store as startDemo } from '@/routes/demo';

const page = usePage();

const name = page.props.name;

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const links = [
    { label: 'Features', href: `${home().url}#features` },
    { label: 'How it works', href: `${home().url}#how-it-works` },
    { label: 'Pricing', href: pricing().url },
];
</script>

<template>
    <header
        class="sticky top-0 z-40 border-b border-border/60 bg-background/75 backdrop-blur-lg"
    >
        <div
            class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
        >
            <Link
                :href="home()"
                class="flex items-center gap-2.5 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <AppLogoIcon class="size-8" />
                <span class="text-lg font-bold tracking-tight">{{ name }}</span>
            </Link>

            <nav class="hidden items-center gap-1 md:flex" aria-label="Primary">
                <Link
                    v-for="link in links"
                    :key="link.label"
                    :href="link.href"
                    class="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {{ link.label }}
                </Link>
            </nav>

            <div class="flex items-center gap-2">
                <AppearanceToggle />
                <Button v-if="page.props.auth.user" as-child>
                    <Link :href="dashboardUrl">Dashboard</Link>
                </Button>
                <template v-else>
                    <Button
                        variant="ghost"
                        class="hidden cursor-pointer sm:inline-flex"
                        as-child
                    >
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button
                        variant="outline"
                        class="hidden cursor-pointer font-semibold md:inline-flex"
                        as-child
                    >
                        <Link
                            :href="startDemo()"
                            as="button"
                            data-test="try-demo"
                        >
                            Try the demo
                        </Link>
                    </Button>
                    <Button class="cursor-pointer font-semibold" as-child>
                        <Link :href="`${pricing().url}#contact`">
                            Contact sales
                        </Link>
                    </Button>
                </template>
            </div>
        </div>
    </header>
</template>
