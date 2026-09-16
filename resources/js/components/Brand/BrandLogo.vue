<script setup>
/**
 * Full logo lockup: mark plus wordmark.
 *
 * The wordmark is live text rather than outlined paths, so it stays crisp at
 * any size and inherits the theme's text colour. That works because Sora is
 * self hosted and already loaded by the time this renders.
 */
import { computed } from 'vue';

import BrandMark from './BrandMark.vue';

const props = defineProps({
    /** 'horizontal' for headers and navs, 'stacked' for hero and auth screens. */
    layout: { type: String, default: 'horizontal' },
    size: { type: String, default: 'md' },
    /** Hide the "Solutions" descender on very tight bars. */
    showTagline: { type: Boolean, default: true },
    variant: { type: String, default: 'gradient' },
});

/** In monochrome the whole lockup inherits currentColor, mark and words alike. */
const mono = computed(() => props.variant !== 'gradient');

const markSize = { sm: 24, md: 32, lg: 44, xl: 64 };
const titleSize = {
    sm: 'text-base',
    md: 'text-lg',
    lg: 'text-2xl',
    xl: 'text-4xl',
};
</script>

<template>
    <div
        :class="[
            'flex items-center gap-2.5 select-none',
            layout === 'stacked' && 'flex-col gap-3 text-center',
        ]"
    >
        <BrandMark :size="markSize[size]" :variant="variant" />

        <div :class="layout === 'stacked' ? 'leading-tight' : 'leading-none'">
            <div
                :class="[
                    'font-[family-name:var(--font-display)] font-semibold tracking-tight',
                    titleSize[size],
                ]"
                :style="mono ? 'color: currentColor' : 'color: var(--text-strong)'"
            >
                unbound<span :class="mono ? '' : 'text-gradient'">byte</span>
            </div>
            <div
                v-if="showTagline"
                class="mt-0.5 text-[0.62rem] font-medium uppercase tracking-[0.22em]"
                :class="mono ? 'opacity-70' : ''"
                :style="mono ? 'color: currentColor' : 'color: var(--text-muted)'"
            >
                Solutions
            </div>
        </div>
    </div>
</template>
