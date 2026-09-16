<script setup>
/**
 * Live component gallery.
 *
 * Every primitive the application is built from, rendered in both themes, so a
 * visual regression is obvious in one place instead of being discovered on a
 * customer's screen.
 */
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Rocket, Trash2, Plus, Pencil, MoreHorizontal, Palette } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import BrandMark from '@/components/Brand/BrandMark.vue';
import BrandLogo from '@/components/Brand/BrandLogo.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiDrawer from '@/components/UI/UiDrawer.vue';
import UiTabs from '@/components/UI/UiTabs.vue';
import UiTooltip from '@/components/UI/UiTooltip.vue';
import UiSkeleton from '@/components/UI/UiSkeleton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiDropdown from '@/components/UI/UiDropdown.vue';
import UiDropdownItem from '@/components/UI/UiDropdownItem.vue';
import { toast } from '@/support/toast';

defineProps({
    table: { type: Object, required: true },
});

const modalOpen = ref(false);
const drawerOpen = ref(false);
const tab = ref('overview');
const text = ref('');
const notes = ref('');
const select = ref('');
const combo = ref(null);
const checked = ref(true);
const switched = ref(true);
const date = ref('');
const files = ref([]);
const selectedRows = ref([]);

const palette = [
    { name: 'Brand', token: 'brand', shades: [50, 100, 300, 500, 600, 700, 900] },
    { name: 'Accent', token: 'accent', shades: [50, 100, 300, 400, 500, 700, 900] },
    { name: 'Ink', token: 'ink', shades: [50, 100, 300, 500, 700, 900, 950] },
];

const comboOptions = [
    { value: 1, label: 'Aarti Sharma', description: 'Client · Meridian Logistics' },
    { value: 2, label: 'Rahul Verma', description: 'Student · Full Stack Batch 4' },
    { value: 3, label: 'Priya Nair', description: 'Client · Nair Diagnostics' },
    { value: 4, label: 'Imran Qureshi', description: 'Student · Data Engineering' },
];
</script>

