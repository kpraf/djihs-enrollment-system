import { type Ability, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Hides UI the user cannot use. The server checks the same abilities on
 * every request, so this is for convenience, not security.
 */
export function useCan() {
    const { auth } = usePage<SharedData>().props;

    return (ability: Ability) => auth.abilities.includes(ability);
}
