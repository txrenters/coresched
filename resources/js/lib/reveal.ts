import type { Directive } from 'vue';

let observer: IntersectionObserver | null = null;

function sharedObserver(): IntersectionObserver {
    observer ??= new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    observer?.unobserve(entry.target);
                }
            }
        },
        { rootMargin: '0px 0px -10% 0px' },
    );

    return observer;
}

/**
 * Fades an element up into place the first time it scrolls into view. The
 * styles live in app.css under [data-reveal]. An optional value staggers it:
 * `v-reveal="150"` waits 150ms. Browsers without IntersectionObserver show the
 * element straight away.
 */
export const vReveal: Directive<HTMLElement, number | undefined> = {
    mounted(element, binding) {
        element.dataset.reveal = '';

        if (binding.value) {
            element.style.setProperty('--reveal-delay', `${binding.value}ms`);
        }

        if (typeof IntersectionObserver === 'undefined') {
            element.classList.add('is-revealed');

            return;
        }

        sharedObserver().observe(element);
    },
    unmounted(element) {
        observer?.unobserve(element);
    },
};
