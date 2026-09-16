<script setup>
/**
 * Create and edit a catalogue entry.
 *
 * Long, because a product page carries a lot. Grouped so an administrator
 * editing one paragraph does not have to scroll past the pricing bands.
 */
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';
import UiObjectRepeater from '@/components/UI/UiObjectRepeater.vue';

const props = defineProps({
    solution: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    accents: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.solution));

const form = useForm({
    title: props.solution?.title ?? '',
    slug: props.solution?.slug ?? '',
    solution_category_id: props.solution?.solution_category_id ?? props.categories[0]?.value ?? null,
    tagline: props.solution?.tagline ?? '',
    summary: props.solution?.summary ?? '',
    description: props.solution?.description ?? '',
    industries: props.solution?.industries ?? [],
    platforms: props.solution?.platforms ?? [],
    tech_stack: props.solution?.tech_stack ?? [],
    modules: props.solution?.modules ?? [],
    outcomes: props.solution?.outcomes ?? [],
    integrations: props.solution?.integrations ?? [],
    features: props.solution?.features ?? [],
    price_band_min: props.solution?.price_band_min ?? '',
    price_band_max: props.solution?.price_band_max ?? '',
    timeline_weeks_min: props.solution?.timeline_weeks_min ?? '',
    timeline_weeks_max: props.solution?.timeline_weeks_max ?? '',
    accent: props.solution?.accent ?? 'brand',
    needs_api_keys: props.solution?.needs_api_keys ?? false,
    is_featured: props.solution?.is_featured ?? false,
    is_published: props.solution?.is_published ?? false,
    sort_order: props.solution?.sort_order ?? 0,
});

function submit() {
    isEdit.value
        ? form.put(`/admin/solutions/${props.solution.id}`, { preserveScroll: true })
        : form.post('/admin/solutions');
}

function destroy() {
    if (confirm('Delete this solution? Taking it off the site is usually what you want instead.')) {
        router.delete(`/admin/solutions/${props.solution.id}`);
    }
}
</script>

