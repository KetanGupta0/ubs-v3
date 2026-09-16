<script setup>
import { Link } from '@inertiajs/vue3';
import { Server, Layout, Database, Cloud, ShieldCheck, TestTube2 } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

defineProps({ seo: { type: Object, required: true } });

const stacks = [
    { icon: Server, title: 'Backend', items: ['Laravel', 'PHP 8.4', 'Node.js', 'Python'], body: 'Laravel for almost everything. Mature, extremely well documented, and easy to hire for in India.' },
    { icon: Layout, title: 'Frontend', items: ['Vue.js', 'Inertia.js', 'Tailwind CSS', 'Vite'], body: 'Server driven routing with a single page feel, which keeps authorisation on the server where it belongs.' },
    { icon: Database, title: 'Data', items: ['MySQL', 'PostgreSQL', 'Redis', 'Meilisearch'], body: 'Relational by default. We reach for something exotic only when the problem genuinely demands it.' },
    { icon: Cloud, title: 'Infrastructure', items: ['Docker', 'Nginx', 'AWS', 'Cloudflare'], body: 'Repeatable deployments and infrastructure described in files, so a server can be rebuilt rather than remembered.' },
    { icon: TestTube2, title: 'Quality', items: ['Pest', 'Pint', 'GitHub Actions', 'Playwright'], body: 'Tests, style checks and a production build on every push, so a regression is caught before a customer finds it.' },
    { icon: ShieldCheck, title: 'Security', items: ['OWASP practices', 'Dependency audits', 'Signed URLs', 'Two factor'], body: 'Authorisation policies on every model, rate limits on anything that costs money to call.' },
];
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />

            <div class="relative mx-auto max-w-4xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>Technology</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">
                    Boring technology, chosen on purpose
                </h1>

                <div class="mt-6 space-y-4 text-base leading-relaxed" style="color: var(--text-muted)">
                    <p>
                        The most expensive decision in a software project is usually a framework
                        chosen because it was interesting. Three years later it is unmaintained,
                        nobody local knows it, and replacing the original team means replacing the
                        system.
                    </p>
                    <p>
                        So we build on tools that are well understood, actively maintained, and easy
                        to hire for. The constraint is deliberate: you should be able to hand your
                        system to another competent team without it being an archaeology project.
                    </p>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-5xl px-4 py-14 sm:px-6 lg:px-8">
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="(stack, index) in stacks"
                    :key="stack.title"
                    class="animate-fade-up rounded-[var(--radius-card)] border p-5"
                    :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 70}ms` }"
                >
                    <component :is="stack.icon" class="h-5 w-5 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <h2 class="mt-4 text-base font-semibold">{{ stack.title }}</h2>
                    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">{{ stack.body }}</p>

                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <UiBadge v-for="item in stack.items" :key="item" size="sm">{{ item }}</UiBadge>
                    </div>
                </div>
            </div>
        </section>

        <section class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Worth saying plainly"
                    title="This site runs on the same stack"
                    body="The platform you are reading, and the dashboards behind it, are built with exactly what is listed above. If the stack could not carry a real application we would be the first to know."
                />
            </div>
        </section>

        <CtaSection secondary-label="See what we build with it" secondary-href="/solutions" />
    </PublicLayout>
</template>
