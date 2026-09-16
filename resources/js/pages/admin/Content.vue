<script setup>
/**
 * Questions and testimonials.
 *
 * Both are short enough to edit in a dialog rather than on their own page, so
 * the list stays the thing you are looking at.
 *
 * The testimonial form has no "generate" button and never will. A quote here is
 * something a real person said, or the section on the public site renders
 * nothing at all, which is the honest outcome until somebody has said it.
 */
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { MessageSquareQuote, Plus, Pencil, Trash2, HelpCircle, Eye, EyeOff } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTabs from '@/components/UI/UiTabs.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    faqs: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    testimonials: { type: Array, default: () => [] },
});

const tab = ref('faqs');

const tabs = computed(() => [
    { value: 'faqs', label: 'Questions', count: props.faqs.length },
    { value: 'testimonials', label: 'Testimonials', count: props.testimonials.length },
]);

/* ------------------------------------------------------------------- faqs */

const grouped = computed(() => {
    const buckets = new Map();

    props.faqs.forEach((faq) => {
        if (!buckets.has(faq.group)) buckets.set(faq.group, []);
        buckets.get(faq.group).push(faq);
    });

    return [...buckets].map(([group, items]) => ({ group, items }));
});

const editingFaq = ref(null);
const faqDialogOpen = ref(false);

const faqForm = useForm({
    question: '',
    answer: '',
    group: '',
    sort_order: 0,
    is_published: true,
});

function startFaq(faq = null) {
    editingFaq.value = faq;
    faqForm.clearErrors();
    faqForm.question = faq?.question ?? '';
    faqForm.answer = faq?.answer ?? '';
    faqForm.group = faq?.group ?? props.groups[0] ?? 'General';
    faqForm.sort_order = faq?.sortOrder ?? 0;
    faqForm.is_published = faq?.isPublished ?? true;
    faqDialogOpen.value = true;
}

function endFaq() {
    faqDialogOpen.value = false;
    editingFaq.value = null;
    faqForm.reset();
}

function saveFaq() {
    const done = { preserveScroll: true, onSuccess: () => endFaq() };

    editingFaq.value
        ? faqForm.put(`/admin/content/faqs/${editingFaq.value.id}`, done)
        : faqForm.post('/admin/content/faqs', done);
}

function deleteFaq(faq) {
    if (confirm(`Remove "${faq.question}"?`)) {
        router.delete(`/admin/content/faqs/${faq.id}`, { preserveScroll: true });
    }
}

/* ----------------------------------------------------------- testimonials */

const editingTestimonial = ref(null);
const testimonialDialogOpen = ref(false);

const testimonialForm = useForm({
    author_name: '',
    author_role: '',
    company: '',
    quote: '',
    rating: 5,
    sort_order: 0,
    is_published: false,
});

function startTestimonial(testimonial = null) {
    editingTestimonial.value = testimonial;
    testimonialForm.clearErrors();
    testimonialForm.author_name = testimonial?.authorName ?? '';
    testimonialForm.author_role = testimonial?.authorRole ?? '';
    testimonialForm.company = testimonial?.company ?? '';
    testimonialForm.quote = testimonial?.quote ?? '';
    testimonialForm.rating = testimonial?.rating ?? 5;
    testimonialForm.sort_order = testimonial?.sortOrder ?? 0;
    testimonialForm.is_published = testimonial?.isPublished ?? false;
    testimonialDialogOpen.value = true;
}

function endTestimonial() {
    testimonialDialogOpen.value = false;
    editingTestimonial.value = null;
    testimonialForm.reset();
}

function saveTestimonial() {
    const done = { preserveScroll: true, onSuccess: () => endTestimonial() };

    editingTestimonial.value
        ? testimonialForm.put(`/admin/content/testimonials/${editingTestimonial.value.id}`, done)
        : testimonialForm.post('/admin/content/testimonials', done);
}

