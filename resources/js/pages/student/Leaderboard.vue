<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Trophy, Info } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    boards: { type: Array, default: () => [] },
    points: { type: Number, default: 0 },
    howItWorks: { type: Array, default: () => [] },
});

const page = usePage();
const me = computed(() => page.props.auth?.user?.id ?? null);

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

            <UiEmptyState
                v-if="!boards.length"
                :icon="Trophy"
                title="No board yet"
                description="Join a batch and finish your first lesson — points start from there."
            />

            <section v-for="board in boards" :key="board.batchId" class="space-y-3">
                <UiCard padding="p-0">
                    <template #header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-sm font-semibold">{{ board.batch }}</h2>
                                <p class="text-xs" style="color: var(--text-muted)">{{ board.course }}</p>
                            </div>
                            <p v-if="board.mine" class="text-xs" style="color: var(--text-muted)">
                                You are {{ board.mine.rank }} of {{ board.mine.outOf }}
                            </p>
                        </div>
                    </template>

                    <p v-if="!board.rows.length" class="px-1 py-4 text-sm" style="color: var(--text-muted)">
                        Nobody in this batch has earned a point yet. Somebody has to go first.
                    </p>

                    <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                        <li
                            v-for="row in board.rows"
                            :key="row.userId"
                            class="flex items-center gap-3 px-1 py-2.5"
                            :class="row.userId === me ? 'rounded-[var(--radius-control)] bg-[var(--surface-sunken)]' : ''"
                        >
                            <span class="w-8 shrink-0 text-center text-sm font-semibold tnum">
                                <span v-if="medal(row.rank)">{{ medal(row.rank) }}</span>
                                <span v-else style="color: var(--text-muted)">{{ row.rank }}</span>
                            </span>

                            <UiAvatar :name="row.name" :src="row.avatarUrl" size="sm" />

                            <span class="min-w-0 flex-1 truncate text-sm">
                                {{ row.name }}
                                <span v-if="row.userId === me" class="text-xs" style="color: var(--text-muted)">
                                    · you
                                </span>
                            </span>

                            <span class="shrink-0 text-sm font-semibold tnum">{{ row.points }}</span>
                        </li>
                    </ul>
                </UiCard>

                <p
                    v-if="board.mine && board.mine.rank > 1"
                    class="px-1 text-xs"
                    style="color: var(--text-muted)"
                >
                    {{ board.mine.topPoints - board.mine.points }} points behind the top of this batch.
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
