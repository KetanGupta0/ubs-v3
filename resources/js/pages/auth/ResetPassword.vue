<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, Eye, EyeOff } from 'lucide-vue-next';

import AuthLayout from '@/layouts/AuthLayout.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const showPassword = ref(false);

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Set a new password" />

    <AuthLayout title="Set a new password" subtitle="Choose something you have not used here before.">
        <form class="space-y-4" @submit.prevent="submit">
            <UiFormField label="Email address" :error="form.errors.email">
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="form.email"
                        type="email"
                        :invalid="field.invalid"
                        autocomplete="email"
                        required
                    />
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

            <UiButton type="submit" size="lg" block :loading="form.processing">Reset password</UiButton>
        </form>
    </AuthLayout>
</template>
