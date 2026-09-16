<script setup>
/**
 * Menu anchored to a trigger. Closes on outside click, Escape and navigation.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    align: { type: String, default: 'right' },
    width: { type: String, default: 'w-56' },
});

const open = ref(false);
const root = ref(null);

function close() {
    open.value = false;
}

function onDocumentClick(event) {
    if (open.value && root.value && !root.value.contains(event.target)) {
        close();
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));

defineExpose({ close });
</script>

<template>
    <div ref="root" class="relative inline-block" @keydown.esc="close">
        <div @click="open = !open">
            <slot name="trigger" :open="open" />
        </div>

        <Transition
            enter-active-class="transition duration-[var(--duration-fast)] ease-[var(--ease-out-expo)]"
            leave-active-class="transition duration-100"
            enter-from-class="opacity-0 scale-95 -translate-y-1"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="open"
                :class="[
                    'absolute z-40 mt-2 origin-top overflow-hidden rounded-xl border p-1.5',
                    'bg-[var(--surface)] shadow-[var(--shadow-pop)]',
                    align === 'right' ? 'right-0' : 'left-0',
                    width,
                ]"
                style="border-color: var(--border-subtle)"
                role="menu"
                @click="close"
            >
                <slot :close="close" />
            </div>
        </Transition>
    </div>
</template>
