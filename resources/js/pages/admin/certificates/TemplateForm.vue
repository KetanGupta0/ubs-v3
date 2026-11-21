<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, FileText, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';

const props = defineProps({
    template: { type: Object, default: null },
    courses: { type: Array, default: () => [] },
    layouts: { type: Array, default: () => [] },
});

const background = ref([]);
const signature = ref([]);

const form = useForm({
    name: props.template?.name ?? '',
    layout: props.template?.layout ?? 'classic',
    course_id: props.template?.course_id ?? null,
    heading: props.template?.heading ?? 'Certificate of completion',
    body_line: props.template?.body_line ?? 'has completed',
    signatory_name: props.template?.signatory_name ?? '',
    signatory_title: props.template?.signatory_title ?? '',
    accent: props.template?.accent ?? '#4f46e5',
    show_grade: props.template?.show_grade ?? true,
    show_qr: props.template?.show_qr ?? true,
    is_active: props.template?.is_active ?? true,
    background: null,
    signature: null,
    remove_background: false,
    remove_signature: false,
});

const chosen = computed(() => props.layouts.find((layout) => layout.value === form.layout) ?? null);

/** The WCAG contrast ratio of two colours written #rrggbb (CertificateArt::contrast on the server). */
function contrast(one, two) {
    const luminance = (hex) => [1, 3, 5]
        .map((at) => {
            const channel = parseInt(hex.slice(at, at + 2), 16) / 255;

            return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
        })
        .reduce((sum, channel, i) => sum + channel * [0.2126, 0.7152, 0.0722][i], 0);
    const [a, b] = [luminance(one), luminance(two)];

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/**
 * Whether the chosen layout would set the accent aside: it prints the accent
 * only where it makes 4.5:1 with every colour it meets, as the server decides.
 */
const accentTooLight = computed(() => {
    const ink = chosen.value?.ink;

    if (!ink || !/^#[0-9a-fA-F]{6}$/.test(form.accent ?? '')) {
        return false;
    }

    return ink.against.some((other) => contrast(form.accent, other) < 4.5);
});

function save() {
    form.background = background.value[0] ?? null;
    form.signature = signature.value[0] ?? null;

    const url = props.template ? `/admin/certificate-templates/${props.template.id}` : '/admin/certificate-templates';

    form
        .transform((data) => ({ ...data, course_id: data.course_id || null }))
        .post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                background.value = [];
                signature.value = [];
            },
        });
}

function destroy() {
    const note = props.template.issued
        ? ` ${props.template.issued} certificates were issued with it; they stay exactly as they are.`
        : '';

    if (window.confirm(`Remove "${props.template.name}"?${note}`)) {
        router.delete(`/admin/certificate-templates/${props.template.id}`);
    }
}
</script>

