import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * True while this page is reloading itself, for example after a search or
 * a filter change, so its list can show that new results are on the way.
 * Leaving for another page, loading a page early on hover, and fetching one
 * extra piece of data (such as the Approve All list) don't count.
 */
export function useReloading(): boolean {
    const [busy, setBusy] = useState(false);
    const current = useRef<unknown>(null);

    useEffect(() => {
        const offStart = router.on('start', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get' || visit.prefetch || visit.only.length > 0 || visit.url.pathname !== window.location.pathname) return;
            current.current = visit;
            setBusy(true);
        });
        // A newer search cancels the older one; only the latest one ends the wait.
        const offFinish = router.on('finish', (event) => {
            if (event.detail.visit === current.current) {
                current.current = null;
                setBusy(false);
            }
        });

        return () => {
            offStart();
            offFinish();
        };
    }, []);

    return busy;
}
