<script setup>
/**
 * The people in a client organisation. Everybody sees who is in it; an owner
 * brings people in, changes their role and takes them out.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Mail, UserPlus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';

const props = defineProps({
    organisation: { type: String, required: true },
    holder: { type: Object, required: true },
    members: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    myRole: { type: String, default: null },
    canManage: { type: Boolean, default: false },
});

const inviting = ref(false);
const invite = useForm({ name: '', email: '', mobile: '', role: 'technical' });

function openInvite() {
    invite.reset();
    invite.clearErrors();
    inviting.value = true;
}

const sendInvite = () => invite.post('/client/people', { preserveScroll: true, onSuccess: () => { inviting.value = false; } });

function changeRole(member, role) {
    router.put(`/client/people/${member.id}`, { role }, { preserveScroll: true });
}

function remove(member) {
    const question = member.pending
        ? `Withdraw the invitation to ${member.email}?`
        : `Take ${member.name} out of ${props.organisation}? They keep their account but stop seeing its work.`;

    if (window.confirm(question)) {
        router.delete(`/client/people/${member.id}`, { preserveScroll: true });
    }
}

const roleOptions = props.roles.map((role) => ({ value: role.value, label: role.label }));
</script>

<template>
    <Head title="People" />

    <AppLayout title="People" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'People' }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                :title="`People in ${organisation}`"
                description="Everybody here sees the organisation's projects, documents and invoices. What they may do depends on their role."
            >
                <template v-if="canManage" #actions>
                    <UiButton size="sm" @click="openInvite">
                        <template #leading><UserPlus class="h-3.5 w-3.5" /></template>
                        Add a person
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li class="flex flex-wrap items-center gap-3 py-3 first:pt-0">
                        <UiAvatar :name="holder.name" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium" style="color: var(--text-strong)">{{ holder.name }}<span v-if="holder.me" class="text-xs font-normal" style="color: var(--text-muted)"> · you</span></span>
                            <span class="block text-xs" style="color: var(--text-muted)">{{ holder.email }}</span>
                        </span>
                        <UiBadge tone="brand" size="sm">Owner · account holder</UiBadge>
                    </li>

                    <li v-for="member in members" :key="member.id" class="flex flex-wrap items-center gap-3 py-3 last:pb-0">
                        <UiAvatar :name="member.name || member.email" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium" style="color: var(--text-strong)">
                                {{ member.name }}<span v-if="member.me" class="text-xs font-normal" style="color: var(--text-muted)"> · you</span>
                            </span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ member.email }}
                                <template v-if="member.pending"> · invited by {{ member.invitedBy }}, not accepted yet</template>
                                <template v-else-if="member.lastSignIn"> · last signed in {{ member.lastSignIn }}</template>
                            </span>
                        </span>

                        <UiBadge v-if="member.pending" tone="warning" size="sm">
                            <Mail class="mr-1 h-3 w-3" /> Invited
                        </UiBadge>

                        <div v-if="canManage && !member.me" class="flex items-center gap-2">
                            <UiSelect
                                :model-value="member.role"
                                :options="roleOptions"
                                size="sm"
                                class="w-48"
                                :aria-label="`Role for ${member.name}`"
                                @update:model-value="changeRole(member, $event)"
                            />
                            <UiButton variant="ghost" size="xs" :aria-label="member.pending ? `Withdraw the invitation to ${member.email}` : `Take ${member.name} out`" @click="remove(member)">
                                <Trash2 class="h-3.5 w-3.5" />
                            </UiButton>
                        </div>
                        <UiBadge v-else size="sm">{{ member.roleLabel }}</UiBadge>
                    </li>
                </ul>
            </UiCard>

            <UiCard>
                <template #header><h2 class="text-sm font-semibold">What each role may do</h2></template>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div v-for="role in roles" :key="role.value">
                        <dt class="text-sm font-medium" style="color: var(--text-strong)">{{ role.label }}</dt>
                        <dd class="text-sm" style="color: var(--text-muted)">{{ role.description }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs" style="color: var(--text-muted)">
                    Everybody can read the organisation's work and raise a support ticket. Invoices are sent to finance, proposals and agreements to signatories.
                </p>
            </UiCard>
        </div>

        <UiModal :open="inviting" title="Add a person" description="Somebody new gets their sign in details by email. Somebody who already has an account here is asked to accept." @close="inviting = false">
            <form id="invite-form" class="space-y-4" @submit.prevent="sendInvite">
                <UiFormField label="Name" required :error="invite.errors.name">
                    <UiInput v-model="invite.name" autocomplete="off" />
                </UiFormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Email" required :error="invite.errors.email">
                        <UiInput v-model="invite.email" type="email" autocapitalize="none" />
                    </UiFormField>
                    <UiFormField label="Mobile" hint="Optional." :error="invite.errors.mobile">
                        <UiInput v-model="invite.mobile" type="tel" />
                    </UiFormField>
                </div>
                <UiFormField label="Role" :hint="roles.find((role) => role.value === invite.role)?.description" :error="invite.errors.role">
                    <UiSelect v-model="invite.role" :options="roleOptions" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="inviting = false">Cancel</UiButton>
                <UiButton type="submit" form="invite-form" :loading="invite.processing">Send the invitation</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
