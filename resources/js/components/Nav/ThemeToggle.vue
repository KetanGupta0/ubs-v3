<script setup>
import { ref } from 'vue';
import { Sun, Moon, Monitor } from 'lucide-vue-next';
import { storedPreference, setPreference } from '@/support/theme';

const preference = ref(storedPreference());

const options = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'System' },
];

function choose(value) {
    preference.value = value;
    setPreference(value);
}
</script>

<template>
    <div
        class="inline-flex items-center gap-0.5 rounded-full border p-0.5"
        style="border-color: var(--border-subtle)"
        role="radiogroup"
        aria-label="Colour theme"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="preference === option.value"
            :aria-label="option.label"
            :class="[
                'inline-flex h-7 w-7 items-center justify-center rounded-full transition',
                preference === option.value
                    ? 'bg-[var(--surface-sunken)] text-[var(--text-strong)]'
                    : 'text-[var(--text-muted)] hover:text-[var(--text-strong)]',
            ]"
            @click="choose(option.value)"
        >
            <component :is="option.icon" class="h-3.5 w-3.5" />
        </button>
    </div>
</template>
