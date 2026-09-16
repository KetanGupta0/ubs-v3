<script setup>
/**
 * Staff accounts and what each one may do.
 *
 * Permissions are edited in a dialog next to the person they apply to, because
 * "what can Priya do" is the question being asked, and answering it should not
 * require holding a separate matrix in your head.
 */
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { UserPlus, ShieldCheck, KeyRound, Pause, Play } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    staff: { type: Array, default: () => [] },
    permissionGroups: { type: Array, default: () => [] },
});

const statusTones = { active: 'success', suspended: 'danger', pending: 'warning' };

const allKeys = computed(() =>
    props.permissionGroups.flatMap((group) => group.permissions.map((permission) => permission.key)),
);

/* ------------------------------------------------------- adding a member */

const inviteOpen = ref(false);

const inviteForm = useForm({
    name: '',
    email: '',
    mobile: '',
    permissions: [],
});

function openInvite() {
    inviteForm.reset();
    inviteForm.clearErrors();
    inviteOpen.value = true;
}

function invite() {
    inviteForm.post('/admin/staff', {
        preserveScroll: true,
        onSuccess: () => {
            inviteOpen.value = false;
            inviteForm.reset();
        },
    });
}

/* --------------------------------------------------- editing permissions */

const editing = ref(null);

const permissionForm = useForm({ permissions: [] });

function openPermissions(member) {
    editing.value = member;
    permissionForm.clearErrors();
    permissionForm.permissions = [...(member.permissions ?? [])];
}

