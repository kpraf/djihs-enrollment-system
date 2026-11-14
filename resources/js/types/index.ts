import { LucideIcon } from 'lucide-react';

export type Ability =
    | 'manage-users'
    | 'manage-school-setup'
    | 'encode-enrollments'
    | 'import-enrollments'
    | 'view-enrollments'
    | 'view-reports'
    | 'view-audit-log';

export interface User {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    username: string;
    email: string | null;
    role: string;
    role_label: string;
    must_change_password: boolean;
}

export interface Auth {
    user: User;
    abilities: Ability[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    ability?: Ability;
}

export interface Option<T = string> {
    value: T;
    label: string;
    description?: string;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface SharedData {
    name: string;
    auth: Auth;
    activeSchoolYear: { id: number; name: string; is_enrollment_open: boolean } | null;
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
}
