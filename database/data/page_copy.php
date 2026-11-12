<?php

/*
 * The editable wording of the site's fixed pages, and where each piece sits.
 *
 * What is written here is each field's starting wording: what the page says
 * until somebody changes it at /admin/copy, and what "Starting wording" puts
 * back. Changing a default here changes the site wherever nobody has edited
 * that field. Edits live in the page_copy table and never touch this file.
 *
 * Only words are editable. Layout, icons, links and how many items a list has
 * stay in the page's code, so an edit cannot break the design.
 *
 * Field types:
 *  - line:  one line
 *  - title: one line, where *words in asterisks* are highlighted
 *  - text:  a paragraph
 *  - list:  one item per line
 *
 * Some fields take {tokens}, filled in from live figures, so a count written
 * into a sentence never goes stale. Each field says which.
 */

return [
    'home' => [
        'label' => 'Home',
        'path' => '/',
        'sections' => [
            'hero' => [
                'label' => 'Top of the page',
                'fields' => [
                    'hero.badge' => ['label' => 'Small label above the headline', 'type' => 'line', 'max' => 60,
                        'default' => 'Software development · Live training'],
                    'hero.title' => ['label' => 'Headline', 'type' => 'title', 'max' => 120,
                        'default' => 'We build the software, and we train the people who *run it*.'],
                    'hero.body' => ['label' => 'Under the headline', 'type' => 'text', 'max' => 400,
                        'default' => 'Unboundbyte Solutions builds custom software, modernises systems you already run, and maintains them afterwards. We also run internships and live courses for college students. Two halves of the same problem, handled by one team.'],
                    'hero.primary' => ['label' => 'First button', 'type' => 'line', 'max' => 40,
                        'default' => 'Browse what we build'],
                    'hero.secondary' => ['label' => 'Second button', 'type' => 'line', 'max' => 40,
                        'default' => 'See training programmes'],
                ],
            ],
            'paths' => [
                'label' => 'Two ways we work',
                'fields' => [
                    'paths.eyebrow' => ['label' => 'Small label', 'type' => 'line', 'max' => 40,
                        'default' => 'Two ways we work'],
                    'paths.title' => ['label' => 'Heading', 'type' => 'line', 'max' => 100,
                        'default' => 'Which one brought you here?'],
                    'paths.body' => ['label' => 'Under the heading', 'type' => 'text', 'max' => 300,
                        'default' => 'They are genuinely different jobs, so we have kept them apart rather than blurring them into one pitch.'],
                    'paths.software_title' => ['label' => 'Software card: heading', 'type' => 'line', 'max' => 80,
                        'default' => 'I need software built or fixed'],
                    'paths.software_body' => ['label' => 'Software card: text', 'type' => 'text', 'max' => 300,
                        'default' => 'A new system, an upgrade to one you already run, or a maintenance contract with someone accountable when it breaks at nine on a Friday night.'],
                    'paths.software_button' => ['label' => 'Software card: button', 'type' => 'line', 'max' => 40,
                        'default' => 'Browse the catalogue'],
                    'paths.student_title' => ['label' => 'Student card: heading', 'type' => 'line', 'max' => 80,
                        'default' => 'I am a student, and I want to build'],
                    'paths.student_body' => ['label' => 'Student card: text', 'type' => 'text', 'max' => 300,
                        'default' => 'Internships that meet your college requirement and leave you with a real project, plus longer live courses if you want a subject properly.'],
                    'paths.student_points' => ['label' => 'Student card: points', 'type' => 'list', 'max' => 120, 'items' => 6,
                        'default' => "Internships with the four documents your college asks for\nLive on Google Meet, at a fixed time each week\nA mentor who reviews your code, not a recorded video\nA project you can defend at a viva or an interview"],
                    'paths.student_button' => ['label' => 'Student card: first button', 'type' => 'line', 'max' => 40,
                        'default' => 'See internships'],
                    'paths.student_secondary' => ['label' => 'Student card: second button', 'type' => 'line', 'max' => 40,
                        'default' => 'Courses'],
                ],
            ],
            'catalogue' => [
                'label' => 'Solutions catalogue',
                'fields' => [
                    'catalogue.eyebrow' => ['label' => 'Small label', 'type' => 'line', 'max' => 40,
                        'default' => 'Solutions catalogue'],
                    'catalogue.title' => ['label' => 'Heading', 'type' => 'line', 'max' => 100,
                        'default' => 'Software we build, by category'],
                    'catalogue.body' => ['label' => 'Under the heading', 'type' => 'text', 'max' => 300, 'tokens' => ['solutions', 'categories'],
                        'default' => '{solutions} products across {categories} industries, each with what is in it, what it runs on, an indicative budget and an indicative timeline.'],
                ],
            ],
            'pillars' => [
                'label' => 'How we work',
                'fields' => [
                    'pillars.eyebrow' => ['label' => 'Small label', 'type' => 'line', 'max' => 40,
                        'default' => 'How we work'],
                    'pillars.title' => ['label' => 'Heading', 'type' => 'line', 'max' => 100,
                        'default' => 'The parts that decide whether a project goes well'],
                    'pillars.body' => ['label' => 'Under the heading', 'type' => 'text', 'max' => 300,
                        'default' => 'None of this is exciting. All of it is what separates software you keep from software you replace in eighteen months.'],
                    'pillars.1.title' => ['label' => 'First point: heading', 'type' => 'line', 'max' => 60,
                        'default' => 'Your code, your repository'],
                    'pillars.1.body' => ['label' => 'First point: text', 'type' => 'text', 'max' => 200,
                        'default' => 'Yours from the first commit. We do not hold source as leverage, and you can replace us without drama.'],
                    'pillars.2.title' => ['label' => 'Second point: heading', 'type' => 'line', 'max' => 60,
                        'default' => 'Working software every two weeks'],
                    'pillars.2.body' => ['label' => 'Second point: text', 'type' => 'text', 'max' => 200,
                        'default' => 'Deployed somewhere you can open it. No six month silence ending in a surprise.'],
                    'pillars.3.title' => ['label' => 'Third point: heading', 'type' => 'line', 'max' => 60,
                        'default' => 'Scope written down'],
                    'pillars.3.body' => ['label' => 'Third point: text', 'type' => 'text', 'max' => 200,
                        'default' => 'Including what is deliberately out. Most disputes are about the second list, not the first.'],
                    'pillars.4.title' => ['label' => 'Fourth point: heading', 'type' => 'line', 'max' => 60,
                        'default' => 'Built for the phone first'],
                    'pillars.4.body' => ['label' => 'Fourth point: text', 'type' => 'text', 'max' => 200,
                        'default' => 'Most of your users are on a mid range Android on patchy data. We build for that, not for an office desktop.'],
                    'pillars.link' => ['label' => 'Link under the points', 'type' => 'line', 'max' => 60,
                        'default' => 'Read how a project actually runs'],
                ],
            ],
            'technology' => [
                'label' => 'Technology strip',
                'fields' => [
                    'technology.caption' => ['label' => 'Line above the strip', 'type' => 'line', 'max' => 100,
                        'default' => 'Built on tools chosen to be maintainable by someone other than us'],
                    'technology.list' => ['label' => 'Tools in the strip', 'type' => 'list', 'max' => 30, 'items' => 24,
                        'default' => "Laravel\nVue.js\nMySQL\nPostgreSQL\nRedis\nDocker\nTailwind CSS\nPython\nNode.js\nNginx\nAWS\nInertia.js\nPest\nGit"],
                ],
            ],
            'internships' => [
                'label' => 'Internships',
                'fields' => [
                    'internships.eyebrow' => ['label' => 'Small label', 'type' => 'line', 'max' => 40,
                        'default' => 'For college students'],
                    'internships.title' => ['label' => 'Heading', 'type' => 'line', 'max' => 100,
                        'default' => 'Internships that produce something you can show'],
                    'internships.body' => ['label' => 'Under the heading', 'type' => 'text', 'max' => 300,
                        'default' => 'Remote, with a brief instead of a tutorial and a mentor who reviews your code every week. Every one ends with the four documents your college asks for.'],
                    'internships.colleges' => ['label' => 'Line for colleges', 'type' => 'line', 'max' => 80,
                        'default' => 'Sending a whole batch from your college?'],
                    'internships.colleges_link' => ['label' => 'Link for colleges', 'type' => 'line', 'max' => 40,
                        'default' => 'See how a tie-up works'],
                ],
            ],
            'courses' => [
                'label' => 'Live courses',
                'fields' => [
                    'courses.eyebrow' => ['label' => 'Small label', 'type' => 'line', 'max' => 40,
                        'default' => 'Live courses'],
                    'courses.title' => ['label' => 'Heading', 'type' => 'line', 'max' => 100,
                        'default' => 'Go deeper on one subject'],
                    'courses.body' => ['label' => 'Under the heading', 'type' => 'text', 'max' => 300,
                        'default' => 'Longer programmes for students and working developers who want a technology properly rather than a taste of it. Live on Google Meet, with assessment and a reviewed project.'],
                ],
            ],
            'testimonials' => [
                'label' => 'Testimonials (shown only once there is a real one)',
                'fields' => [
                    'testimonials.eyebrow' => ['label' => 'Small label', 'type' => 'line', 'max' => 40,
                        'default' => 'In their words'],
                    'testimonials.title' => ['label' => 'Heading', 'type' => 'line', 'max' => 100,
                        'default' => 'What clients and students say'],
                ],
            ],
            'cta' => [
                'label' => 'Closing invitation',
                'fields' => [
                    'cta.title' => ['label' => 'Heading', 'type' => 'line', 'max' => 100,
                        'default' => 'Tell us what you are trying to build'],
                    'cta.body' => ['label' => 'Text', 'type' => 'text', 'max' => 300,
                        'default' => 'A short conversation is usually enough to tell whether we are the right people for it. If we are not, we will say so.'],
                    'cta.primary' => ['label' => 'Button', 'type' => 'line', 'max' => 40,
                        'default' => 'Start a conversation'],
                    'cta.secondary' => ['label' => 'Second button', 'type' => 'line', 'max' => 40,
                        'default' => 'Browse the catalogue'],
                ],
            ],
        ],
    ],
];
