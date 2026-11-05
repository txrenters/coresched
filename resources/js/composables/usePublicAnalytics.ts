import { usePage } from '@inertiajs/vue3';
import { onMounted } from 'vue';

const SCRIPT_ID = 'cf-web-analytics';

/**
 * Load Cloudflare Web Analytics on a public marketing page.
 *
 * The beacon runs with `spa: false`, so it counts the page the visitor landed
 * on and does not follow Inertia's client-side navigation -- otherwise it
 * would keep counting after the visitor clicks through to sign in. Only
 * components that call this load it, and the server shares `analytics` only
 * for signed-out visitors when a token is configured.
 */
export function usePublicAnalytics(): void {
    const page = usePage();

    onMounted(() => {
        const analytics = page.props.analytics;

        if (!analytics || document.getElementById(SCRIPT_ID)) {
            return;
        }

        const script = document.createElement('script');
        script.id = SCRIPT_ID;
        script.defer = true;
        script.src = 'https://static.cloudflareinsights.com/beacon.min.js';
        script.dataset.cfBeacon = JSON.stringify({
            token: analytics.token,
            spa: false,
        });
        document.head.appendChild(script);
    });
}
