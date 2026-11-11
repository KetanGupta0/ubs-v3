<script setup>
/** Our profiles elsewhere, as set on the settings screen. Nothing when there are none. */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Facebook, Github, Instagram, Linkedin, Twitter, Youtube } from 'lucide-vue-next';

const icons = { linkedin: Linkedin, instagram: Instagram, youtube: Youtube, facebook: Facebook, x: Twitter, github: Github };

const links = computed(() => usePage().props.site?.social ?? []);
</script>

<template>
    <ul v-if="links.length" class="flex flex-wrap gap-1.5" aria-label="Unboundbyte elsewhere">
        <li v-for="link in links" :key="link.network">
            <a
                :href="link.url"
                target="_blank"
                rel="noopener"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border transition hover:bg-[var(--surface)]"
                style="border-color: var(--border-subtle); color: var(--text-muted)"
                :aria-label="link.label"
                :title="link.label"
            >
                <component :is="icons[link.network]" class="h-4 w-4" aria-hidden="true" />
            </a>
        </li>
    </ul>
</template>
