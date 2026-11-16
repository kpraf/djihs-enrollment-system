import { ConfirmDialog } from '@/components/confirm-dialog';
import { CredentialDialog } from '@/components/credential-dialog';
import { Icon } from '@/components/icon';
import { Sidebar } from '@/components/sidebar';
import { Toasts } from '@/components/toasts';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Page frame from the legacy system: a fixed white sidebar on desktop,
 * a top bar with a slide-in drawer on phones, and a light grey content area.
 */
export default function AppLayout({ children, wide = false }: { children: React.ReactNode; wide?: boolean }) {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [confirmLogout, setConfirmLogout] = useState(false);

    useEffect(() => {
        document.body.style.overflow = drawerOpen ? 'hidden' : '';
        return () => {
            document.body.style.overflow = '';
        };
    }, [drawerOpen]);

    const askLogout = () => {
        setDrawerOpen(false);
        setConfirmLogout(true);
    };

    return (
        <div className="bg-background-light flex min-h-dvh">
            <aside className="bg-primary sticky top-0 hidden h-dvh w-72 shrink-0 overflow-y-auto md:block">
                <Sidebar onLogout={askLogout} />
            </aside>

            {/* Phone drawer */}
            <div
                className={cn(
                    'fixed inset-0 z-40 bg-slate-900/50 transition-opacity md:hidden',
                    drawerOpen ? 'opacity-100' : 'pointer-events-none opacity-0',
                )}
                onClick={() => setDrawerOpen(false)}
                aria-hidden="true"
            />
            <aside
                className={cn(
                    'bg-primary fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] overflow-y-auto shadow-xl transition-transform md:hidden',
                    drawerOpen ? 'translate-x-0' : '-translate-x-full',
                )}
                aria-hidden={!drawerOpen}
                inert={!drawerOpen}
            >
                <button
                    type="button"
                    onClick={() => setDrawerOpen(false)}
                    className="absolute top-4 right-4 z-10 rounded-lg p-1 text-white hover:bg-white/15"
                    aria-label="Close menu"
                >
                    <Icon name="close" />
                </button>
                <Sidebar onNavigate={() => setDrawerOpen(false)} onLogout={askLogout} />
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="bg-primary sticky top-0 z-30 flex items-center gap-3 px-4 py-3 text-white shadow md:hidden">
                    <button type="button" onClick={() => setDrawerOpen(true)} className="rounded-lg p-1.5 hover:bg-white/15" aria-label="Open menu">
                        <Icon name="menu" />
                    </button>
                    <img src="/images/djihs-logo.png" srcSet="/images/djihs-logo@2x.png 2x" alt="" className="h-9 w-auto object-contain" />
                    <span className="font-bold">DJIHS Enrollment</span>
                </header>

                <main className={cn('w-full flex-1 p-4 sm:p-6 md:p-10', !wide && 'mx-auto max-w-[90rem]')}>{children}</main>
            </div>

            <Toasts />
            <CredentialDialog />
            <ConfirmDialog
                open={confirmLogout}
                onClose={() => setConfirmLogout(false)}
                onConfirm={() => router.post(route('logout'))}
                title="Are you sure you want to logout?"
                message="You will be logged out immediately. Please make sure all your work is saved before continuing."
                confirmLabel="Logout"
                icon="logout"
            />
        </div>
    );
}
