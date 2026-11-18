<script setup>
/**
 * The bell: what the platform has told this person, in the platform.
 *
 * Shows how many are unread, and the latest few when opened; opening one
 * marks it read and goes where it points. Until November 2026 the bell was a
 * picture with a dot that was always on, and every notification written to
 * the database was never seen.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, CheckCheck } from 'lucide-vue-next';

import UiDropdown from '@/components/UI/UiDropdown.vue';

const page = usePage();
const unread = computed(() => Number(page.props.auth?.unreadNotifications ?? 0));
const viewingAs = computed(() => Boolean(page.props.viewingAs));

const items = ref([]);
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        const response = await fetch('/notifications/recent', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (response.ok) items.value = (await response.json()).items;
    } finally {
        loading.value = false;
    }
}

function toggled(open) {
    if (open) load();
}

function openItem(item) {
    // Viewing as somebody is read only: go there without marking it read.
    if (viewingAs.value || !item.unread) {
        if (item.url) router.visit(item.url);
        return;
    }
    router.post(`/notifications/${item.id}`);
}

function readAll() {
    router.post('/notifications/read-all', {}, { preserveScroll: true, onSuccess: load });
}
</script>

<template>
    <UiDropdown width="w-80">
        <template #trigger="{ open }">
            <button
                type="button"
                class="relative inline-flex h-9 w-9 items-center justify-center rounded-full transition hover:bg-[var(--surface-sunken)]"
                style="color: var(--text-muted)"
                :aria-label="unread ? `Notifications, ${unread} unread` : 'Notifications'"
                :aria-expanded="open"
                @click="toggled(!open)"
            >
                <Bell class="h-[18px] w-[18px]" />
                <span
                    v-if="unread"
                    class="absolute -right-0.5 -top-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-accent-500 px-1 text-[10px] font-semibold text-white tnum"
                >{{ unread > 99 ? '99+' : unread }}</span>
            </button>
        </template>

        <div class="flex items-center justify-between px-2 pb-1.5 pt-1">
            <p class="text-sm font-semibold" style="color: var(--text-strong)">Notifications</p>
            <button v-if="unread && !viewingAs" type="button" class="inline-flex items-center gap-1 text-xs hover:underline" style="color: var(--text-muted)" @click.stop="readAll">
                <CheckCheck class="h-3.5 w-3.5" /> Mark all read
            </button>
        </div>
        <p v-if="loading && !items.length" class="px-2 py-3 text-sm" style="color: var(--text-muted)">Loading…</p>
        <p v-else-if="!items.length" class="px-2 py-3 text-sm" style="color: var(--text-muted)">Nothing yet.</p>
        <ul v-else class="max-h-96 overflow-y-auto">
            <li v-for="item in items" :key="item.id">
                <button
                    type="button"
                    class="flex w-full items-start gap-2 rounded-lg px-2 py-2 text-left transition hover:bg-[var(--surface-sunken)]"
                    @click="openItem(item)"
                >
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="item.unread ? 'bg-accent-500' : 'bg-transparent'" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="block text-sm" :style="{ color: 'var(--text-strong)', fontWeight: item.unread ? 600 : 400 }">{{ item.title }}</span>
                        <span class="block text-xs" style="color: var(--text-muted)">{{ item.created_at }}</span>
                    </span>
                </button>
            </li>
        </ul>
        <Link href="/notifications" class="mt-1 block rounded-lg px-2 py-2 text-center text-xs font-medium hover:bg-[var(--surface-sunken)]" style="color: var(--text-base)">See them all</Link>
    </UiDropdown>
</template>
