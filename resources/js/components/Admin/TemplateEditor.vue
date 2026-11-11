<script setup>
/**
 * The wording of one automated message: what it says, what it can say, and
 * what it will actually look like.
 *
 * The preview is the real notification rendered on the server with sample
 * details and the wording as typed, not a second imitation of it here. Saving
 * is refused for a placeholder the message does not fill in, and for one it
 * cannot do without.
 */
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Eye, Mail, MessageSquare, RotateCcw, Send, TriangleAlert } from 'lucide-vue-next';

import FormSection from '@/components/Admin/FormSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    template: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});

const form = useForm({
    subject: props.template.subject ?? '',
    body: props.template.body,
    is_active: props.template.isActive,
});

const isMail = computed(() => props.template.channel === 'mail');
const base = computed(() => `/admin/settings/templates/${props.template.id}`);

/* Written out rather than interpolated, because a literal }} would close the
 * interpolation it sits in. */
const placeholder = (name) => `{${'{'}${name}}${'}'}`;

/* ---------------------------------------------------------- placeholders */

const body = ref(null);

/** Put a placeholder where the cursor is in the message, or at its end. */
function insert(name) {
    const field = body.value?.$el;
    const text = form.body ?? '';
    const at = field && typeof field.selectionStart === 'number' ? field.selectionStart : text.length;
    const token = placeholder(name);

    form.body = text.slice(0, at) + token + text.slice(field?.selectionEnd ?? at);

    requestAnimationFrame(() => {
        field?.focus();
        field?.setSelectionRange(at + token.length, at + token.length);
    });
}

const used = computed(() => `${form.subject} ${form.body}`);
const missing = computed(() => props.template.variables
    .filter((variable) => variable.required && !used.value.includes(placeholder(variable.name))));

/* ---------------------------------------------------------------- preview */

const preview = ref(null);
const previewing = ref(false);

async function showPreview() {
    previewing.value = true;
    form.clearErrors();

    try {
        const response = await fetch(`${base.value}/preview`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ subject: form.subject, body: form.body }),
        });
        const data = await response.json();

        if (response.status === 422) {
            form.setError(Object.fromEntries(Object.entries(data.errors ?? {}).map(([key, messages]) => [key, messages[0]])));
            preview.value = null;
        } else if (response.ok) {
            preview.value = data;
        }
    } finally {
        previewing.value = false;
    }
}

/* ------------------------------------------------------------ save, test */

