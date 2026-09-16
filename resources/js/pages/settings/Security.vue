<script setup>
/**
 * Account security: two factor, sessions, devices and recent activity.
 */
import { ref, computed, watch } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import {
    ShieldCheck, ShieldOff, Smartphone, Monitor, KeyRound, Copy, Check,
    MailCheck, MailWarning, PhoneCall, Trash2,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import OtpInput from '@/components/Auth/OtpInput.vue';
import { toast } from '@/support/toast';

defineProps({
    twoFactor: { type: Object, required: true },
    account: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    devices: { type: Array, default: () => [] },
    recentActivity: { type: Array, default: () => [] },
});

const page = usePage();

const setup = computed(() => page.props.flash?.twoFactorSetup ?? null);
const recoveryCodes = ref(null);
const confirmingDisable = ref(false);
const copied = ref(false);

watch(
    () => page.props.flash?.recoveryCodes,
    (codes) => {
        if (codes) recoveryCodes.value = codes;
    },
);

const enableForm = useForm({});
const confirmForm = useForm({ code: '' });
const passwordForm = useForm({ password: '' });
const mobileForm = useForm({ code: '' });

function beginSetup() {
    enableForm.post('/settings/two-factor', { preserveScroll: true });
}

function confirmSetup() {
    confirmForm.post('/settings/two-factor/confirm', {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset(),
        onError: () => confirmForm.reset('code'),
    });
}

function disable() {
    passwordForm.delete('/settings/two-factor', {
        preserveScroll: true,
        onSuccess: () => {
            confirmingDisable.value = false;
            passwordForm.reset();
        },
    });
}

function regenerate() {
    passwordForm.post('/settings/two-factor/recovery-codes', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

function copyCodes() {
    navigator.clipboard?.writeText(recoveryCodes.value.join('\n')).then(() => {
        copied.value = true;
        toast.success('Recovery codes copied.');
        setTimeout(() => (copied.value = false), 2000);
    });
}

function revokeSession(id) {
    router.delete(`/settings/sessions/${id}`, { preserveScroll: true });
}

function revokeDevice(id) {
    router.delete(`/settings/devices/${id}`, { preserveScroll: true });
}

function sendMobileCode() {
    router.post('/verify-mobile/send', {}, { preserveScroll: true });
}

function confirmMobile() {
    mobileForm.post('/verify-mobile/confirm', {
        preserveScroll: true,
        onSuccess: () => mobileForm.reset(),
        onError: () => mobileForm.reset('code'),
    });
}

const eventLabels = {
    'login.succeeded': 'Signed in',
    'login.failed': 'Failed sign in',
    'login.throttled': 'Sign in blocked',
    logout: 'Signed out',
    'otp.requested': 'Code requested',
    'otp.verified': 'Code accepted',
    'otp.failed': 'Code rejected',
    'two_factor.challenged': 'Two factor asked',
    'two_factor.passed': 'Two factor passed',
    'two_factor.failed': 'Two factor failed',
    'two_factor.enabled': 'Two factor enabled',
    'two_factor.disabled': 'Two factor disabled',
    'two_factor.recovery_used': 'Recovery code used',
    'password.changed': 'Password changed',
    'password.reset': 'Password reset',
    'session.revoked': 'Session revoked',
    'token.issued': 'Device signed in',
    'token.revoked': 'Device signed out',
    'account.registered': 'Account created',
    'email.verified': 'Email confirmed',
    'mobile.verified': 'Mobile confirmed',
    'social.linked': 'Google linked',
    'social.login.succeeded': 'Signed in with Google',
};
</script>

<template>
    <Head title="Security" />

    <AppLayout title="Security" :breadcrumbs="[{ label: 'Settings' }, { label: 'Security' }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <!-- ------------------------------------------------- two factor -->
            <UiCard>
                <template #header>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold">Two factor authentication</h2>
                            <p class="mt-1 text-sm" style="color: var(--text-muted)">
                                Ask for a code from an authenticator app every time you sign in.
                            </p>
                        </div>

                        <UiBadge :tone="twoFactor.enabled ? 'success' : 'neutral'" dot>
                            {{ twoFactor.enabled ? 'On' : 'Off' }}
                        </UiBadge>
                    </div>
                </template>

                <!-- Enabled -->
                <div v-if="twoFactor.enabled && !recoveryCodes" class="space-y-4">
                    <p class="text-sm" style="color: var(--text-base)">
                        You have
                        <span class="font-semibold" style="color: var(--text-strong)">
                            {{ twoFactor.recoveryCodesRemaining }}
                        </span>
                        recovery {{ twoFactor.recoveryCodesRemaining === 1 ? 'code' : 'codes' }} left. Each
                        one works once, and they are the only way in if you lose your phone.
                    </p>

                    <UiFormField
                        label="Confirm your password"
                        hint="Needed to change either of these settings."
                        :error="passwordForm.errors.password"
                    >
                        <template #default="field">
                            <UiInput
                                :id="field.id"
                                v-model="passwordForm.password"
                                type="password"
                                :invalid="field.invalid"
                                autocomplete="current-password"
                            />
                        </template>
                    </UiFormField>

                    <div class="flex flex-wrap gap-2">
                        <UiButton variant="secondary" :loading="passwordForm.processing" @click="regenerate">
                            <template #leading><KeyRound class="h-4 w-4" /></template>
                            New recovery codes
                        </UiButton>

                        <UiButton variant="danger" @click="confirmingDisable = true">
                            <template #leading><ShieldOff class="h-4 w-4" /></template>
                            Turn off
                        </UiButton>
                    </div>
                </div>

                <!-- Mid setup -->
                <div v-else-if="setup && !twoFactor.enabled" class="space-y-5">
                    <p class="text-sm" style="color: var(--text-base)">
                        Scan this with your authenticator app, then enter the code it shows.
                    </p>

                    <div class="flex flex-col items-start gap-5 sm:flex-row">
                        <div class="rounded-xl bg-white p-3" v-html="setup.qr" />

                        <div class="min-w-0 flex-1 space-y-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                    Cannot scan? Enter this key
                                </p>
                                <code
                                    class="mt-1 block break-all rounded-lg px-3 py-2 font-mono text-sm"
                                    style="background: var(--surface-sunken); color: var(--text-strong)"
                                >{{ setup.secret }}</code>
                            </div>

                            <UiFormField label="Code from your app" :error="confirmForm.errors.code">
                                <template #default>
                                    <OtpInput
                                        v-model="confirmForm.code"
                                        :invalid="Boolean(confirmForm.errors.code)"
                                        @complete="confirmSetup"
                                    />
                                </template>
                            </UiFormField>

                            <UiButton
                                :loading="confirmForm.processing"
                                :disabled="confirmForm.code.length !== 6"
                                @click="confirmSetup"
                            >
                                Confirm and turn on
                            </UiButton>
                        </div>
                    </div>
                </div>

                <!-- Off -->
                <div v-else-if="!recoveryCodes">
                    <UiButton :loading="enableForm.processing" @click="beginSetup">
                        <template #leading><ShieldCheck class="h-4 w-4" /></template>
                        Turn on two factor authentication
                    </UiButton>
                </div>

                <!-- Codes just issued -->
                <div v-if="recoveryCodes" class="space-y-4">
                    <div
                        class="rounded-xl border px-4 py-3"
                        style="border-color: var(--color-warn-500); background: color-mix(in oklab, var(--color-warn-500) 10%, transparent)"
                    >
                        <p class="text-sm font-medium" style="color: var(--text-strong)">
                            Save these now. This is the only time they are shown.
                        </p>
                    </div>

                    <ul
                        class="grid grid-cols-2 gap-2 rounded-xl p-4 font-mono text-sm"
                        style="background: var(--surface-sunken)"
                    >
                        <li v-for="code in recoveryCodes" :key="code" style="color: var(--text-strong)">
                            {{ code }}
                        </li>
                    </ul>

                    <div class="flex gap-2">
                        <UiButton variant="secondary" @click="copyCodes">
                            <template #leading>
                                <component :is="copied ? Check : Copy" class="h-4 w-4" />
                            </template>
                            {{ copied ? 'Copied' : 'Copy codes' }}
                        </UiButton>

                        <UiButton variant="ghost" @click="recoveryCodes = null">I have saved them</UiButton>
                    </div>
                </div>
            </UiCard>

            <!-- ------------------------------------------------ verification -->
            <UiCard>
                <template #header>
                    <h2 class="text-base font-semibold">Contact details</h2>
                </template>

                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <component
                                :is="account.emailVerified ? MailCheck : MailWarning"
                                :class="['h-4 w-4', account.emailVerified ? 'text-signal-500' : 'text-warn-500']"
                            />
                            <span class="text-sm" style="color: var(--text-base)">Email address</span>
                        </div>

                        <UiBadge :tone="account.emailVerified ? 'success' : 'warning'" dot>
                            {{ account.emailVerified ? 'Confirmed' : 'Not confirmed' }}
                        </UiBadge>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <PhoneCall
                                :class="['h-4 w-4', account.mobileVerified ? 'text-signal-500' : 'text-warn-500']"
                            />
                            <span class="text-sm" style="color: var(--text-base)">
                                {{ account.mobile || 'No mobile number' }}
                            </span>
                        </div>

                        <UiBadge :tone="account.mobileVerified ? 'success' : 'warning'" dot>
                            {{ account.mobileVerified ? 'Confirmed' : 'Not confirmed' }}
                        </UiBadge>
                    </div>

                    <div v-if="account.mobile && !account.mobileVerified" class="space-y-3 border-t pt-4" style="border-color: var(--border-subtle)">
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-0 flex-1">
                                <UiFormField label="Code sent to your mobile" :error="mobileForm.errors.code">
                                    <template #default>
                                        <OtpInput v-model="mobileForm.code" @complete="confirmMobile" />
                                    </template>
                                </UiFormField>
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <UiButton variant="secondary" size="sm" @click="sendMobileCode">Send code</UiButton>
                            <UiButton
                                size="sm"
                                :loading="mobileForm.processing"
                                :disabled="mobileForm.code.length !== 6"
                                @click="confirmMobile"
                            >
                                Confirm number
                            </UiButton>
                        </div>
                    </div>
                </div>
            </UiCard>

            <!-- --------------------------------------------------- sessions -->
            <UiCard>
                <template #header>
                    <div>
                        <h2 class="text-base font-semibold">Where you are signed in</h2>
                        <p class="mt-1 text-sm" style="color: var(--text-muted)">
                            If you do not recognise something here, sign it out and change your password.
                        </p>
                    </div>
                </template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="session in sessions" :key="session.id" class="flex items-center gap-3 py-3 first:pt-0">
                        <Monitor class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium" style="color: var(--text-strong)">
                                {{ session.agent }}
                                <UiBadge v-if="session.current" tone="brand" size="sm" class="ml-1.5">This device</UiBadge>
                            </p>
                            <p class="text-xs" style="color: var(--text-muted)">
                                {{ session.ip }} · {{ session.lastActive }}
                            </p>
                        </div>

                        <UiButton
                            v-if="!session.current"
                            variant="ghost"
                            size="xs"
                            icon
                            aria-label="Sign out this session"
                            @click="revokeSession(session.id)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </UiButton>
                    </li>

                    <li v-for="device in devices" :key="`d-${device.id}`" class="flex items-center gap-3 py-3">
                        <Smartphone class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium" style="color: var(--text-strong)">
                                {{ device.name }}
                            </p>
                            <p class="text-xs" style="color: var(--text-muted)">
                                {{ [device.platform, device.appVersion, device.lastUsedAt].filter(Boolean).join(' · ') || 'Never used' }}
                            </p>
                        </div>

                        <UiButton
                            variant="ghost"
                            size="xs"
                            icon
                            aria-label="Sign out this device"
                            @click="revokeDevice(device.id)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </UiButton>
                    </li>
                </ul>

                <p v-if="!sessions.length && !devices.length" class="py-2 text-sm" style="color: var(--text-muted)">
                    No other sessions.
                </p>
            </UiCard>

            <!-- --------------------------------------------------- activity -->
            <UiCard>
                <template #header>
                    <h2 class="text-base font-semibold">Recent account activity</h2>
                </template>

                <ul class="divide-y text-sm" style="border-color: var(--border-subtle)">
                    <li
                        v-for="entry in recentActivity"
                        :key="entry.id"
                        class="flex items-center justify-between gap-3 py-2.5 first:pt-0"
                    >
                        <span class="flex items-center gap-2.5">
                            <span
                                class="h-1.5 w-1.5 shrink-0 rounded-full"
                                :class="entry.succeeded ? 'bg-signal-500' : 'bg-danger-500'"
                            />
                            <span style="color: var(--text-base)">
                                {{ eventLabels[entry.event] ?? entry.event }}
                            </span>
                            <span v-if="entry.method" class="text-xs" style="color: var(--text-muted)">
                                via {{ entry.method }}
                            </span>
                        </span>

                        <span class="shrink-0 text-xs" style="color: var(--text-muted)">
                            {{ entry.ip }} · {{ entry.at }}
                        </span>
                    </li>
                </ul>
            </UiCard>
        </div>

        <UiModal
            :open="confirmingDisable"
            title="Turn off two factor authentication?"
            description="Your account will be protected by its password alone."
            size="sm"
            @close="confirmingDisable = false"
        >
            <UiFormField label="Confirm your password" :error="passwordForm.errors.password">
                <template #default="field">
                    <UiInput
                        :id="field.id"
                        v-model="passwordForm.password"
                        type="password"
                        :invalid="field.invalid"
                        autocomplete="current-password"
                    />
                </template>
            </UiFormField>

            <template #footer>
                <UiButton variant="secondary" @click="confirmingDisable = false">Cancel</UiButton>
                <UiButton variant="danger" :loading="passwordForm.processing" @click="disable">
                    Turn it off
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
