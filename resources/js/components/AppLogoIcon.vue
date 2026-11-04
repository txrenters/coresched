<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!--
      The CoreSched mark ships as two fixed-colour SVGs in public/: logo.svg
      (navy + green) for light surfaces and logo-dark.svg (white + green) for
      dark ones. An <img> cannot be recoloured by the theme, so both are
      rendered and the .dark variant picks one. Callers size the box;
      object-contain keeps the mark's 218x226 ratio inside whatever square
      they pick. Decorative by default — every caller renders the product
      name alongside it.
    -->
    <span class="inline-block shrink-0" :class="className" v-bind="$attrs">
        <img
            src="/logo.svg"
            alt=""
            width="218"
            height="226"
            decoding="async"
            class="size-full object-contain dark:hidden"
        />
        <img
            src="/logo-dark.svg"
            alt=""
            width="218"
            height="226"
            decoding="async"
            class="hidden size-full object-contain dark:block"
        />
    </span>
</template>
