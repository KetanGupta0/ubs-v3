<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Trophy, Info, EyeOff } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTabs from '@/components/UI/UiTabs.vue';

const props = defineProps({
    board: { type: String, default: 'batch' },
    tabs: { type: Array, default: () => [] },
    boards: { type: Array, default: () => [] },
    showName: { type: Boolean, default: true },
    points: { type: Number, default: 0 },
    howItWorks: { type: Array, default: () => [] },
});

const current = computed({
    get: () => props.board,
    set: (value) => router.get('/student/leaderboard', { board: value }, { preserveState: true, preserveScroll: true, only: ['board', 'boards'] }),
});

const empty = computed(() => ({
    batch: 'Nobody in this batch has earned a point yet. Somebody has to go first.',
    week: 'Nobody has earned a point this week yet. It starts again every Monday.',
    course: 'Nobody on this course has earned a point yet.',
    all: 'No points earned yet.',
}[props.board]));

function setShowName(value) {
    router.put('/student/leaderboard/visibility', { show: value }, { preserveScroll: true });
}

const medal = (rank) => ({ 1: '🥇', 2: '🥈', 3: '🥉' })[rank] ?? null;
</script>

<template>
    <Head title="Leaderboard" />

    <AppLayout title="Leaderboard" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Leaderboard' }]">
        <div class="mx-auto max-w-3xl space-y-6">
            <PageHeader
                title="Leaderboard"
                description="Your own batch, not the whole platform. Being bottom of a list of everybody who ever enrolled tells you nothing you can act on."
            >
                <template #actions>
                    <UiBadge tone="brand">{{ points }} points in total</UiBadge>
                </template>
            </PageHeader>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <UiTabs v-model="current" :tabs="tabs" />
                <UiSwitch
                    :model-value="showName"
                    label="Show my name"
                    description="Off shows you to other students as A student, in the same place."
                    @update:model-value="setShowName"
                />
            </div>

            <UiEmptyState
                v-if="!boards.length"
                :icon="Trophy"
                title="No board yet"
                description="Join a batch and finish your first lesson — points start from there."
            />

            <section v-for="one in boards" :key="one.key" class="space-y-3">
                <UiCard padding="p-0">
                    <template #header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-sm font-semibold">{{ one.title }}</h2>
                                <p class="text-xs" style="color: var(--text-muted)">{{ one.subtitle }}</p>
                            </div>
                            <p v-if="one.mine" class="text-xs" style="color: var(--text-muted)">
                                You are {{ one.mine.rank }} of {{ one.mine.outOf }}
                            </p>
                        </div>
                    </template>

                    <p v-if="!one.rows.length" class="px-1 py-4 text-sm" style="color: var(--text-muted)">
                        {{ empty }}
                    </p>

                    <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                        <li
                            v-for="(row, index) in one.rows"
                            :key="index"
                            class="flex items-center gap-3 px-1 py-2.5"
                            :class="row.me ? 'rounded-[var(--radius-control)] bg-[var(--surface-sunken)]' : ''"
                        >
                            <span class="w-8 shrink-0 text-center text-sm font-semibold tnum">
                                <span v-if="medal(row.rank)">{{ medal(row.rank) }}</span>
                                <span v-else style="color: var(--text-muted)">{{ row.rank }}</span>
                            </span>

                            <span v-if="row.hidden" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full" style="background: var(--surface-sunken)">
                                <EyeOff class="h-3.5 w-3.5" style="color: var(--text-muted)" />
                            </span>
                            <UiAvatar v-else :name="row.name" :src="row.avatarUrl" size="sm" />

                            <span class="min-w-0 flex-1 truncate text-sm" :style="row.hidden ? { color: 'var(--text-muted)' } : {}">
                                {{ row.name }}
                                <span v-if="row.me" class="text-xs" style="color: var(--text-muted)">
                                    · you{{ showName ? '' : ' (hidden)' }}
                                </span>
                            </span>

                            <span class="shrink-0 text-sm font-semibold tnum">{{ row.points }}</span>
                        </li>
                    </ul>
                </UiCard>

                <p
                    v-if="one.mine && one.mine.rank > 1"
                    class="px-1 text-xs"
                    style="color: var(--text-muted)"
                >
                    {{ one.mine.topPoints - one.mine.points }} points behind the top.
                </p>
            </section>

            <!-- --------------------------------------------- how it works -->
            <UiCard v-if="howItWorks.length">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Info class="h-4 w-4" style="color: var(--text-muted)" />
                    How points are earned
                </h2>

                <ul class="mt-3 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                    <li
                        v-for="rule in howItWorks"
                        :key="rule.source"
                        class="flex items-baseline justify-between gap-3 text-sm"
                    >
                        <span>{{ rule.source }}</span>
                        <span class="font-semibold tnum" style="color: var(--text-muted)">+{{ rule.points }}</span>
                    </li>
                </ul>

                <p class="mt-4 text-xs" style="color: var(--text-muted)">
                    Each of these pays once. Watching the same lesson twice is not twice the points, because a
                    leaderboard that can be farmed is not measuring anything.
                </p>
            </UiCard>
        </div>
    </AppLayout>
</template>
