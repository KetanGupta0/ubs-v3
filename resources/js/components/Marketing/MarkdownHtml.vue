<script setup>
/**
 * Text written as Markdown in the admin panel, rendered.
 *
 * The HTML comes from App\Support\Markdown on the server, which strips
 * anything typed as HTML and refuses unsafe links. That is the only reason it
 * may be rendered with v-html, so never pass this anything else.
 */
defineProps({
    html: { type: String, default: '' },
});
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- made safe on the server, see the note above -->
    <div class="cms-text" v-html="html" />
</template>

<style scoped>
.cms-text :deep(p) { margin: 0 0 0.9em; line-height: 1.7; color: var(--text-base); }
.cms-text :deep(p:last-child) { margin-bottom: 0; }
.cms-text :deep(a) { color: var(--color-brand-600); text-decoration: underline; text-underline-offset: 2px; }
.cms-text :deep(ul) { list-style: disc; padding-left: 1.4em; margin: 0 0 0.9em; }
.cms-text :deep(ol) { list-style: decimal; padding-left: 1.4em; margin: 0 0 0.9em; }
.cms-text :deep(li) { margin: 0.25em 0; line-height: 1.6; }
.cms-text :deep(strong) { color: var(--text-strong); }
.cms-text :deep(h1), .cms-text :deep(h2), .cms-text :deep(h3) { font-weight: 600; color: var(--text-strong); margin: 1.6em 0 0.5em; line-height: 1.3; }
.cms-text :deep(h1) { font-size: 1.5rem; }
.cms-text :deep(h2) { font-size: 1.2rem; }
.cms-text :deep(h3) { font-size: 1.05rem; }
.cms-text > :deep(:first-child) { margin-top: 0; }
.cms-text :deep(blockquote) { border-left: 3px solid var(--border-strong); padding-left: 1em; color: var(--text-muted); margin: 0 0 0.9em; }
.cms-text :deep(code) { font-family: ui-monospace, monospace; font-size: 0.9em; background: var(--surface-sunken); padding: 0.1em 0.35em; border-radius: 4px; }
.cms-text :deep(hr) { border: 0; border-top: 1px solid var(--border-subtle); margin: 1.6em 0; }
</style>
