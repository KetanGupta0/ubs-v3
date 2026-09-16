<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    item: { type: Object, required: true },
    active: { type: Boolean, default: false },
    collapsed: { type: Boolean, default: false },
});

const component = computed(() => (props.item.href ? Link : 'span'));
</script>

<template>
    <component
        :is="component"
        :href="item.href || undefined"
        :aria-current="active ? 'page' : undefined"
        :class="[
            'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
            active
                ? 'bg-brand-600 text-white shadow-sm'
                : item.href
                    ? 'text-[var(--text-base)] hover:bg-[var(--surface-sunken)] hover:text-[var(--text-strong)]'
                    : 'cursor-not-allowed text-[var(--text-muted)] opacity-55',
            collapsed && 'justify-center px-2',
        ]"
        :title="collapsed ? item.label : undefined"
    >
        <component :is="item.icon" class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
        <span
            v-if="!collapsed && !item.href"
            class="ml-auto rounded px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide"
            style="background: var(--surface-sunken); color: var(--text-muted)"
        >
            Soon
        </span>
    </component>
</template>
