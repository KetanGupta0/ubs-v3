<script setup>
/**
 * The single sign in screen.
 *
 * Administrators, clients and students all arrive here. Password and one time
 * code sit behind a toggle rather than on separate pages, so nobody has to
 * guess which door is theirs before they have typed anything.
 */
import { ref, computed, watch, onUnmounted } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Mail, Lock, KeyRound, Eye, EyeOff, ArrowRight, Smartphone } from 'lucide-vue-next';

import AuthLayout from '@/layouts/AuthLayout.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import GoogleButton from '@/components/Auth/GoogleButton.vue';
import OtpInput from '@/components/Auth/OtpInput.vue';

defineProps({
    canRegister: { type: Boolean, default: true },
    googleEnabled: { type: Boolean, default: false },
    status: { type: String, default: null },
});

const page = usePage();

/** 'password' or 'code'. */
const method = ref('password');
const showPassword = ref(false);
/** Set once a code has actually been sent, which swaps the form to code entry. */
const codeSentTo = ref(null);

const passwordForm = useForm({
    identifier: '',
    password: '',
    remember: false,
});

const codeRequestForm = useForm({ identifier: '' });
const codeVerifyForm = useForm({ identifier: '', code: '' });

/* Resend cooldown, so the button says what the server would enforce anyway. */
const cooldown = ref(0);
let timer = null;

function startCooldown(seconds = 60) {
    cooldown.value = seconds;
    clearInterval(timer);
    timer = setInterval(() => {
        cooldown.value -= 1;
        if (cooldown.value <= 0) clearInterval(timer);
    }, 1000);
}

onUnmounted(() => clearInterval(timer));

// The controller flashes where the code went, without echoing the full address.
watch(
    () => page.props.flash?.otpSentTo,
    (sentTo) => {
        if (!sentTo) return;
        codeSentTo.value = sentTo;
        codeVerifyForm.identifier = page.props.flash.otpIdentifier ?? codeRequestForm.identifier;
        startCooldown();
    },
);

function submitPassword() {
    passwordForm.post('/login', {
        onFinish: () => passwordForm.reset('password'),
    });
}

function requestCode() {
    codeRequestForm.post('/login/code', { preserveScroll: true });
}

function verifyCode() {
    codeVerifyForm.post('/login/code/verify', {
        onError: () => codeVerifyForm.reset('code'),
    });
}

function changeIdentifier() {
    codeSentTo.value = null;
    codeVerifyForm.reset();
}

const identifierHint = computed(() =>
    method.value === 'code'
        ? 'We will send a code to whichever one you enter.'
        : 'Either works, whichever you registered with.',
);
</script>

