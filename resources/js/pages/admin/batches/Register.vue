<script setup>
/**
 * Marking one register.
 *
 * Built for speed with a class waiting: everybody starts at present, the
 * trainer changes the few who are not, and one save writes the lot. Marking
 * somebody present awards their points in the same action, so the register,
 * the leaderboard and the student's own history cannot drift apart.
 */
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, X, Clock, ShieldQuestion } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';

const props = defineProps({
    batch: { type: Object, required: true },
    session: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const form = useForm({
    marks: props.rows.map((row) => ({
        user_id: row.userId,
        status: row.status,
        note: row.note ?? '',
    })),
});

const labels = {
    present: { label: 'Present', icon: Check, tone: 'success' },
    late: { label: 'Late', icon: Clock, tone: 'warning' },
    absent: { label: 'Absent', icon: X, tone: 'danger' },
    excused: { label: 'Excused', icon: ShieldQuestion, tone: 'neutral' },
};

const noting = ref(null);

const counts = computed(() =>
    form.marks.reduce((totals, mark) => ({ ...totals, [mark.status]: (totals[mark.status] ?? 0) + 1 }), {}),
);

function setAll(status) {
    form.marks.forEach((mark) => { mark.status = status; });
}

function save() {
    form.post(`/admin/batches/${props.batch.id}/sessions/${props.session.id}/register`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Register — ${session.title}`" />

    <AppLayout
        title="Register"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: batch.name, href: `/admin/batches/${batch.id}/run` },
            { label: 'Register' },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="session.title" :description="`${session.at} · ${session.duration} min · ${batch.name}`">
                <template #actions>
                    <UiButton :href="`/admin/batches/${batch.id}/run`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Batch
                    </UiButton>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2">
                <UiBadge v-for="status in statuses" :key="status" :tone="labels[status].tone" size="sm">
                    {{ labels[status].label }}: {{ counts[status] ?? 0 }}
                </UiBadge>

                <span class="ml-auto flex flex-wrap gap-1.5">
                    <UiButton variant="ghost" size="xs" @click="setAll('present')">All present</UiButton>
                    <UiButton variant="ghost" size="xs" @click="setAll('absent')">All absent</UiButton>
                </span>
            </div>

            <UiCard padding="p-0">
                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="(mark, index) in form.marks" :key="mark.user_id" class="px-4 py-3 sm:px-5">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{ rows[index].name }}</span>
                                    <UiBadge
                                        v-if="rows[index].nextWarningLevel !== 'notice'"
                                        tone="warning"
                                        size="sm"
                                    >
                                        next notice would be a {{ rows[index].nextWarningLevel }}
                                    </UiBadge>
                                </span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ rows[index].email }}
                                </span>
                            </span>

                            <span class="flex shrink-0 flex-wrap gap-1">
                                <button
                                    v-for="status in statuses"
                                    :key="status"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition"
                                    :style="{
                                        borderColor: mark.status === status ? 'transparent' : 'var(--border-subtle)',
                                        background: mark.status === status ? 'var(--surface-sunken)' : 'transparent',
                                        color: mark.status === status ? 'var(--text-strong)' : 'var(--text-muted)',
                                    }"
                                    :aria-pressed="mark.status === status"
                                    @click="mark.status = status"
                                >
                                    <component :is="labels[status].icon" class="h-3 w-3" />
                                    {{ labels[status].label }}
                                </button>

                                <UiButton
                                    variant="ghost"
                                    size="xs"
                                    @click="noting = noting === mark.user_id ? null : mark.user_id"
                                >
                                    Note
                                </UiButton>
                            </span>
                        </div>

                        <UiInput
                            v-if="noting === mark.user_id || mark.note"
                            v-model="mark.note"
                            class="mt-2"
                            placeholder="Why, in a few words. Kept internal."
                        />
                    </li>
                </ul>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs" style="color: var(--text-muted)">
                            Saving marks the class held. Present and late both count towards attendance.
                        </p>
                        <UiButton :loading="form.processing" @click="save">Save the register</UiButton>
                    </div>
                </template>
            </UiCard>
        </div>
    </AppLayout>
</template>
