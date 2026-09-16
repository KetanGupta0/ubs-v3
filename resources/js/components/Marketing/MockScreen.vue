<script setup>
/**
 * An illustrative product screen.
 *
 * Deliberately abstract. There is no live client work to screenshot yet, and a
 * fabricated screenshot of a system that does not exist would be a lie told to
 * someone deciding whether to trust us. This reads clearly as an illustration
 * while still showing the shape of a real application: navigation, stat tiles,
 * a chart and a table.
 *
 * The layout varies per product but is seeded from its slug, so it is different
 * between products and identical every time for the same one.
 */
import { computed } from 'vue';
import { accentFor, seedFrom } from '@/support/accents';

const props = defineProps({
    slug: { type: String, required: true },
    accent: { type: String, default: 'brand' },
    modules: { type: Array, default: () => [] },
    /** 'full' for a product hero, 'compact' for a card. */
    size: { type: String, default: 'full' },
});

const tone = computed(() => accentFor(props.accent));

const shape = computed(() => {
    const random = seedFrom(props.slug);

    return {
        bars: Array.from({ length: 9 }, () => 24 + Math.round(random() * 70)),
        rows: Array.from({ length: props.size === 'compact' ? 3 : 5 }, () => ({
            width: 40 + Math.round(random() * 45),
            status: random() > 0.6 ? 'ok' : random() > 0.3 ? 'warn' : 'idle',
        })),
        stats: Array.from({ length: 3 }, () => ({
            value: 10 + Math.round(random() * 89),
            width: 30 + Math.round(random() * 40),
        })),
        navLabels: (props.modules.length ? props.modules : ['Overview', 'Records', 'Reports', 'Settings']).slice(0, 6),
    };
});

const statusColour = {
    ok: 'var(--color-emerald-500)',
    warn: 'var(--color-amber-500)',
    idle: 'var(--border-strong)',
};
</script>

<template>
    <div
        class="relative overflow-hidden rounded-2xl border shadow-[var(--shadow-pop)]"
        :style="{ borderColor: 'var(--border-subtle)', background: 'var(--surface)' }"
        role="img"
        :aria-label="`Illustrative interface for ${slug.replace(/-/g, ' ')}`"
    >
        <!-- Window chrome, so it reads as an application rather than a chart. -->
        <div
            class="flex items-center gap-1.5 border-b px-3 py-2.5"
            :style="{ borderColor: 'var(--border-subtle)', background: 'var(--surface-sunken)' }"
        >
            <span class="h-2 w-2 rounded-full" style="background: var(--color-rose-400)" />
            <span class="h-2 w-2 rounded-full" style="background: var(--color-amber-400)" />
            <span class="h-2 w-2 rounded-full" style="background: var(--color-emerald-400)" />
            <span
                class="ml-2 h-3.5 flex-1 rounded-full"
                style="background: color-mix(in oklab, var(--border-strong) 45%, transparent)"
            />
        </div>

        <div class="flex" :class="size === 'compact' ? 'h-44' : 'h-72 sm:h-80'">
            <!-- Sidebar -->
            <div
                class="hidden w-28 shrink-0 flex-col gap-1.5 border-r p-2.5 sm:flex"
                :style="{ borderColor: 'var(--border-subtle)' }"
            >
                <div
                    v-for="(label, index) in shape.navLabels"
                    :key="label"
                    class="flex items-center gap-1.5 rounded-md px-1.5 py-1"
                    :style="index === 0 ? { background: tone.soft } : {}"
                >
                    <span
                        class="h-1.5 w-1.5 shrink-0 rounded-[2px]"
                        :style="{ background: index === 0 ? tone.solid : 'var(--border-strong)' }"
                    />
                    <span
                        class="h-1.5 rounded-full"
                        :style="{
                            width: `${40 + ((index * 13) % 40)}%`,
                            background: index === 0 ? tone.solid : 'var(--border-strong)',
                            opacity: index === 0 ? 0.85 : 0.5,
                        }"
                    />
                </div>
            </div>

            <!-- Body -->
            <div class="flex min-w-0 flex-1 flex-col gap-2.5 p-3">
                <!-- Stat tiles -->
                <div class="grid shrink-0 grid-cols-3 gap-2">
                    <div
                        v-for="(stat, index) in shape.stats"
                        :key="index"
                        class="rounded-lg border p-2"
                        :style="{ borderColor: 'var(--border-subtle)', background: index === 0 ? tone.softer : 'transparent' }"
                    >
                        <span
                            class="block h-1.5 rounded-full"
                            :style="{ width: `${stat.width}%`, background: 'var(--border-strong)' }"
                        />
                        <span
                            class="mt-1.5 block h-3 rounded"
                            :style="{ width: `${stat.value}%`, background: index === 0 ? tone.solid : 'var(--border-strong)', opacity: index === 0 ? 1 : 0.55 }"
                        />
                    </div>
                </div>

                <!-- Chart. Bars grow on mount, staggered, and stay put. -->
                <div
                    class="flex min-h-0 flex-1 items-end gap-1.5 rounded-lg border p-2.5"
                    :style="{ borderColor: 'var(--border-subtle)' }"
                >
                    <span
                        v-for="(bar, index) in shape.bars"
                        :key="index"
                        class="ub-bar flex-1 rounded-t-[3px]"
                        :style="{
                            '--bar-height': `${bar}%`,
                            animationDelay: `${index * 70}ms`,
                            background: index === shape.bars.length - 1 ? tone.gradient : tone.soft,
                            border: `1px solid ${tone.border}`,
                            borderBottom: 'none',
                        }"
                    />
                </div>

                <!-- Table -->
                <div v-if="size === 'full'" class="shrink-0 space-y-1.5">
                    <div
                        v-for="(row, index) in shape.rows"
                        :key="index"
                        class="flex items-center gap-2"
                    >
                        <span class="h-1.5 w-1.5 rounded-full" :style="{ background: statusColour[row.status] }" />
                        <span
                            class="h-1.5 rounded-full"
                            :style="{ width: `${row.width}%`, background: 'var(--border-strong)', opacity: 0.6 }"
                        />
                        <span class="ml-auto h-1.5 w-6 rounded-full" :style="{ background: tone.soft }" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ub-bar {
    height: var(--bar-height);
    transform-origin: bottom;
    animation: ub-grow 900ms var(--ease-out-expo) both;
}

@keyframes ub-grow {
    from { transform: scaleY(0); opacity: 0; }
    to { transform: scaleY(1); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
    .ub-bar { animation: none; }
}
</style>