function deleteTestimonial(testimonial) {
    if (confirm(`Remove the quote from ${testimonial.authorName}?`)) {
        router.delete(`/admin/content/testimonials/${testimonial.id}`, { preserveScroll: true });
    }
}

function togglePublished(testimonial) {
    router.put(
        `/admin/content/testimonials/${testimonial.id}`,
        {
            author_name: testimonial.authorName,
            author_role: testimonial.authorRole,
            company: testimonial.company,
            quote: testimonial.quote,
            rating: testimonial.rating,
            sort_order: testimonial.sortOrder,
            is_published: !testimonial.isPublished,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Site content" />

    <AppLayout title="Site content" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Content' }]">
        <div class="mx-auto max-w-5xl">
            <PageHeader
                title="Site content"
                description="The questions answered on the public site, and the quotes we are allowed to print."
            >
                <template #actions>
                    <UiButton size="sm" @click="tab === 'faqs' ? startFaq() : startTestimonial()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        {{ tab === 'faqs' ? 'Add a question' : 'Add a testimonial' }}
                    </UiButton>
                </template>
            </PageHeader>

            <UiTabs v-model="tab" :tabs="tabs" class="mb-5" />

            <!-- ------------------------------------------------------ faqs -->
            <div v-if="tab === 'faqs'" class="space-y-6">
                <UiEmptyState
                    v-if="!faqs.length"
                    :icon="HelpCircle"
                    title="No questions yet"
                    description="Add the questions people actually ask, in their own words."
                />

                <section v-for="bucket in grouped" :key="bucket.group">
                    <h2 class="mb-2.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                        {{ bucket.group }}
                    </h2>

                    <ul
                        class="divide-y overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)]"
                        style="border-color: var(--border-subtle)"
                    >
                        <li
                            v-for="faq in bucket.items"
                            :key="faq.id"
                            class="flex items-start gap-4 p-4 transition hover:bg-[var(--surface-sunken)]"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                    {{ faq.question }}
                                    <UiBadge v-if="!faq.isPublished" tone="warning" size="sm">hidden</UiBadge>
                                </p>
                                <p class="mt-1 line-clamp-2 text-sm" style="color: var(--text-muted)">
                                    {{ faq.answer }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <UiButton variant="ghost" size="xs" icon aria-label="Edit question" @click="startFaq(faq)">
                                    <Pencil class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton variant="ghost" size="xs" icon aria-label="Remove question" @click="deleteFaq(faq)">
                                    <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                                </UiButton>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- ---------------------------------------------- testimonials -->
            <div v-else class="space-y-4">
                <p
                    class="rounded-[var(--radius-card)] border px-4 py-3 text-sm"
                    style="border-color: var(--border-subtle); color: var(--text-muted)"
                >
                    A testimonial goes live only when you publish it. Until there is a real one, the section on the
                    public site renders nothing, which is deliberate.
                </p>

                <UiEmptyState
                    v-if="!testimonials.length"
                    :icon="MessageSquareQuote"
                    title="No testimonials yet"
                    description="Add one when a client has actually said it and is happy to be quoted."
                />

                <article
                    v-for="testimonial in testimonials"
                    :key="testimonial.id"
                    class="rounded-[var(--radius-card)] border bg-[var(--surface)] p-5"
                    style="border-color: var(--border-subtle)"
                >
                    <blockquote class="text-sm leading-relaxed" style="color: var(--text-base)">
                        “{{ testimonial.quote }}”
                    </blockquote>

                    <footer class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">{{ testimonial.authorName }}</p>
                            <p class="text-xs" style="color: var(--text-muted)">
                                {{ [testimonial.authorRole, testimonial.company].filter(Boolean).join(', ') || '—' }}
                            </p>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <UiBadge :tone="testimonial.isPublished ? 'success' : 'neutral'" size="sm" dot>
                                {{ testimonial.isPublished ? 'live' : 'not published' }}
                            </UiBadge>
                            <UiButton variant="ghost" size="xs" @click="togglePublished(testimonial)">
                                <template #leading>
                                    <component :is="testimonial.isPublished ? EyeOff : Eye" class="h-3.5 w-3.5" />
                                </template>
                                {{ testimonial.isPublished ? 'Unpublish' : 'Publish' }}
                            </UiButton>
                            <UiButton variant="ghost" size="xs" icon aria-label="Edit testimonial" @click="startTestimonial(testimonial)">
                                <Pencil class="h-3.5 w-3.5" />
                            </UiButton>
                            <UiButton variant="ghost" size="xs" icon aria-label="Remove testimonial" @click="deleteTestimonial(testimonial)">
                                <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                            </UiButton>
                        </div>
                    </footer>
                </article>
            </div>
        </div>

        <!-- ------------------------------------------------- faq dialog -->
        <UiModal
            :open="faqDialogOpen"
            :title="editingFaq ? 'Edit question' : 'Add a question'"
            size="lg"
            @close="endFaq"
        >
            <form id="faq-form" class="space-y-4" @submit.prevent="saveFaq">
                <UiFormField label="Question" required :error="faqForm.errors.question">
                    <UiInput v-model="faqForm.question" placeholder="How long does a project usually take?" />
                </UiFormField>

                <UiFormField label="Answer" required :error="faqForm.errors.answer">
                    <UiTextarea v-model="faqForm.answer" :rows="6" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Group" required :error="faqForm.errors.group" hint="Questions are shown grouped under this heading.">
                        <UiInput v-model="faqForm.group" list="faq-groups" placeholder="General" />
                        <datalist id="faq-groups">
                            <option v-for="group in groups" :key="group" :value="group" />
                        </datalist>
                    </UiFormField>

                    <UiFormField label="Order" :error="faqForm.errors.sort_order" hint="Lower numbers come first.">
                        <UiInput v-model="faqForm.sort_order" type="number" min="0" />
                    </UiFormField>
                </div>

                <UiSwitch v-model="faqForm.is_published" label="Published" description="Hidden questions stay here but never reach the site." />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="endFaq">Cancel</UiButton>
                <UiButton type="submit" form="faq-form" :loading="faqForm.processing">
                    {{ editingFaq ? 'Save question' : 'Add question' }}
                </UiButton>
            </template>
        </UiModal>

        <!-- ----------------------------------------- testimonial dialog -->
        <UiModal
            :open="testimonialDialogOpen"
            :title="editingTestimonial ? 'Edit testimonial' : 'Add a testimonial'"
            description="Only add a quote somebody actually gave us, with their permission to print it."
            size="lg"
            @close="endTestimonial"
        >
            <form id="testimonial-form" class="space-y-4" @submit.prevent="saveTestimonial">
                <UiFormField label="Quote" required :error="testimonialForm.errors.quote">
                    <UiTextarea v-model="testimonialForm.quote" :rows="5" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Name" required :error="testimonialForm.errors.author_name">
                        <UiInput v-model="testimonialForm.author_name" />
                    </UiFormField>

                    <UiFormField label="Role" :error="testimonialForm.errors.author_role">
                        <UiInput v-model="testimonialForm.author_role" placeholder="Operations lead" />
                    </UiFormField>

                    <UiFormField label="Company" :error="testimonialForm.errors.company">
                        <UiInput v-model="testimonialForm.company" />
                    </UiFormField>

                    <UiFormField label="Rating" :error="testimonialForm.errors.rating">
                        <UiSelect
                            v-model="testimonialForm.rating"
                            :options="[1, 2, 3, 4, 5].map((n) => ({ value: n, label: `${n} out of 5` }))"
                        />
                    </UiFormField>
                </div>

                <UiSwitch
                    v-model="testimonialForm.is_published"
                    label="Published"
                    description="Leave this off until you have their written go ahead."
                />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="endTestimonial">Cancel</UiButton>
                <UiButton type="submit" form="testimonial-form" :loading="testimonialForm.processing">
                    {{ editingTestimonial ? 'Save testimonial' : 'Add testimonial' }}
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
