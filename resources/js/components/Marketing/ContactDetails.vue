<script setup>
/**
 * The ways to reach us that the settings screen chose to show: email, phone,
 * WhatsApp, address. Renders nothing when none were chosen.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Mail, MapPin, MessageCircle, Phone } from 'lucide-vue-next';

defineProps({
    /** Larger type for the contact page; the footer uses the small one. */
    size: { type: String, default: 'sm' },
});

const site = computed(() => usePage().props.site ?? {});

const items = computed(() => [
    site.value.email && { icon: Mail, label: site.value.email, href: `mailto:${site.value.email}` },
    site.value.phone && { icon: Phone, label: site.value.phone, href: site.value.phoneHref },
    site.value.whatsapp && { icon: MessageCircle, label: `WhatsApp ${site.value.whatsapp.label}`, href: site.value.whatsapp.href, external: true },
    site.value.address && { icon: MapPin, label: site.value.address },
].filter(Boolean));
</script>

<template>
    <ul v-if="items.length" :class="['space-y-2', size === 'lg' ? 'text-base' : 'text-sm']" style="color: var(--text-muted)">
        <li v-for="item in items" :key="item.label" class="flex items-start gap-2.5">
            <component :is="item.icon" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <a
                v-if="item.href"
                :href="item.href"
                :target="item.external ? '_blank' : undefined"
                :rel="item.external ? 'noopener' : undefined"
                class="break-all transition hover:text-[var(--text-strong)]"
            >
                {{ item.label }}
            </a>
            <span v-else class="whitespace-pre-line">{{ item.label }}</span>
        </li>
    </ul>
</template>