function savePermissions() {
    permissionForm.put(`/admin/staff/${editing.value.id}/permissions`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

function toggleGroup(group, form) {
    const keys = group.permissions.map((permission) => permission.key);
    const allOn = keys.every((key) => form.permissions.includes(key));

    form.permissions = allOn
        ? form.permissions.filter((key) => !keys.includes(key))
        : [...new Set([...form.permissions, ...keys])];
}

function setStatus(member, status) {
    const verb = status === 'suspended' ? 'Suspend' : 'Reactivate';

    if (confirm(`${verb} ${member.name}?`)) {
        router.put(`/admin/staff/${member.id}/status`, { status }, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Staff" />

    <AppLayout title="Staff" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Staff' }]">
        <div class="mx-auto max-w-5xl">
            <PageHeader
                title="Staff"
                description="Who works here, and what each of them may do. The owner holds everything; everybody else holds only what is ticked."
            >
                <template #actions>
                    <UiButton size="sm" @click="openInvite">
                        <template #leading><UserPlus class="h-3.5 w-3.5" /></template>
                        Add a staff member
                    </UiButton>
                </template>
            </PageHeader>

            <ul
                class="divide-y overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)]"
                style="border-color: var(--border-subtle)"
            >
                <li
                    v-for="member in staff"
                    :key="member.id"
                    class="flex flex-wrap items-center gap-4 p-4 transition hover:bg-[var(--surface-sunken)]"
                >
                    <UiAvatar :name="member.name" />

                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ member.name }}
                            <UiBadge v-if="member.isOwner" tone="brand" size="sm">owner</UiBadge>
                            <UiBadge :tone="statusTones[member.status] ?? 'neutral'" size="sm" dot>
                                {{ member.status }}
                            </UiBadge>
                            <UiBadge v-if="member.mustChangePassword" tone="warning" size="sm">
                                temporary password
                            </UiBadge>
                        </p>
                        <p class="truncate text-xs" style="color: var(--text-muted)">{{ member.email }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" style="color: var(--text-muted)">
                            <span class="inline-flex items-center gap-1">
                                <ShieldCheck class="h-3 w-3" />
                                {{ member.isOwner ? 'Everything' : `${member.permissionCount} permission${member.permissionCount === 1 ? '' : 's'}` }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <KeyRound class="h-3 w-3" />
                                {{ member.twoFactorEnabled ? 'Two factor on' : 'Two factor off' }}
                            </span>
                            <span>Last sign in: {{ member.lastLoginAgo }}</span>
                        </p>
                    </div>

                    <div v-if="member.isOwner" class="text-xs" style="color: var(--text-muted)">
                        Unrestricted
                    </div>

                    <div v-else class="flex items-center gap-1.5">
                        <UiButton variant="ghost" size="xs" @click="openPermissions(member)">Permissions</UiButton>
                        <UiButton
                            v-if="member.status === 'suspended'"
                            variant="ghost"
                            size="xs"
                            @click="setStatus(member, 'active')"
                        >
                            <template #leading><Play class="h-3 w-3" /></template>
                            Reactivate
                        </UiButton>
                        <UiButton v-else variant="ghost" size="xs" @click="setStatus(member, 'suspended')">
                            <template #leading><Pause class="h-3 w-3" /></template>
                            Suspend
                        </UiButton>
                    </div>
                </li>
            </ul>
        </div>

        <!-- ---------------------------------------------- invite dialog -->
        <UiModal
            :open="inviteOpen"
            title="Add a staff member"
            description="They get a temporary password by email and SMS, and have to change it on the first sign in."
            size="lg"
            @close="inviteOpen = false"
        >
            <form id="staff-form" class="space-y-4" @submit.prevent="invite">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Name" required :error="inviteForm.errors.name">
                        <UiInput v-model="inviteForm.name" />
                    </UiFormField>

                    <UiFormField label="Email" required :error="inviteForm.errors.email">
                        <UiInput v-model="inviteForm.email" type="email" />
                    </UiFormField>

                    <UiFormField
                        label="Mobile"
                        :error="inviteForm.errors.mobile"
                        hint="Optional. With it, the password also goes out by SMS."
                        class="sm:col-span-2"
                    >
                        <UiInput v-model="inviteForm.mobile" type="tel" placeholder="+91 98765 43210" />
                    </UiFormField>
                </div>

                <fieldset class="space-y-4">
                    <legend class="text-sm font-medium">Permissions</legend>

                    <div v-for="group in permissionGroups" :key="group.group" class="space-y-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                {{ group.group }}
                            </p>
                            <button
                                type="button"
                                class="text-xs underline"
                                style="color: var(--text-muted)"
                                @click="toggleGroup(group, inviteForm)"
                            >
                                Toggle all
                            </button>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <UiCheckbox
                                v-for="permission in group.permissions"
                                :key="permission.key"
                                v-model="inviteForm.permissions"
                                :value="permission.key"
                                :label="permission.label"
                            />
                        </div>
                    </div>
                </fieldset>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="inviteOpen = false">Cancel</UiButton>
                <UiButton type="submit" form="staff-form" :loading="inviteForm.processing">Create account</UiButton>
            </template>
        </UiModal>

        <!-- ----------------------------------------- permissions dialog -->
        <UiModal
            :open="Boolean(editing)"
            :title="editing ? `What ${editing.name} may do` : ''"
            size="lg"
            @close="editing = null"
        >
            <form id="permissions-form" class="space-y-4" @submit.prevent="savePermissions">
                <div v-for="group in permissionGroups" :key="group.group" class="space-y-2">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                            {{ group.group }}
                        </p>
                        <button
                            type="button"
                            class="text-xs underline"
                            style="color: var(--text-muted)"
                            @click="toggleGroup(group, permissionForm)"
                        >
                            Toggle all
                        </button>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <UiCheckbox
                            v-for="permission in group.permissions"
                            :key="permission.key"
                            v-model="permissionForm.permissions"
                            :value="permission.key"
                            :label="permission.label"
                        />
                    </div>
                </div>

                <p class="text-xs" style="color: var(--text-muted)">
                    {{ permissionForm.permissions.length }} of {{ allKeys.length }} selected.
                </p>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="editing = null">Cancel</UiButton>
                <UiButton type="submit" form="permissions-form" :loading="permissionForm.processing">
                    Save permissions
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
