<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Mail, ArrowRight } from 'lucide-vue-next';

import AuthLayout from '@/layouts/AuthLayout.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const form = useForm({ email: '' });

function submit() {
    form.post('/forgot-password');
}
</script>

<template>
    <Head title="Forgot password" />

    <AuthLayout
        title="Reset your password"
        subtitle="Enter your email address and we will send you a link to set a new one."
    >
        <p
            v-if="$page.props.flash?.status"
            class="mb-5 rounded-xl border px-4 py-3 text-sm"
            style="border-color: var(--border-subtle); background: var(--surface-sunken); color: var(--text-base)"
        >
            {{ $page.props.flash.status }}
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <UiFormField label="Email address" :error="form.errors.email">
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

            <UiButton type="submit" size="lg" block :loading="form.processing">
                Send reset link
                <template #trailing><ArrowRight class="h-4 w-4" /></template>
            </UiButton>
        </form>

        <template #footer>
            Remembered it?
            <Link href="/login" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">
                Back to sign in
            </Link>
        </template>
    </AuthLayout>
</template>
