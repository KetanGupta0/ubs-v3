/**
 * What the signed in client may do in their organisation (CL8).
 *
 * Presentation only: a screen leaves out a button the person's role cannot
 * use, and says who can instead. Every one of these actions is refused on the
 * server for a role that may not do it, whatever the page shows.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useClientRole() {
    const page = usePage();
    const client = computed(() => page.props.auth?.client ?? null);

    /** Not a client at all (staff viewing as somebody, for instance): leave the page as it was. */
    const may = (ability) => !client.value || client.value.may.includes(ability);

    return { client, may };
}
