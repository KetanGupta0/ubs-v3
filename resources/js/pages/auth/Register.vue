<script setup>
/**
 * Student self registration. Clients are created by an administrator.
 */
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { User, Mail, Phone, Lock, Eye, EyeOff, ArrowRight } from 'lucide-vue-next';

import AuthLayout from '@/layouts/AuthLayout.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import GoogleButton from '@/components/Auth/GoogleButton.vue';

defineProps({
    googleEnabled: { type: Boolean, default: false },
});

const showPassword = ref(false);

const form = useForm({
    name: '',
    email: '',
    mobile: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Create your account" />

    <AuthLayout
        title="Create your account"
        subtitle="For students joining a programme. Takes about a minute."
        width="max-w-lg"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <UiFormField label="Full name" required :error="form.errors.name">
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="form.name"
                        :invalid="field.invalid"
                        autocomplete="name"
                        placeholder="Rahul Verma"
                        required
                    >
                        <template #leading><User class="h-4 w-4" /></template>
                    </UiInput>
                </template>
            </UiFormField>

            <div class="grid gap-4 sm:grid-cols-2">
                <UiFormField label="Email" required :error="form.errors.email">
                    <template #default="field">
                        <UiInput
                            :id="field.id"
                            v-model="form.email"
                            type="email"
                            :invalid="field.invalid"
                            autocomplete="email"
                            autocapitalize="none"
                            placeholder="you@example.com"
                            required
                        >
                            <template #leading><Mail class="h-4 w-4" /></template>
                        </UiInput>
                    </template>
                </UiFormField>

                <UiFormField
                    label="Mobile"
                    required
                    hint="Used for class reminders."
                    :error="form.errors.mobile"
                >
                    <template #default="field">
                        <UiInput
                            :id="field.id"
                            v-model="form.mobile"
                            type="tel"
                            :invalid="field.invalid"
                            autocomplete="tel"
                            placeholder="98765 43210"
                            required
                        >
                            <template #leading><Phone class="h-4 w-4" /></template>
                        </UiInput>
                    </template>
                </UiFormField>
            </div>

            <UiFormField
                label="Password"
                required
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

            <UiFormField label="Confirm password" required :error="form.errors.password_confirmation">
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

            <div>
                <UiCheckbox v-model="form.terms" label="I agree to the terms and the privacy policy" />
                <p v-if="form.errors.terms" class="mt-1 text-xs font-medium text-danger-500">
                    {{ form.errors.terms }}
                </p>
            </div>

            <UiButton type="submit" size="lg" block :loading="form.processing">
                Create account
                <template #trailing><ArrowRight class="h-4 w-4" /></template>
            </UiButton>
        </form>

        <template v-if="googleEnabled">
            <div class="my-6 flex items-center gap-3">
                <span class="h-px flex-1" style="background: var(--border-subtle)" />
                <span class="text-xs uppercase tracking-wide" style="color: var(--text-muted)">or</span>
                <span class="h-px flex-1" style="background: var(--border-subtle)" />
            </div>

            <GoogleButton label="Sign up with Google" />
        </template>

        <template #footer>
            Already have an account?
            <Link href="/login" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">
                Sign in
            </Link>
        </template>
    </AuthLayout>
</template>
