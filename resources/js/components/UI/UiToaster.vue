<script setup>
/**
 * Renders the toast queue. Mounted once, in the app shell.
 *
 * Bottom centre on phones so it sits above the thumb and clear of the tab bar,
 * top right on desktop where it does not cover content being worked on.
 */
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, AlertTriangle, XCircle, Info, X } from 'lucide-vue-next';
import { toasts, dismiss, push } from '@/support/toast';

const icons = {
    success: CheckCircle2,
    warning: AlertTriangle,
    danger: XCircle,
    brand: Info,
    neutral: Info,
};

const tones = {
    success: 'text-signal-500',
    warning: 'text-warn-500',
    danger: 'text-danger-500',
    brand: 'text-brand-500',
    neutral: 'text-ink-400',
};

// Server side flash messages become toasts automatically, so a controller only
// has to call ->with('success', '...') and the feedback appears.
const page = usePage();

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        if (flash.success) push(flash.success, { tone: 'success' });
        if (flash.error) push(flash.error, { tone: 'danger' });
        if (flash.warning) push(flash.warning, { tone: 'warning' });
        if (flash.info) push(flash.info, { tone: 'brand' });
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <Teleport to="body">
        <div
            class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 pb-safe sm:inset-auto sm:right-0 sm:top-0 sm:items-end"
            role="region"
            aria-label="Notifications"
        >
            <TransitionGroup
                enter-active-class="transition duration-[var(--duration-base)] ease-[var(--ease-out-expo)]"
                leave-active-class="transition duration-[var(--duration-fast)] absolute"
                enter-from-class="opacity-0 translate-y-3 sm:translate-y-0 sm:translate-x-4"
                leave-to-class="opacity-0 scale-95"
                move-class="transition duration-[var(--duration-base)]"
            >
                <div
                    v-for="item in toasts"
                    :key="item.id"
                    class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border bg-[var(--surface)] p-3.5 shadow-[var(--shadow-pop)]"
                    style="border-color: var(--border-subtle)"
                    role="status"
                    aria-live="polite"
                >
                    <component
                        :is="icons[item.tone] ?? icons.neutral"
                        :class="['mt-0.5 h-4.5 w-4.5 shrink-0', tones[item.tone] ?? tones.neutral]"
                        aria-hidden="true"
                    />

                    <div class="min-w-0 flex-1">
                        <p v-if="item.title" class="text-sm font-semibold" style="color: var(--text-strong)">
                            {{ item.title }}
                        </p>
                        <p class="text-sm" style="color: var(--text-base)">{{ item.message }}</p>

                        <button
                            v-if="item.action"
                            type="button"
                            class="mt-1.5 text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400"
                            @click="item.action.onClick(); dismiss(item.id)"
                        >
                            {{ item.action.label }}
                        </button>
                    </div>

                    <button
                        type="button"
                        class="-m-1 rounded p-1 transition hover:bg-[var(--surface-sunken)]"
                        style="color: var(--text-muted)"
                        aria-label="Dismiss notification"
                        @click="dismiss(item.id)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
