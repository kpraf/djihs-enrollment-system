import { useCan } from '@/hooks/use-can';
import { cn } from '@/lib/utils';
import { type Ability, type AuthedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Icon } from './icon';
import { type IconName } from './icons';
import { TextSizeControl } from './text-size-control';

interface NavItem {
    title: string;
    href: string;
    icon: IconName;
    ability?: Ability;
    /** Other URL prefixes that should highlight this item. */
    match?: string[];
}

const items: NavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
    { title: 'New Enrollment', href: '/enrollments/create', icon: 'how_to_reg', ability: 'encode-enrollments', match: ['/imports'] },
    { title: 'Student Management', href: '/enrollments', icon: 'groups', ability: 'view-enrollments' },
    { title: 'User Accounts', href: '/users', icon: 'person_add', ability: 'manage-users' },
    { title: 'Audit Log', href: '/audit-log', icon: 'fact_check', ability: 'view-audit-log' },
];

function isActive(url: string, item: NavItem): boolean {
    const path = url.split('?')[0];
    if (item.href === '/enrollments') {
        // "Student Management" covers the list and a learner's pages, but not the new-enrollment form.
        return path === '/enrollments' || /^\/enrollments\/\d+/.test(path);
    }
    return [item.href, ...(item.match ?? [])].some((p) => path === p || path.startsWith(p + '/'));
}

/**
 * The legacy sidebar: logo, "DJIHS <role>" title, green active item, and the
 * signed-in user with a red Logout at the bottom.
 */
export function Sidebar({ onNavigate, onLogout }: { onNavigate?: () => void; onLogout: () => void }) {
    const page = usePage<AuthedData>();
    const { auth } = page.props;
    const can = useCan();
    const user = auth.user;
    const initials = `${user.first_name.charAt(0)}${user.last_name.charAt(0)}`.toUpperCase();

    return (
        <div className="flex h-full flex-col justify-between bg-white">
            <div>
                <div className="p-6">
                    <div className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-full border border-yellow-200 bg-yellow-100">
                        <img alt="DJIHS logo" className="size-full object-cover opacity-90" src="/images/djihs-logo.png" />
                    </div>
                    <h1 className="mt-3 text-lg leading-tight font-bold text-slate-900">DJIHS {user.role_label}</h1>
                    <p className="text-xs text-slate-500">Don Jose Integrated High School</p>
                </div>
                <nav className="space-y-1 px-4" aria-label="Main">
                    {items
                        .filter((item) => !item.ability || can(item.ability))
                        .map((item) => {
                            const active = isActive(page.url, item);
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    onClick={onNavigate}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'group flex items-center gap-3 rounded-lg px-4 py-3 transition-colors',
                                        active ? 'bg-primary text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100',
                                    )}
                                >
                                    <Icon name={item.icon} className={cn(!active && 'group-hover:text-primary')} />
                                    <span className="text-sm font-medium">{item.title}</span>
                                </Link>
                            );
                        })}
                </nav>
            </div>

            <div className="space-y-3 border-t border-slate-200 p-4">
                <TextSizeControl className="px-2" />
                <Link href="/settings/profile" onClick={onNavigate} className="flex items-center gap-3 rounded-lg px-2 py-1 hover:bg-slate-50">
                    <div className="text-primary flex size-10 shrink-0 items-center justify-center rounded-full bg-green-100 text-sm font-bold">
                        {initials}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-slate-900">{user.name}</p>
                        <p className="truncate text-xs text-slate-500">{user.role_label}</p>
                    </div>
                    <Icon name="settings" className="text-slate-400" label="Account settings" />
                </Link>
                <button
                    type="button"
                    onClick={onLogout}
                    className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-sm text-red-600 transition-colors hover:bg-red-50"
                >
                    <Icon name="logout" />
                    Logout
                </button>
            </div>
        </div>
    );
}
