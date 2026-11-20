<script setup>
/**
 * How a solution is sold (§4).
 *
 * What it is offered as, the live demo the owner hosts on its own subdomain
 * (X9), licence tiers and plans at fixed prices before tax (X17), add-ons, and
 * every release of the code, kept privately (X16). Orders copy what they need,
 * so editing here never rewrites a sale; what has been sold is switched off
 * rather than removed.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Download, ExternalLink, Eye, EyeOff, Pencil, Plus, Trash2, Upload } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    solution: { type: Object, required: true },
    offerings: { type: Array, required: true },
    warnings: { type: Array, default: () => [] },
    tiers: { type: Array, default: () => [] },
    addons: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    addonKinds: { type: Array, default: () => [] },
    taxRate: { type: Number, default: 18 },
    maxUploadMb: { type: Number, default: 100 },
});

const base = `/admin/solutions/${props.solution.id}`;

/* ---------------------------------------------------- offerings and demo */

const settings = useForm({
    offerings: [...props.solution.offerings],
    demo_url: props.solution.demo_url ?? '',
    demo_username: props.solution.demo_username ?? '',
    demo_password: props.solution.demo_password ?? '',
    demo_notes: props.solution.demo_notes ?? '',
    sac_code: props.solution.sac_code ?? '',
});
const demoOn = computed(() => settings.offerings.includes('demo'));

function saveSettings() {
    settings.put(`${base}/selling`, { preserveScroll: true });
}

/* ------------------------------------------------------- tiers and plans */

const licences = computed(() => props.tiers.filter((tier) => tier.kind === 'licence'));
const plans = computed(() => props.tiers.filter((tier) => tier.kind === 'plan'));
const intervals = [
    { value: 'monthly', label: 'Every month' },
    { value: 'yearly', label: 'Every year' },
];

const tierBlank = { kind: 'licence', name: '', description: '', price: '', sites: 1, update_months: 12, support_months: 0, interval: 'yearly', is_active: true, sort_order: 0 };
const tierOpen = ref(false);
const tierEditing = ref(null);
const tierForm = useForm(structuredClone(tierBlank));

function startTier(kind, row = null) {
    tierEditing.value = row;
    blankForm(tierForm, row ? {
        kind: row.kind, name: row.name, description: row.description ?? '', price: row.priceRupees, sites: row.sites ?? '',
        update_months: row.update_months, support_months: row.support_months, interval: row.interval ?? 'yearly',
        is_active: row.is_active, sort_order: row.sort_order,
    } : { ...tierBlank, kind });
    tierOpen.value = true;
}

function saveTier() {
    const options = { preserveScroll: true, onSuccess: () => (tierOpen.value = false) };
    const data = (values) => {
        const { kind, ...rest } = values;
        return { ...(tierEditing.value ? {} : { kind }), ...rest, sites: values.sites === '' ? null : values.sites };
    };

    tierEditing.value
        ? tierForm.transform(data).put(`${base}/tiers/${tierEditing.value.id}`, options)
        : tierForm.transform(data).post(`${base}/tiers`, options);
}

function removeTier(row) {
    if (window.confirm(`Remove ${row.name}?`)) router.delete(`${base}/tiers/${row.id}`, { preserveScroll: true });
}

/* ------------------------------------------------------------- add-ons */

const addonBlank = { name: '', description: '', kind: 'service', price: '', unit: '', max_quantity: 1, sac_code: '', is_active: true, sort_order: 0 };
const addonOpen = ref(false);
const addonEditing = ref(null);
const addonForm = useForm(structuredClone(addonBlank));
const units = [
    { value: '', label: 'Once' },
    { value: 'hour', label: 'Per hour' },
    { value: 'month', label: 'Per month' },
];
const addonCounted = computed(() => addonForm.kind !== 'service' || addonForm.unit !== '');

function startAddon(row = null) {
    addonEditing.value = row;
    blankForm(addonForm, row ? {
        name: row.name, description: row.description ?? '', kind: row.kind, price: row.priceRupees, unit: row.unit ?? '',
        max_quantity: row.max_quantity, sac_code: row.sac_code ?? '', is_active: row.is_active, sort_order: row.sort_order,
    } : addonBlank);
    addonOpen.value = true;
}

function saveAddon() {
    const options = { preserveScroll: true, onSuccess: () => (addonOpen.value = false) };
    const data = (values) => ({ ...values, unit: values.kind === 'service' ? values.unit || null : 'month' });

    addonEditing.value
        ? addonForm.transform(data).put(`${base}/addons/${addonEditing.value.id}`, options)
        : addonForm.transform(data).post(`${base}/addons`, options);
}

