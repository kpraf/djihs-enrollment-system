import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';

const tabs = [
    { title: 'Profile', href: '/settings/profile' },
    { title: 'Password', href: '/settings/password' },
    { title: 'Text Size', href: '/settings/text-size' },
];

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const { url } = usePage();

    return (
        <AppLayout>
            <PageHeader title="Account Settings" description="Manage your profile, password and how large the text appears." />
            <div className="flex flex-col gap-6 lg:flex-row">
                <nav className="flex gap-2 overflow-x-auto lg:w-56 lg:flex-col" aria-label="Settings">
                    {tabs.map((t) => (
                        <Link
                            key={t.href}
                            href={t.href}
                            aria-current={url.startsWith(t.href) ? 'page' : undefined}
                            className={cn(
                                'rounded-lg px-4 py-2.5 text-sm font-medium whitespace-nowrap',
                                url.startsWith(t.href) ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-100',
                            )}
                        >
                            {t.title}
                        </Link>
                    ))}
                </nav>
                <div className="panel max-w-2xl flex-1 p-6">{children}</div>
            </div>
        </AppLayout>
    );
}
