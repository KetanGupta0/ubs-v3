<script setup>
/**
 * The second factor, asked for after a password, a code or Google.
 */
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ShieldCheck, LifeBuoy } from 'lucide-vue-next';

import AuthLayout from '@/layouts/AuthLayout.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import OtpInput from '@/components/Auth/OtpInput.vue';

defineProps({
    recoveryCodesRemaining: { type: Number, default: 0 },
});

const useRecovery = ref(false);
const form = useForm({ code: '' });

function submit() {
    form.post('/two-factor-challenge', {
        onError: () => form.reset('code'),
    });
}

function toggleMode() {
    useRecovery.value = !useRecovery.value;
    form.reset('code');
    form.clearErrors();
}

function cancel() {
    router.delete('/two-factor-challenge');
}
</script>

<template>
    <Head title="Two factor authentication" />

    <AuthLayout
        title="One more step"
        :subtitle="
            useRecovery
                ? 'Enter one of the recovery codes you saved when you turned this on.'
                : 'Open your authenticator app and enter the six digit code it is showing.'
        "
    >
        <form class="space-y-5" @submit.prevent="submit">
            <UiFormField v-if="useRecovery" label="Recovery code" :error="form.errors.code">
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="form.code"
                        :invalid="field.invalid"
                        class="font-mono"
                        placeholder="ABC123-DEF456"
                        autocomplete="one-time-code"
                        autocapitalize="characters"
                        required
                    />
                </template>
            </UiFormField>

            <UiFormField v-else label="Authentication code" :error="form.errors.code">
                <template #default>
                    <OtpInput v-model="form.code" :invalid="Boolean(form.errors.code)" @complete="submit" />
                </template>
            </UiFormField>

            <UiButton
                type="submit"
                size="lg"
                block
                :loading="form.processing"
                :disabled="useRecovery ? !form.code : form.code.length !== 6"
            >
                <template #leading><ShieldCheck class="h-4 w-4" /></template>
                Verify
            </UiButton>
        </form>

        <button
            type="button"
            class="mt-5 inline-flex w-full items-center justify-center gap-2 text-sm font-medium transition hover:text-[var(--text-strong)]"
            style="color: var(--text-muted)"
            @click="toggleMode"
        >
            <LifeBuoy class="h-3.5 w-3.5" />
            {{ useRecovery ? 'Use my authenticator app instead' : 'I do not have my phone' }}
        </button>

        <p
            v-if="useRecovery && recoveryCodesRemaining"
            class="mt-2 text-center text-xs"
            style="color: var(--text-muted)"
        >
            {{ recoveryCodesRemaining }} recovery {{ recoveryCodesRemaining === 1 ? 'code' : 'codes' }} left.
            Each one works once.
        </p>

        <template #footer>
            <button type="button" class="font-medium hover:underline" @click="cancel">
                Cancel and sign in as someone else
            </button>
        </template>
    </AuthLayout>
</template>
