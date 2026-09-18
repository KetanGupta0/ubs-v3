<script setup>
/**
 * The search box in the header, as a palette.
 *
 * Over whatever somebody is already doing rather than a page of its own: the
 * point of searching from a header is to jump somewhere, and taking the reader
 * away from their work to show them a list of links is a strange way to help.
 *
 * What comes back is scoped on the server by who is asking, so this component
 * does not know or care which panel it is sitting in.
 */
import { ref, watch, nextTick, onMounted, onBeforeUnmount, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Search, CornerDownLeft, Loader2 } from 'lucide-vue-next';

const open = ref(false);
const term = ref('');
const groups = ref([]);
const loading = ref(false);
const highlighted = ref(0);
const input = ref(null);

let timer = null;
let sequence = 0;

/** Every result in one list, so the arrow keys can walk across groups. */
const flat = computed(() => groups.value.flatMap((group) => group.results));

watch(term, (value) => {
    clearTimeout(timer);
    highlighted.value = 0;

    if (value.trim().length < 2) {
        groups.value = [];
        loading.value = false;

        return;
    }

    loading.value = true;
    timer = setTimeout(() => look(value), 220);
});

async function look(value) {
    const mine = ++sequence;

    try {
        const response = await fetch(`/search?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        // A slower earlier request must not overwrite a faster later one.
        if (!response.ok || mine !== sequence) return;

        groups.value = (await response.json()).groups ?? [];
    } catch {
        groups.value = [];
    } finally {
        if (mine === sequence) loading.value = false;
    }
}

function show() {
    open.value = true;
    nextTick(() => input.value?.focus());
}

function hide() {
    open.value = false;
    term.value = '';
    groups.value = [];
}

function go(result) {
    hide();
    router.visit(result.href);
}

function move(step) {
    if (!flat.value.length) return;

    highlighted.value = (highlighted.value + step + flat.value.length) % flat.value.length;
}

function choose() {
    const result = flat.value[highlighted.value];

    if (result) go(result);
}

/** Slash opens it, the way every tool this audience already uses does. */
function onKey(event) {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)
        || event.target.isContentEditable;

    if (event.key === '/' && !typing && !open.value) {
        event.preventDefault();
        show();
        return;
    }

    if (event.key === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        open.value ? hide() : show();
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    clearTimeout(timer);
});

defineExpose({ show });
</script>

<template>
    <div>
        <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition hover:bg-[var(--surface-sunken)]"
            style="border-color: var(--border-subtle); color: var(--text-muted)"
            aria-label="Search"
            @click="show"
        >
            <Search class="h-4 w-4" />
            <span class="hidden md:inline">Search</span>
            <kbd
                class="hidden rounded border px-1 text-[0.65rem] md:inline"
                style="border-color: var(--border-subtle)"
            >/</kbd>
        </button>

        <!-- ------------------------------------------------------ palette -->
        <Teleport to="body">
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[12vh]"
                style="background: rgb(8 10 22 / 55%)"
                @click.self="hide"
            >
                <div
                    class="w-full max-w-xl overflow-hidden rounded-[var(--radius-card)] border shadow-[var(--shadow-pop)]"
                    style="background: var(--surface-raised); border-color: var(--border-subtle)"
                    role="dialog"
                    aria-label="Search"
                >
                    <div class="flex items-center gap-2 border-b px-4 py-3" style="border-color: var(--border-subtle)">
                        <Search class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />

                        <input
                            ref="input"
                            v-model="term"
                            type="text"
                            placeholder="Search people, projects, invoices, courses…"
                            class="w-full bg-transparent text-sm outline-none"
                            style="color: var(--text-strong)"
                            autocomplete="off"
                            spellcheck="false"
                            @keydown.down.prevent="move(1)"
                            @keydown.up.prevent="move(-1)"
                            @keydown.enter.prevent="choose"
                            @keydown.esc="hide"
                        />

                        <Loader2 v-if="loading" class="h-4 w-4 shrink-0 animate-spin" style="color: var(--text-muted)" />
                    </div>

                    <div class="max-h-[60vh] overflow-y-auto">
                        <p
                            v-if="term.trim().length < 2"
                            class="px-4 py-6 text-center text-sm"
                            style="color: var(--text-muted)"
                        >
                            Type at least two characters.
                        </p>

                        <p
                            v-else-if="!loading && !flat.length"
                            class="px-4 py-6 text-center text-sm"
                            style="color: var(--text-muted)"
                        >
                            Nothing matches “{{ term }}”.
                        </p>

                        <template v-for="group in groups" :key="group.label">
                            <p
                                class="px-4 pt-3 pb-1 text-[0.68rem] font-semibold uppercase tracking-wide"
                                style="color: var(--text-muted)"
                            >
                                {{ group.label }}
                            </p>

                            <button
                                v-for="result in group.results"
                                :key="result.href"
                                type="button"
                                class="flex w-full items-center gap-3 px-4 py-2 text-left transition"
                                :style="{
                                    background: flat.indexOf(result) === highlighted
                                        ? 'var(--surface-sunken)'
                                        : 'transparent',
                                }"
                                @mouseenter="highlighted = flat.indexOf(result)"
                                @click="go(result)"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm">{{ result.title }}</span>
                                    <span v-if="result.subtitle" class="block truncate text-xs" style="color: var(--text-muted)">
                                        {{ result.subtitle }}
                                    </span>
                                </span>

                                <CornerDownLeft
                                    v-if="flat.indexOf(result) === highlighted"
                                    class="h-3.5 w-3.5 shrink-0"
                                    style="color: var(--text-muted)"
                                />
                            </button>
                        </template>
                    </div>

                    <p
                        class="border-t px-4 py-2 text-[0.68rem]"
                        style="border-color: var(--border-subtle); color: var(--text-muted)"
                    >
                        Arrow keys to move, Enter to open, Escape to close.
                    </p>
                </div>
            </div>
        </Teleport>
    </div>
</template>
