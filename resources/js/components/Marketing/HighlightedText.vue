<script setup>
/**
 * Text where *words in asterisks* are highlighted, as written in the panel.
 *
 * Split into plain text pieces rather than turned into HTML, so whatever is
 * typed can only ever be text.
 */
import { computed } from 'vue';

const props = defineProps({
    text: { type: String, default: '' },
});

const parts = computed(() => props.text.split(/\*([^*]+)\*/).map((value, index) => ({ value, highlighted: index % 2 === 1 })));
</script>

<template>
    <template v-for="(part, index) in parts" :key="index">
        <span v-if="part.highlighted" class="text-gradient">{{ part.value }}</span>
        <template v-else>{{ part.value }}</template>
    </template>
</template>
