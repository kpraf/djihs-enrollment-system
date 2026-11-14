export type Ability =
    | 'manage-users'
    | 'manage-school-years'
    | 'manage-sections'
    | 'encode-enrollments'
    | 'review-enrollments'
    | 'edit-enrollments'
    | 'request-revisions'
    | 'manage-documents'
    | 'import-enrollments'
    | 'view-enrollments'
    | 'view-own-section'
    | 'view-own-classes'
    | 'view-reports'
    | 'view-analytics'
    | 'view-metrics'
    | 'view-audit-log'
    | 'backup-data';

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
    user: User | null;
    abilities: Ability[];
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
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface TextSizeState {
    value: string;
    percent: number;
    options: { value: string; label: string; percent: number }[];
}

export interface SharedData {
    name: string;
    auth: Auth;
    textSize: TextSizeState;
    activeSchoolYear: { id: number; name: string; is_enrollment_open: boolean } | null;
    flash: {
        success: string | null;
        error: string | null;
        credential: { username: string; password: string; reason: 'created' | 'reset' } | null;
    };
    errors: Record<string, string>;
    [key: string]: unknown;
}

/** The signed-in user, on pages that require one. */
export interface AuthedData extends SharedData {
    auth: Auth & { user: User };
}
