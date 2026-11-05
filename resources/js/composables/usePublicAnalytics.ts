import { usePage } from '@inertiajs/vue3';
import { onMounted } from 'vue';

declare global {
    interface Window {
        umami?: { track: () => void };
    }
}

const SCRIPT_ID = 'umami-analytics';

/**
 * Count a page view on a public marketing page.
 *
 * The script loads with auto-tracking off: this is an Inertia SPA, so Umami's
 * own history tracking would keep counting after the visitor clicks through to
 * sign in. Only components that call this record a view. The server shares
 * `analytics` only for signed-out visitors and only when Umami is configured.
 */
export function usePublicAnalytics(): void {
    const page = usePage();

    onMounted(() => {
        const analytics = page.props.analytics;

        if (!analytics) {
            return;
        }

        if (window.umami) {
            window.umami.track();

            return;
        }

        if (document.getElementById(SCRIPT_ID)) {
            return;
        }

        const script = document.createElement('script');
        script.id = SCRIPT_ID;
        script.defer = true;
        script.src = analytics.scriptUrl;
        script.dataset.websiteId = analytics.websiteId;
        script.dataset.autoTrack = 'false';
        script.dataset.doNotTrack = 'true';
        script.addEventListener('load', () => window.umami?.track());
        document.head.appendChild(script);
    });
}
