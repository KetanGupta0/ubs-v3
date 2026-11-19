<script setup>
/**
 * The cart (§4).
 *
 * Every figure on this page came from the server's pricing, which is also what
 * the order is made from; the page only says what was chosen. A guest proves
 * their email with a code to check out (CL6), and only a client account buys.
 */
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, Lock, ShoppingCart, Tag, Trash2, X } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    quote: { type: Object, required: true },
    state: { type: String, default: null },
    states: { type: Array, required: true },
    billing: { type: Object, required: true },
    buyer: { type: Object, default: null },
    checkoutBuyer: { type: Object, default: null },
    seo: { type: Object, required: true },
});

const page = usePage();
const empty = computed(() => props.quote.lines.length === 0);

/* -------------------------------------------------- the state, for GST */

const gstState = ref(props.state ?? '');

watch(gstState, (value) => {
    router.get('/cart', value ? { state: value } : {}, { preserveState: true, preserveScroll: true, replace: true, only: ['quote', 'state'] });
});

/* -------------------------------------------------------------- lines */

function remove(line) {
    router.delete(`/cart/lines/${line.solutionId}`, { preserveScroll: true });
}

/* ------------------------------------------------------------ coupons */

const couponForm = useForm({ code: '' });

function applyCoupon() {
    couponForm.post('/cart/coupons', { preserveScroll: true, onSuccess: () => couponForm.reset() });
}

function removeCoupon(code) {
    router.delete(`/cart/coupons/${encodeURIComponent(code)}`, { preserveScroll: true });
}

/* --------------------------------------------- a guest proves an email */

const codeSentTo = computed(() => page.props.flash?.checkoutCodeSentTo ?? (props.checkoutBuyer ? props.checkoutBuyer.email : null));
const emailForm = useForm({ name: '', email: '', mobile: '', company: '', website: '' });
const codeForm = useForm({ code: '' });

function sendCode() {
    emailForm.post('/checkout/code', { preserveScroll: true });
}

function verifyCode() {
    codeForm.post('/checkout/code/verify', { preserveScroll: true });
}

function restart() {
    router.post('/checkout/restart', {}, { preserveScroll: true });
}

/* ---------------------------------------------------------- the order */

const billingValues = (billing) => ({
    name: billing.name ?? '',
    gstin: billing.gstin ?? '',
    address: billing.address ?? '',
    city: billing.city ?? '',
    postal_code: billing.postal_code ?? '',
    accept_terms: false,
});

const orderForm = useForm(billingValues(props.billing));

/*
 * Signing in at checkout comes back to this same page, and Inertia keeps the
 * component, so the form would keep the empty details it started with. Take
 * the account's details when they arrive, unless the buyer has typed already.
 */
watch(() => props.billing, (billing) => {
    if (!orderForm.isDirty) blankForm(orderForm, billingValues(billing));
});

watch(() => props.state, (state) => {
    if (state && !gstState.value) gstState.value = state;
});

const legal = computed(() => page.props.legal ?? []);
const termsLink = computed(() => legal.value.find((doc) => /terms/i.test(doc.href)));
const refundLink = computed(() => legal.value.find((doc) => /refund/i.test(doc.href)));

