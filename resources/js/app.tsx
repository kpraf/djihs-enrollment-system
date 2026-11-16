import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { type TextSizeState } from './types';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'DJIHS Enrollment';

createInertiaApp({
    // Printable pages carry a full title such as "DJIHS Enrollment Report - SY 2026-2027",
    // which the browser also uses as the name of a PDF saved from the print dialog.
    title: (title) => (title ? (title.startsWith('DJIHS') ? title : `${title} | ${appName}`) : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#085019',
    },
});

// Sidebar links load a page's data a moment early, on hover. After anything
// is saved, drop those early copies so no page shows numbers from before.
router.on('finish', (event) => {
    if (event.detail.visit.method !== 'get') router.flushAll();
});

// The server sets the text size on first load. Keep it in sync when a
// different user signs in, or the size is changed, without a full reload.
router.on('navigate', (event) => {
    const size = event.detail.page.props.textSize as TextSizeState | undefined;
    if (size) document.documentElement.style.fontSize = `${size.percent}%`;
});
