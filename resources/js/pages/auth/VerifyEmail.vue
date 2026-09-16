<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { MailCheck } from 'lucide-vue-next';

import AuthLayout from '@/layouts/AuthLayout.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    status: { type: String, default: null },
    email: { type: String, default: '' },
});

const form = useForm({});

function resend() {
    form.post('/verify-email/resend', { preserveScroll: true });
}

function signOut() {
    router.post('/logout');
}
</script>

<template>
    <Head title="Confirm your email" />

    <AuthLayout
        title="Confirm your email"
        :subtitle="`We sent a link to ${email}. Open it to confirm the address is yours.`"
    >
        <p
            v-if="status"
            class="mb-5 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm"
            style="border-color: var(--border-subtle); background: var(--surface-sunken); color: var(--text-base)"
        >
            <MailCheck class="mt-0.5 h-4 w-4 shrink-0 text-signal-500" aria-hidden="true" />
            {{ status }}
        </p>

        <div class="space-y-3">
            <UiButton size="lg" block :loading="form.processing" @click="resend">
                Send the link again
            </UiButton>

            <UiButton variant="ghost" size="lg" block @click="signOut">Sign out</UiButton>
        </div>

        <template #footer>
            Nothing arrived? Check your spam folder, or sign in with a one time code instead.
        </template>
    </AuthLayout>
</template>
