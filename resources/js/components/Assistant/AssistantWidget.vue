<script setup>
/**
 * The assistant on the public site (§9.2).
 *
 * Nothing is sent until the visitor has read the notice and pressed Start:
 * it is an AI, what they type goes to Anthropic to be answered, the
 * conversation is kept for a limited time, and their details reach the team
 * only if they press Send on the card. Answers come back whole, a few seconds
 * each, because a stream would hold a process open on shared hosting.
 *
 * The conversation lives in this tab's session storage, so it survives moving
 * between pages; replies arrive as HTML the server has already made safe.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Bot, Send, X } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';

const page = usePage();
const settings = computed(() => page.props.assistant);

const STORE = 'ubs-assistant';
const open = ref(false);
const token = ref(null);
const messages = ref([]);
const offer = ref(null);
const closed = ref(false);
const sending = ref(false);
const draft = ref('');
const problem = ref(null);
const sent = ref(false);
const list = ref(null);
const input = ref(null);

const greeting = 'Hello! What are you looking for: one of our products, something built for you, a course or an internship?';

function save() {
    sessionStorage.setItem(STORE, JSON.stringify({ open: open.value, token: token.value, messages: messages.value, offer: offer.value, closed: closed.value, sent: sent.value }));
}

onMounted(() => {
    try {
        const kept = JSON.parse(sessionStorage.getItem(STORE) ?? 'null');
        if (kept) {
            open.value = kept.open && window.innerWidth >= 640;
            token.value = kept.token ?? null;
            messages.value = kept.messages ?? [];
            offer.value = kept.offer ?? null;
            closed.value = kept.closed ?? false;
            sent.value = kept.sent ?? false;
        }
    } catch {
        sessionStorage.removeItem(STORE);
    }
});

watch([open, messages, offer, closed, sent], save, { deep: true });

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function post(path, body) {
    const response = await fetch(path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (response.status === 404) throw new Error('The assistant is not available just now. You can write to the team on the contact page.');
    if (response.status === 429) throw new Error('That was a lot of messages. Wait a minute, or write to the team on the contact page.');
    if (!response.ok) throw new Error(data.message ?? Object.values(data.errors ?? {})[0]?.[0] ?? 'Something went wrong. Try again.');

    return data;
}

async function scrollDown() {
    await nextTick();
    list.value?.scrollTo({ top: list.value.scrollHeight, behavior: 'smooth' });
}

/* The notice is agreed to: only now does a conversation start. */
async function start() {
    problem.value = null;
    sending.value = true;
    try {
        const data = await post('/assistant/start', { agree: true, page: window.location.pathname });
        if (data.closed) {
            closed.value = true;
            messages.value.push({ role: 'assistant', html: data.html });
        } else {
            token.value = data.token;
        }
        await nextTick();
        input.value?.focus();
    } catch (error) {
        problem.value = error.message;
    } finally {
        sending.value = false;
    }
}

async function send() {
    const text = draft.value.trim();
    if (!text || sending.value || closed.value) return;

    problem.value = null;
    messages.value.push({ role: 'visitor', text });
    draft.value = '';
    offer.value = null;
    sending.value = true;
    scrollDown();

    try {
        const data = await post('/assistant/message', { token: token.value, message: text });
        messages.value.push({ role: 'assistant', html: data.html });
        offer.value = data.offer ? { ...data.offer, mobile: data.offer.mobile ?? '' } : null;
        closed.value = Boolean(data.closed);
    } catch (error) {
        problem.value = error.message;
    } finally {
        sending.value = false;
        scrollDown();
        if (!closed.value) input.value?.focus();
    }
}

/* The card's Send: the visitor's own click, and the only way details reach the team. */
async function sendDetails() {
    problem.value = null;
    sending.value = true;
    try {
        const data = await post('/assistant/lead', { token: token.value, ...offer.value });
        messages.value.push({ role: 'assistant', html: data.html });
        offer.value = null;
        sent.value = true;
    } catch (error) {
        problem.value = error.message;
    } finally {
        sending.value = false;
        scrollDown();
    }
}

