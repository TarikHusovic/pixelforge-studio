import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useCredits() {
    const page = usePage();
    const credits = computed(() => page.props.credits);

    /** What the user can spend now: the balance minus what in-flight generations hold. */
    const available = computed(() =>
        credits.value
            ? credits.value.balance_nanos - credits.value.reserved_nanos
            : 0,
    );

    return { credits, available };
}