<template>
    <Head title="Sign in" />

    <AuthLayout
        title="Sign in"
        subtitle="One account for your projects, your courses and your team."
    >
        <p
            v-if="status"
            class="mb-5 rounded-xl border px-4 py-3 text-sm"
            style="border-color: var(--border-subtle); background: var(--surface-sunken); color: var(--text-base)"
        >
            {{ status }}
        </p>

        <!-- Method switch. Hidden once a code is in flight, because at that
             point the only sensible next action is to type it. -->
        <div
            v-if="!codeSentTo"
            class="mb-6 grid grid-cols-2 gap-1 rounded-xl p-1"
            style="background: var(--surface-sunken)"
            role="tablist"
        >
            <button
                v-for="option in [
                    { value: 'password', label: 'Password', icon: Lock },
                    { value: 'code', label: 'One time code', icon: KeyRound },
                ]"
                :key="option.value"
                type="button"
                role="tab"
                :aria-selected="method === option.value"
                :class="[
                    'inline-flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium transition',
                    method === option.value
                        ? 'bg-[var(--surface)] shadow-sm text-[var(--text-strong)]'
                        : 'text-[var(--text-muted)] hover:text-[var(--text-strong)]',
                ]"
                @click="method = option.value"
            >
                <component :is="option.icon" class="h-4 w-4" />
                {{ option.label }}
            </button>
        </div>

        <!-- ------------------------------------------------- password sign in -->
        <form v-if="method === 'password'" class="space-y-4" @submit.prevent="submitPassword">
            <UiFormField
                label="Email or mobile number"
                :hint="identifierHint"
                :error="passwordForm.errors.identifier"
            >
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="passwordForm.identifier"
                        :invalid="field.invalid"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        placeholder="you@example.com or 98765 43210"
                        required
                    >
                        <template #leading><Mail class="h-4 w-4" /></template>
                    </UiInput>
                </template>
            </UiFormField>

            <UiFormField label="Password" :error="passwordForm.errors.password">
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="passwordForm.password"
                        :type="showPassword ? 'text' : 'password'"
                        :invalid="field.invalid"
                        autocomplete="current-password"
                        placeholder="Your password"
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

            <div class="flex items-center justify-between">
                <UiCheckbox v-model="passwordForm.remember" label="Keep me signed in" />
                <Link
                    href="/forgot-password"
                    class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                >
                    Forgot password?
                </Link>
            </div>

            <UiButton type="submit" size="lg" block :loading="passwordForm.processing">
                Sign in
                <template #trailing><ArrowRight class="h-4 w-4" /></template>
            </UiButton>
        </form>

        <!-- ----------------------------------------------- one time code entry -->
        <form v-else-if="codeSentTo" class="space-y-5" @submit.prevent="verifyCode">
            <div
                class="flex items-start gap-3 rounded-xl border px-4 py-3"
                style="border-color: var(--border-subtle); background: var(--surface-sunken)"
            >
                <Smartphone class="mt-0.5 h-4 w-4 shrink-0 text-brand-500" aria-hidden="true" />
                <p class="text-sm" style="color: var(--text-base)">
                    If that account exists, a six digit code is on its way to
                    <span class="font-medium" style="color: var(--text-strong)">{{ codeSentTo }}</span>.
                </p>
            </div>

            <UiFormField label="Enter the code" :error="codeVerifyForm.errors.code">
                <template #default>
                    <OtpInput v-model="codeVerifyForm.code" @complete="verifyCode" />
                </template>
            </UiFormField>

            <UiButton
                type="submit"
                size="lg"
                block
                :loading="codeVerifyForm.processing"
                :disabled="codeVerifyForm.code.length !== 6"
            >
                Verify and sign in
            </UiButton>

            <div class="flex items-center justify-between text-sm">
                <button
                    type="button"
                    class="font-medium transition hover:text-[var(--text-strong)]"
                    style="color: var(--text-muted)"
                    @click="changeIdentifier"
                >
                    Use a different account
                </button>

                <button
                    type="button"
                    class="font-medium text-brand-600 disabled:opacity-50 dark:text-brand-400"
                    :disabled="cooldown > 0 || codeRequestForm.processing"
                    @click="requestCode"
                >
                    {{ cooldown > 0 ? `Resend in ${cooldown}s` : 'Resend code' }}
                </button>
            </div>
        </form>

        <!-- --------------------------------------------- one time code request -->
        <form v-else class="space-y-4" @submit.prevent="requestCode">
            <UiFormField
                label="Email or mobile number"
                :hint="identifierHint"
                :error="codeRequestForm.errors.identifier"
            >
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="codeRequestForm.identifier"
                        :invalid="field.invalid"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        placeholder="you@example.com or 98765 43210"
                        required
                    >
                        <template #leading><Mail class="h-4 w-4" /></template>
                    </UiInput>
                </template>
            </UiFormField>

            <UiButton type="submit" size="lg" block :loading="codeRequestForm.processing">
                Send me a code
                <template #trailing><ArrowRight class="h-4 w-4" /></template>
            </UiButton>
        </form>

        <!-- ------------------------------------------------------------ google -->
        <template v-if="googleEnabled && !codeSentTo">
            <div class="my-6 flex items-center gap-3">
                <span class="h-px flex-1" style="background: var(--border-subtle)" />
                <span class="text-xs uppercase tracking-wide" style="color: var(--text-muted)">or</span>
                <span class="h-px flex-1" style="background: var(--border-subtle)" />
            </div>

            <GoogleButton />
        </template>

        <template #footer>
            <template v-if="canRegister">
                New here?
                <Link href="/register" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">
                    Create a student account
                </Link>
                <p class="mt-2 text-xs">
                    Client accounts are created by our team. Get in touch and we will set yours up.
                </p>
            </template>
        </template>
    </AuthLayout>
</template>
