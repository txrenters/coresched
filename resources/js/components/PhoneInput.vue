<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * A US phone number with the +1 already in place.
 *
 * The invitee types, or pastes, the ten digits in any shape; the model holds
 * the number as +1 followed by those digits, which is the E.164 form the
 * server stores and Twilio dials. A pasted "+1 (346) 239-9213" or
 * "13462399213" loses its leading 1 rather than doubling the country code.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

const model = defineModel<string>({ default: '' });

const nationalDigits = (value: string): string => {
    const digits = value.replace(/\D/g, '');

    return (
        digits.length > 10 && digits.startsWith('1') ? digits.slice(1) : digits
    ).slice(0, 10);
};

const display = computed(() => {
    const digits = nationalDigits(model.value);

    if (digits.length <= 3) {
        return digits;
    }

    if (digits.length <= 6) {
        return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
    }

    return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
});

const update = (value: string | number) => {
    const digits = nationalDigits(String(value));

    model.value = digits === '' ? '' : `+1${digits}`;
};
</script>

<template>
    <div class="relative">
        <!--
          An image rather than the flag emoji, which Windows shows as the
          letters "US". The file holds the flag's own colours so no component
          has to.
        -->
        <span
            class="pointer-events-none absolute inset-y-0 left-0 flex items-center gap-1.5 pl-3 text-sm text-muted-foreground"
            aria-hidden="true"
        >
            <img
                src="/flag-us.svg"
                alt=""
                class="h-3 w-auto rounded-[2px] shadow-flat"
            />
            +1
        </span>
        <Input
            type="tel"
            inputmode="tel"
            autocomplete="tel-national"
            placeholder="(555) 555-0100"
            :class="cn('pl-15', props.class)"
            :model-value="display"
            v-bind="$attrs"
            @update:model-value="update"
        />
    </div>
</template>
