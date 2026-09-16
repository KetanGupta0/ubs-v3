<script setup>
/**
 * API keys.
 *
 * The secret appears exactly once, in a dialog that says so plainly, and is
 * never rendered again anywhere. A key the screen could show twice is a key
 * anybody holding a database backup can read.
 */
import { computed, ref, watch } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { KeyRound, Plus, Copy, RotateCw, Ban, Check, AlertTriangle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiBarChart from '@/components/UI/UiBarChart.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import { toast } from '@/support/toast';

const props = defineProps({
    keys: { type: Array, default: () => [] },
    plans: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
});

const page = usePage();

const requesting = ref(false);
const issued = ref(null);
const copied = ref(false);

const form = useForm({
    label: '',
    plan_id: props.plans[0]?.id ?? null,
    project_id: null,
    environment: 'test',
});

const chosenPlan = computed(() => props.plans.find((plan) => plan.id === Number(form.plan_id)));

// The one readable copy arrives as a flash payload and is shown immediately.
watch(
    () => page.props.flash?.issuedApiKey,
    (value) => {
        if (value) {
            issued.value = value;
            copied.value = false;
            requesting.value = false;
            form.reset();
        }
    },
    { immediate: true },
);

function submit() {
    form.post('/client/api-keys', {
        preserveScroll: true,
        onSuccess: () => {
            requesting.value = false;
            form.reset();
        },
    });
}

async function copy() {
    try {
        await navigator.clipboard.writeText(issued.value.key);
        copied.value = true;
    } catch {
        toast.error('Could not copy. Select the key and copy it by hand.');
    }
}

function rotate(key) {
    if (confirm(`Rotate "${key.label}"? The current key stops working immediately.`)) {
        router.post(`/client/api-keys/${key.id}/rotate`, {}, { preserveScroll: true });
    }
}

function revoke(key) {
    if (confirm(`Revoke "${key.label}"? Anything using it stops working now.`)) {
        router.post(`/client/api-keys/${key.id}/revoke`, {}, { preserveScroll: true });
    }
}

const statusTones = { active: 'success', revoked: 'danger', rotated: 'neutral', expired: 'warning' };
</script>

<template>
    <Head title="API keys" />

    <AppLayout title="API keys" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'API keys' }]">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader
                title="API keys"
                description="Keys for the products that need them, with the quota each one is using."
            >
                <template #actions>
                    <UiButton v-if="plans.length" size="sm" @click="requesting = true">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New key
                    </UiButton>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!keys.length"
                :icon="KeyRound"
                title="No keys yet"
                description="Create a test key first. Test keys are always free."
            />

            <UiCard v-for="key in keys" :key="key.id">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-base font-semibold">
                            {{ key.label }}
                            <UiBadge :tone="statusTones[key.status] ?? 'neutral'" size="sm" dot>{{ key.status }}</UiBadge>
                            <UiBadge :tone="key.environment === 'live' ? 'brand' : 'neutral'" size="sm">
                                {{ key.environment }}
                            </UiBadge>
                        </p>

                        <p class="mt-1 font-mono text-sm" style="color: var(--text-muted)">{{ key.masked }}</p>

                        <p class="mt-1 flex flex-wrap items-center gap-x-3 text-xs" style="color: var(--text-muted)">
                            <span v-if="key.plan">{{ key.plan }}</span>
                            <span v-if="key.project">{{ key.project }}</span>
                            <span>Created {{ key.createdAt }}</span>
                            <span v-if="key.lastUsedAt">Last used {{ key.lastUsedAt }}</span>
                        </p>
                    </div>

                    <div v-if="key.usable" class="flex items-center gap-1.5">
                        <UiButton variant="ghost" size="xs" @click="rotate(key)">
                            <template #leading><RotateCw class="h-3 w-3" /></template>
                            Rotate
                        </UiButton>
                        <UiButton variant="ghost" size="xs" @click="revoke(key)">
                            <template #leading><Ban class="h-3 w-3" style="color: var(--color-danger-500)" /></template>
                            Revoke
                        </UiButton>
                    </div>
                </div>

                <div v-if="key.quota" class="mt-4">
                    <UiProgress
                        :value="key.quotaPercent"
                        :label="`${key.quotaUsed.toLocaleString('en-IN')} of ${key.quota.toLocaleString('en-IN')} calls this month`"
                        :tone="key.exceeded ? 'danger' : key.quotaPercent > 80 ? 'warning' : 'brand'"
                    />
                    <p v-if="key.exceeded" class="mt-1.5 flex items-center gap-1.5 text-xs" style="color: var(--color-danger-500)">
                        <AlertTriangle class="h-3.5 w-3.5" />
                        Quota used up. Calls are being refused until the month rolls over.
                    </p>
                </div>

                <div v-if="key.usage?.length" class="mt-4">
                    <p class="mb-2 text-xs font-medium" style="color: var(--text-muted)">Last 30 days</p>
                    <UiBarChart
                        :data="key.usage.map((point) => ({ label: point.date.slice(8), value: point.count }))"
                        :height="72"
                        :label-every="5"
                    />
                </div>
            </UiCard>
        </div>

        <!-- ---------------------------------------------------- new key -->
        <UiModal :open="requesting" title="Create an API key" size="lg" @close="requesting = false">
            <form id="key-form" class="space-y-4" @submit.prevent="submit">
                <UiFormField label="What is it for" required :error="form.errors.label" hint="A name you will recognise later.">
                    <UiInput v-model="form.label" placeholder="Production website" />
                </UiFormField>

                <UiFormField label="Plan" required :error="form.errors.plan_id">
                    <UiSelect
                        v-model="form.plan_id"
                        :options="plans.map((plan) => ({ value: plan.id, label: `${plan.name} — ${plan.priceLabel}` }))"
                    />
                </UiFormField>

                <p v-if="chosenPlan" class="rounded-[var(--radius-field)] px-3 py-2 text-xs"
                   style="background: var(--surface-sunken); color: var(--text-muted)">
                    {{ chosenPlan.quota.toLocaleString('en-IN') }} calls a month, up to
                    {{ chosenPlan.rateLimit }} a minute.{{ chosenPlan.description ? ` ${chosenPlan.description}` : '' }}
                </p>

                <UiFormField
                    label="Environment"
                    required
                    :error="form.errors.environment"
                    hint="Test keys are always free, whatever the plan costs."
                >
                    <UiSelect
                        v-model="form.environment"
                        :options="[
                            { value: 'test', label: 'Test — free' },
                            { value: 'live', label: 'Live' },
                        ]"
                    />
                </UiFormField>

                <UiFormField v-if="projects.length" label="Project" :error="form.errors.project_id">
                    <UiSelect
                        v-model="form.project_id"
                        :options="[{ value: '', label: 'Not tied to one' }, ...projects]"
                    />
                </UiFormField>

                <p v-if="chosenPlan && chosenPlan.price !== 'Free' && form.environment === 'live'"
                   class="text-xs" style="color: var(--text-muted)">
                    A live key on this plan raises a payment request first. The key is issued as soon as it is paid.
                </p>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="requesting = false">Cancel</UiButton>
                <UiButton type="submit" form="key-form" :loading="form.processing">Create the key</UiButton>
            </template>
        </UiModal>

        <!-- ------------------------------------------ the one readable copy -->
        <UiModal
            :open="Boolean(issued)"
            title="Copy this key now"
            description="This is the only time it will ever be shown. If you lose it, rotate the key rather than asking us for it: we cannot read it back."
            size="lg"
            @close="issued = null"
        >
            <div v-if="issued">
                <p class="mb-2 text-xs font-medium" style="color: var(--text-muted)">{{ issued.label }}</p>

                <div
                    class="flex items-center gap-2 rounded-[var(--radius-field)] border p-3"
                    style="border-color: var(--border-strong); background: var(--surface-sunken)"
                >
                    <code class="min-w-0 flex-1 break-all font-mono text-sm">{{ issued.key }}</code>
                    <UiButton variant="ghost" size="xs" @click="copy">
                        <template #leading>
                            <component :is="copied ? Check : Copy" class="h-3.5 w-3.5" />
                        </template>
                        {{ copied ? 'Copied' : 'Copy' }}
                    </UiButton>
                </div>
            </div>

            <template #footer>
                <UiButton @click="issued = null">I have stored it safely</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
