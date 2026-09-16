/**
 * Per product accent colours.
 *
 * Each solution and course carries an accent name, and every surface that
 * renders it, card, hero, mock screen and badge, reads from here. That is what
 * makes a product page feel like one object rather than a template with a
 * different title.
 *
 * Values are Tailwind's own palette variables, so they follow the theme into
 * dark mode without a second definition.
 */
const palette = {
    brand: ['--color-brand-600', '--color-brand-400'],
    accent: ['--color-cyan-600', '--color-cyan-400'],
    violet: ['--color-violet-600', '--color-violet-400'],
    emerald: ['--color-emerald-600', '--color-emerald-400'],
    amber: ['--color-amber-500', '--color-amber-300'],
    rose: ['--color-rose-600', '--color-rose-400'],
};

export function accentFor(name) {
    const [deep, light] = palette[name] ?? palette.brand;

    return {
        name: name in palette ? name : 'brand',
        solid: `var(${deep})`,
        light: `var(${light})`,
        gradient: `linear-gradient(135deg, var(${deep}), var(${light}))`,
        /** Tinted background that stays legible in both themes. */
        soft: `color-mix(in oklab, var(${deep}) 10%, transparent)`,
        softer: `color-mix(in oklab, var(${deep}) 5%, transparent)`,
        border: `color-mix(in oklab, var(${deep}) 28%, transparent)`,
        glow: `radial-gradient(closest-side, color-mix(in oklab, var(${deep}) 55%, transparent), transparent)`,
    };
}

/**
 * A stable pseudo random number from a string.
 *
 * Used to vary the illustrative mock screens per product. Deterministic on
 * purpose: a card that reshuffles every time it renders reads as noise.
 */
export function seedFrom(text) {
    let hash = 2166136261;

    for (let i = 0; i < text.length; i++) {
        hash ^= text.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }

    return () => {
        hash = Math.imul(hash ^ (hash >>> 15), 2246822507);
        hash = Math.imul(hash ^ (hash >>> 13), 3266489909);
        return ((hash ^= hash >>> 16) >>> 0) / 4294967296;
    };
}
