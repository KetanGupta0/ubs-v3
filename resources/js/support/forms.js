/**
 * Empty a form for a dialog that is opening again.
 *
 * Inertia 3 makes whatever was last submitted a form's new defaults, so a
 * bare `reset()` when a dialog opens brings back the previous entry: the
 * last student enrolled, the last amount recorded. Setting the defaults
 * first is what makes reset mean blank.
 *
 * @param {object} form   An Inertia form.
 * @param {object} values What the form holds when it is empty.
 */
export function blankForm(form, values) {
    form.defaults(structuredClone(values));
    form.reset();
    form.clearErrors();

    return form;
}
