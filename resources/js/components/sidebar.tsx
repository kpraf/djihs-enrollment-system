import { useCan } from '@/hooks/use-can';
import { cn } from '@/lib/utils';
import { type Ability, type AuthedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Icon } from './icon';
import { type IconName } from './icons';
import { TextSizeControl } from './text-size-control';

export interface NavItem {
    title: string;
    href: string;
    icon: IconName;
    ability?: Ability;
    /** Extra paths that should highlight this item. */
    match?: RegExp;
}

export const navItems: NavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
    { title: 'New Enrollment', href: '/enrollments/create', icon: 'how_to_reg', ability: 'encode-enrollments', match: /^\/imports/ },
    { title: 'Review Enrollments', href: '/reviews', icon: 'rate_review', ability: 'review-enrollments' },
    { title: 'Student Management', href: '/enrollments', icon: 'groups', ability: 'view-enrollments', match: /^\/enrollments\/\d+/ },
    { title: 'My Section', href: '/my-section', icon: 'co_present', ability: 'view-own-section' },
    { title: 'My Students', href: '/my-students', icon: 'school', ability: 'view-own-classes' },
    { title: 'Manage Sections', href: '/sections', icon: 'meeting_room', ability: 'manage-sections' },
    { title: 'Assign Students', href: '/section-assignments', icon: 'group_add', ability: 'manage-sections' },
    { title: 'Manage Strands', href: '/strands', icon: 'category', ability: 'manage-sections' },
    { title: 'Analytics', href: '/analytics', icon: 'analytics', ability: 'view-analytics' },
    { title: 'Metrics', href: '/metrics', icon: 'speed', ability: 'view-metrics' },
    { title: 'Academic Years', href: '/school-years', icon: 'calendar_month', ability: 'manage-school-years' },
    { title: 'User Accounts', href: '/users', icon: 'person_add', ability: 'manage-users' },
    { title: 'Audit Log', href: '/audit-log', icon: 'fact_check', ability: 'view-audit-log' },
];

function isActive(url: string, item: NavItem): boolean {
    const path = url.split('?')[0];
    return path === item.href || (item.href !== '/enrollments' && path.startsWith(item.href + '/')) || Boolean(item.match?.test(path));
}

/**
 * The DJIHS sidebar in school green: logo, "DJIHS <role>" title, a white
 * pill for the current page, and the signed-in user with Logout below.
 */
export function Sidebar({ onNavigate, onLogout }: { onNavigate?: () => void; onLogout: () => void }) {
    const page = usePage<AuthedData>();
    const user = page.props.auth.user;
    const can = useCan();
    const initials = `${user.first_name.charAt(0)}${user.last_name.charAt(0)}`.toUpperCase();

    return (
        <div
            className="flex min-h-full flex-col justify-between text-white"
            style={{ backgroundImage: 'linear-gradient(180deg, #0a5a1d 0%, #085019 45%, #063a12 100%)' }}
        >
            <div>
                <div className="p-6">
                    <div className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-yellow-300 bg-yellow-100">
                        <img alt="DJIHS logo" className="size-full object-cover" src="/images/djihs-logo.png" />
                    </div>
                    <h1 className="mt-3 text-lg leading-tight font-bold">DJIHS {user.role_label}</h1>
                    <p className="text-xs text-green-100/80">Don Jose Integrated High School</p>
                </div>
                <nav className="space-y-1 px-4 pb-4" aria-label="Main">
                    {navItems
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
                                        'flex items-center gap-3 rounded-lg px-4 py-3 transition-colors',
                                        active ? 'text-primary bg-white font-semibold shadow-sm' : 'text-green-50 hover:bg-white/10 hover:text-white',
                                    )}
                                >
                                    <Icon name={item.icon} />
                                    <span className="text-sm font-medium">{item.title}</span>
                                </Link>
                            );
                        })}
                </nav>
            </div>

            <div className="space-y-3 border-t border-white/15 p-4">
                <TextSizeControl className="px-2" tone="dark" />
                <Link href="/settings/profile" onClick={onNavigate} className="flex items-center gap-3 rounded-lg px-2 py-1 hover:bg-white/10">
                    <div className="text-primary flex size-10 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold">
                        {initials}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{user.name}</p>
                        <p className="truncate text-xs text-green-100/80">{user.role_label}</p>
                    </div>
                    <Icon name="settings" className="text-green-100" label="Account settings" />
                </Link>
                <button
                    type="button"
                    onClick={onLogout}
                    className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-sm font-medium transition-colors hover:bg-red-600"
                >
                    <Icon name="logout" />
                    Logout
                </button>
            </div>
        </div>
    );
}
