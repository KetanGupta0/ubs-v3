<script setup>
/**
 * Edge anchored panel. Used for filters, detail peeks and the mobile nav.
 */
import { ref, computed, toRef } from 'vue';
import { X } from 'lucide-vue-next';
import { useScrollLock } from '@/composables/useScrollLock';
import { useFocusTrap } from '@/composables/useFocusTrap';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: null },
    side: { type: String, default: 'right' },
    width: { type: String, default: 'max-w-md' },
});

const emit = defineEmits(['close']);

const panel = ref(null);
const isOpen = toRef(props, 'open');

useScrollLock(isOpen);
useFocusTrap(panel, isOpen);

const position = computed(() =>
    props.side === 'left' ? 'left-0 border-r' : 'right-0 border-l',
);
const enterFrom = computed(() =>
    props.side === 'left' ? '-translate-x-full' : 'translate-x-full',
);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-[var(--duration-base)]"
            leave-active-class="transition-opacity duration-[var(--duration-fast)]"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-50 bg-ink-950/60 backdrop-blur-sm" @click="emit('close')" />
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-[var(--duration-base)] ease-[var(--ease-out-expo)]"
            leave-active-class="transition-transform duration-[var(--duration-fast)]"
            :enter-from-class="enterFrom"
            :leave-to-class="enterFrom"
        >
            <aside
                v-if="open"
                ref="panel"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                :aria-label="title || undefined"
                :class="[
                    'fixed inset-y-0 z-50 flex w-full flex-col outline-none',
                    'bg-[var(--surface)] shadow-[var(--shadow-pop)]',
                    position, width,
                ]"
                style="border-color: var(--border-subtle)"
                @keydown.esc="emit('close')"
            >
                <header
                    class="flex items-center justify-between gap-3 border-b px-5 py-4"
                    style="border-color: var(--border-subtle)"
                >
                    <slot name="header">
                        <h2 class="text-base font-semibold">{{ title }}</h2>
                    </slot>

                    <button
                        type="button"
                        class="rounded-lg p-2 transition hover:bg-[var(--surface-sunken)]"
                        style="color: var(--text-muted)"
                        aria-label="Close panel"
                        @click="emit('close')"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto scrollbar-thin px-5 py-5">
                    <slot />
                </div>

                <footer
                    v-if="$slots.footer"
                    class="border-t px-5 py-4 pb-safe"
                    style="border-color: var(--border-subtle)"
                >
                    <slot name="footer" />
                </footer>
            </aside>
        </Transition>
    </Teleport>
</template>
