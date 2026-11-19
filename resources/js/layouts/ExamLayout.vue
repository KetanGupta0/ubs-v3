<script setup>
/**
 * A proctored quiz's page (§9.1a): the quiz and nothing else. No sidebar, no
 * header, no tab bar, because every link on screen during a sitting is a way
 * out of the quiz that a lockdown cannot see; the browser's own ways out are
 * covered by the lockdown itself.
 */
import UiToaster from '@/components/UI/UiToaster.vue';

defineProps({
    title: { type: String, default: '' },
});
</script>

<template>
    <div class="min-h-screen px-4 py-6 sm:px-6" style="background: var(--surface-sunken)">
        <main>
            <slot />
        </main>
        <UiToaster />
    </div>
</template>
