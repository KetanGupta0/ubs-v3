<script setup>
/**
 * The home page.
 *
 * It splits the two business lines above the fold rather than blending them. A
 * visitor arrives wanting either software built or training taken, and the
 * fastest way to lose them is to make them work out which door is theirs.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight, ArrowUpRight, Code2, GraduationCap, Building2, ShoppingBag,
    HeartPulse, Truck, Landmark, ShieldCheck, Smartphone, GitBranch, Gauge,
    Users, FileCheck2, Star,
} from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import SolutionCard from '@/components/Marketing/SolutionCard.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import MockScreen from '@/components/Marketing/MockScreen.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiCard from '@/components/UI/UiCard.vue';

const props = defineProps({
    featuredSolutions: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    services: { type: Array, default: () => [] },
    featuredCourses: { type: Array, default: () => [] },
    featuredInternships: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    testimonials: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

const categoryIcons = { Building2, ShoppingBag, HeartPulse, Truck, Landmark };
const serviceIcons = { Code2, ArrowUpRight, LifeBuoy: ShieldCheck };

const pillars = [
    { icon: GitBranch, title: 'Your code, your repository', body: 'Yours from the first commit. We do not hold source as leverage, and you can replace us without drama.' },
    { icon: Gauge, title: 'Working software every two weeks', body: 'Deployed somewhere you can open it. No six month silence ending in a surprise.' },
    { icon: FileCheck2, title: 'Scope written down', body: 'Including what is deliberately out. Most disputes are about the second list, not the first.' },
    { icon: Smartphone, title: 'Built for the phone first', body: 'Most of your users are on a mid range Android on patchy data. We build for that, not for an office desktop.' },
];

const technologies = [
    'Laravel', 'Vue.js', 'MySQL', 'PostgreSQL', 'Redis', 'Docker', 'Tailwind CSS',
    'Python', 'Node.js', 'Nginx', 'AWS', 'Inertia.js', 'Pest', 'Git',
];

const trainingPoints = [
    'Internships with the four documents your college asks for',
    'Live on Google Meet, at a fixed time each week',
    'A mentor who reviews your code, not a recorded video',
    'A project you can defend at a viva or an interview',
];

const heroSolution = computed(() => props.featuredSolutions[0] ?? null);
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <!-- ------------------------------------------------------------ hero -->
        <section class="relative overflow-hidden">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />
            <div
                class="pointer-events-none absolute -top-48 left-1/2 h-[34rem] w-[60rem] -translate-x-1/2 rounded-full opacity-25 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-brand-500), transparent)"
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-7xl px-4 pb-16 pt-16 sm:px-6 sm:pb-20 sm:pt-24 lg:px-8">
                <div class="grid items-center gap-12 lg:grid-cols-12">
                    <div class="lg:col-span-6">
                        <UiBadge tone="brand" dot class="animate-fade-up">
                            Software development · Live training
                        </UiBadge>

                        <h1
                            class="animate-fade-up mt-6 text-4xl font-semibold leading-[1.08] sm:text-5xl lg:text-[3.4rem]"
                            style="animation-delay: 80ms"
                        >
                            We build the software, and we train
                            the people who <span class="text-gradient">run it</span>.
                        </h1>

                        <p
                            class="animate-fade-up mt-6 max-w-xl text-base leading-relaxed sm:text-lg"
                            style="animation-delay: 160ms; color: var(--text-muted)"
                        >
                            Unboundbyte Solutions builds custom software, modernises systems you
                            already run, and maintains them afterwards. We also run internships and
                            live courses for college students. Two halves of the same problem,
                            handled by one team.
                        </p>

                        <div
                            class="animate-fade-up mt-8 flex flex-col gap-3 sm:flex-row"
                            style="animation-delay: 240ms"
                        >
                            <UiButton size="lg" href="/solutions">
                                Browse what we build
                                <template #trailing><ArrowRight class="h-4 w-4" /></template>
                            </UiButton>
                            <UiButton size="lg" variant="secondary" href="/training">
                                See training programmes
                            </UiButton>
                        </div>

                        <dl
                            class="animate-fade-up mt-10 flex flex-wrap gap-x-8 gap-y-4"
                            style="animation-delay: 320ms"
                        >
                            <div v-for="stat in [
                                { value: stats.solutions, label: 'products in the catalogue' },
                                { value: stats.internships, label: 'student internships' },
                                { value: stats.courses, label: 'live courses' },
                            ]" :key="stat.label">
                                <dt class="sr-only">{{ stat.label }}</dt>
                                <dd>
                                    <span class="block text-2xl font-semibold tnum" style="color: var(--text-strong)">
                                        {{ stat.value }}
                                    </span>
                                    <span class="text-sm" style="color: var(--text-muted)">{{ stat.label }}</span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="animate-fade-up lg:col-span-6" style="animation-delay: 200ms">
                        <MockScreen
                            v-if="heroSolution"
                            :slug="heroSolution.slug"
                            :accent="heroSolution.accent"
                            :modules="['Overview', 'Purchase', 'Inventory', 'Sales', 'Accounts', 'Reports']"
                        />
                        <p class="mt-3 text-center text-xs" style="color: var(--text-muted)">
                            Illustrative interface. We have not published client screenshots, because
                            we do not have client permission to.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ----------------------------------------------------- the two paths -->
        <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
            <SectionHeading
                align="center"
                eyebrow="Two ways we work"
                title="Which one brought you here?"
                body="They are genuinely different jobs, so we have kept them apart rather than blurring them into one pitch."
            />

            <div class="mt-10 grid gap-5 md:grid-cols-2">
                <UiCard interactive padding="p-7 sm:p-8" as="div">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
                        <Code2 class="h-5 w-5" aria-hidden="true" />
                    </span>

                    <h3 class="mt-5 text-xl font-semibold">I need software built or fixed</h3>
                    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">
                        A new system, an upgrade to one you already run, or a maintenance contract
                        with someone accountable when it breaks at nine on a Friday night.
                    </p>

                    <ul class="mt-5 space-y-2">
                        <li v-for="service in services" :key="service.slug">
                            <Link
                                :href="`/services/${service.slug}`"
                                class="group flex items-center gap-2 text-sm font-medium transition hover:text-brand-600 dark:hover:text-brand-400"
                                style="color: var(--text-base)"
                            >
                                <ArrowRight class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" />
                                {{ service.title }}
                            </Link>
                        </li>
                    </ul>

                    <UiButton class="mt-6" href="/solutions" variant="secondary">
                        Browse the catalogue
                        <template #trailing><ArrowUpRight class="h-3.5 w-3.5" /></template>
                    </UiButton>
                </UiCard>

                <UiCard interactive padding="p-7 sm:p-8" as="div">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-accent-50 text-accent-700 dark:bg-accent-900/40 dark:text-accent-300">
                        <GraduationCap class="h-5 w-5" aria-hidden="true" />
                    </span>

                    <h3 class="mt-5 text-xl font-semibold">I am a student, and I want to build</h3>
                    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">
                        Internships that meet your college requirement and leave you with a real
                        project, plus longer live courses if you want a subject properly.
                    </p>

                    <ul class="mt-5 space-y-2">
                        <li
                            v-for="point in trainingPoints"
                            :key="point"
                            class="flex items-start gap-2 text-sm"
                            style="color: var(--text-base)"
                        >
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-accent-500" aria-hidden="true" />
                            {{ point }}
                        </li>
                    </ul>

                    <div class="mt-6 flex flex-wrap gap-2">
                        <UiButton href="/internships">
                            See internships
                            <template #trailing><ArrowUpRight class="h-3.5 w-3.5" /></template>
                        </UiButton>
                        <UiButton href="/training" variant="secondary">Courses</UiButton>
                    </div>
                </UiCard>
            </div>
        </section>

        <!-- --------------------------------------------------------- catalogue -->
        <section class="py-16 sm:py-20" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-end justify-between gap-6">
                    <SectionHeading
                        eyebrow="Solutions catalogue"
                        title="Software we build, by category"
                        body="Fifteen products across five industries, each with what is in it, what it runs on, an indicative budget and an indicative timeline."
                    />

                    <UiButton variant="secondary" href="/solutions">
                        See all {{ stats.solutions }}
                        <template #trailing><ArrowRight class="h-4 w-4" /></template>
                    </UiButton>
                </div>

                <!-- Categories as a quick way in. -->
                <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <Link
                        v-for="(category, index) in categories"
                        :key="category.slug"
                        :href="`/solutions?category=${category.slug}`"
                        class="animate-fade-up group rounded-xl border bg-[var(--surface)] p-4 transition-[transform,border-color] duration-[var(--duration-base)] hover:-translate-y-0.5 hover:border-brand-300 dark:hover:border-brand-700"
                        :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 60}ms` }"
                    >
                        <component
                            :is="categoryIcons[category.icon] ?? Building2"
                            class="h-4.5 w-4.5 text-brand-600 dark:text-brand-400"
                            aria-hidden="true"
                        />
                        <h3 class="mt-3 text-sm font-semibold leading-snug">{{ category.name }}</h3>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">
                            {{ category.count }} {{ category.count === 1 ? 'product' : 'products' }}
                        </p>
                    </Link>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <SolutionCard
                        v-for="(solution, index) in featuredSolutions"
                        :key="solution.slug"
                        :solution="solution"
                        :delay="index * 80"
                    />
                </div>
            </div>
        </section>

        <!-- ----------------------------------------------------------- pillars -->
        <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
            <SectionHeading
                eyebrow="How we work"
                title="The parts that decide whether a project goes well"
                body="None of this is exciting. All of it is what separates software you keep from software you replace in eighteen months."
            />

            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="(pillar, index) in pillars"
                    :key="pillar.title"
                    class="animate-fade-up rounded-[var(--radius-card)] border p-5"
                    :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 70}ms` }"
                >
                    <component :is="pillar.icon" class="h-5 w-5 text-accent-500" aria-hidden="true" />
                    <h3 class="mt-4 text-sm font-semibold">{{ pillar.title }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed" style="color: var(--text-muted)">{{ pillar.body }}</p>
                </div>
            </div>

            <UiButton class="mt-8" variant="ghost" href="/process">
                Read how a project actually runs
                <template #trailing><ArrowRight class="h-4 w-4" /></template>
            </UiButton>
        </section>

        <!-- -------------------------------------------------------- technology -->
        <section class="border-y py-10" style="border-color: var(--border-subtle)">
            <p class="mb-6 text-center text-xs font-semibold uppercase tracking-[0.18em]" style="color: var(--text-muted)">
                Built on tools chosen to be maintainable by someone other than us
            </p>

            <div class="ub-marquee flex gap-3 overflow-hidden" aria-hidden="true">
                <div v-for="copy in 2" :key="copy" class="ub-marquee-track flex shrink-0 gap-3">
                    <span
                        v-for="tech in technologies"
                        :key="`${copy}-${tech}`"
                        class="rounded-full border px-4 py-2 text-sm font-medium whitespace-nowrap"
                        style="border-color: var(--border-subtle); color: var(--text-base)"
                    >
                        {{ tech }}
                    </span>
                </div>
            </div>

            <p class="sr-only">We build on {{ technologies.join(', ') }}.</p>
        </section>

        <!-- ------------------------------------------------------- internships -->
        <section v-if="featuredInternships.length" class="py-16 sm:py-20" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-end justify-between gap-6">
                    <SectionHeading
                        eyebrow="For college students"
                        title="Internships that produce something you can show"
                        body="Remote, with a brief instead of a tutorial and a mentor who reviews your code every week. Every one ends with the four documents your college asks for."
                    />

                    <UiButton variant="secondary" href="/internships">
                        All {{ stats.internships }} internships
                        <template #trailing><ArrowRight class="h-4 w-4" /></template>
                    </UiButton>
                </div>

                <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="(item, index) in featuredInternships"
                        :key="item.slug"
                        :href="`/internships/${item.slug}`"
                        class="animate-fade-up group flex flex-col rounded-[var(--radius-card)] border bg-[var(--surface)] p-6 shadow-[var(--shadow-card)] transition-[transform,box-shadow] duration-[var(--duration-base)] hover:-translate-y-1 hover:shadow-[var(--shadow-pop)]"
                        :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 80}ms` }"
                    >
                        <div class="flex flex-wrap items-center gap-1.5">
                            <UiBadge tone="brand" size="sm">{{ item.durationLabel }}</UiBadge>
                            <UiBadge size="sm" class="capitalize">{{ item.level }}</UiBadge>
                        </div>

                        <h3 class="mt-4 text-lg font-semibold leading-snug">{{ item.title }}</h3>
                        <p class="mt-1.5 text-sm" style="color: var(--text-muted)">{{ item.tagline }}</p>

                        <p
                            v-if="item.projectFocus"
                            class="mt-4 rounded-lg px-3 py-2 text-xs leading-relaxed"
                            style="background: var(--surface-sunken); color: var(--text-base)"
                        >
                            <span class="font-semibold">You build:</span> {{ item.projectFocus }}
                        </p>

                        <div class="mt-auto flex items-end justify-between border-t pt-4" style="border-color: var(--border-subtle); margin-top: 1.25rem">
                            <span>
                                <span class="text-lg font-semibold tnum" style="color: var(--text-strong)">
                                    ₹{{ item.price.toLocaleString('en-IN') }}
                                </span>
                                <span
                                    v-if="item.originalPrice"
                                    class="ml-1.5 text-sm line-through tnum"
                                    style="color: var(--text-muted)"
                                >
                                    ₹{{ item.originalPrice.toLocaleString('en-IN') }}
                                </span>
                            </span>

                            <ArrowUpRight
                                class="h-4 w-4 text-brand-600 transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5 dark:text-brand-400"
                            />
                        </div>
                    </Link>
                </div>

                <p class="mt-6 text-sm" style="color: var(--text-muted)">
                    Sending a whole batch from your college?
                    <Link href="/for-colleges" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">
                        See how a tie-up works
                    </Link>
                </p>
            </div>
        </section>

        <!-- ---------------------------------------------------------- training -->
        <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <SectionHeading
                    eyebrow="Live courses"
                    title="Go deeper on one subject"
                    body="Longer programmes for students and working developers who want a technology properly rather than a taste of it. Live on Google Meet, with assessment and a reviewed project."
                />

                <UiButton variant="secondary" href="/training">
                    All programmes
                    <template #trailing><ArrowRight class="h-4 w-4" /></template>
                </UiButton>
            </div>

            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="(course, index) in featuredCourses"
                    :key="course.slug"
                    :href="`/training/${course.slug}`"
                    class="animate-fade-up group flex flex-col rounded-[var(--radius-card)] border bg-[var(--surface)] p-6 shadow-[var(--shadow-card)] transition-[transform,box-shadow] duration-[var(--duration-base)] hover:-translate-y-1 hover:shadow-[var(--shadow-pop)]"
                    :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 80}ms` }"
                >
                    <div class="flex items-center gap-2">
                        <UiBadge tone="accent" size="sm">{{ course.type }}</UiBadge>
                        <UiBadge size="sm">{{ course.level }}</UiBadge>
                    </div>

                    <h3 class="mt-4 text-lg font-semibold leading-snug">{{ course.title }}</h3>
                    <p class="mt-1.5 text-sm" style="color: var(--text-muted)">{{ course.tagline }}</p>

                    <dl class="mt-4 space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <dt style="color: var(--text-muted)">Duration</dt>
                            <dd class="tnum" style="color: var(--text-base)">{{ course.durationWeeks }} weeks</dd>
                        </div>
                        <div v-if="course.nextBatch" class="flex justify-between">
                            <dt style="color: var(--text-muted)">Next batch</dt>
                            <dd style="color: var(--text-base)">{{ course.nextBatch.startsOnLabel }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 flex items-end justify-between border-t pt-4" style="border-color: var(--border-subtle)">
                        <span>
                            <span v-if="course.price" class="text-lg font-semibold tnum" style="color: var(--text-strong)">
                                ₹{{ course.price.toLocaleString('en-IN') }}
                            </span>
                            <span v-else class="text-sm font-semibold" style="color: var(--text-strong)">On request</span>
                            <span
                                v-if="course.originalPrice"
                                class="ml-1.5 text-sm line-through tnum"
                                style="color: var(--text-muted)"
                            >
                                ₹{{ course.originalPrice.toLocaleString('en-IN') }}
                            </span>
                        </span>

                        <ArrowUpRight
                            class="h-4 w-4 text-brand-600 transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5 dark:text-brand-400"
                        />
                    </div>
                </Link>
            </div>
        </section>

        <!-- Testimonials render only when there is a real one to show. -->
        <section v-if="testimonials.length" class="py-16 sm:py-20" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading align="center" eyebrow="In their words" title="What clients and students say" />

                <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <figure
                        v-for="item in testimonials"
                        :key="item.author"
                        class="rounded-[var(--radius-card)] border bg-[var(--surface)] p-6"
                        style="border-color: var(--border-subtle)"
                    >
                        <div v-if="item.rating" class="flex gap-0.5" :aria-label="`${item.rating} out of 5`">
                            <Star
                                v-for="n in item.rating"
                                :key="n"
                                class="h-3.5 w-3.5 fill-amber-400 text-amber-400"
                                aria-hidden="true"
                            />
                        </div>

                        <blockquote class="mt-3 text-sm leading-relaxed" style="color: var(--text-base)">
                            {{ item.quote }}
                        </blockquote>

                        <figcaption class="mt-4 text-sm">
                            <span class="font-semibold" style="color: var(--text-strong)">{{ item.author }}</span>
                            <span v-if="item.role" class="block text-xs" style="color: var(--text-muted)">{{ item.role }}</span>
                        </figcaption>
                    </figure>
                </div>
            </div>
        </section>

        <CtaSection
            secondary-label="Browse the catalogue"
            secondary-href="/solutions"
        />
    </PublicLayout>
</template>

<style scoped>
.ub-marquee-track {
    animation: ub-scroll 40s linear infinite;
}

.ub-marquee:hover .ub-marquee-track {
    animation-play-state: paused;
}

@keyframes ub-scroll {
    from { transform: translateX(0); }
    to { transform: translateX(calc(-100% - 0.75rem)); }
}

@media (prefers-reduced-motion: reduce) {
    .ub-marquee-track { animation: none; }
    .ub-marquee { overflow-x: auto; }
}
</style>
