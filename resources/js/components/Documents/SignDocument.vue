<script setup>
/**
 * Signing a document with a typed name and a one time code (CL2).
 *
 * The code goes to the signer's own registered email address; the server
 * checks it, records the SHA-256 of the PDF that was sent and refuses if the
 * document has moved on. Nothing here decides anything: it collects the four
 * things a signature needs and shows what the server answered.
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Mail, PenLine } from 'lucide-vue-next';

import OtpInput from '@/components/Auth/OtpInput.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    codeUrl: { type: String, required: true },
    signUrl: { type: String, required: true },
    pdfUrl: { type: String, required: true },
    sha256: { type: String, default: null },
    heading: { type: String, default: 'Sign this document' },
    intro: { type: String, default: null },
    confirmLabel: { type: String, default: 'I have read the PDF and agree to it.' },
    buttonLabel: { type: String, default: 'Sign' },
    withNote: { type: Boolean, default: false },
});

// The name is typed, never filled in: typing it is the act of signing, and
// the account's name may be the organisation's rather than the person's.
const form = useForm({
    name: '',
    designation: '',
    code: '',
    note: '',
    confirm: false,
});

const sending = ref(false);
const sentTo = ref(null);
const hash = ref(props.sha256);
const codeProblem = ref(null);

const ready = computed(() => form.name.trim() !== '' && form.confirm && form.code.length === 6 && !form.processing);

async function sendCode() {
    sending.value = true;
    codeProblem.value = null;

    try {
        const response = await fetch(props.codeUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
        });

        const body = await response.json().catch(() => ({}));

        if (response.ok) {
            sentTo.value = body.sentTo;
            hash.value = body.sha256 ?? hash.value;
            form.code = '';
            form.clearErrors('code');
        } else if (response.status === 429) {
            codeProblem.value = 'Too many tries for now. Wait a minute and ask again.';
        } else {
            codeProblem.value = body.message ?? 'The code could not be sent. Try again in a moment.';
        }
    } catch {
        codeProblem.value = 'The code could not be sent. Check your connection and try again.';
    } finally {
        sending.value = false;
    }
}

function sign() {
    form.post(props.signUrl, {
        preserveScroll: true,
        onError: () => {
            form.code = '';
        },
    });
}
</script>

<template>
    <UiCard>
        <div class="flex items-start gap-3">
            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-500/10 text-brand-600 dark:text-brand-400">
                <PenLine class="h-4 w-4" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">{{ heading }}</h2>
                <p class="mt-1 text-sm" style="color: var(--text-muted)">
                    <template v-if="intro">{{ intro }} </template>
                    Read the <a :href="pdfUrl" class="font-medium text-brand-600 hover:underline dark:text-brand-400">PDF</a> first: that file is what you sign.
                    We send a one time code to your email address to confirm it is you.
                </p>
            </div>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="sign">
            <div class="grid gap-4 sm:grid-cols-2">
                <UiFormField label="Your full name, as your signature" :error="form.errors.name" required>
                    <UiInput v-model="form.name" autocomplete="name" maxlength="160" />
                </UiFormField>
                <UiFormField label="Designation" hint="Optional, such as Director." :error="form.errors.designation">
                    <UiInput v-model="form.designation" autocomplete="organization-title" maxlength="120" />
                </UiFormField>
            </div>

            <UiFormField v-if="withNote" label="A note for us" hint="Optional." :error="form.errors.note">
                <UiTextarea v-model="form.note" rows="2" maxlength="2000" />
            </UiFormField>

            <div>
                <UiCheckbox v-model="form.confirm" :label="confirmLabel" />
                <p v-if="form.errors.confirm" class="mt-1 text-xs font-medium text-danger-500">{{ form.errors.confirm }}</p>
            </div>

            <div class="rounded-xl border p-4" style="border-color: var(--border-subtle); background: var(--surface-sunken)">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm" style="color: var(--text-muted)" aria-live="polite">
                        <template v-if="sentTo">We sent a code to <strong style="color: var(--text-strong)">{{ sentTo }}</strong>. It lasts ten minutes.</template>
                        <template v-else>Ask for a code when you are ready to sign.</template>
                    </p>
                    <UiButton type="button" variant="secondary" size="sm" :loading="sending" @click="sendCode">
                        <template #leading><Mail class="h-3.5 w-3.5" /></template>
                        {{ sentTo ? 'Send another code' : 'Email me a code' }}
                    </UiButton>
                </div>
                <p v-if="codeProblem" class="mt-2 text-sm font-medium text-danger-500" role="alert">{{ codeProblem }}</p>

                <UiFormField v-if="sentTo" class="mt-4" label="Code from the email" :error="form.errors.code">
                    <template #default>
                        <OtpInput v-model="form.code" :invalid="Boolean(form.errors.code)" />
                    </template>
                </UiFormField>
            </div>

            <p v-if="hash" class="break-all font-mono text-[0.65rem]" style="color: var(--text-muted)">You are signing the PDF whose SHA-256 is {{ hash }}</p>

            <div class="flex justify-end">
                <UiButton type="submit" :disabled="!ready" :loading="form.processing">
                    <template #leading><PenLine class="h-4 w-4" /></template>
                    {{ buttonLabel }}
                </UiButton>
            </div>
        </form>
    </UiCard>
</template>
