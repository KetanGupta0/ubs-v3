<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { UserPlus, ArrowRight, Users } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';

const props = defineProps({
    role: { type: String, required: true },
    roleLabel: { type: String, required: true },
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    selfRegisterable: { type: Boolean, default: false },
});

const statusTones = { active: 'success', suspended: 'danger', pending: 'warning' };
const plural = `${props.roleLabel}s`;
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

            <DataTable
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
    </AppLayout>
</template>
