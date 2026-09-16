<script setup>
/**
 * Page metadata.
 *
 * Every public page renders this. Title, description, canonical URL, Open Graph
 * and Twitter cards, and the JSON-LD block search engines read. Keeping it in
 * one component is what stops a new page shipping with half of them.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    seo: { type: Object, required: true },
});

const structuredData = computed(() =>
    props.seo.structuredData ? JSON.stringify(props.seo.structuredData) : null,
);
</script>

<template>
    <Head :title="seo.title">
        <meta name="description" :content="seo.description">
        <link rel="canonical" :href="seo.url">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Unboundbyte Solutions">
        <meta property="og:title" :content="seo.title">
        <meta property="og:description" :content="seo.description">
        <meta property="og:url" :content="seo.url">
        <meta property="og:image" :content="seo.image">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" :content="seo.title">
        <meta name="twitter:description" :content="seo.description">
        <meta name="twitter:image" :content="seo.image">

        <component :is="'script'" v-if="structuredData" type="application/ld+json">
            {{ structuredData }}
        </component>
    </Head>
</template>
