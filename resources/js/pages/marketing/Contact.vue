<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Code2, GraduationCap, MessageCircle, Clock, ShieldCheck } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import EnquiryForm from '@/components/Marketing/EnquiryForm.vue';

defineProps({
    solutions: { type: Array, default: () => [] },
    services: { type: Array, default: () => [] },
    courses: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

/** Which form to show. Changes the prompts and the fields, not the endpoint. */
const interest = ref('solution');

const tabs = [
    { value: 'solution', label: 'Software project', icon: Code2 },
    { value: 'training', label: 'Training', icon: GraduationCap },
    { value: 'general', label: 'Something else', icon: MessageCircle },
];

const assurances = [
    { icon: Clock, title: 'One working day', body: 'That is the reply target, and a person reads every enquiry rather than a routing rule.' },
    { icon: ShieldCheck, title: 'Used only to reply', body: 'No mailing lists, no sharing, no sales sequence you have to unsubscribe from.' },
];
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />

            <div class="relative mx-auto max-w-4xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>Contact</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">Tell us what you need</h1>
                <p class="mt-4 max-w-2xl text-base leading-relaxed" style="color: var(--text-muted)">
                    Whether it is software you want built, a system that needs rescuing, or a
                    programme you are thinking of joining. The more specific you are, the more
                    useful our reply can be.
                </p>

                <div class="mt-6 flex flex-wrap gap-x-8 gap-y-3">
                    <div v-for="item in assurances" :key="item.title" class="flex items-start gap-2.5">
                        <component :is="item.icon" class="mt-0.5 h-4 w-4 shrink-0 text-brand-500" aria-hidden="true" />
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{{ item.title }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">{{ item.body }}</span>
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
            <div
                class="mb-6 grid grid-cols-3 gap-1 rounded-xl p-1"
                style="background: var(--surface-sunken)"
                role="tablist"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    role="tab"
                    :aria-selected="interest === tab.value"
                    :class="[
                        'inline-flex items-center justify-center gap-2 rounded-lg px-2 py-2.5 text-sm font-medium transition',
                        interest === tab.value
                            ? 'bg-[var(--surface)] shadow-sm text-[var(--text-strong)]'
                            : 'text-[var(--text-muted)] hover:text-[var(--text-strong)]',
                    ]"
                    @click="interest = tab.value"
                >
                    <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ tab.label }}</span>
                </button>
            </div>

            <!-- Keyed so switching tab gives a clean form rather than carrying
                 project fields into a training enquiry. -->
            <EnquiryForm
                :key="interest"
                :interest="interest"
                :show-project-fields="interest === 'solution'"
                compact
            />
        </section>
    </PublicLayout>
</template>
