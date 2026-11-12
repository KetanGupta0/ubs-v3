<script setup>
/**
 * A registration form for one course, filled without an account.
 *
 * The answer after sending is always the same, whoever the address belongs
 * to: "Thanks, check your email." Everything else arrives by email.
 */
import { computed, onMounted, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { CalendarDays, CheckCircle2, Clock, IndianRupee, MailCheck, UserCheck } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    form: { type: Object, required: true },
    course: { type: Object, required: true },
    batch: { type: Object, default: null },
    humanCheck: { type: String, default: null },
    seo: { type: Object, required: true },
});

const page = usePage();
// Set from the response itself rather than by watching the flash: a second
// registration from the same page brings the same flash value back, and a
// watcher would see nothing change.
const sent = ref(Boolean(page.props.flash?.registrationSent));

const entry = useForm({
    name: '',
    email: '',
    mobile: '',
    details: Object.fromEntries(props.form.fields.map((field) => [field.key, ''])),
    answers: Object.fromEntries(props.form.questions.map((question) => [question.key, ''])),
    // Never shown to a person. A crude bot fills every field it finds.
    website: '',
    'cf-turnstile-response': '',
});

const facts = computed(() => [
    props.batch && {
        icon: CalendarDays,
        label: props.batch.startsOn ? `${props.batch.name}, starting ${props.batch.startsOn}` : props.batch.name,
    },
    props.course.weeks && { icon: Clock, label: `${props.course.weeks} weeks` },
    { icon: IndianRupee, label: props.form.fee },
    {
        icon: UserCheck,
        label: props.form.reviewed ? 'Every registration is reviewed by the team' : 'Confirmed by email straight away',
    },
].filter(Boolean));

const closedMessage = computed(() => ({
    not_yet: `Registration opens on ${props.form.opensAt}.`,
    closed: 'Registration on this form has closed.',
    full: 'Every place on this form has been taken.',
}[props.form.state]));

const yesNo = [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }];

/* Cloudflare Turnstile, only when the site has it switched on. */
const widget = ref(null);
let widgetId = null;

function renderWidget() {
    if (!props.humanCheck || !widget.value || !window.turnstile) return;
    widgetId = window.turnstile.render(widget.value, {
        sitekey: props.humanCheck,
        callback: (token) => { entry['cf-turnstile-response'] = token; },
        'expired-callback': () => { entry['cf-turnstile-response'] = ''; },
    });
}

onMounted(() => {
    if (!props.humanCheck || props.form.state !== 'open') return;
    if (window.turnstile) {
        renderWidget();
        return;
    }
    const script = document.createElement('script');
    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    script.async = true;
    script.onload = renderWidget;
    document.head.appendChild(script);
});

