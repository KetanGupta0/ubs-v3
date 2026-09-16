<script setup>
/**
 * Renders the filter controls a Table declared on the server.
 *
 * Shared by the desktop popover and the mobile drawer, so the two can never
 * drift apart.
 */
import UiSelect from '@/components/UI/UiSelect.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';

const props = defineProps({
    filters: { type: Array, required: true },
    modelValue: { type: Object, required: true },
});

const emit = defineEmits(['change']);

function valueOf(key, fallback = '') {
    return props.modelValue[key] ?? fallback;
}

function toggleMulti(key, option) {
    const current = Array.isArray(props.modelValue[key]) ? [...props.modelValue[key]] : [];
    const index = current.indexOf(option);

    if (index === -1) {
        current.push(option);
    } else {
        current.splice(index, 1);
    }

    emit('change', key, current);
}
</script>

<template>
    <div class="space-y-5">
        <div v-for="filter in filters" :key="filter.key" class="space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                {{ filter.label }}
            </p>

            <UiSelect
                v-if="filter.type === 'select'"
                :model-value="valueOf(filter.key)"
                :options="[{ value: '', label: filter.placeholder || 'Any' }, ...filter.options]"
                size="sm"
                @update:model-value="emit('change', filter.key, $event)"
            />

            <div v-else-if="filter.type === 'multi'" class="flex flex-wrap gap-x-4 gap-y-2">
                <UiCheckbox
                    v-for="option in filter.options"
                    :key="option.value"
                    :label="option.label"
                    :model-value="(modelValue[filter.key] || []).includes(option.value)"
                    @update:model-value="toggleMulti(filter.key, option.value)"
                />
            </div>

            <UiSwitch
                v-else-if="filter.type === 'boolean'"
                :label="filter.placeholder || 'Only matching records'"
                :model-value="valueOf(filter.key, false) === true || valueOf(filter.key) === 'true'"
                @update:model-value="emit('change', filter.key, $event ? 'true' : '')"
            />

            <UiDateInput
                v-else-if="filter.type === 'date_range'"
                range
                :model-value="valueOf(filter.key, { from: '', to: '' })"
                @update:model-value="emit('change', filter.key, $event)"
            />

            <div v-else-if="filter.type === 'number_range'" class="grid grid-cols-2 gap-2">
                <UiInput
                    type="number"
                    size="sm"
                    placeholder="Min"
                    :model-value="valueOf(filter.key, {}).min ?? ''"
                    @update:model-value="emit('change', filter.key, { ...valueOf(filter.key, {}), min: $event })"
                />
                <UiInput
                    type="number"
                    size="sm"
                    placeholder="Max"
                    :model-value="valueOf(filter.key, {}).max ?? ''"
                    @update:model-value="emit('change', filter.key, { ...valueOf(filter.key, {}), max: $event })"
                />
            </div>

            <UiInput
                v-else
                size="sm"
                :placeholder="filter.placeholder || `Filter by ${filter.label.toLowerCase()}`"
                :model-value="valueOf(filter.key)"
                @update:model-value="emit('change', filter.key, $event)"
            />
        </div>
    </div>
</template>
