<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    name: { type: String, default: '' },
    src: { type: String, default: null },
    size: { type: String, default: 'md' },
});

const failed = ref(false);
watch(() => props.src, () => { failed.value = false; });

const sizes = {
    xs: 'h-6 w-6 text-[0.6rem]',
    sm: 'h-8 w-8 text-xs',
    md: 'h-10 w-10 text-sm',
    lg: 'h-14 w-14 text-lg',
};

const initials = computed(() =>
    props.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('') || '?',
);

/** Stable colour per person, so the same user always looks the same. */
const hue = computed(() => {
    let hash = 0;
    for (const char of props.name) {
        hash = (hash * 31 + char.charCodeAt(0)) % 360;
    }
    return hash;
});
</script>

<template>
    <span
        :class="[
            'inline-flex items-center justify-center overflow-hidden rounded-full font-semibold text-white',
            sizes[size],
        ]"
        :style="!src || failed ? `background: linear-gradient(135deg, hsl(${hue} 62% 52%), hsl(${(hue + 40) % 360} 68% 44%))` : ''"
        :title="name || undefined"
    >
        <img
            v-if="src && !failed"
            :src="src"
            :alt="name"
            class="h-full w-full object-cover"
            @error="failed = true"
        >
        <template v-else>{{ initials }}</template>
    </span>
</template>