function placeOrder() {
    orderForm
        .transform((data) => ({ ...data, state: gstState.value, expected_total: props.quote.totalPaise }))
        .post('/cart/order', { preserveScroll: true });
}
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
            <h1 class="flex items-center gap-2 text-2xl font-semibold sm:text-3xl">
                <ShoppingCart class="h-6 w-6" aria-hidden="true" />
                Your cart
            </h1>

            <p
                v-for="notice in quote.notices"
                :key="notice"
                class="mt-4 flex items-start gap-2 rounded-[var(--radius-control)] p-3 text-sm"
                style="background: var(--surface-sunken)"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--color-warn-500)" />
                <span>{{ notice }}</span>
            </p>

            <div v-if="empty" class="mt-8 rounded-[var(--radius-card)] border" style="border-color: var(--border-subtle)">
                <UiEmptyState :icon="ShoppingCart" title="Nothing in your cart" description="Products sold with their source code, or hosted as a plan, can be bought straight from their page.">
                    <template #action>
                        <UiButton href="/solutions">
                            Browse solutions
                            <template #trailing><ArrowRight class="h-4 w-4" /></template>
                        </UiButton>
                    </template>
                </UiEmptyState>
            </div>

            <div v-else class="mt-8 grid items-start gap-8 lg:grid-cols-12">
                <!-- ------------------------------------------------ what is in it -->
                <div class="space-y-4 lg:col-span-7">
                    <article
                        v-for="line in quote.lines"
                        :key="line.solutionId"
                        class="rounded-[var(--radius-card)] border bg-[var(--surface)] p-5"
                        style="border-color: var(--border-subtle)"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <Link :href="`/solutions/${line.slug}`" class="text-base font-semibold hover:underline" style="color: var(--text-strong)">{{ line.product }}</Link>
                                <p class="text-sm" style="color: var(--text-base)">{{ line.description.replace(`${line.product}, `, '') }}</p>
                                <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ line.terms.join(' · ') }}</p>
                            </div>
                            <div class="text-right">
                                <p class="tnum text-sm font-semibold" style="color: var(--text-strong)">{{ line.amount }}</p>
                                <p v-if="line.discount" class="tnum text-xs" style="color: var(--text-muted)">− {{ line.discount }}</p>
                            </div>
                        </div>

                        <ul v-if="line.addons.length" class="mt-3 space-y-1.5 border-t pt-3" style="border-color: var(--border-subtle)">
                            <li v-for="addon in line.addons" :key="addon.description" class="flex items-start justify-between gap-4 text-sm">
                                <span style="color: var(--text-base)">{{ addon.description }}</span>
                                <span class="text-right">
                                    <span class="tnum">{{ addon.amount }}</span>
                                    <span v-if="addon.discount" class="block tnum text-xs" style="color: var(--text-muted)">− {{ addon.discount }}</span>
                                </span>
                            </li>
                        </ul>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <UiButton size="xs" variant="ghost" :href="`/solutions/${line.slug}#buy`">Change</UiButton>
                            <UiButton size="xs" variant="ghost" @click="remove(line)">
                                <template #leading><Trash2 class="h-3 w-3" /></template>
                                Remove
                            </UiButton>
                        </div>
                    </article>

                    <form class="rounded-[var(--radius-card)] border bg-[var(--surface)] p-5" style="border-color: var(--border-subtle)" @submit.prevent="applyCoupon">
                        <UiFormField label="Discount code" :error="couponForm.errors.code">
                            <div class="flex gap-2">
                                <UiInput v-model="couponForm.code" maxlength="40" autocomplete="off" class="uppercase" />
                                <UiButton type="submit" variant="secondary" :loading="couponForm.processing">Apply</UiButton>
                            </div>
                        </UiFormField>
                        <ul v-if="quote.coupons.length" class="mt-3 space-y-1.5">
                            <li v-for="coupon in quote.coupons" :key="coupon.code" class="flex items-center justify-between gap-3 text-sm">
                                <span class="flex min-w-0 items-center gap-2">
                                    <Tag class="h-3.5 w-3.5 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                                    <span class="font-mono font-semibold">{{ coupon.code }}</span>
                                    <span v-if="coupon.refusal" class="truncate text-xs" style="color: var(--text-danger)">{{ coupon.refusal }}</span>
                                    <span v-else-if="coupon.label" class="truncate text-xs" style="color: var(--text-muted)">{{ coupon.label }}</span>
                                </span>
                                <span class="flex items-center gap-1">
                                    <span v-if="coupon.discount" class="tnum">− {{ coupon.discount }}</span>
                                    <button type="button" class="rounded p-1 hover:bg-[var(--surface-sunken)]" :aria-label="`Remove ${coupon.code}`" @click="removeCoupon(coupon.code)">
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </span>
                            </li>
                        </ul>
                    </form>
                </div>

                <!-- ---------------------------------------------- totals and checkout -->
                <aside class="space-y-4 lg:sticky lg:top-24 lg:col-span-5">
                    <div class="rounded-[var(--radius-card)] border bg-[var(--surface)] p-5" style="border-color: var(--border-subtle)">
                        <UiFormField label="Your state, for GST" hint="Inside our state it is CGST and SGST; outside it, IGST. The total is the same either way.">
                            <UiSelect v-model="gstState" :options="states" placeholder="Choose your state" />
                        </UiFormField>

                        <dl class="mt-4 space-y-2 text-sm">
                            <div class="flex justify-between"><dt style="color: var(--text-muted)">Subtotal</dt><dd class="tnum">{{ quote.subtotal }}</dd></div>
                            <div v-if="quote.discount" class="flex justify-between"><dt style="color: var(--text-muted)">Discount</dt><dd class="tnum">− {{ quote.discount }}</dd></div>
                            <div class="flex justify-between"><dt style="color: var(--text-muted)">Taxable value</dt><dd class="tnum">{{ quote.taxable }}</dd></div>
                            <div v-for="part in quote.breakup" :key="part.label" class="flex justify-between"><dt style="color: var(--text-muted)">{{ part.label }}</dt><dd class="tnum">{{ part.amount }}</dd></div>
                            <div class="flex justify-between border-t pt-2 text-base font-semibold" style="border-color: var(--border-subtle)"><dt>Total</dt><dd class="tnum">{{ quote.total }}</dd></div>
                        </dl>
                    </div>

                    <!-- A guest proves the address first. -->
                    <div v-if="!buyer" class="rounded-[var(--radius-card)] border bg-[var(--surface)] p-5" style="border-color: var(--border-subtle)">
                        <template v-if="!codeSentTo">
                            <h2 class="text-base font-semibold">Check out</h2>
                            <p class="mt-1 text-xs" style="color: var(--text-muted)">We send a code to your email. It signs you in, or opens an account for your orders, licences and invoices.</p>
                            <form class="mt-4 space-y-3" @submit.prevent="sendCode">
                                <UiFormField label="Your name" required :error="emailForm.errors.name">
                                    <UiInput v-model="emailForm.name" autocomplete="name" maxlength="120" />
                                </UiFormField>
                                <UiFormField label="Work email" required :error="emailForm.errors.email">
                                    <UiInput v-model="emailForm.email" type="email" autocomplete="email" maxlength="255" />
                                </UiFormField>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <UiFormField label="Mobile" :error="emailForm.errors.mobile">
                                        <UiInput v-model="emailForm.mobile" type="tel" autocomplete="tel" placeholder="+91" />
                                    </UiFormField>
                                    <UiFormField label="Company" :error="emailForm.errors.company">
                                        <UiInput v-model="emailForm.company" autocomplete="organization" maxlength="160" />
                                    </UiFormField>
                                </div>
                                <input v-model="emailForm.website" type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true" />
                                <UiButton type="submit" block :loading="emailForm.processing">Send me a code</UiButton>
                            </form>
                            <p class="mt-3 text-center text-xs" style="color: var(--text-muted)">
                                Have a password? <Link href="/checkout/sign-in" class="font-medium underline">Sign in</Link> and come back here.
                            </p>
                        </template>

                        <template v-else>
                            <h2 class="text-base font-semibold">Enter your code</h2>
                            <p class="mt-1 text-xs" style="color: var(--text-muted)">We sent a six digit code to {{ codeSentTo }}. It works for ten minutes.</p>
                            <form class="mt-4 space-y-3" @submit.prevent="verifyCode">
                                <UiFormField label="Code" required :error="codeForm.errors.code">
                                    <UiInput v-model="codeForm.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="tnum tracking-widest" />
                                </UiFormField>
                                <UiButton type="submit" block :loading="codeForm.processing">Continue</UiButton>
                            </form>
                            <button type="button" class="mt-3 w-full text-center text-xs underline" style="color: var(--text-muted)" @click="restart">Use a different address</button>
                        </template>
                    </div>

                    <!-- Staff and students look, but do not buy. -->
                    <div v-else-if="!buyer.canBuy" class="rounded-[var(--radius-card)] border p-5 text-sm" style="border-color: var(--border-subtle); background: var(--surface-sunken)">
                        Products are bought on a client account, and you are signed in as {{ buyer.email }}. Sign out and buy with the address you use for work.
                    </div>

                    <!-- A client places the order. -->
                    <form v-else class="rounded-[var(--radius-card)] border bg-[var(--surface)] p-5" style="border-color: var(--border-subtle)" @submit.prevent="placeOrder">
                        <h2 class="text-base font-semibold">Billing</h2>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">As it should read on the GST invoice<template v-if="buyer.organisation"> for {{ buyer.organisation }}</template>.</p>
                        <div class="mt-4 space-y-3">
                            <UiFormField label="Name on the invoice" required hint="Your organisation's legal name, or yours." :error="orderForm.errors.name">
                                <UiInput v-model="orderForm.name" maxlength="160" autocomplete="organization" />
                            </UiFormField>
                            <UiFormField label="GSTIN" hint="If you are registered, to claim the input tax." :error="orderForm.errors.gstin">
                                <UiInput v-model="orderForm.gstin" maxlength="15" class="uppercase" placeholder="27ABCDE1234F1Z5" />
                            </UiFormField>
                            <p v-if="orderForm.errors.state" class="text-xs" style="color: var(--text-danger)">{{ orderForm.errors.state }}</p>
                            <UiFormField label="Address" :error="orderForm.errors.address">
                                <UiInput v-model="orderForm.address" maxlength="255" autocomplete="street-address" />
                            </UiFormField>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <UiFormField label="City" :error="orderForm.errors.city">
                                    <UiInput v-model="orderForm.city" maxlength="120" autocomplete="address-level2" />
                                </UiFormField>
                                <UiFormField label="PIN code" :error="orderForm.errors.postal_code">
                                    <UiInput v-model="orderForm.postal_code" inputmode="numeric" maxlength="6" autocomplete="postal-code" />
                                </UiFormField>
                            </div>
                            <UiFormField :error="orderForm.errors.accept_terms">
                                <UiCheckbox v-model="orderForm.accept_terms">
                                    <template #label>
                                        I agree to the
                                        <a v-if="termsLink" :href="termsLink.href" target="_blank" class="underline">terms</a><span v-else>terms</span>
                                        and the
                                        <a v-if="refundLink" :href="refundLink.href" target="_blank" class="underline">refund policy</a><span v-else>refund policy</span>.
                                    </template>
                                </UiCheckbox>
                            </UiFormField>
                        </div>
                        <UiButton type="submit" block size="lg" class="mt-4" :loading="orderForm.processing" :disabled="!gstState">
                            <template #leading><Lock class="h-4 w-4" /></template>
                            Place order, {{ quote.total }}
                        </UiButton>
                        <p v-if="!gstState" class="mt-2 text-center text-xs" style="color: var(--text-muted)">Choose your state above first.</p>
                        <p class="mt-2 text-center text-xs" style="color: var(--text-muted)">Next you pay on the provider's secure window. The invoice is ready as soon as the order is placed.</p>
                    </form>
                </aside>
            </div>
        </section>
    </PublicLayout>
</template>