function submit() {
    entry.post(`/join/${props.form.slug}`, {
        preserveScroll: true,
        onSuccess: (response) => {
            sent.value = Boolean(response.props.flash?.registrationSent);
            if (sent.value) entry.reset();
        },
        onFinish: () => {
            if (widgetId !== null && window.turnstile) {
                window.turnstile.reset(widgetId);
                entry['cf-turnstile-response'] = '';
            }
        },
    });
}
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />

            <div class="relative mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-14 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>Registration</span>
                </nav>

                <p class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ course.title }}</p>
                <h1 class="mt-1 text-3xl font-semibold leading-tight sm:text-4xl">{{ form.title }}</h1>
                <p v-if="course.tagline" class="mt-3 max-w-2xl text-base leading-relaxed" style="color: var(--text-muted)">
                    {{ course.tagline }}
                </p>

                <ul class="mt-6 flex flex-wrap gap-x-6 gap-y-2.5">
                    <li v-for="fact in facts" :key="fact.label" class="flex items-center gap-2 text-sm">
                        <component :is="fact.icon" class="h-4 w-4 shrink-0 text-brand-500" aria-hidden="true" />
                        {{ fact.label }}
                    </li>
                </ul>
            </div>
        </section>

        <section class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
            <div v-if="form.intro" class="mb-8">
                <MarkdownHtml :html="form.intro" />
            </div>

            <!-- The same words whoever the address belongs to. -->
            <div
                v-if="sent"
                class="rounded-[var(--radius-card)] border p-6 text-center sm:p-8"
                style="border-color: var(--border-subtle); background: var(--surface)"
                role="status"
            >
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-green-50 dark:bg-green-950/60">
                    <MailCheck class="h-5 w-5 text-signal-500" aria-hidden="true" />
                </span>
                <h2 class="mt-4 text-lg font-semibold">Thanks. Check your email.</h2>
                <p class="mx-auto mt-2 max-w-md text-sm" style="color: var(--text-muted)">
                    What happens next is in a message on its way to the address you gave. It can take a
                    few minutes; look in the spam folder if it has not arrived.
                </p>
                <button
                    type="button"
                    class="mt-5 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                    @click="sent = false"
                >
                    Register somebody else
                </button>
            </div>

            <div
                v-else-if="form.state !== 'open'"
                class="rounded-[var(--radius-card)] border p-6 text-center sm:p-8"
                style="border-color: var(--border-subtle); background: var(--surface)"
            >
                <h2 class="text-lg font-semibold">{{ closedMessage }}</h2>
                <p class="mt-2 text-sm" style="color: var(--text-muted)">
                    Questions about {{ course.title }}?
                    <Link href="/contact" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Write to us</Link>.
                </p>
            </div>

            <form
                v-else
                class="rounded-[var(--radius-card)] border p-5 sm:p-6"
                style="border-color: var(--border-subtle); background: var(--surface)"
                novalidate
                @submit.prevent="submit"
            >
                <p v-if="form.placesLeft !== null" class="mb-5 text-sm" style="color: var(--text-muted)">
                    {{ form.placesLeft }} {{ form.placesLeft === 1 ? 'place' : 'places' }} left on this form.
                </p>

                <div class="space-y-4">
                    <UiFormField label="Your name" required :error="entry.errors.name">
                        <UiInput v-model="entry.name" autocomplete="name" />
                    </UiFormField>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Email" required hint="Where we write to you about this." :error="entry.errors.email">
                            <UiInput v-model="entry.email" type="email" autocomplete="email" autocapitalize="none" />
                        </UiFormField>
                        <UiFormField label="Mobile" required :error="entry.errors.mobile">
                            <UiInput v-model="entry.mobile" type="tel" autocomplete="tel" placeholder="98765 43210" />
                        </UiFormField>
                    </div>

                    <div v-if="form.fields.length" class="grid gap-4 sm:grid-cols-2">
                        <UiFormField
                            v-for="field in form.fields"
                            :key="field.key"
                            :label="field.label"
                            required
                            :error="entry.errors[`details.${field.key}`]"
                        >
                            <UiInput
                                v-model="entry.details[field.key]"
                                :type="field.key === 'graduation_year' ? 'number' : 'text'"
                                :inputmode="field.key === 'graduation_year' ? 'numeric' : undefined"
                                :autocomplete="field.key === 'city' ? 'address-level2' : 'off'"
                            />
                        </UiFormField>
                    </div>

                    <UiFormField
                        v-for="question in form.questions"
                        :key="question.key"
                        :label="question.label"
                        :required="question.required"
                        :error="entry.errors[`answers.${question.key}`]"
                    >
                        <UiTextarea v-if="question.type === 'long'" v-model="entry.answers[question.key]" :rows="4" />
                        <UiSelect
                            v-else-if="question.type === 'choice'"
                            v-model="entry.answers[question.key]"
                            :options="question.options"
                            placeholder="Choose one"
                        />
                        <UiSelect
                            v-else-if="question.type === 'yesno'"
                            v-model="entry.answers[question.key]"
                            :options="yesNo"
                            placeholder="Choose one"
                        />
                        <UiInput v-else v-model="entry.answers[question.key]" />
                    </UiFormField>

                    <!-- Hidden from people and from screen readers. -->
                    <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                        <label>Website <input v-model="entry.website" type="text" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <div v-if="humanCheck">
                        <div ref="widget" />
                        <p v-if="entry.errors.human" class="mt-1.5 text-sm text-danger-600">{{ entry.errors.human }}</p>
                    </div>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs" style="color: var(--text-muted)">
                        Used to register you for this course and nothing else.
                        <Link href="/legal/privacy" class="underline underline-offset-2">Privacy policy</Link>
                    </p>
                    <UiButton type="submit" :loading="entry.processing">
                        <template #leading><CheckCircle2 class="h-4 w-4" /></template>
                        Register
                    </UiButton>
                </div>
            </form>
        </section>
    </PublicLayout>
</template>
