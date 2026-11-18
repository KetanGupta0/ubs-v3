<script setup>
/**
 * Buying a product as it is (§4): a licence tier or a hosted plan, with
 * add-ons, into the cart.
 *
 * Prices are before tax (X17) and say so. The running sum here is a courtesy
 * for the page; the cart prices everything again on the server, with GST for
 * the buyer's state, and that is the figure anybody pays.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Check, ShoppingCart } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import { accentFor } from '@/support/accents';

const props = defineProps({
    solution: { type: Object, required: true },
    offer: { type: Object, required: true },
});

const tone = computed(() => accentFor(props.solution.accent));
const tiers = computed(() => [...props.offer.licences, ...props.offer.plans]);
const chosen = ref(tiers.value[0]?.id ?? null);
const tier = computed(() => tiers.value.find((item) => item.id === chosen.value) ?? null);

/** Add-ons that go with the chosen tier: months on a licence mean nothing on a plan. */
const addons = computed(() => props.offer.addons.filter((addon) => tier.value?.kind === 'licence' || !addon.needsLicence));
const picked = ref([]);
const quantities = ref(Object.fromEntries(props.offer.addons.map((addon) => [addon.id, 1])));

watch(addons, (list) => {
    picked.value = picked.value.filter((id) => list.some((addon) => addon.id === id));
});

const rupees = (paise) => '₹' + Math.round(paise / 100).toLocaleString('en-IN');
const quantityOf = (addon) => Math.min(Math.max(1, Number(quantities.value[addon.id]) || 1), addon.maxQuantity);

const beforeTax = computed(() => {
    if (!tier.value) return 0;
    return addons.value
        .filter((addon) => picked.value.includes(addon.id))
        .reduce((sum, addon) => sum + addon.pricePaise * quantityOf(addon), tier.value.pricePaise);
});

const adding = ref(false);

function addToCart() {
    adding.value = true;
    router.post('/cart/lines', {
        solution: props.solution.id,
        tier: chosen.value,
        addons: addons.value.filter((addon) => picked.value.includes(addon.id)).map((addon) => ({ id: addon.id, quantity: quantityOf(addon) })),
    }, { onFinish: () => (adding.value = false) });
}

const unitLabel = (addon) => (addon.unit ? ` per ${addon.unit}` : '');
</script>