<template>
    <Head title="Design system" />

    <AppLayout title="Design system" :breadcrumbs="[{ label: 'Internal' }, { label: 'Design system' }]">
        <div class="mx-auto max-w-6xl space-y-10">
            <!-- ------------------------------------------------------- brand -->
            <section class="space-y-4">
                <header>
                    <h2 class="text-lg font-semibold">Brand</h2>
                    <p class="text-sm" style="color: var(--text-muted)">
                        The mark is a "U" whose left arm holds and whose right arm breaks into
                        ascending bytes. Structure on one side, data set free on the other.
                    </p>
                </header>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <UiCard>
                        <div class="flex h-24 items-center justify-center"><BrandMark :size="56" /></div>
                        <p class="text-center text-xs" style="color: var(--text-muted)">Mark</p>
                    </UiCard>
                    <UiCard>
                        <div class="flex h-24 items-center justify-center"><BrandLogo size="md" /></div>
                        <p class="text-center text-xs" style="color: var(--text-muted)">Horizontal lockup</p>
                    </UiCard>
                    <UiCard>
                        <div class="flex h-24 items-center justify-center"><BrandLogo layout="stacked" size="sm" /></div>
                        <p class="text-center text-xs" style="color: var(--text-muted)">Stacked lockup</p>
                    </UiCard>
                    <UiCard padding="p-0">
                        <div class="flex h-24 items-center justify-center rounded-t-[var(--radius-card)] bg-ink-950 text-white">
                            <BrandLogo size="sm" variant="current" />
                        </div>
                        <p class="py-3 text-center text-xs" style="color: var(--text-muted)">Monochrome on dark</p>
                    </UiCard>
                </div>

                <UiCard>
                    <div class="space-y-4">
                        <div v-for="ramp in palette" :key="ramp.token">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                {{ ramp.name }}
                            </p>
                            <div class="flex gap-1.5 overflow-x-auto scrollbar-thin">
                                <div v-for="shade in ramp.shades" :key="shade" class="shrink-0 text-center">
                                    <div
                                        class="h-12 w-16 rounded-lg border"
                                        :style="`background: var(--color-${ramp.token}-${shade}); border-color: var(--border-subtle)`"
                                    />
                                    <span class="mt-1 block text-[0.65rem] tnum" style="color: var(--text-muted)">{{ shade }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="border-t pt-4" style="border-color: var(--border-subtle)">
                            <p class="font-[family-name:var(--font-display)] text-2xl font-semibold">Sora sets every heading</p>
                            <p class="mt-1 text-sm">Inter carries body copy and the entire interface at small sizes.</p>
                            <p class="mt-1 font-mono text-sm">JetBrains Mono holds keys, invoice numbers and code.</p>
                        </div>
                    </div>
                </UiCard>
            </section>

            <!-- ----------------------------------------------------- buttons -->
            <section class="space-y-4">
                <h2 class="text-lg font-semibold">Buttons</h2>

                <UiCard>
                    <div class="space-y-5">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <UiButton>Primary</UiButton>
                            <UiButton variant="accent">Accent</UiButton>
                            <UiButton variant="secondary">Secondary</UiButton>
                            <UiButton variant="subtle">Subtle</UiButton>
                            <UiButton variant="ghost">Ghost</UiButton>
                            <UiButton variant="danger">Delete</UiButton>
                        </div>

                        <div class="flex flex-wrap items-center gap-2.5">
                            <UiButton size="xs">Extra small</UiButton>
                            <UiButton size="sm">Small</UiButton>
                            <UiButton size="md">Medium</UiButton>
                            <UiButton size="lg">Large</UiButton>
                        </div>

                        <div class="flex flex-wrap items-center gap-2.5">
                            <UiButton loading>Saving</UiButton>
                            <UiButton disabled>Disabled</UiButton>
                            <UiButton icon aria-label="Add"><Plus class="h-4 w-4" /></UiButton>
                            <UiButton variant="secondary" icon aria-label="Edit"><Pencil class="h-4 w-4" /></UiButton>
                            <UiButton>
                                <template #leading><Rocket class="h-4 w-4" /></template>
                                With icon
                            </UiButton>
                        </div>
                    </div>
                </UiCard>
            </section>

            <!-- ------------------------------------------------------- forms -->
            <section class="space-y-4">
                <h2 class="text-lg font-semibold">Form controls</h2>

                <div class="grid gap-4 lg:grid-cols-2">
                    <UiCard>
                        <div class="space-y-4">
                            <UiFormField label="Full name" hint="As it should appear on the invoice." required>
                                <template #default="field">
                                    <UiInput :id="field.id" v-model="text" placeholder="Aarti Sharma" />
                                </template>
                            </UiFormField>

                            <UiFormField label="Email" error="That address is already registered.">
                                <template #default="field">
                                    <UiInput :id="field.id" invalid type="email" placeholder="aarti@example.com" />
                                </template>
                            </UiFormField>

                            <UiFormField label="Engagement model">
                                <template #default="field">
                                    <UiSelect
                                        :id="field.id"
                                        v-model="select"
                                        placeholder="Choose one"
                                        :options="['Fixed scope', 'Retainer', 'Time and materials']"
                                    />
                                </template>
                            </UiFormField>

                            <UiFormField label="Assign to" hint="Type to search across clients and students.">
                                <template #default="field">
                                    <UiCombobox :id="field.id" v-model="combo" :options="comboOptions" />
                                </template>
                            </UiFormField>
                        </div>
                    </UiCard>

                    <UiCard>
                        <div class="space-y-4">
                            <UiFormField label="Notes">
                                <template #default="field">
                                    <UiTextarea :id="field.id" v-model="notes" placeholder="Anything the team should know…" />
                                </template>
                            </UiFormField>

                            <UiFormField label="Target date">
                                <template #default="field">
                                    <UiDateInput :id="field.id" v-model="date" />
                                </template>
                            </UiFormField>

                            <div class="space-y-3">
                                <UiCheckbox v-model="checked" label="Send welcome message on creation" />
                                <UiSwitch
                                    v-model="switched"
                                    label="Two factor authentication"
                                    description="Ask for a code from an authenticator app at sign in."
                                />
                            </div>

                            <UiFileDrop v-model="files" hint="PNG, JPG or PDF up to 10 MB" accept="image/*,.pdf" multiple />
                        </div>
                    </UiCard>
                </div>
            </section>

            <!-- ---------------------------------------------------- feedback -->
            <section class="space-y-4">
                <h2 class="text-lg font-semibold">Status, feedback and overlays</h2>

                <UiCard>
                    <div class="space-y-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <UiBadge tone="neutral" dot>Draft</UiBadge>
                            <UiBadge tone="brand" dot>In development</UiBadge>
                            <UiBadge tone="accent" dot>Under review</UiBadge>
                            <UiBadge tone="success" dot>Paid</UiBadge>
                            <UiBadge tone="warning" dot>Due</UiBadge>
                            <UiBadge tone="danger" dot>Overdue</UiBadge>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <UiAvatar name="Aarti Sharma" size="lg" />
                            <UiAvatar name="Rahul Verma" />
                            <UiAvatar name="Priya Nair" size="sm" />
                            <UiAvatar name="Imran Qureshi" size="xs" />
                        </div>

                        <div class="flex flex-wrap items-center gap-2.5">
                            <UiButton variant="secondary" @click="toast.success('Client account created and credentials sent.')">
                                Success toast
                            </UiButton>
                            <UiButton variant="secondary" @click="toast.error('Payment gateway declined the request.')">
                                Error toast
                            </UiButton>
                            <UiButton variant="secondary" @click="toast.warning('Attendance is below 60 percent.')">
                                Warning toast
                            </UiButton>
                            <UiButton variant="secondary" @click="modalOpen = true">Open dialog</UiButton>
                            <UiButton variant="secondary" @click="drawerOpen = true">Open drawer</UiButton>

                            <UiTooltip text="Tooltips appear on hover and focus">
                                <UiButton variant="secondary">Hover me</UiButton>
                            </UiTooltip>

                            <UiDropdown>
                                <template #trigger>
                                    <UiButton variant="secondary" icon aria-label="More actions">
                                        <MoreHorizontal class="h-4 w-4" />
                                    </UiButton>
                                </template>
                                <UiDropdownItem><Pencil class="h-4 w-4" /> Edit</UiDropdownItem>
                                <UiDropdownItem><Plus class="h-4 w-4" /> Duplicate</UiDropdownItem>
                                <UiDropdownItem danger><Trash2 class="h-4 w-4" /> Delete</UiDropdownItem>
                            </UiDropdown>
                        </div>

                        <UiTabs
                            v-model="tab"
                            :tabs="[
                                { value: 'overview', label: 'Overview' },
                                { value: 'activity', label: 'Activity', count: 12 },
                                { value: 'files', label: 'Files', count: 3 },
                            ]"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2 rounded-xl border p-4" style="border-color: var(--border-subtle)">
                                <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Loading</p>
                                <UiSkeleton class="h-4 w-3/4" />
                                <UiSkeleton class="h-4 w-1/2" />
                                <UiSkeleton class="h-4 w-5/6" />
                            </div>

                            <div class="rounded-xl border" style="border-color: var(--border-subtle)">
                                <UiEmptyState
                                    :icon="Palette"
                                    title="No documents yet"
                                    description="Uploaded files will appear here."
                                >
                                    <template #action>
                                        <UiButton size="sm">Upload a file</UiButton>
                                    </template>
                                </UiEmptyState>
                            </div>
                        </div>
                    </div>
                </UiCard>
            </section>

            <!-- -------------------------------------------------- data table -->
            <section class="space-y-4">
                <header>
                    <h2 class="text-lg font-semibold">Data table</h2>
                    <p class="text-sm" style="color: var(--text-muted)">
                        Search, sorting, filtering, paging and export all run on the server, and
                        the state lives in the URL so a view can be shared. Narrow the browser to
                        see the same rows become cards.
                    </p>
                </header>

                <DataTable
                    :table="table"
                    selectable
                    v-model:selected="selectedRows"
                    search-placeholder="Search by name or email…"
                    empty-title="No people match"
                    empty-description="Run the demo seeder to populate this table."
                >
                    <template #cell:name="{ row }">
                        <div class="flex items-center gap-2.5">
                            <UiAvatar :name="row.name" size="sm" />
                            <span class="font-medium" style="color: var(--text-strong)">{{ row.name }}</span>
                        </div>
                    </template>

                    <template #cell:status="{ value }">
                        <UiBadge :tone="value === 'Verified' ? 'success' : 'warning'" dot>{{ value }}</UiBadge>
                    </template>

                    <template #rowActions>
                        <UiDropdown>
                            <template #trigger>
                                <UiButton variant="ghost" size="xs" icon aria-label="Row actions">
                                    <MoreHorizontal class="h-4 w-4" />
                                </UiButton>
                            </template>
                            <UiDropdownItem><Pencil class="h-4 w-4" /> Edit</UiDropdownItem>
                            <UiDropdownItem danger><Trash2 class="h-4 w-4" /> Remove</UiDropdownItem>
                        </UiDropdown>
                    </template>
                </DataTable>

                <p v-if="selectedRows.length" class="text-sm" style="color: var(--text-muted)">
                    {{ selectedRows.length }} selected.
                </p>
            </section>
        </div>

        <UiModal
            :open="modalOpen"
            title="Create client account"
            description="Credentials are sent to the registered email and mobile as soon as this is saved."
            @close="modalOpen = false"
        >
            <div class="space-y-4">
                <UiFormField label="Company name" required>
                    <template #default="field"><UiInput :id="field.id" placeholder="Meridian Logistics" /></template>
                </UiFormField>
                <UiFormField label="Primary contact" required>
                    <template #default="field"><UiInput :id="field.id" placeholder="Aarti Sharma" /></template>
                </UiFormField>
                <UiCheckbox :model-value="true" label="Force password change on first sign in" />
            </div>

            <template #footer>
                <UiButton variant="secondary" @click="modalOpen = false">Cancel</UiButton>
                <UiButton @click="modalOpen = false; toast.success('Client created. Credentials sent.')">
                    Create account
                </UiButton>
            </template>
        </UiModal>

        <UiDrawer :open="drawerOpen" title="Project details" @close="drawerOpen = false">
            <div class="space-y-4">
                <p class="text-sm">
                    Drawers hold detail that supports the page behind them, so the user keeps
                    their place in the list instead of navigating away and back.
                </p>
                <UiBadge tone="brand" dot>In development</UiBadge>
            </div>

            <template #footer>
                <UiButton block @click="drawerOpen = false">Close</UiButton>
            </template>
        </UiDrawer>
    </AppLayout>
</template>