function removeAddon(row) {
    if (window.confirm(`Remove ${row.name}?`)) router.delete(`${base}/addons/${row.id}`, { preserveScroll: true });
}

const addonPrice = (row) => `${row.price}${row.unit ? ` per ${row.unit}` : ''}`;

/* ------------------------------------------------------------- versions */

const versionOpen = ref(false);
const versionBlank = () => ({ version: '', released_on: new Date().toISOString().slice(0, 10), changelog: '', file: [] });
const versionForm = useForm(versionBlank());

function startVersion() {
    blankForm(versionForm, versionBlank());
    versionOpen.value = true;
}

function uploadVersion() {
    versionForm
        .transform((data) => ({ ...data, file: data.file[0] ?? null }))
        .post(`${base}/versions`, { preserveScroll: true, forceFormData: true, onSuccess: () => (versionOpen.value = false) });
}

function toggleVersion(row) {
    const verb = row.is_available ? 'Withdraw' : 'Make available again';
    if (window.confirm(`${verb}: version ${row.version}?`)) {
        router.put(`${base}/versions/${row.id}`, { changelog: row.changelog, is_available: !row.is_available }, { preserveScroll: true });
    }
}

function removeVersion(row) {
    if (window.confirm(`Remove version ${row.version} and its file?`)) router.delete(`${base}/versions/${row.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Selling ${solution.title}`" />

    <AppLayout
        :title="`Selling ${solution.title}`"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Solutions', href: '/admin/solutions' }, { label: solution.title, href: `/admin/solutions/${solution.id}/edit` }, { label: 'Selling' }]"
    >
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader :title="`Selling ${solution.title}`" :description="`Prices here are before tax. The cart and the invoice add GST at ${taxRate}%, split by the buyer's state.`">
                <template #actions>
                    <UiButton :href="`/admin/solutions/${solution.id}/edit`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        The page itself
                    </UiButton>
                    <UiButton :href="solution.publicUrl" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="sm">
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        {{ solution.published ? 'View live' : 'Preview' }}
                    </UiButton>
                </template>
            </PageHeader>

            <p
                v-for="warning in warnings"
                :key="warning"
                class="flex items-start gap-2 rounded-[var(--radius-control)] p-3 text-sm"
                style="background: var(--surface-sunken)"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--color-warn-500)" />
                <span>{{ warning }}</span>
            </p>

            <form @submit.prevent="saveSettings">
                <FormSection title="What it is offered as" description="Built to order shows the budget band and asks for an enquiry. The source code and plans are sold at their price through the cart. A demo shows its sign in on the page.">
                    <UiFormField label="Offered as" :error="settings.errors.offerings">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <UiCheckbox v-for="option in offerings" :key="option.value" v-model="settings.offerings" :value="option.value" :label="option.label" />
                        </div>
                    </UiFormField>

                    <div v-if="demoOn" class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Demo address" required hint="Hosted by us on its own subdomain, never inside this site." :error="settings.errors.demo_url" class="sm:col-span-2">
                            <UiInput v-model="settings.demo_url" type="url" placeholder="https://hospital-demo.unboundbytesolution.com" />
                        </UiFormField>
                        <UiFormField label="Demo username" :error="settings.errors.demo_username">
                            <UiInput v-model="settings.demo_username" maxlength="120" autocomplete="off" />
                        </UiFormField>
                        <UiFormField label="Demo password" hint="Printed on the page for anybody. Use one made for the demo." :error="settings.errors.demo_password">
                            <UiInput v-model="settings.demo_password" maxlength="120" autocomplete="off" />
                        </UiFormField>
                        <UiFormField label="Note under the demo" :error="settings.errors.demo_notes" class="sm:col-span-2">
                            <UiInput v-model="settings.demo_notes" maxlength="300" placeholder="Everything resets every night, so try anything." />
                        </UiFormField>
                    </div>

                    <UiFormField label="SAC for a licence" hint="Printed against the licence on the invoice. Left empty, 997331 (licensing the right to use software)." :error="settings.errors.sac_code">
                        <UiInput v-model="settings.sac_code" inputmode="numeric" maxlength="8" class="max-w-40" placeholder="997331" />
                    </UiFormField>

                    <div class="flex justify-end">
                        <UiButton type="submit" size="sm" :loading="settings.processing">Save</UiButton>
                    </div>
                </FormSection>
            </form>

            <FormSection title="Licence tiers" description="The source code at a fixed price. Each tier says how many sites it may run on, and for how long updates and support come with it.">
                <ul v-if="licences.length" class="divide-y rounded-[var(--radius-control)] border" style="border-color: var(--border-subtle)">
                    <li v-for="tier in licences" :key="tier.id" class="flex flex-wrap items-start justify-between gap-3 p-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ tier.name }}
                                <span class="tnum font-normal" style="color: var(--text-muted)">{{ tier.price }} + GST</span>
                                <UiBadge v-if="!tier.is_active" tone="neutral" size="sm">Switched off</UiBadge>
                                <UiBadge v-if="tier.sold" tone="info" size="sm">Sold</UiBadge>
                            </p>
                            <p class="mt-0.5 text-xs" style="color: var(--text-muted)">{{ tier.terms.join(' · ') }}</p>
                            <p v-if="tier.description" class="mt-1 text-xs">{{ tier.description }}</p>
                        </div>
                        <div class="flex gap-1">
                            <UiButton size="xs" variant="ghost" icon :aria-label="`Edit ${tier.name}`" @click="startTier('licence', tier)"><Pencil class="h-3 w-3" /></UiButton>
                            <UiButton v-if="!tier.sold" size="xs" variant="ghost" icon :aria-label="`Remove ${tier.name}`" @click="removeTier(tier)"><Trash2 class="h-3 w-3" /></UiButton>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm" style="color: var(--text-muted)">No licence tiers yet. A single site and a multi site tier are the usual pair.</p>
                <div><UiButton size="sm" variant="secondary" @click="startTier('licence')"><template #leading><Plus class="h-3.5 w-3.5" /></template>Add a licence tier</UiButton></div>
            </FormSection>

            <FormSection title="Hosted plans" description="We host it and bill a plan. Buying one starts a subscription that renews itself; we are told to set it up.">
                <ul v-if="plans.length" class="divide-y rounded-[var(--radius-control)] border" style="border-color: var(--border-subtle)">
                    <li v-for="plan in plans" :key="plan.id" class="flex flex-wrap items-start justify-between gap-3 p-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ plan.name }}
                                <span class="tnum font-normal" style="color: var(--text-muted)">{{ plan.price }} + GST {{ plan.interval === 'monthly' ? 'a month' : 'a year' }}</span>
                                <UiBadge v-if="!plan.is_active" tone="neutral" size="sm">Switched off</UiBadge>
                                <UiBadge v-if="plan.sold" tone="info" size="sm">Sold</UiBadge>
                            </p>
                            <p v-if="plan.description" class="mt-1 text-xs">{{ plan.description }}</p>
                        </div>
                        <div class="flex gap-1">
                            <UiButton size="xs" variant="ghost" icon :aria-label="`Edit ${plan.name}`" @click="startTier('plan', plan)"><Pencil class="h-3 w-3" /></UiButton>
                            <UiButton v-if="!plan.sold" size="xs" variant="ghost" icon :aria-label="`Remove ${plan.name}`" @click="removeTier(plan)"><Trash2 class="h-3 w-3" /></UiButton>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm" style="color: var(--text-muted)">No plans yet.</p>
                <div><UiButton size="sm" variant="secondary" @click="startTier('plan')"><template #leading><Plus class="h-3.5 w-3.5" /></template>Add a plan</UiButton></div>
            </FormSection>

            <FormSection title="Add-ons" description="Bought with the product: installation or customisation hours we deliver, or months of support or updates added to the licence.">
                <ul v-if="addons.length" class="divide-y rounded-[var(--radius-control)] border" style="border-color: var(--border-subtle)">
                    <li v-for="addon in addons" :key="addon.id" class="flex flex-wrap items-start justify-between gap-3 p-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ addon.name }}
                                <span class="tnum font-normal" style="color: var(--text-muted)">{{ addonPrice(addon) }} + GST</span>
                                <UiBadge v-if="!addon.is_active" tone="neutral" size="sm">Switched off</UiBadge>
                                <UiBadge v-if="addon.sold" tone="info" size="sm">Sold</UiBadge>
                            </p>
                            <p class="mt-0.5 text-xs" style="color: var(--text-muted)">{{ addon.kindLabel }}<template v-if="addon.max_quantity > 1"> · up to {{ addon.max_quantity }}</template></p>
                        </div>
                        <div class="flex gap-1">
                            <UiButton size="xs" variant="ghost" icon :aria-label="`Edit ${addon.name}`" @click="startAddon(addon)"><Pencil class="h-3 w-3" /></UiButton>
                            <UiButton v-if="!addon.sold" size="xs" variant="ghost" icon :aria-label="`Remove ${addon.name}`" @click="removeAddon(addon)"><Trash2 class="h-3 w-3" /></UiButton>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm" style="color: var(--text-muted)">No add-ons yet.</p>
                <div><UiButton size="sm" variant="secondary" @click="startAddon()"><template #leading><Plus class="h-3.5 w-3.5" /></template>Add an add-on</UiButton></div>
            </FormSection>

            <FormSection title="Versions of the code" :description="`Every release is kept. A licence holder downloads the versions released inside their update window, through a link that expires. Up to ${maxUploadMb} MB a file, as a .zip.`">
                <ul v-if="versions.length" class="divide-y rounded-[var(--radius-control)] border" style="border-color: var(--border-subtle)">
                    <li v-for="version in versions" :key="version.id" class="p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                    Version {{ version.version }}
                                    <span class="font-normal" style="color: var(--text-muted)">released {{ version.releasedOn }}</span>
                                    <UiBadge v-if="!version.is_available" tone="neutral" size="sm">Withdrawn</UiBadge>
                                </p>
                                <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                                    {{ version.fileName }} · {{ version.size }} · {{ version.downloads }} {{ version.downloads === 1 ? 'download' : 'downloads' }}<template v-if="version.uploadedBy"> · uploaded by {{ version.uploadedBy }}</template>
                                </p>
                                <p class="mt-0.5 break-all font-mono text-[11px]" style="color: var(--text-muted)" :title="'SHA-256'">{{ version.sha256 }}</p>
                            </div>
                            <div class="flex gap-1">
                                <UiButton size="xs" variant="ghost" icon :href="`${base}/versions/${version.id}/download`" :inertia="false" :aria-label="`Download version ${version.version}`"><Download class="h-3 w-3" /></UiButton>
                                <UiButton size="xs" variant="ghost" icon :aria-label="version.is_available ? `Withdraw version ${version.version}` : `Make version ${version.version} available`" @click="toggleVersion(version)">
                                    <component :is="version.is_available ? EyeOff : Eye" class="h-3 w-3" />
                                </UiButton>
                                <UiButton v-if="version.downloads === 0" size="xs" variant="ghost" icon :aria-label="`Remove version ${version.version}`" @click="removeVersion(version)"><Trash2 class="h-3 w-3" /></UiButton>
                            </div>
                        </div>
                        <p v-if="version.changelog" class="mt-2 whitespace-pre-line text-xs">{{ version.changelog }}</p>
                    </li>
                </ul>
                <p v-else class="text-sm" style="color: var(--text-muted)">Nothing uploaded yet.</p>
                <div><UiButton size="sm" variant="secondary" @click="startVersion"><template #leading><Upload class="h-3.5 w-3.5" /></template>Upload a version</UiButton></div>
            </FormSection>
        </div>

        <UiModal :open="tierOpen" :title="tierEditing ? `Change ${tierEditing.name}` : tierForm.kind === 'licence' ? 'Add a licence tier' : 'Add a plan'" @close="tierOpen = false">
            <form id="tier-form" class="space-y-4" @submit.prevent="saveTier">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Name" required :error="tierForm.errors.name">
                        <UiInput v-model="tierForm.name" maxlength="80" :placeholder="tierForm.kind === 'licence' ? 'Single site' : 'Business'" />
                    </UiFormField>
                    <UiFormField label="Price, before tax" required :hint="`GST at ${taxRate}% is added at checkout.`" :error="tierForm.errors.price">
                        <UiInput v-model="tierForm.price" type="number" min="1" step="0.01" inputmode="decimal" placeholder="49999" />
                    </UiFormField>
                </div>
                <UiFormField label="What it is for" :error="tierForm.errors.description">
                    <UiInput v-model="tierForm.description" maxlength="300" placeholder="One live site, for your own business." />
                </UiFormField>
                <div v-if="tierForm.kind === 'licence'" class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Sites" hint="Empty for no limit." :error="tierForm.errors.sites">
                        <UiInput v-model="tierForm.sites" type="number" min="1" max="1000" />
                    </UiFormField>
                    <UiFormField label="Months of updates" :error="tierForm.errors.update_months">
                        <UiInput v-model="tierForm.update_months" type="number" min="0" max="120" />
                    </UiFormField>
                    <UiFormField label="Months of support" :error="tierForm.errors.support_months">
                        <UiInput v-model="tierForm.support_months" type="number" min="0" max="120" />
                    </UiFormField>
                </div>
                <UiFormField v-else label="Billed" required :error="tierForm.errors.interval">
                    <UiSelect v-model="tierForm.interval" :options="intervals" />
                </UiFormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Order on the page" :error="tierForm.errors.sort_order">
                        <UiInput v-model="tierForm.sort_order" type="number" min="0" max="999" />
                    </UiFormField>
                </div>
                <UiSwitch v-model="tierForm.is_active" label="On sale" description="Switched off, it stays on orders already placed and is not offered again." />
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="tierOpen = false">Cancel</UiButton>
                <UiButton type="submit" form="tier-form" :loading="tierForm.processing">Save</UiButton>
            </template>
        </UiModal>

        <UiModal :open="addonOpen" :title="addonEditing ? `Change ${addonEditing.name}` : 'Add an add-on'" @close="addonOpen = false">
            <form id="addon-form" class="space-y-4" @submit.prevent="saveAddon">
                <UiFormField label="Name" required :error="addonForm.errors.name">
                    <UiInput v-model="addonForm.name" maxlength="120" placeholder="Installation on your server" />
                </UiFormField>
                <UiFormField label="What it includes" :error="addonForm.errors.description">
                    <UiInput v-model="addonForm.description" maxlength="300" />
                </UiFormField>
                <UiFormField label="Kind" required :error="addonForm.errors.kind">
                    <UiSelect v-model="addonForm.kind" :options="addonKinds" />
                </UiFormField>
                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Price, before tax" required :error="addonForm.errors.price">
                        <UiInput v-model="addonForm.price" type="number" min="1" step="0.01" inputmode="decimal" />
                    </UiFormField>
                    <UiFormField v-if="addonForm.kind === 'service'" label="Charged" :error="addonForm.errors.unit">
                        <UiSelect v-model="addonForm.unit" :options="units" />
                    </UiFormField>
                    <UiFormField v-else label="Charged">
                        <UiInput model-value="Per month" disabled />
                    </UiFormField>
                    <UiFormField v-if="addonCounted" label="Most in one order" :error="addonForm.errors.max_quantity">
                        <UiInput v-model="addonForm.max_quantity" type="number" min="1" max="1000" />
                    </UiFormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="SAC" hint="Left empty, 998314 (IT design and development)." :error="addonForm.errors.sac_code">
                        <UiInput v-model="addonForm.sac_code" inputmode="numeric" maxlength="8" />
                    </UiFormField>
                    <UiFormField label="Order on the page" :error="addonForm.errors.sort_order">
                        <UiInput v-model="addonForm.sort_order" type="number" min="0" max="999" />
                    </UiFormField>
                </div>
                <UiSwitch v-model="addonForm.is_active" label="On sale" />
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="addonOpen = false">Cancel</UiButton>
                <UiButton type="submit" form="addon-form" :loading="addonForm.processing">Save</UiButton>
            </template>
        </UiModal>

        <UiModal :open="versionOpen" title="Upload a version" @close="versionOpen = false">
            <form id="version-form" class="space-y-4" @submit.prevent="uploadVersion">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Version" required :error="versionForm.errors.version">
                        <UiInput v-model="versionForm.version" maxlength="30" placeholder="1.4.0" />
                    </UiFormField>
                    <UiFormField label="Released on" required :error="versionForm.errors.released_on">
                        <UiInput v-model="versionForm.released_on" type="date" />
                    </UiFormField>
                </div>
                <UiFormField label="What changed" :error="versionForm.errors.changelog">
                    <UiTextarea v-model="versionForm.changelog" :rows="4" placeholder="Fixed …&#10;Added …" />
                </UiFormField>
                <UiFormField label="The code, as a .zip" required :error="versionForm.errors.file">
                    <UiFileDrop v-model="versionForm.file" accept=".zip,application/zip" :max-size="maxUploadMb" />
                </UiFormField>
                <p v-if="versionForm.progress" class="text-xs tnum" style="color: var(--text-muted)">Uploading… {{ versionForm.progress.percentage }}%</p>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="versionOpen = false">Cancel</UiButton>
                <UiButton type="submit" form="version-form" :loading="versionForm.processing">Upload</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
