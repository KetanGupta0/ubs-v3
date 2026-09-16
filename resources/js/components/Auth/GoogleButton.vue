<script setup>
/**
 * Google sign in.
 *
 * A plain link rather than an Inertia visit, because the destination is an
 * OAuth redirect off this origin and Inertia would try to parse the response
 * as a page.
 */
defineProps({
    label: { type: String, default: 'Continue with Google' },
});
</script>

<template>
    <a
        href="/auth/google/redirect"
        class="inline-flex h-12 w-full items-center justify-center gap-3 rounded-[var(--radius-field)] border bg-[var(--surface)] text-sm font-medium transition hover:bg-[var(--surface-sunken)]"
        style="border-color: var(--border-strong); color: var(--text-strong)"
    >
        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#4285F4" d="M23.52 12.27c0-.79-.07-1.54-.2-2.27H12v4.3h6.46a5.52 5.52 0 0 1-2.4 3.62v3h3.88c2.27-2.09 3.58-5.17 3.58-8.65z"/>
            <path fill="#34A853" d="M12 24c3.24 0 5.96-1.08 7.94-2.91l-3.88-3.01c-1.08.72-2.45 1.16-4.06 1.16-3.13 0-5.78-2.11-6.73-4.96H1.26v3.09A12 12 0 0 0 12 24z"/>
            <path fill="#FBBC05" d="M5.27 14.28a7.2 7.2 0 0 1 0-4.56V6.63H1.26a12 12 0 0 0 0 10.74l4.01-3.09z"/>
            <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.18 15.24 0 12 0A12 12 0 0 0 1.26 6.63l4.01 3.09C6.22 6.86 8.87 4.75 12 4.75z"/>
        </svg>
        {{ label }}
    </a>
</template>
