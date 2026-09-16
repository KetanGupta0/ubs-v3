<script setup>
/**
 * Centred dialog. On phones it slides up from the bottom like a sheet, which
 * is what a native app does and what a thumb expects.
 */
import { ref, computed, toRef } from 'vue';
import { X } from 'lucide-vue-next';
import { useScrollLock } from '@/composables/useScrollLock';
import { useFocusTrap } from '@/composables/useFocusTrap';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: null },
    description: { type: String, default: null },
    size: { type: String, default: 'md' },
    /** Block closing while a request is in flight. */
    persistent: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const panel = ref(null);
const isOpen = toRef(props, 'open');

useScrollLock(isOpen);
useFocusTrap(panel, isOpen);

const sizes = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-lg',
    lg: 'sm:max-w-2xl',
    xl: 'sm:max-w-4xl',
};

const panelClasses = computed(() => sizes[props.size] ?? sizes.md);

function dismiss() {
    if (!props.persistent) emit('close');
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-[var(--duration-base)]"
            leave-active-class="transition-opacity duration-[var(--duration-fast)]"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 bg-ink-950/60 backdrop-blur-sm"
                @click="dismiss"
                @keydown.esc="dismiss"
            />
        </Transition>

        <Transition
            enter-active-class="transition duration-[var(--duration-base)] ease-[var(--ease-out-expo)]"
            leave-active-class="transition duration-[var(--duration-fast)]"
            enter-from-class="opacity-0 translate-y-full sm:translate-y-3 sm:scale-95"
            leave-to-class="opacity-0 translate-y-full sm:translate-y-2 sm:scale-95"
        >
            <div
                v-if="open"
                class="fixed inset-x-0 bottom-0 z-50 sm:inset-0 sm:flex sm:items-center sm:justify-center sm:p-4"
                role="dialog"
                aria-modal="true"
                :aria-label="title || undefined"
                @keydown.esc="dismiss"
            >
                <div
                    ref="panel"
                    tabindex="-1"
                    :class="[
                        'w-full rounded-t-2xl sm:rounded-[var(--radius-card)] outline-none',
                        'bg-[var(--surface)] border border-[var(--border-subtle)]',
                        'shadow-[var(--shadow-pop)] max-h-[92vh] overflow-y-auto scrollbar-thin',
                        panelClasses,
                    ]"
                >
                    <!-- Grab handle, phone only. -->
                    <div class="sticky top-0 flex justify-center pt-2 sm:hidden">
                        <span class="h-1 w-10 rounded-full" style="background: var(--border-strong)" />
                    </div>

                    <div v-if="title || $slots.header" class="flex items-start gap-4 px-5 pt-5 sm:px-6">
                        <div class="min-w-0 flex-1">
                            <slot name="header">
                                <h2 class="text-lg font-semibold">{{ title }}</h2>
                                <p v-if="description" class="mt-1 text-sm" style="color: var(--text-muted)">
                                    {{ description }}
                                </p>
                            </slot>
                        </div>

                        <button
                            v-if="!persistent"
                            type="button"
                            class="-mr-1 -mt-1 rounded-lg p-2 transition hover:bg-[var(--surface-sunken)]"
                            style="color: var(--text-muted)"
                            aria-label="Close dialog"
                            @click="emit('close')"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="px-5 py-5 sm:px-6">
                        <slot />
                    </div>

                    <div
                        v-if="$slots.footer"
                        class="flex flex-col-reverse gap-2 border-t px-5 py-4 sm:flex-row sm:justify-end sm:px-6 pb-safe sm:pb-4"
                        style="border-color: var(--border-subtle)"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
