<script setup>
/**
 * Menu anchored to a trigger. Closes on outside click, Escape, scrolling and
 * navigation.
 *
 * The menu is drawn on the page's top layer (teleported to <body>, fixed at
 * the trigger) and opens upwards when there is no room below. Until November
 * 2026 it was drawn inside whatever held the trigger, so a row's menu in a
 * table, whose box scrolls sideways and so clips, was cut off at the table's
 * edge and gave the table a scrollbar of its own.
 */
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    align: { type: String, default: 'right' },
    width: { type: String, default: 'w-56' },
});

const open = ref(false);
const root = ref(null);
const menu = ref(null);
const position = ref({});
const upwards = ref(false);

/** Space kept between the trigger and the menu, and from the window's edge. */
const GAP = 8;

function close() {
    open.value = false;
}

async function toggle() {
    open.value = !open.value;

    if (open.value) {
        await nextTick();
        place();
    }
}

function place() {
    if (!root.value || !menu.value) return;

    const anchor = root.value.getBoundingClientRect();
    const height = menu.value.offsetHeight;
    const below = window.innerHeight - anchor.bottom;

    upwards.value = below < height + GAP * 2 && anchor.top > below;

    position.value = {
        top: `${upwards.value ? Math.max(GAP, anchor.top - height - GAP) : anchor.bottom + GAP}px`,
        ...(props.align === 'right'
            ? { right: `${Math.max(GAP, window.innerWidth - anchor.right)}px` }
            : { left: `${Math.max(GAP, anchor.left)}px` }),
    };
}

function onDocumentClick(event) {
    if (!open.value) return;
    if (root.value?.contains(event.target) || menu.value?.contains(event.target)) return;
    close();
}

function onKeydown(event) {
    if (open.value && event.key === 'Escape') close();
}

/* A fixed menu would float away from its trigger; it closes instead, as native menus do. */
function onScroll(event) {
    if (open.value && !menu.value?.contains(event.target)) close();
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', close);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', close);
});

defineExpose({ close });
</script>

<template>
    <div ref="root" class="relative inline-block">
        <div @click="toggle">
            <slot name="trigger" :open="open" />
        </div>

        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-[var(--duration-fast)] ease-[var(--ease-out-expo)]"
                leave-active-class="transition duration-100"
                :enter-from-class="upwards ? 'opacity-0 scale-95 translate-y-1' : 'opacity-0 scale-95 -translate-y-1'"
                leave-to-class="opacity-0 scale-95"
            >
                <div
                    v-if="open"
                    ref="menu"
                    :class="[
                        'fixed z-[60] overflow-hidden rounded-xl border p-1.5',
                        'bg-[var(--surface)] shadow-[var(--shadow-pop)]',
                        upwards ? 'origin-bottom' : 'origin-top',
                        width,
                    ]"
                    :style="{ borderColor: 'var(--border-subtle)', ...position }"
                    role="menu"
                    @click="close"
                >
                    <slot :close="close" />
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
