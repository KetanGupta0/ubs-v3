<script setup>
/**
 * Six separate boxes for a one time code.
 *
 * Typing advances, backspace retreats, and a pasted code fills every box at
 * once, which is what people actually do after copying it from a message.
 * `inputmode="numeric"` and the `one-time-code` autocomplete hint let phones
 * offer the code from the SMS directly.
 */
import { ref, nextTick, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    length: { type: Number, default: 6 },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'complete']);

const inputs = ref([]);

const digits = ref(Array.from({ length: props.length }, (_, i) => props.modelValue[i] ?? ''));

watch(
    () => props.modelValue,
    (value) => {
        // Keeps the boxes in step when the parent resets the field after an error.
        if (value === digits.value.join('')) return;
        digits.value = Array.from({ length: props.length }, (_, i) => value[i] ?? '');
    },
);

function publish() {
    const code = digits.value.join('');
    emit('update:modelValue', code);

    // Joining collapses empty slots, so a full length string is exactly the
    // condition "every box is filled". Testing for an empty slot with
    // `includes('')` would always be true, which is what silently stopped this
    // from ever firing.
    if (code.length === props.length) {
        emit('complete', code);
    }
}

function onInput(index, event) {
    const value = event.target.value.replace(/\D/g, '');

    if (!value) {
        digits.value[index] = '';
        publish();

        return;
    }

    // A phone autofilling the whole code lands in one box; spread it out.
    if (value.length > 1) {
        fill(value, index);

        return;
    }

    digits.value[index] = value;
    publish();

    if (index < props.length - 1) {
        focus(index + 1);
    }
}

function onKeydown(index, event) {
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        event.preventDefault();
        digits.value[index - 1] = '';
        publish();
        focus(index - 1);
    }

    if (event.key === 'ArrowLeft' && index > 0) {
        event.preventDefault();
        focus(index - 1);
    }

    if (event.key === 'ArrowRight' && index < props.length - 1) {
        event.preventDefault();
        focus(index + 1);
    }
}

function onPaste(event) {
    event.preventDefault();
    fill((event.clipboardData?.getData('text') ?? '').replace(/\D/g, ''), 0);
}

function fill(value, from) {
    for (let i = 0; i < value.length && from + i < props.length; i++) {
        digits.value[from + i] = value[i];
    }

    publish();
    focus(Math.min(from + value.length, props.length - 1));
}

async function focus(index) {
    await nextTick();
    inputs.value[index]?.focus();
    inputs.value[index]?.select();
}

defineExpose({ focusFirst: () => focus(0) });
</script>

<template>
    <div class="flex justify-between gap-2" @paste="onPaste">
        <input
            v-for="(digit, index) in digits"
            :key="index"
            :ref="(el) => (inputs[index] = el)"
            :value="digit"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            :aria-label="`Digit ${index + 1} of ${length}`"
            :class="[
                'h-14 w-full min-w-0 rounded-xl border text-center font-mono text-xl font-semibold',
                'bg-[var(--surface)] text-[var(--text-strong)] tabular-nums',
                'transition-[border-color,box-shadow] duration-[var(--duration-fast)]',
                invalid
                    ? 'border-danger-500'
                    : digit
                        ? 'border-brand-500'
                        : 'border-[var(--border-strong)] focus:border-brand-500',
            ]"
            @input="onInput(index, $event)"
            @keydown="onKeydown(index, $event)"
            @focus="$event.target.select()"
        >
    </div>
</template>
