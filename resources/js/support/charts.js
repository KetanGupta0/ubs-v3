/**
 * Which colour a series gets.
 *
 * Slots are assigned in a fixed order and never cycled: the order is what
 * makes the palette safe for colour blind readers, and a ninth series given a
 * generated hue would be indistinguishable from one already on the chart. A
 * chart that needs more than six series needs fewer series.
 *
 * Reports name a slot, never a colour, so this file is the only place that
 * knows what slot three looks like — and both themes change together.
 */
export const SLOTS = 6;

export function slotColor(slot = 1) {
    const index = ((Number(slot) - 1) % SLOTS + SLOTS) % SLOTS;

    return `var(--chart-${index + 1})`;
}

/**
 * A step on the one hue ramp, for ordered things.
 *
 * Funnel stages, age bands, size tiers: swapping two of them would change the
 * meaning, so the reader should see the order in the colour rather than have
 * to read the labels to recover it.
 */
export function rampColor(index, total = 4) {
    const steps = 4;

    if (total <= 1) return 'var(--chart-step-2)';

    const position = Math.round((index / (total - 1)) * (steps - 1));

    return `var(--chart-step-${Math.min(steps, Math.max(1, position + 1))})`;
}

/** Series as the chart components want them, with colours resolved. */
export function withColors(series = [], { ramp = false } = {}) {
    return series.map((one, index) => ({
        ...one,
        color: ramp ? rampColor(index, series.length) : slotColor(one.slot ?? index + 1),
    }));
}

/** Rupees, short enough for an axis tick. */
export function rupeeTick(value) {
    const amount = Number(value) || 0;

    if (Math.abs(amount) >= 10000000) return `₹${(amount / 10000000).toFixed(1)}Cr`;
    if (Math.abs(amount) >= 100000) return `₹${(amount / 100000).toFixed(1)}L`;
    if (Math.abs(amount) >= 1000) return `₹${Math.round(amount / 1000)}K`;

    return `₹${Math.round(amount)}`;
}

export function plainTick(value) {
    return new Intl.NumberFormat('en-IN').format(Math.round(Number(value) || 0));
}
