<script setup>
/**
 * A product's live demo (§4, X9): hosted by us on its own subdomain, reset on
 * a schedule, with a sign in that is public by design.
 *
 * Opening it is a plain link and records nothing. A visitor who wants a call
 * can say so, and agree to be contacted, which is the only way it becomes a
 * lead.
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Copy, ExternalLink, MonitorPlay } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import { accentFor } from '@/support/accents';
import { toast } from '@/support/toast';

const props = defineProps({
    solution: { type: Object, required: true },
    demo: { type: Object, required: true },
});

const tone = computed(() => accentFor(props.solution.accent));
const asking = ref(false);
const form = useForm({ name: '', email: '', mobile: '', company: '', note: '', consent: false, website: '' });

async function copy(text, what) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copied.`);
    } catch {
        toast.info(`Select the ${what.toLowerCase()} and copy it.`);
    }
}

function send() {
    form.post(`/solutions/${props.solution.slug}/demo`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            asking.value = false;
        },
    });
}
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-12">
        <div class="rounded-xl border p-5 lg:col-span-7" :style="{ borderColor: tone.border, background: tone.softer }">
            <p class="flex items-center gap-2 text-sm font-semibold">
                <MonitorPlay class="h-4 w-4" :style="{ color: tone.solid }" aria-hidden="true" />
                Try it yourself
            </p>
            <p class="mt-1 text-sm" style="color: var(--text-muted)">
                A working copy with made up data. Click anything, add anything: nothing you do there reaches anybody.
            </p>

            <dl v-if="demo.username || demo.password" class="mt-4 grid gap-3 sm:grid-cols-2">
                <div v-if="demo.username" class="rounded-lg border bg-[var(--surface)] p-3" style="border-color: var(--border-subtle)">
                    <dt class="text-xs" style="color: var(--text-muted)">Username</dt>
                    <dd class="mt-0.5 flex items-center justify-between gap-2 font-mono text-sm">
                        <span class="truncate">{{ demo.username }}</span>
                        <button type="button" class="shrink-0 rounded p-1 hover:bg-[var(--surface-sunken)]" aria-label="Copy the username" @click="copy(demo.username, 'Username')">
                            <Copy class="h-3.5 w-3.5" />
                        </button>
                    </dd>
                </div>
                <div v-if="demo.password" class="rounded-lg border bg-[var(--surface)] p-3" style="border-color: var(--border-subtle)">
                    <dt class="text-xs" style="color: var(--text-muted)">Password</dt>
                    <dd class="mt-0.5 flex items-center justify-between gap-2 font-mono text-sm">
                        <span class="truncate">{{ demo.password }}</span>
                        <button type="button" class="shrink-0 rounded p-1 hover:bg-[var(--surface-sunken)]" aria-label="Copy the password" @click="copy(demo.password, 'Password')">
                            <Copy class="h-3.5 w-3.5" />
                        </button>
                    </dd>
                </div>
            </dl>

            <p v-if="demo.notes" class="mt-3 text-xs" style="color: var(--text-muted)">{{ demo.notes }}</p>

            <div class="mt-5 flex flex-wrap gap-2">
                <UiButton :href="demo.url" :inertia="false" target="_blank" rel="noopener">
                    <template #leading><ExternalLink class="h-4 w-4" /></template>
                    Open the live demo
                </UiButton>
                <UiButton v-if="!asking" variant="secondary" @click="asking = true">Ask for a walkthrough</UiButton>
            </div>
        </div>

        <form v-if="asking" class="space-y-3 rounded-xl border p-5 lg:col-span-5" style="border-color: var(--border-subtle)" @submit.prevent="send">
            <p class="text-sm font-semibold">We will show you round</p>
            <p class="text-xs" style="color: var(--text-muted)">Leave your details and we will call within one working day.</p>
            <UiFormField label="Your name" required :error="form.errors.name">
                <UiInput v-model="form.name" autocomplete="name" maxlength="120" />
            </UiFormField>
            <UiFormField label="Email" required :error="form.errors.email">
                <UiInput v-model="form.email" type="email" autocomplete="email" maxlength="255" />
            </UiFormField>
            <div class="grid gap-3 sm:grid-cols-2">
                <UiFormField label="Mobile" :error="form.errors.mobile">
                    <UiInput v-model="form.mobile" type="tel" autocomplete="tel" placeholder="+91" />
                </UiFormField>
                <UiFormField label="Company" :error="form.errors.company">
                    <UiInput v-model="form.company" autocomplete="organization" maxlength="160" />
                </UiFormField>
            </div>
            <UiFormField label="Anything to look at in particular?" :error="form.errors.note">
                <UiInput v-model="form.note" maxlength="1000" />
            </UiFormField>
            <input v-model="form.website" type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true" />
            <UiFormField :error="form.errors.consent">
                <UiCheckbox v-model="form.consent" :label="`You may contact me about ${solution.title}.`" />
            </UiFormField>
            <div class="flex gap-2">
                <UiButton type="submit" :loading="form.processing">Send</UiButton>
                <UiButton variant="ghost" @click="asking = false">Not now</UiButton>
            </div>
        </form>
    </div>
</template>