function onKey(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send();
    }
}

/* Links in answers are this site's own pages: go there without losing the conversation. */
function follow(event) {
    const link = event.target.closest('a[href^="/"]');
    if (!link) return;
    event.preventDefault();
    if (window.innerWidth < 640) open.value = false;
    router.visit(link.getAttribute('href'));
}

function startOver() {
    token.value = null;
    messages.value = [];
    offer.value = null;
    closed.value = false;
    sent.value = false;
    problem.value = null;
}
</script>

<template>
    <div v-if="settings" class="print:hidden">
        <!-- The way in. -->
        <button
            v-if="!open"
            type="button"
            class="fixed bottom-5 right-5 z-40 flex items-center gap-2 rounded-full px-4 py-3 text-sm font-semibold text-white shadow-lg transition hover:shadow-xl focus-visible:outline-2 focus-visible:outline-offset-2"
            style="background: var(--color-brand-600)"
            aria-haspopup="dialog"
            @click="open = true"
        >
            <Bot class="h-4 w-4" aria-hidden="true" />
            Ask us
        </button>

        <section
            v-else
            class="fixed inset-0 z-50 flex flex-col sm:inset-auto sm:bottom-5 sm:right-5 sm:h-[34rem] sm:w-[23rem] sm:rounded-[var(--radius-card)] sm:border sm:shadow-2xl"
            style="background: var(--surface-raised); border-color: var(--border-subtle)"
            role="dialog"
            aria-label="Our assistant"
        >
            <header class="flex items-center justify-between gap-2 border-b px-4 py-3" style="border-color: var(--border-subtle)">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-sm font-semibold"><Bot class="h-4 w-4" aria-hidden="true" /> Our assistant</p>
                    <p class="text-xs" style="color: var(--text-muted)">An AI, answering from this website</p>
                </div>
                <div class="flex items-center gap-1">
                    <UiButton v-if="token && messages.length" size="xs" variant="ghost" @click="startOver">Start over</UiButton>
                    <UiButton size="xs" variant="ghost" icon aria-label="Close the assistant" @click="open = false"><X class="h-4 w-4" /></UiButton>
                </div>
            </header>

            <!-- Before anything is sent: what it is, and what happens to what they type. -->
            <div v-if="!token && !closed" class="flex flex-1 flex-col justify-center gap-4 overflow-y-auto px-5 py-6 text-sm">
                <p class="font-medium">Before you start</p>
                <p style="color: var(--text-base)">
                    This assistant is an AI. It answers questions about our products, services and courses from what is on this website, and points you to the right page.
                </p>
                <p style="color: var(--text-base)">
                    What you type is sent to Anthropic, the company that makes it, to produce each answer, and we keep the conversation for {{ settings.retentionDays }} {{ settings.retentionDays === 1 ? 'day' : 'days' }}.
                    Your name and contact details reach our team only if you press Send on the card it shows you.
                    Please do not type passwords, card numbers or other sensitive details.
                    <a href="/legal/privacy" class="underline" @click.prevent="router.visit('/legal/privacy')">Privacy policy</a>.
                </p>
                <p v-if="problem" class="text-xs" role="alert" style="color: var(--text-danger)">{{ problem }}</p>
                <div class="flex flex-wrap gap-2">
                    <UiButton :loading="sending" @click="start">Start</UiButton>
                    <UiButton variant="ghost" @click="router.visit('/contact')">Write to us instead</UiButton>
                </div>
            </div>

            <template v-else>
                <div ref="list" class="flex-1 space-y-3 overflow-y-auto px-4 py-4 text-sm" aria-live="polite" @click="follow">
                    <div class="max-w-[85%] rounded-2xl rounded-tl-sm px-3 py-2" style="background: var(--surface-sunken)">{{ greeting }}</div>

                    <template v-for="(message, index) in messages" :key="index">
                        <div v-if="message.role === 'visitor'" class="ml-auto max-w-[85%] whitespace-pre-line rounded-2xl rounded-tr-sm px-3 py-2 text-white" style="background: var(--color-brand-600)">{{ message.text }}</div>
                        <!-- eslint-disable-next-line vue/no-v-html -- made safe on the server: no typed HTML, links to this site only -->
                        <div v-else class="assistant-reply max-w-[85%] rounded-2xl rounded-tl-sm px-3 py-2" style="background: var(--surface-sunken)" v-html="message.html" />
                    </template>

                    <!-- The card: their details, as they choose to send them. -->
                    <form v-if="offer" class="space-y-2 rounded-[var(--radius-field)] border p-3" style="border-color: var(--color-brand-500)" @submit.prevent="sendDetails">
                        <p class="text-xs font-semibold">Send these to our team?</p>
                        <label class="block text-xs">Name<input v-model="offer.name" class="mt-0.5 w-full rounded border px-2 py-1 text-sm" style="border-color: var(--border-strong); background: var(--surface)" maxlength="120" required></label>
                        <label class="block text-xs">Email<input v-model="offer.email" type="email" class="mt-0.5 w-full rounded border px-2 py-1 text-sm" style="border-color: var(--border-strong); background: var(--surface)" required></label>
                        <label class="block text-xs">Phone (optional)<input v-model="offer.mobile" type="tel" class="mt-0.5 w-full rounded border px-2 py-1 text-sm" style="border-color: var(--border-strong); background: var(--surface)" placeholder="+91…"></label>
                        <label class="block text-xs">What you need<textarea v-model="offer.need" rows="2" class="mt-0.5 w-full rounded border px-2 py-1 text-sm" style="border-color: var(--border-strong); background: var(--surface)" maxlength="1000" required /></label>
                        <div class="flex gap-2">
                            <UiButton type="submit" size="sm" :loading="sending">Send</UiButton>
                            <UiButton type="button" size="sm" variant="ghost" @click="offer = null">Not now</UiButton>
                        </div>
                    </form>

                    <div v-if="sending && !offer" class="w-fit rounded-2xl rounded-tl-sm px-3 py-2" style="background: var(--surface-sunken); color: var(--text-muted)" aria-label="The assistant is writing">…</div>
                    <p v-if="problem" class="text-xs" role="alert" style="color: var(--text-danger)">{{ problem }}</p>
                </div>

                <footer class="border-t p-3" style="border-color: var(--border-subtle)">
                    <div v-if="closed" class="flex items-center justify-between gap-2 text-xs" style="color: var(--text-muted)">
                        This conversation has ended.
                        <UiButton size="sm" @click="router.visit('/contact')">Write to us</UiButton>
                    </div>
                    <form v-else class="flex items-end gap-2" @submit.prevent="send">
                        <textarea
                            ref="input"
                            v-model="draft"
                            rows="1"
                            maxlength="1000"
                            class="max-h-32 min-h-10 flex-1 resize-none rounded-[var(--radius-field)] border px-3 py-2 text-sm"
                            style="border-color: var(--border-strong); background: var(--surface)"
                            placeholder="Ask about a product, a course…"
                            aria-label="Your message"
                            @keydown="onKey"
                        />
                        <UiButton type="submit" icon aria-label="Send message" :loading="sending" :disabled="!draft.trim()"><Send class="h-4 w-4" /></UiButton>
                    </form>
                </footer>
            </template>
        </section>
    </div>
</template>

<style scoped>
.assistant-reply :deep(a) {
    color: var(--text-brand);
    text-decoration: underline;
}
.assistant-reply :deep(p + p),
.assistant-reply :deep(ul),
.assistant-reply :deep(ol) {
    margin-top: 0.4rem;
}
.assistant-reply :deep(ul) {
    list-style: disc;
    padding-left: 1.1rem;
}
.assistant-reply :deep(ol) {
    list-style: decimal;
    padding-left: 1.1rem;
}
</style>
