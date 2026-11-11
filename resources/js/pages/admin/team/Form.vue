<script setup>
/**
 * One person on the public site: who they are, where they appear, a photo.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    member: { type: Object, default: null },
    nextOrder: { type: Number, default: 1 },
});

const photo = ref([]);

const form = useForm({
    name: props.member?.name ?? '',
    role: props.member?.role ?? '',
    bio: props.member?.bio ?? '',
    experience: props.member?.experience ?? '',
    skills: props.member?.skills ?? '',
    linkedin_url: props.member?.linkedin_url ?? '',
    on_about: props.member?.on_about ?? true,
    is_mentor: props.member?.is_mentor ?? false,
    is_active: props.member?.is_active ?? true,
    sort_order: props.member?.sort_order ?? props.nextOrder,
    photo: null,
    remove_photo: false,
});

function save() {
    form.photo = photo.value[0] ?? null;

    form.post(props.member ? `/admin/team/${props.member.id}` : '/admin/team', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            photo.value = [];
            form.remove_photo = false;
        },
    });
}

function destroy() {
    if (window.confirm(`Remove ${props.member.name} from the site? Their photo is deleted too.`)) {
        router.delete(`/admin/team/${props.member.id}`);
    }
}
</script>

<template>
    <Head :title="member ? member.name : 'Add a person'" />

    <AppLayout
        :title="member ? member.name : 'Add a person'"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Team and mentors', href: '/admin/team' }, { label: member ? member.name : 'New' }]"
    >
        <div class="mx-auto max-w-3xl">
            <PageHeader :title="member ? member.name : 'Add a person'">
                <template #actions>
                    <UiButton href="/admin/team" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Team and mentors
                    </UiButton>
                </template>
            </PageHeader>

            <form class="space-y-5" @submit.prevent="save">
                <FormSection title="Who they are">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Name" required :error="form.errors.name">
                            <UiInput v-model="form.name" />
                        </UiFormField>
                        <UiFormField label="Role" required :error="form.errors.role">
                            <UiInput v-model="form.role" placeholder="Lead engineer, full stack mentor" />
                        </UiFormField>
                    </div>
                    <UiFormField label="Experience" hint="Short, shown under the role." :error="form.errors.experience">
                        <UiInput v-model="form.experience" placeholder="8 years building web platforms" />
                    </UiFormField>
                    <UiFormField label="About them" hint="Two or three sentences, in plain words." :error="form.errors.bio">
                        <UiTextarea v-model="form.bio" :rows="3" />
                    </UiFormField>
                    <UiFormField label="What they teach" hint="Separated by commas, up to eight. Shown on mentor cards." :error="form.errors.skills">
                        <UiInput v-model="form.skills" placeholder="Laravel, Vue, MySQL" />
                    </UiFormField>
                    <UiFormField label="LinkedIn" hint="Optional. The full address of their profile." :error="form.errors.linkedin_url">
                        <UiInput v-model="form.linkedin_url" type="url" placeholder="https://www.linkedin.com/in/…" />
                    </UiFormField>
                </FormSection>

                <FormSection title="Photo" description="A clear, recent photo, ideally square. It is re-encoded on upload, which removes location and camera details. Without one, their initials are shown.">
                    <div v-if="member?.photo && !form.remove_photo" class="flex items-center gap-3">
                        <UiAvatar :name="member.name" :src="member.photo" size="lg" />
                        <span class="text-sm" style="color: var(--text-muted)">Current photo</span>
                    </div>
                    <UiFormField label="Upload a photo" hint="JPG, PNG or WebP, under 5 MB." :error="form.errors.photo">
                        <UiFileDrop v-model="photo" accept="image/jpeg,image/png,image/webp" :max-size="5" />
                    </UiFormField>
                    <UiCheckbox v-if="member?.photo" v-model="form.remove_photo" label="Remove the current photo" />
                </FormSection>

                <FormSection title="Where they appear">
                    <UiSwitch v-model="form.on_about" label="On the About page" description="As part of the team." />
                    <UiSwitch v-model="form.is_mentor" label="On the Training page" description="As a mentor, with what they teach." />
                    <p v-if="form.errors.on_about" class="text-sm text-danger-600">{{ form.errors.on_about }}</p>
                    <UiSwitch v-model="form.is_active" label="Shown" description="Turn off to take them off the site without losing what is written here." />
                    <UiFormField label="Order" hint="Lower numbers come first." :error="form.errors.sort_order">
                        <UiInput v-model="form.sort_order" type="number" min="0" class="max-w-32" />
                    </UiFormField>
                </FormSection>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <UiButton v-if="member" variant="ghost" size="sm" @click="destroy">
                        <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                        Remove from the site
                    </UiButton>
                    <span v-else />
                    <UiButton type="submit" :loading="form.processing">{{ member ? 'Save' : 'Add to the site' }}</UiButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
