<script setup>
import { Loader2 } from 'lucide-vue-next';

defineProps({
    label: { type: String, default: 'Loading' },
    size: { type: String, default: 'h-5 w-5' },
});
</script>

<template>
    <span class="inline-flex items-center gap-2" role="status">
        <Loader2 :class="['animate-spin', size]" aria-hidden="true" />
        <span class="sr-only">{{ label }}</span>
    </span>
</template>
