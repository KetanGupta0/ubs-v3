<script setup>
/**
 * The website assistant (§9.2): its switch and limits, what it did this
 * month, the questions it could not answer (where the site's words fall
 * short), and its conversations.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';

const props = defineProps({
    table: { type: Object, required: true },
    settings: { type: Object, required: true },
    state: { type: Object, required: true },
    stats: { type: Object, required: true },
    gaps: { type: Array, default: () => [] },
});

const form = useForm({ ...props.settings, policy_ack: props.settings.enabled });

function save() {
    form.put('/admin/assistant', { preserveScroll: true });
}

const blocked = computed(() => {
    if (!props.state.configured) return 'The Claude API key is not set in the environment, so it cannot answer anybody.';
    if (!props.state.policyAllows) return `Version ${props.state.policyVersion ?? '…'} of the privacy policy, which says what happens to what visitors type, is waiting to be published under Legal documents.`;

    return null;
});
</script>

<template>
    <Head title="Website assistant" />

    <AppLayout title="Website assistant" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Website assistant' }]">
        <div class="space-y-5">
            <PageHeader
                title="Website assistant"
                description="An AI on the public site that answers from the catalogue and points visitors to the right page. It never invents a price, a date or a seat, says so when it does not know, and passes on a visitor's details only when they press Send."
            >
                <template #actions>
                    <UiBadge :tone="state.live ? 'success' : 'neutral'" size="md" dot>{{ state.live ? 'On the site' : 'Off' }}</UiBadge>
                </template>
            </PageHeader>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <UiCard>
                    <template #header><h2 class="text-base font-semibold">Settings</h2></template>
                    <form class="space-y-4" @submit.prevent="save">
                        <p v-if="blocked" class="rounded-[var(--radius-control)] p-3 text-sm" style="background: var(--surface-sunken)">{{ blocked }}</p>

                        <UiSwitch v-model="form.enabled" label="On the public site" description="Visitors see an “Ask us” button on every public page." />
                        <UiCheckbox v-if="form.enabled" v-model="form.policy_ack" label="The published privacy policy tells visitors what happens to what they type into it." />
                        <p v-if="form.errors.enabled" class="text-xs" style="color: var(--text-danger)">{{ form.errors.enabled }}</p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <UiFormField label="Keep conversations for" :hint="`Days, at most ${state.retentionLimit}, as the privacy policy promises. Then they are deleted; a lead they produced stays.`" :error="form.errors.retention_days">
                                <UiInput v-model.number="form.retention_days" type="number" min="1" :max="state.retentionLimit" class="max-w-32" />
                            </UiFormField>
                            <UiFormField label="New conversations a day" hint="After this many in a day, visitors are shown the contact page instead. 0: no limit." :error="form.errors.daily_limit">
                                <UiInput v-model.number="form.daily_limit" type="number" min="0" max="5000" class="max-w-32" />
                            </UiFormField>
                        </div>

                        <div class="flex justify-end">
                            <UiButton type="submit" :loading="form.processing">Save</UiButton>
                        </div>
                    </form>
                </UiCard>

                <UiCard>
                    <template #header><h2 class="text-base font-semibold">The last 30 days</h2></template>
                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <div><dt class="text-xs" style="color: var(--text-muted)">Today</dt><dd class="text-xl font-semibold tnum">{{ stats.today }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted)">Conversations</dt><dd class="text-xl font-semibold tnum">{{ stats.month }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted)">Became leads</dt><dd class="text-xl font-semibold tnum">{{ stats.leads }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted)">Tokens used</dt><dd class="text-xl font-semibold tnum">{{ stats.tokens.toLocaleString('en-IN') }}</dd></div>
                    </dl>
                    <p class="mt-4 text-xs" style="color: var(--text-muted)">Model: {{ state.model }}.</p>
                </UiCard>
            </div>

            <UiCard>
                <template #header>
                    <div>
                        <h2 class="text-base font-semibold">Questions it could not answer</h2>
                        <p class="mt-0.5 text-xs" style="color: var(--text-muted)">What visitors asked that the site does not say. Each one is a gap in the site's wording, a page, or the catalogue.</p>
                    </div>
                </template>
                <p v-if="!gaps.length" class="text-sm" style="color: var(--text-muted)">None yet.</p>
                <ul v-else class="divide-y text-sm" style="border-color: var(--border-subtle)">
                    <li v-for="gap in gaps" :key="gap.question" class="flex items-start justify-between gap-3 py-2 first:pt-0 last:pb-0">
                        <span>{{ gap.question }}</span>
                        <span class="shrink-0 text-xs" style="color: var(--text-muted)">{{ gap.times > 1 ? `${gap.times} times · ` : '' }}{{ gap.last }}</span>
                    </li>
                </ul>
            </UiCard>

            <DataTable :table="table" empty-title="No conversations yet" empty-description="Conversations appear here once visitors use the assistant. They are deleted after the period set above.">
                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/assistant/conversations/${row.id}`" size="xs" variant="ghost" icon :aria-label="`Read the conversation started ${row.created_at}`"><ArrowRight class="h-3.5 w-3.5" /></UiButton>
                </template>
                <template #mobileRow="{ row }">
                    <a :href="`/admin/assistant/conversations/${row.id}`" class="block">
                        <p class="truncate text-sm font-medium">{{ row.first_question || '—' }}</p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.created_at }} · {{ row.visitor_messages }} messages · {{ row.outcome }}</p>
                    </a>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
