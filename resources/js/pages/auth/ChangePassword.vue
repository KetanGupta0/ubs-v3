<script setup>
/**
 * Changing your own password, and the forced first change for a client whose
 * account was created by an administrator.
 */
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, Eye, EyeOff, ShieldAlert } from 'lucide-vue-next';

import AuthLayout from '@/layouts/AuthLayout.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

defineProps({
    forced: { type: Boolean, default: false },
    hasPassword: { type: Boolean, default: true },
});

const showPassword = ref(false);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put('/password/change', {
        onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Set your password" />

    <AuthLayout
        :title="forced ? 'Set your own password' : 'Change your password'"
        :subtitle="
            forced
                ? 'Your account was created by our team, so your current password was sent to you. Replace it now to make the account fully yours.'
                : 'Choose something you have not used here before.'
        "
    >
        <div
            v-if="forced"
            class="mb-5 flex items-start gap-3 rounded-xl border px-4 py-3"
            style="border-color: var(--color-warn-500); background: color-mix(in oklab, var(--color-warn-500) 10%, transparent)"
        >
            <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0 text-warn-600" aria-hidden="true" />
            <p class="text-sm" style="color: var(--text-base)">
                Until you do this, the password we issued still exists in your email and your messages.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <UiFormField v-if="hasPassword" label="Current password" :error="form.errors.current_password">
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="form.current_password"
                        type="password"
                        :invalid="field.invalid"
                        autocomplete="current-password"
                        required
                    >
                        <template #leading><Lock class="h-4 w-4" /></template>
                    </UiInput>
                </template>
            </UiFormField>

            <UiFormField
                label="New password"
                hint="At least 10 characters, with upper and lower case letters and a number."
                :error="form.errors.password"
            >
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        :invalid="field.invalid"
                        autocomplete="new-password"
                        required
                    >
                        <template #leading><Lock class="h-4 w-4" /></template>
                        <template #trailing>
                            <button
                                type="button"
                                class="rounded-lg p-1.5 transition hover:bg-[var(--surface-sunken)]"
                                style="color: var(--text-muted)"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                @click="showPassword = !showPassword"
                            >
                                <component :is="showPassword ? EyeOff : Eye" class="h-4 w-4" />
                            </button>
                        </template>
                    </UiInput>
                </template>
            </UiFormField>

            <UiFormField label="Confirm new password" :error="form.errors.password_confirmation">
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="form.password_confirmation"
                        type="password"
                        :invalid="field.invalid"
                        autocomplete="new-password"
                        required
                    >
                        <template #leading><Lock class="h-4 w-4" /></template>
                    </UiInput>
                </template>
            </UiFormField>

            <UiButton type="submit" size="lg" block :loading="form.processing">
                {{ forced ? 'Set password and continue' : 'Update password' }}
            </UiButton>
        </form>
    </AuthLayout>
</template>
