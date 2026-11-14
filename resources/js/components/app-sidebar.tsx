import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-can';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { FileSpreadsheet, LayoutGrid, ListChecks, UsersRound } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
    { title: 'Enrollments', url: '/enrollments', icon: ListChecks, ability: 'view-enrollments' },
    { title: 'Import spreadsheet', url: '/imports', icon: FileSpreadsheet, ability: 'import-enrollments' },
    { title: 'User accounts', url: '/users', icon: UsersRound, ability: 'manage-users' },
];

export function AppSidebar() {
    const can = useCan();
    const items = mainNavItems.filter((item) => !item.ability || can(item.ability));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
