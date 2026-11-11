<?php

/*
 * The first version of each legal document, written into the database when
 * its table is created.
 *
 * Only the first version. From then on these are edited in the admin panel
 * (/admin/legal), where every change is a new version with its own date, and
 * the versions people agreed to stay readable. Editing this file changes
 * nothing on a site that already has these documents.
 *
 * Each section becomes a "## heading" and its paragraph in Markdown.
 *
 * This is not legal advice. Have a lawyer review before the site goes live.
 */

return [
    'privacy' => [
        'intro' => 'This explains what we collect, why, and what you can ask us to do about it. It describes how the platform actually behaves rather than what a template says.',
        'sections' => [
            [
                'heading' => 'What we collect',
                'body' => 'When you send an enquiry we store your name, email address, and optionally your mobile number and company, along with the message and which page you sent it from. If you hold an account we store what you do in it, such as your projects, invoices, course progress and attendance. We record authentication events, with the identifier partially masked.',
            ],
            [
                'heading' => 'Why we collect it',
                'body' => 'To reply to you, to provide the service you have asked for, and to keep the account secure. We do not sell your information, we do not share it with advertisers, and we do not add you to a mailing list because you contacted us.',
            ],
            [
                'heading' => 'Who else sees it',
                'body' => 'Only the providers we need to operate: our hosting provider, our email and SMS providers for messages we send you, and our payment gateway for payments you make. Each receives only what that specific task requires.',
            ],
            [
                'heading' => 'How long we keep it',
                'body' => 'Enquiries are kept for three years. Account data is kept for as long as the account is open, and afterwards only where law requires it, such as invoices retained for tax purposes.',
            ],
            [
                'heading' => 'Your choices',
                'body' => 'You can ask for a copy of what we hold, ask us to correct it, or ask us to delete it. Write to us and we will act within thirty days, except where we are legally required to keep a record.',
            ],
            [
                'heading' => 'Cookies',
                'body' => 'We set a session cookie so that signing in works, and we remember your light or dark theme choice in your browser. Neither is used for advertising or shared with anyone.',
            ],
            [
                'heading' => 'Security',
                'body' => 'Passwords are hashed and never stored in a readable form. One time codes are hashed, expire, and can be used once. Two factor secrets are encrypted at rest. Access to production data is limited to the people who need it.',
            ],
        ],
    ],

    'terms' => [
        'intro' => 'The terms under which we provide our services. Anything agreed in a signed proposal takes precedence over what is written here.',
        'sections' => [
            [
                'heading' => 'Who we are',
                'body' => 'Unboundbyte Solutions Private Limited, a company registered in India, providing software development, maintenance and technical training.',
            ],
            [
                'heading' => 'Quotations and indicative pricing',
                'body' => 'Budget bands and timelines shown on this site are indicative and exist to help you judge whether we are in the right range. They are not quotations. A quotation is a written document stating scope, price, assumptions and validity, and only that document binds either of us.',
            ],
            [
                'heading' => 'Ownership of work',
                'body' => 'On a paid engagement, the software we write for you is yours, in your repository, from the first commit. We retain rights only to general purpose components and know-how that are not specific to your business.',
            ],
            [
                'heading' => 'Payment',
                'body' => 'Payment terms are set out in each proposal. Invoices are due on the stated date. We may pause work on an overdue account after giving you notice.',
            ],
            [
                'heading' => 'Training',
                'body' => 'Programmes are taught live at the scheduled times. Certificates are issued on completion, which means attendance, assessment and the project, not merely enrolment. Fees are refundable as stated in the programme terms at the time you enrol.',
            ],
            [
                'heading' => 'Accounts',
                'body' => 'You are responsible for what happens under your account. Keep your password and two factor codes to yourself, and tell us promptly if you think someone else has them. We may suspend an account that is being used to harm the service or another user.',
            ],
            [
                'heading' => 'Liability',
                'body' => 'We provide our services with reasonable skill and care. Except where law does not allow it to be limited, our liability for any claim is capped at the fees you paid us for the work the claim relates to.',
            ],
            [
                'heading' => 'Governing law',
                'body' => 'These terms are governed by the laws of India, and the courts of India have jurisdiction over any dispute.',
            ],
        ],
    ],

    // The refund and cancellation policy has no first version on purpose. Its
    // terms are a business decision, not something to invent: it is written in
    // the panel (or brought across from the old site) and stays off the public
    // site until it is published.
];
