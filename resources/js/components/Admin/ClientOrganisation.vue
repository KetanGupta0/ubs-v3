<script setup>
/**
 * A client's organisation on their page in the panel (CL8): who is in it and
 * in what role, or, for a member, which organisation they work in.
 */
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Building2, Trash2, UserPlus } from 'lucide-vue-next';

import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';

const props = defineProps({
    organisation: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const base = `/admin/clients/${props.organisation.accountId}/members`;
const adding = ref(false);
const form = useForm({ name: '', email: '', mobile: '', role: 'technical' });

function openAdd() {
    form.reset();
    form.clearErrors();
    adding.value = true;
}

const add = () => form.post(base, { preserveScroll: true, onSuccess: () => { adding.value = false; } });
const setRole = (member, role) => router.put(`${base}/${member.id}`, { role }, { preserveScroll: true });

function remove(member) {
    if (window.confirm(member.pending ? `Withdraw the invitation to ${member.email}?` : `Take ${member.name} out of ${props.organisation.name}?`)) {
        router.delete(`${base}/${member.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <UiCard>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <Building2 class="h-4 w-4" style="color: var(--text-muted)" />
                    {{ organisation.name }}
                </h2>
                <UiButton v-if="canManage" variant="secondary" size="sm" @click="openAdd">
                    <template #leading><UserPlus class="h-3.5 w-3.5" /></template>
                    Add a person
                </UiButton>
            </div>
        </template>

        <p v-if="!organisation.isAccount" class="mb-3 text-sm">
            Works in this organisation as
            <strong>{{ roles.find((role) => role.value === organisation.role)?.label?.toLowerCase() }}</strong>.
            Its records belong to
            <Link :href="`/admin/clients/${organisation.accountId}`" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ organisation.accountName }}</Link>'s account.
        </p>

        <ul class="divide-y" style="border-color: var(--border-subtle)">
            <li class="flex flex-wrap items-center gap-2 py-2.5 first:pt-0 text-sm">
                <span class="min-w-0 flex-1">
                    <Link :href="`/admin/clients/${organisation.accountId}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ organisation.accountName }}</Link>
                </span>
                <UiBadge tone="brand" size="sm">Owner · account holder</UiBadge>
            </li>
            <li v-for="member in organisation.members" :key="member.id" class="flex flex-wrap items-center gap-2 py-2.5 last:pb-0 text-sm">
                <span class="min-w-0 flex-1">
                    <Link :href="`/admin/clients/${member.userId}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ member.name }}</Link>
                    <span class="block text-xs" style="color: var(--text-muted)">{{ member.email }}{{ member.pending ? ' · invited, not accepted yet' : '' }}</span>
                </span>
                <template v-if="canManage">
                    <UiSelect :model-value="member.role" :options="roles" size="sm" class="w-44" :aria-label="`Role for ${member.name}`" @update:model-value="setRole(member, $event)" />
                    <UiButton variant="ghost" size="xs" :aria-label="`Take ${member.name} out`" @click="remove(member)">
                        <Trash2 class="h-3.5 w-3.5" />
                    </UiButton>
                </template>
                <UiBadge v-else size="sm">{{ member.roleLabel }}</UiBadge>
            </li>
        </ul>
        <p v-if="!organisation.members.length" class="mt-2 text-xs" style="color: var(--text-muted)">Nobody else yet. Invoices, proposals and updates go to the account holder.</p>
    </UiCard>

    <UiModal :open="adding" :title="`Add a person to ${organisation.name}`" description="A new address gets an account and its sign in details. An existing client account with nothing of its own is asked to accept." @close="adding = false">
        <form id="client-member-form" class="space-y-4" @submit.prevent="add">
            <UiFormField label="Name" required :error="form.errors.name">
                <UiInput v-model="form.name" />
            </UiFormField>
            <div class="grid gap-4 sm:grid-cols-2">
                <UiFormField label="Email" required :error="form.errors.email">
                    <UiInput v-model="form.email" type="email" autocapitalize="none" />
                </UiFormField>
                <UiFormField label="Mobile" hint="Optional." :error="form.errors.mobile">
                    <UiInput v-model="form.mobile" type="tel" />
                </UiFormField>
            </div>
            <UiFormField label="Role" :error="form.errors.role">
                <UiSelect v-model="form.role" :options="roles" />
            </UiFormField>
        </form>
        <template #footer>
            <UiButton variant="ghost" @click="adding = false">Cancel</UiButton>
            <UiButton type="submit" form="client-member-form" :loading="form.processing">Add</UiButton>
        </template>
    </UiModal>
</template>
