<script setup>
/**
 * Privacy policy and terms.
 *
 * Written plainly and kept accurate to what the platform actually does. A legal
 * page that describes behaviour the software does not have is worse than none,
 * because it is a documented promise nobody is keeping.
 *
 * This is not legal advice. Have a lawyer review before the site goes live.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';

const props = defineProps({
    document: { type: String, required: true },
    title: { type: String, required: true },
    seo: { type: Object, required: true },
});

const privacy = [
    {
        heading: 'What we collect',
        body: 'When you send an enquiry we store your name, email address, and optionally your mobile number and company, along with the message and which page you sent it from. If you hold an account we store what you do in it, such as your projects, invoices, course progress and attendance. We record authentication events, with the identifier partially masked.',
    },
    {
        heading: 'Why we collect it',
        body: 'To reply to you, to provide the service you have asked for, and to keep the account secure. We do not sell your information, we do not share it with advertisers, and we do not add you to a mailing list because you contacted us.',
    },
    {
        heading: 'Who else sees it',
        body: 'Only the providers we need to operate: our hosting provider, our email and SMS providers for messages we send you, and our payment gateway for payments you make. Each receives only what that specific task requires.',
    },
    {
        heading: 'How long we keep it',
        body: 'Enquiries are kept for three years. Account data is kept for as long as the account is open, and afterwards only where law requires it, such as invoices retained for tax purposes.',
    },
    {
        heading: 'Your choices',
        body: 'You can ask for a copy of what we hold, ask us to correct it, or ask us to delete it. Write to us and we will act within thirty days, except where we are legally required to keep a record.',
    },
    {
        heading: 'Cookies',
        body: 'We set a session cookie so that signing in works, and we remember your light or dark theme choice in your browser. Neither is used for advertising or shared with anyone.',
    },
    {
        heading: 'Security',
        body: 'Passwords are hashed and never stored in a readable form. One time codes are hashed, expire, and can be used once. Two factor secrets are encrypted at rest. Access to production data is limited to the people who need it.',
    },
];

const terms = [
    {
        heading: 'Who we are',
        body: 'Unboundbyte Solutions Private Limited, a company registered in India, providing software development, maintenance and technical training.',
    },
    {
        heading: 'Quotations and indicative pricing',
        body: 'Budget bands and timelines shown on this site are indicative and exist to help you judge whether we are in the right range. They are not quotations. A quotation is a written document stating scope, price, assumptions and validity, and only that document binds either of us.',
    },
    {
        heading: 'Ownership of work',
        body: 'On a paid engagement, the software we write for you is yours, in your repository, from the first commit. We retain rights only to general purpose components and know-how that are not specific to your business.',
    },
    {
        heading: 'Payment',
        body: 'Payment terms are set out in each proposal. Invoices are due on the stated date. We may pause work on an overdue account after giving you notice.',
    },
    {
        heading: 'Training',
        body: 'Programmes are taught live at the scheduled times. Certificates are issued on completion, which means attendance, assessment and the project, not merely enrolment. Fees are refundable as stated in the programme terms at the time you enrol.',
    },
    {
        heading: 'Accounts',
        body: 'You are responsible for what happens under your account. Keep your password and two factor codes to yourself, and tell us promptly if you think someone else has them. We may suspend an account that is being used to harm the service or another user.',
    },
    {
        heading: 'Liability',
        body: 'We provide our services with reasonable skill and care. Except where law does not allow it to be limited, our liability for any claim is capped at the fees you paid us for the work the claim relates to.',
    },
    {
        heading: 'Governing law',
        body: 'These terms are governed by the laws of India, and the courts of India have jurisdiction over any dispute.',
    },
];

const sections = computed(() => (props.document === 'privacy' ? privacy : terms));

const intro = computed(() =>
    props.document === 'privacy'
        ? 'This explains what we collect, why, and what you can ask us to do about it. It describes how the platform actually behaves rather than what a template says.'
        : 'The terms under which we provide our services. Anything agreed in a signed proposal takes precedence over what is written here.',
);
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="border-b" style="border-color: var(--border-subtle)">
            <div class="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>{{ title }}</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight">{{ title }}</h1>
                <p class="mt-4 text-base leading-relaxed" style="color: var(--text-muted)">{{ intro }}</p>
                <p class="mt-3 text-xs" style="color: var(--text-muted)">
                    Last updated {{ new Date().toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' }) }}
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="space-y-8">
                <section v-for="section in sections" :key="section.heading">
                    <h2 class="text-lg font-semibold">{{ section.heading }}</h2>
                    <p class="mt-2 text-base leading-relaxed" style="color: var(--text-muted)">{{ section.body }}</p>
                </section>
            </div>

            <div
                class="mt-10 rounded-xl border p-5"
                style="border-color: var(--border-subtle); background: var(--surface-sunken)"
            >
                <p class="text-sm" style="color: var(--text-base)">
                    Questions about this page? Write to us through the
                    <Link href="/contact" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">
                        contact form
                    </Link>
                    and we will answer.
                </p>
            </div>
        </section>
    </PublicLayout>
</template>