<template>
    <Head :title="template ? template.name : 'New certificate design'" />

    <AppLayout
        :title="template ? template.name : 'New certificate design'"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Certificate designs', href: '/admin/certificate-templates' },
            { label: template ? template.name : 'New' },
        ]"
    >
        <div>
            <PageHeader
                :title="template ? template.name : 'New certificate design'"
                :description="template?.issued ? `${template.issued} certificates have been issued with this design. Changes apply to new ones only.` : 'Changes apply to certificates issued from now on.'"
            >
                <template #actions>
                    <UiButton href="/admin/certificate-templates" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Designs
                    </UiButton>
                    <UiButton
                        v-if="template"
                        :href="`/admin/certificate-templates/${template.id}/preview`"
                        :inertia="false"
                        target="_blank"
                        rel="noopener"
                        variant="secondary"
                        size="sm"
                    >
                        <template #leading><FileText class="h-3.5 w-3.5" /></template>
                        Preview
                    </UiButton>
                </template>
            </PageHeader>

            <form class="space-y-5" @submit.prevent="save">
                <FormSection title="Which certificates" description="A design for one course, or the default for every course without its own.">
                    <UiFormField label="Name" required :error="form.errors.name">
                        <UiInput v-model="form.name" placeholder="Internships, 2026" />
                    </UiFormField>
                    <UiFormField label="Course" :error="form.errors.course_id">
                        <UiSelect v-model="form.course_id">
                            <option :value="null">Every course without its own design</option>
                            <option v-for="course in courses" :key="course.value" :value="course.value">{{ course.label }}</option>
                        </UiSelect>
                    </UiFormField>
                    <UiSwitch v-model="form.is_active" label="In use" description="New certificates are issued with the newest active design for their course." />
                </FormSection>

                <FormSection title="Layout" description="Where everything sits and how it is drawn. Every layout prints the same things: the name, the course, the dates, the grade, the signatory and the verification code.">
                    <fieldset>
                        <legend class="sr-only">Layout</legend>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <label
                                v-for="layout in layouts"
                                :key="layout.value"
                                class="relative flex cursor-pointer flex-col overflow-hidden rounded-xl border transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-[var(--ring)]"
                                :style="form.layout === layout.value
                                    ? { borderColor: 'var(--ring)', boxShadow: '0 0 0 1px var(--ring)' }
                                    : { borderColor: 'var(--border-subtle)' }"
                            >
                                <input v-model="form.layout" type="radio" name="layout" :value="layout.value" class="sr-only" />
                                <img
                                    v-if="layout.picture"
                                    :src="layout.picture"
                                    alt=""
                                    width="600"
                                    height="425"
                                    loading="lazy"
                                    class="aspect-[600/425] w-full border-b object-cover"
                                    style="border-color: var(--border-subtle); background: var(--surface-sunken)"
                                />
                                <span class="flex flex-1 flex-col p-3">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold" style="color: var(--text-strong)">{{ layout.label }}</span>
                                        <Check v-if="form.layout === layout.value" class="h-4 w-4 shrink-0" style="color: var(--text-brand)" aria-hidden="true" />
                                    </span>
                                    <span class="mt-1 text-xs" style="color: var(--text-muted)">{{ layout.description }}</span>
                                </span>
                            </label>
                        </div>
                    </fieldset>
                    <p v-if="form.errors.layout" class="text-sm" style="color: var(--text-danger)">{{ form.errors.layout }}</p>
                </FormSection>

                <FormSection title="Wording">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Heading" required :error="form.errors.heading">
                            <UiInput v-model="form.heading" />
                        </UiFormField>
                        <UiFormField label="Line under the name" required :error="form.errors.body_line">
                            <UiInput v-model="form.body_line" />
                        </UiFormField>
                    </div>
                    <UiSwitch v-model="form.show_grade" label="Show the grade and final percentage" />
                    <UiSwitch v-model="form.show_qr" label="Show a QR code to the verification page" description="The verification code is always printed, with or without it." />
                </FormSection>

                <FormSection title="Signatory">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Name" :error="form.errors.signatory_name">
                            <UiInput v-model="form.signatory_name" placeholder="Ketan Gupta" />
                        </UiFormField>
                        <UiFormField label="Title" :error="form.errors.signatory_title">
                            <UiInput v-model="form.signatory_title" placeholder="Director" />
                        </UiFormField>
                    </div>
                    <UiFormField label="Signature" hint="A PNG with a transparent background, under 1 MB." :error="form.errors.signature">
                        <UiFileDrop v-model="signature" accept="image/png" :max-size="1" />
                    </UiFormField>
                    <UiCheckbox v-if="template?.hasSignature" v-model="form.remove_signature" label="Remove the current signature" />
                </FormSection>

                <FormSection title="Look">
                    <UiFormField label="Accent colour" required :hint="chosen?.accent ?? 'Colours the border and the course title.'" :error="form.errors.accent">
                        <div class="flex items-center gap-3">
                            <input v-model="form.accent" type="color" class="h-10 w-14 cursor-pointer rounded border" style="border-color: var(--border-subtle)" aria-label="Pick the accent colour">
                            <UiInput v-model="form.accent" class="max-w-32" />
                        </div>
                    </UiFormField>
                    <p v-if="accentTooLight" class="text-sm" style="color: var(--text-warning)">
                        This colour would not stand out enough on {{ chosen?.label ?? 'this layout' }}, so it prints in the layout's own colour instead.
                    </p>
                    <template v-if="form.layout === 'classic'">
                        <UiFormField
                            label="Background"
                            hint="A4 landscape (297 × 210 mm), JPG or PNG, under 5 MB. Leave room in the middle for the text. Without one, the built-in border is used."
                            :error="form.errors.background"
                        >
                            <UiFileDrop v-model="background" accept="image/jpeg,image/png" :max-size="5" />
                        </UiFormField>
                        <UiCheckbox v-if="template?.hasBackground" v-model="form.remove_background" label="Remove the current background" />
                    </template>
                    <p v-else class="text-sm" style="color: var(--text-muted)">
                        {{ chosen?.label }} is drawn whole, ornaments and all, so it takes no background.<template v-if="template?.hasBackground"> The background saved for Classic is kept, should you go back to it.</template>
                    </p>
                </FormSection>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <UiButton v-if="template" variant="ghost" size="sm" @click="destroy">
                        <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                        Remove design
                    </UiButton>
                    <span v-else />
                    <UiButton type="submit" :loading="form.processing">Save design</UiButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
