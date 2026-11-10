<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { UserPlus, ArrowRight, KeyRound, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    role: { type: String, required: true },
    roleLabel: { type: String, required: true },
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    selfRegisterable: { type: Boolean, default: false },
    canSendCredentials: { type: Boolean, default: false },
});

const statusTones = { active: 'success', suspended: 'danger', pending: 'warning' };
const plural = `${props.roleLabel}s`;

/* -------------------------------------------------------- bulk credentials */

const selected = ref([]);
const sendOpen = ref(false);

const defaultNote =
    'Unboundbyte has moved to a new platform, and your account and history have come across with you. '
    + 'Sign in with the temporary password below and choose your own.';

const sendForm = useForm({ scope: 'selected', ids: [], note: defaultNote });

const matchingTotal = computed(() => props.table.meta?.total ?? 0);

/** True when the list is narrowed, so "everyone matching" means something. */
const isNarrowed = computed(() => {
    const state = props.table.state ?? {};
    const filters = Object.values(state.filter ?? {}).filter((value) => value !== null && value !== '' && value !== false);

    return Boolean(state.q) || filters.length > 0;
});

function openSend(scope) {
    sendForm.clearErrors();
    sendForm.scope = scope;
    sendOpen.value = true;
}

function closeSend() {
    if (!sendForm.processing) {
        sendOpen.value = false;
    }
}

function submitSend() {
    sendForm.ids = sendForm.scope === 'selected' ? selected.value : [];

    // "Everyone matching" sends the list's own search and filters, so the
    // server picks exactly the people this screen is showing.
    const query = sendForm.scope === 'matching' ? window.location.search : '';

    sendForm.post(`/admin/${props.role}s/send-credentials${query}`, {
        preserveScroll: true,
        onSuccess: () => {
            sendOpen.value = false;
            selected.value = [];
        },
    });
}

const sendCount = computed(() => (sendForm.scope === 'selected' ? selected.value.length : matchingTotal.value));
</script>

<template>
    <Head :title="plural" />

    <AppLayout :title="plural" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: plural }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                :title="plural"
                :description="selfRegisterable
                    ? 'Students can sign themselves up, and you can create accounts here too.'
                    : 'Client accounts are only created here. Credentials go out by email and SMS as soon as one is saved.'"
            >
                <template #actions>
                    <UiButton :href="`/admin/${role}s/new`" size="sm">
                        <template #leading><UserPlus class="h-3.5 w-3.5" /></template>
                        Add a {{ roleLabel.toLowerCase() }}
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile :label="`All ${plural.toLowerCase()}`" :value="counts.all ?? 0" />
                <StatTile label="Active" :value="counts.active ?? 0" tone="success" />
                <StatTile
                    label="Never signed in"
                    :value="counts.neverSignedIn ?? 0"
                    :tone="counts.neverSignedIn ? 'warning' : 'neutral'"
                    hint="The welcome message may not have arrived"
                />
            </div>

            <div
                v-if="canSendCredentials && (selected.length || isNarrowed)"
                class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border px-4 py-2.5 text-sm"
                style="border-color: var(--border-subtle); background: var(--surface-raised)"
                role="region"
                aria-label="Bulk actions"
            >
                <span v-if="selected.length" class="font-medium" style="color: var(--text-strong)">
                    {{ selected.length }} selected
                </span>
                <span v-else style="color: var(--text-muted)">
                    {{ matchingTotal }} {{ matchingTotal === 1 ? roleLabel.toLowerCase() : plural.toLowerCase() }} match this view
                </span>

                <span class="ms-auto flex flex-wrap items-center gap-2">
                    <UiButton v-if="selected.length" variant="ghost" size="xs" @click="selected = []">
                        <template #leading><X class="h-3 w-3" /></template>
                        Clear
                    </UiButton>
                    <UiButton v-if="selected.length" size="xs" @click="openSend('selected')">
                        <template #leading><KeyRound class="h-3 w-3" /></template>
                        Send credentials
                    </UiButton>
                    <UiButton
                        v-else-if="isNarrowed && matchingTotal"
                        variant="secondary"
                        size="xs"
                        @click="openSend('matching')"
                    >
                        <template #leading><KeyRound class="h-3 w-3" /></template>
                        Send credentials to all {{ matchingTotal }}
                    </UiButton>
                </span>
            </div>

            <DataTable
                v-model:selected="selected"
                :selectable="canSendCredentials"
                :table="table"
                :search-placeholder="`Search ${plural.toLowerCase()} by name, email or mobile…`"
                :empty-title="`No ${plural.toLowerCase()} match`"
                empty-description="Try clearing the filters, or add one."
                :only="['table']"
            >
                <template #cell:name="{ row }">
                    <Link :href="`/admin/${role}s/${row.id}`" class="flex items-center gap-2.5 hover:underline">
                        <UiAvatar :name="row.name" size="sm" />
                        <span class="font-medium" style="color: var(--text-strong)">{{ row.name }}</span>
                    </Link>
                </template>

                <template #cell:status="{ row }">
                    <span class="inline-flex flex-wrap items-center gap-1.5">
                        <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                        <UiBadge v-if="row.mustChangePassword" tone="warning" size="sm">temporary password</UiBadge>
                    </span>
                </template>

                <template #cell:credentials="{ row }">
                    <span :style="row.credentialsFailed ? { color: 'var(--color-danger-600)' } : {}">
                        {{ row.credentials }}
                    </span>
                </template>

                <template #cell:last_login="{ row }">
                    <span :style="row.neverSignedIn ? { color: 'var(--color-warn-600)' } : {}">
                        {{ row.last_login }}
                    </span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/${role}s/${row.id}`" variant="ghost" size="xs">
                        Open
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/admin/${role}s/${row.id}`" class="block">
                        <div class="flex items-center gap-2.5">
                            <UiAvatar :name="row.name" size="sm" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{ row.name }}</span>
                                <span class="block truncate text-xs" style="color: var(--text-muted)">{{ row.email }}</span>
                            </span>
                            <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <p class="mt-2 text-xs" style="color: var(--text-muted)">
                            Last sign in: {{ row.last_login }}
                        </p>
                    </Link>
                </template>
            </DataTable>
        </div>

        <UiModal
            :open="sendOpen"
            title="Send new credentials"
            :persistent="sendForm.processing"
            @close="closeSend"
        >
            <form id="bulk-credentials-form" class="space-y-4" @submit.prevent="submitSend">
                <p class="text-sm" style="color: var(--text-muted)">
                    {{ sendCount === 1 ? 'One account gets' : `Up to ${sendCount} accounts get` }}
                    a new temporary password by email and SMS, and must choose their own when they sign in.
                    Any password they had before stops working.
                </p>
                <p class="text-sm" style="color: var(--text-muted)">
                    Anybody who already has a password they chose is skipped, so nobody is locked out of
                    their account. Messages go out gradually over the next few minutes.
                </p>

                <UiFormField
                    label="Note in the message"
                    hint="Tell people why they are getting this, so it does not look like phishing."
                    :error="sendForm.errors.note"
                >
                    <UiTextarea v-model="sendForm.note" :rows="4" />
                </UiFormField>

                <p v-if="sendForm.errors.ids || sendForm.errors.scope" class="text-sm" style="color: var(--color-danger-600)">
                    {{ sendForm.errors.ids || sendForm.errors.scope }}
                </p>
            </form>

            <template #footer>
                <UiButton variant="ghost" :disabled="sendForm.processing" @click="closeSend">Cancel</UiButton>
                <UiButton type="submit" form="bulk-credentials-form" :loading="sendForm.processing">
                    Send credentials
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