function save() {
    form.put(base.value, {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}

const testing = ref(false);

function sendTest() {
    testing.value = true;
    router.post(`${base.value}/test`, { subject: form.subject, body: form.body }, {
        preserveScroll: true,
        onError: (errors) => form.setError(errors),
        onFinish: () => { testing.value = false; },
    });
}

function restoreDefaults() {
    if (window.confirm('Put back the starting wording? Your changes here are replaced; nothing is saved until you press Save.')) {
        form.subject = props.template.defaults.subject ?? '';
        form.body = props.template.defaults.body;
        preview.value = null;
    }
}
</script>

<template>
    <FormSection :title="template.name" :description="template.sent">
        <form class="space-y-4" @submit.prevent="save">
            <p
                v-if="!isMail"
                class="flex items-start gap-2 rounded-lg px-3 py-2 text-sm"
                style="background: color-mix(in oklab, var(--color-warn-500) 12%, var(--surface)); color: var(--text-base)"
            >
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-warn-600" />
                <span>
                    In India every SMS must match a template registered on DLT with your SMS provider. Change this
                    wording only to match one you have registered, or the operator will block the message.
                </span>
            </p>

            <UiFormField v-if="isMail" label="Subject" required :error="form.errors.subject">
                <UiInput v-model="form.subject" :disabled="!canManage" />
            </UiFormField>

            <UiFormField
                label="Message"
                required
                :error="form.errors.body"
                :hint="isMail ? 'A blank line starts a new paragraph. The greeting (Hello and their name) and the sign off are added around it, so leave them out.' : 'Sent exactly as written.'"
            >
                <UiTextarea ref="body" v-model="form.body" :rows="isMail ? 8 : 4" :disabled="!canManage" />
            </UiFormField>

            <div>
                <p class="mb-1.5 text-xs font-medium" style="color: var(--text-muted)">
                    What it can say. Click one to put it where the cursor is.
                </p>
                <ul class="flex flex-wrap gap-1.5">
                    <li v-for="variable in template.variables" :key="variable.name">
                        <button
                            type="button"
                            class="rounded-lg border px-2 py-1 text-left text-xs transition hover:border-brand-500 disabled:opacity-60"
                            style="border-color: var(--border-subtle)"
                            :disabled="!canManage"
                            :title="`${variable.description}. For example: ${variable.sample}`"
                            @click="insert(variable.name)"
                        >
                            <code style="color: var(--text-strong)">{{ placeholder(variable.name) }}</code>
                            <span class="ml-1.5" style="color: var(--text-muted)">{{ variable.description }}</span>
                            <UiBadge v-if="variable.required" tone="warning" size="sm" class="ml-1.5">must include</UiBadge>
                        </button>
                    </li>
                </ul>
                <p v-if="missing.length" class="mt-2 text-xs text-warn-600">
                    Still needs {{ missing.map((variable) => placeholder(variable.name)).join(' and ') }}.
                </p>
            </div>

            <!-- What it will look like, from the server. -->
            <div v-if="preview" class="rounded-xl border" style="border-color: var(--border-subtle)">
                <div class="flex items-center justify-between gap-2 border-b px-4 py-2 text-xs" style="border-color: var(--border-subtle); color: var(--text-muted)">
                    <span>Preview with sample details</span>
                    <button type="button" class="hover:underline" @click="preview = null">Close</button>
                </div>
                <template v-if="isMail">
                    <p class="px-4 pt-3 text-sm"><span style="color: var(--text-muted)">Subject:</span> <strong>{{ preview.subject }}</strong></p>
                    <!-- Sandboxed: the email's own HTML, with nothing allowed to run. -->
                    <iframe :srcdoc="preview.html" sandbox="" title="Email preview" class="mt-2 h-[28rem] w-full rounded-b-xl bg-white" />
                </template>
                <template v-else>
                    <p class="whitespace-pre-wrap px-4 py-3 font-mono text-sm" style="color: var(--text-strong)">{{ preview.text }}</p>
                    <p class="px-4 pb-3 text-xs" :class="preview.parts > 1 ? 'text-warn-600' : ''" :style="preview.parts > 1 ? {} : { color: 'var(--text-muted)' }">
                        {{ preview.length }} characters, sent as {{ preview.parts }} {{ preview.parts === 1 ? 'part' : 'parts' }}{{ preview.unicode ? ' (it has characters outside plain English, which shortens each part to 70)' : '' }}.{{ preview.parts > 1 ? ' Each part is charged.' : '' }}
                    </p>
                </template>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <UiSwitch
                    v-model="form.is_active"
                    :disabled="!canManage"
                    label="In use"
                    description="Switched off, the built in wording is used instead."
                />

                <span class="flex flex-wrap items-center gap-2">
                    <UiButton variant="ghost" size="sm" :disabled="!canManage" @click="restoreDefaults">
                        <template #leading><RotateCcw class="h-3.5 w-3.5" /></template>
                        Starting wording
                    </UiButton>
                    <UiButton variant="secondary" size="sm" :loading="previewing" @click="showPreview">
                        <template #leading><Eye class="h-3.5 w-3.5" /></template>
                        Preview
                    </UiButton>
                    <UiButton v-if="isMail && canManage" variant="secondary" size="sm" :loading="testing" @click="sendTest">
                        <template #leading><Send class="h-3.5 w-3.5" /></template>
                        Send me a test
                    </UiButton>
                    <UiButton v-if="canManage" type="submit" size="sm" :loading="form.processing">
                        <template #leading>
                            <component :is="isMail ? Mail : MessageSquare" class="h-3.5 w-3.5" />
                        </template>
                        Save
                    </UiButton>
                </span>
            </div>
        </form>
    </FormSection>
</template>
