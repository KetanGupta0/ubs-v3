/**
 * The link between a UiFormField and the control inside it.
 *
 * The field knows the generated id, the ids of its hint and error, and whether
 * it is currently invalid. Passing all three down by hand at every call site is
 * something everybody forgets, and a forgotten one is a label that does not
 * focus its input and a screen reader that never reads the error. So the field
 * provides it and the control takes it, and neither call site has to remember.
 *
 * A control keeps whatever it was given explicitly: an id in the template wins
 * over the injected one.
 */
import { computed, inject, provide } from 'vue';

export const fieldKey = Symbol('ui-form-field');

export function provideField(context) {
    provide(fieldKey, context);
}

export function useField() {
    const context = inject(fieldKey, null);

    return computed(() => context?.value ?? { id: undefined, describedBy: undefined, invalid: false });
}