<template>
    <div class="grid gap-8 lg:grid-cols-12">
        <div class="space-y-6 lg:col-span-8">
            <fieldset v-if="offer.licences.length">
                <legend class="text-sm font-semibold">With the source code, for your own server</legend>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="item in offer.licences"
                        :key="item.id"
                        class="relative flex cursor-pointer flex-col rounded-xl border p-4 transition"
                        :style="chosen === item.id
                            ? { borderColor: tone.solid, background: tone.softer, boxShadow: `0 0 0 1px ${tone.solid}` }
                            : { borderColor: 'var(--border-subtle)' }"
                    >
                        <input v-model="chosen" type="radio" name="tier" :value="item.id" class="sr-only" />
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-semibold" style="color: var(--text-strong)">{{ item.name }}</span>
                            <span class="tnum text-base font-semibold" style="color: var(--text-strong)">{{ item.price }}</span>
                        </span>
                        <span class="text-right text-[11px]" style="color: var(--text-muted)">+ GST</span>
                        <span v-if="item.description" class="mt-1 text-xs" style="color: var(--text-muted)">{{ item.description }}</span>
                        <ul class="mt-3 space-y-1">
                            <li v-for="term in item.terms" :key="term" class="flex items-center gap-1.5 text-xs" style="color: var(--text-base)">
                                <Check class="h-3.5 w-3.5 shrink-0" :style="{ color: tone.solid }" aria-hidden="true" />
                                {{ term }}
                            </li>
                        </ul>
                    </label>
                </div>
            </fieldset>

            <fieldset v-if="offer.plans.length">
                <legend class="text-sm font-semibold">{{ offer.licences.length ? 'Or hosted by us, as a plan' : 'Hosted by us, as a plan' }}</legend>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="item in offer.plans"
                        :key="item.id"
                        class="relative flex cursor-pointer flex-col rounded-xl border p-4 transition"
                        :style="chosen === item.id
                            ? { borderColor: tone.solid, background: tone.softer, boxShadow: `0 0 0 1px ${tone.solid}` }
                            : { borderColor: 'var(--border-subtle)' }"
                    >
                        <input v-model="chosen" type="radio" name="tier" :value="item.id" class="sr-only" />
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-semibold" style="color: var(--text-strong)">{{ item.name }}</span>
                            <span class="tnum text-base font-semibold" style="color: var(--text-strong)">{{ item.price }}<span class="text-xs font-normal" style="color: var(--text-muted)"> a {{ item.per }}</span></span>
                        </span>
                        <span class="text-right text-[11px]" style="color: var(--text-muted)">+ GST</span>
                        <span v-if="item.description" class="mt-1 text-xs" style="color: var(--text-muted)">{{ item.description }}</span>
                        <ul class="mt-3 space-y-1">
                            <li v-for="term in item.terms" :key="term" class="flex items-center gap-1.5 text-xs" style="color: var(--text-base)">
                                <Check class="h-3.5 w-3.5 shrink-0" :style="{ color: tone.solid }" aria-hidden="true" />
                                {{ term }}
                            </li>
                        </ul>
                    </label>
                </div>
            </fieldset>

            <fieldset v-if="addons.length">
                <legend class="text-sm font-semibold">Add to it</legend>
                <ul class="mt-3 divide-y rounded-xl border" style="border-color: var(--border-subtle)">
                    <li v-for="addon in addons" :key="addon.id" class="flex flex-wrap items-center justify-between gap-3 p-3.5">
                        <div class="min-w-0">
                            <UiCheckbox v-model="picked" :value="addon.id" :label="addon.name" />
                            <p v-if="addon.description" class="ml-6 mt-0.5 text-xs" style="color: var(--text-muted)">{{ addon.description }}</p>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <label v-if="addon.maxQuantity > 1 && picked.includes(addon.id)" class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                                <span class="sr-only">How many {{ addon.unit }}s of {{ addon.name }}</span>
                                <input
                                    v-model.number="quantities[addon.id]"
                                    type="number"
                                    min="1"
                                    :max="addon.maxQuantity"
                                    class="w-16 rounded-[var(--radius-control)] border bg-[var(--surface)] px-2 py-1 text-right text-sm tnum"
                                    style="border-color: var(--border-strong)"
                                />
                                {{ addon.unit }}s
                            </label>
                            <span class="tnum font-medium" style="color: var(--text-strong)">{{ addon.price }}<span class="text-xs font-normal" style="color: var(--text-muted)">{{ unitLabel(addon) }}</span></span>
                        </div>
                    </li>
                </ul>
            </fieldset>
        </div>

        <aside class="lg:col-span-4">
            <div class="sticky top-24 rounded-xl border p-5" :style="{ borderColor: tone.border, background: tone.softer }">
                <p class="text-sm font-semibold">{{ tier ? tier.name : 'Choose an option' }}</p>
                <p class="mt-3 text-2xl font-semibold tnum" style="color: var(--text-strong)">{{ rupees(beforeTax) }}</p>
                <p class="text-xs" style="color: var(--text-muted)">
                    before GST at {{ offer.taxRate }}%{{ tier?.per ? `, every ${tier.per}` : '' }}. The cart adds it for your state: CGST and SGST, or IGST.
                </p>
                <UiButton class="mt-5 w-full" size="lg" :loading="adding" :disabled="!tier" @click="addToCart">
                    <template #leading><ShoppingCart class="h-4 w-4" /></template>
                    Add to cart
                </UiButton>
                <p v-if="offer.latestVersion" class="mt-4 text-xs" style="color: var(--text-muted)">
                    Latest version {{ offer.latestVersion.version }}, released {{ offer.latestVersion.releasedOn }}.
                </p>
                <p class="mt-2 text-xs" style="color: var(--text-muted)">
                    A GST invoice comes with every order. Buying for a company? Add its GSTIN at checkout.
                </p>
            </div>
        </aside>
    </div>
</template>