<template>
    <Head :title="isEdit ? solution.title : 'New solution'" />

    <AppLayout
        :title="isEdit ? solution.title : 'New solution'"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Solutions', href: '/admin/solutions' },
            { label: isEdit ? 'Edit' : 'New' },
        ]"
    >
        <form class="mx-auto max-w-4xl space-y-5" @submit.prevent="submit">
            <PageHeader :title="isEdit ? solution.title : 'New solution'">
                <template #actions>
                    <UiButton href="/admin/solutions" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton
                        v-if="isEdit && solution.is_published"
                        :href="solution.publicUrl"
                        :inertia="false"
                        variant="ghost"
                        size="sm"
                    >
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        View live
                    </UiButton>
                    <UiButton type="submit" size="sm" :loading="form.processing">
                        {{ isEdit ? 'Save' : 'Create' }}
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="The basics" description="What appears on the card and at the top of the page.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Title" required :error="form.errors.title">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.title" :invalid="field.invalid" required />
                        </template>
                    </UiFormField>

                    <UiFormField
                        label="URL slug"
                        hint="Leave empty to build it from the title."
                        :error="form.errors.slug"
                    >
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.slug" class="font-mono" placeholder="unified-erp-suite" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <UiFormField label="Category" required :error="form.errors.solution_category_id">
                    <template #default="field">
                        <UiSelect :id="field.id" v-model="form.solution_category_id" :options="categories" />
                    </template>
                </UiFormField>

                <UiFormField
                    label="Tagline"
                    required
                    hint="One line, under the title. The promise in a sentence."
                    :error="form.errors.tagline"
                >
                    <template #default="field">
                        <UiInput :id="field.id" v-model="form.tagline" :invalid="field.invalid" required />
                    </template>
                </UiFormField>

                <UiFormField
                    label="Summary"
                    required
                    hint="Two or three sentences. Used on the card and as the search description."
                    :error="form.errors.summary"
                >
                    <template #default="field">
                        <UiTextarea :id="field.id" v-model="form.summary" :rows="3" :invalid="field.invalid" required />
                    </template>
                </UiFormField>

                <UiFormField
                    label="Why this exists"
                    hint="The longer explanation. Leave a blank line between paragraphs."
                    :error="form.errors.description"
                >
                    <template #default="field">
                        <UiTextarea :id="field.id" v-model="form.description" :rows="8" :invalid="field.invalid" />
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection
                title="How it is found"
                description="These are the catalogue filters, so use the same words across products."
            >
                <UiFormField label="Industries" :error="form.errors.industries">
                    <template #default>
                        <UiRepeater v-model="form.industries" chips placeholder="Manufacturing" />
                    </template>
                </UiFormField>

                <UiFormField label="Platforms" :error="form.errors.platforms">
                    <template #default>
                        <UiRepeater v-model="form.platforms" chips placeholder="Web" />
                    </template>
                </UiFormField>

                <UiFormField label="Technology" :error="form.errors.tech_stack">
                    <template #default>
                        <UiRepeater v-model="form.tech_stack" chips placeholder="Laravel" />
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection title="What is in it">
                <UiFormField label="Modules" hint="Shown as a grid on the product page." :error="form.errors.modules">
                    <template #default>
                        <UiRepeater v-model="form.modules" chips placeholder="Purchase" />
                    </template>
                </UiFormField>

                <UiFormField label="Integrations" :error="form.errors.integrations">
                    <template #default>
                        <UiRepeater v-model="form.integrations" chips placeholder="Tally" />
                    </template>
                </UiFormField>

                <UiFormField
                    label="What changes once it is running"
                    hint="Outcomes, not features."
                    :error="form.errors.outcomes"
                >
                    <template #default>
                        <UiRepeater v-model="form.outcomes" placeholder="Stock figures that match a physical count" />
                    </template>
                </UiFormField>

                <UiFormField label="Feature tour" :error="form.errors.features">
                    <template #default>
                        <UiObjectRepeater
                            v-model="form.features"
                            title-key="title"
                            add-label="Add a feature"
                            :fields="[
                                { key: 'title', label: 'Feature', type: 'text', placeholder: 'One record, everywhere' },
                                { key: 'body', label: 'What it does', type: 'textarea', rows: 2 },
                            ]"
                        />
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection
                title="Indicative figures"
                description="Shown as bands and captioned as indicative, never as a quotation."
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Budget from (₹)" :error="form.errors.price_band_min">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.price_band_min" type="number" min="0" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Budget to (₹)" :error="form.errors.price_band_max">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.price_band_max" type="number" min="0" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Timeline from (weeks)" :error="form.errors.timeline_weeks_min">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.timeline_weeks_min" type="number" min="1" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Timeline to (weeks)" :error="form.errors.timeline_weeks_max">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.timeline_weeks_max" type="number" min="1" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection title="Presentation and visibility">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Accent colour" :error="form.errors.accent">
                        <template #default="field">
                            <UiSelect
                                :id="field.id"
                                v-model="form.accent"
                                :options="accents.map((a) => ({ value: a, label: a }))"
                            />
                        </template>
                    </UiFormField>

                    <UiFormField label="Order in the list" hint="Lower comes first." :error="form.errors.sort_order">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.sort_order" type="number" min="0" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <div class="space-y-3 border-t pt-4" style="border-color: var(--border-subtle)">
                    <UiSwitch
                        v-model="form.is_published"
                        label="Live on the public site"
                        description="Unpublishing removes it from the catalogue and the sitemap immediately."
                    />
                    <UiSwitch v-model="form.is_featured" label="Featured" description="Appears on the home page and first in the catalogue." />
                    <UiSwitch
                        v-model="form.needs_api_keys"
                        label="Needs API keys"
                        description="Shows the note about buying and managing keys from the client dashboard."
                    />
                </div>
            </FormSection>

            <div class="flex items-center justify-between gap-2">
                <UiButton v-if="isEdit" type="button" variant="ghost" size="sm" @click="destroy">
                    <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                    Delete
                </UiButton>
                <span v-else />

                <span class="flex gap-2">
                    <UiButton href="/admin/solutions" variant="secondary">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing">{{ isEdit ? 'Save changes' : 'Create solution' }}</UiButton>
                </span>
            </div>
        </form>
    </AppLayout>
</template>
