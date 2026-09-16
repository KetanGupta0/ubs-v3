<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Plus, Pencil, Ban, ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    plans: { type: Array, default: () => [] },
    keys: { type: Array, default: () => [] },
});

const open = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    slug: '',
    description: '',
    monthly_quota: 10000,
    rate_limit_per_minute: 60,
    price: 0,
    interval: 'monthly',
    is_active: true,
    sort_order: 0,
});

function start(plan = null) {
    editing.value = plan;
    form.clearErrors();
    form.name = plan?.name ?? '';
    form.slug = plan?.slug ?? '';
    form.description = plan?.description ?? '';
    form.monthly_quota = plan?.quota ?? 10000;
    form.rate_limit_per_minute = plan?.rateLimit ?? 60;
    form.price = plan?.price ?? 0;
    form.interval = plan?.interval ?? 'monthly';
    form.is_active = plan?.isActive ?? true;
    form.sort_order = plan?.sortOrder ?? 0;
    open.value = true;
}

function save() {
    const done = { preserveScroll: true, onSuccess: () => finish() };

    editing.value
        ? form.put(`/admin/api-plans/${editing.value.id}`, done)
        : form.post('/admin/api-plans', done);
}

function finish() {
    open.value = false;
    editing.value = null;
    form.reset();
}

function revoke(key) {
    const reason = prompt(`Why is "${key.label}" being revoked?`);

    if (reason) {
        router.post(`/admin/api-keys/${key.id}/revoke`, { reason }, { preserveScroll: true });
    }
}

const statusTones = { active: 'success', revoked: 'danger', rotated: 'neutral', expired: 'warning' };
</script>

<template>
    <Head title="API plans" />

    <AppLayout title="API plans" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'API plans' }]">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                title="API plans"
                description="What a client can buy a key against, and every key issued."
            >
                <template #actions>
                    <UiButton href="/admin/subscriptions" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Subscriptions
                    </UiButton>
                    <UiButton size="sm" @click="start()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New plan
                    </UiButton>
                </template>
            </PageHeader>

            <!-- ------------------------------------------------------ plans -->
            <section>
                <UiEmptyState
                    v-if="!plans.length"
                    title="No plans yet"
                    description="A client cannot request a key until there is a plan to request it against."
                />

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <UiCard v-for="plan in plans" :key="plan.id">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-base font-semibold">{{ plan.name }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">{{ plan.priceLabel }}</p>
                            </div>
                            <span class="flex items-center gap-1">
                                <UiBadge v-if="!plan.isActive" size="sm">off</UiBadge>
                                <UiButton variant="ghost" size="xs" icon aria-label="Edit plan" @click="start(plan)">
                                    <Pencil class="h-3 w-3" />
                                </UiButton>
                            </span>
                        </div>

                        <p v-if="plan.description" class="mt-2 text-sm" style="color: var(--text-muted)">
                            {{ plan.description }}
                        </p>

                        <dl class="mt-3 space-y-1 text-xs" style="color: var(--text-muted)">
                            <div class="flex justify-between">
                                <dt>Monthly quota</dt>
                                <dd class="tnum">{{ plan.quota.toLocaleString('en-IN') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Rate limit</dt>
                                <dd class="tnum">{{ plan.rateLimit }} / minute</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Keys issued</dt>
                                <dd class="tnum">{{ plan.keys }}</dd>
                            </div>
                        </dl>
                    </UiCard>
                </div>
            </section>

            <!-- ------------------------------------------------------- keys -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Keys issued
                </h2>

                <UiEmptyState v-if="!keys.length" title="No keys issued yet" />

                <ul v-else class="divide-y overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)]"
                    style="border-color: var(--border-subtle)">
                    <li v-for="key in keys" :key="key.id" class="flex flex-wrap items-center gap-3 p-4">
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">{{ key.label }}</span>
                                <UiBadge :tone="statusTones[key.status] ?? 'neutral'" size="sm" dot>{{ key.status }}</UiBadge>
                                <UiBadge :tone="key.environment === 'live' ? 'brand' : 'neutral'" size="sm">
                                    {{ key.environment }}
                                </UiBadge>
                            </span>
                            <span class="mt-0.5 block font-mono text-xs" style="color: var(--text-muted)">{{ key.masked }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ [key.client, key.plan, `issued ${key.at}`, key.lastUsedAt && `last used ${key.lastUsedAt}`]
                                    .filter(Boolean).join(' · ') }}
                            </span>
                        </span>

                        <span v-if="key.quota" class="w-32">
                            <UiProgress
                                :value="Math.min(100, (key.quotaUsed / key.quota) * 100)"
                                size="sm"
                                :show-value="false"
                                :label="`${key.quotaUsed.toLocaleString('en-IN')} calls`"
                            />
                        </span>

                        <UiButton
                            v-if="key.status === 'active'"
                            variant="ghost"
                            size="xs"
                            @click="revoke(key)"
                        >
                            <template #leading><Ban class="h-3 w-3" style="color: var(--color-danger-500)" /></template>
                            Revoke
                        </UiButton>
                    </li>
                </ul>
            </section>
        </div>

        <UiModal :open="open" :title="editing ? 'Edit plan' : 'New API plan'" size="lg" @close="finish">
            <form id="plan-form" class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Name" required :error="form.errors.name">
                        <UiInput v-model="form.name" placeholder="Growth" />
                    </UiFormField>

                    <UiFormField label="Address in links" :error="form.errors.slug" hint="Left blank, this comes from the name.">
                        <UiInput v-model="form.slug" placeholder="growth" />
                    </UiFormField>
                </div>

                <UiFormField label="Description" :error="form.errors.description">
                    <UiTextarea v-model="form.description" :rows="2" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Monthly quota" required :error="form.errors.monthly_quota">
                        <UiInput v-model="form.monthly_quota" type="number" min="1" />
                    </UiFormField>

                    <UiFormField label="Rate limit a minute" required :error="form.errors.rate_limit_per_minute">
                        <UiInput v-model="form.rate_limit_per_minute" type="number" min="1" />
                    </UiFormField>

                    <UiFormField label="Price (₹)" required :error="form.errors.price" hint="Zero makes it free.">
                        <UiInput v-model="form.price" type="number" step="0.01" min="0" />
                    </UiFormField>

                    <UiFormField label="Billed" required :error="form.errors.interval">
                        <UiSelect
                            v-model="form.interval"
                            :options="[
                                { value: 'monthly', label: 'Monthly' },
                                { value: 'quarterly', label: 'Quarterly' },
                                { value: 'yearly', label: 'Yearly' },
                            ]"
                        />
                    </UiFormField>
                </div>

                <UiSwitch v-model="form.is_active" label="Available to buy" />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="finish">Cancel</UiButton>
                <UiButton type="submit" form="plan-form" :loading="form.processing">
                    {{ editing ? 'Save plan' : 'Create plan' }}
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
