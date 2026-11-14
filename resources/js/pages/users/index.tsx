import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, SelectInput, TextInput } from '@/components/form';
import { Icon } from '@/components/icon';
import { Modal } from '@/components/modal';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type AuthedData, type Option } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface UserRow {
    id: number;
    username: string;
    first_name: string;
    last_name: string;
    name: string;
    email: string | null;
    role: string;
    role_label: string;
    is_active: boolean;
    last_login_at: string | null;
}

type Pending = { kind: 'active'; user: UserRow } | { kind: 'reset'; user: UserRow } | null;

const lastLogin = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

export default function UsersIndex({ users, roles }: { users: UserRow[]; roles: Option[] }) {
    const { auth } = usePage<AuthedData>().props;
    const [editing, setEditing] = useState<UserRow | 'new' | null>(null);
    const [pending, setPending] = useState<Pending>(null);
    const [busy, setBusy] = useState(false);
    const [search, setSearch] = useState('');
    const [role, setRole] = useState('');
    const [status, setStatus] = useState('');

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        return users.filter(
            (u) =>
                (!q || `${u.name} ${u.username} ${u.role_label}`.toLowerCase().includes(q)) &&
                (!role || u.role === role) &&
                (!status || (status === 'active') === u.is_active),
        );
    }, [users, search, role, status]);

    const runPending = () => {
        if (!pending) return;
        setBusy(true);
        const done = { preserveScroll: true, onFinish: () => (setBusy(false), setPending(null)) };
        if (pending.kind === 'active') {
            router.put(route('users.set-active', pending.user.id), { active: !pending.user.is_active }, done);
        } else {
            router.post(route('users.reset-password', pending.user.id), {}, done);
        }
    };

    const roleOptions = [...new Map([...roles, ...users.map((u) => ({ value: u.role, label: u.role_label }))].map((r) => [r.value, r])).values()];

    return (
        <AppLayout>
            <Head title="User Accounts" />
            <PageHeader
                title="User Account Management"
                description="Create and manage system user accounts for staff members."
                actions={
                    <>
                        <a href={route('users.export')} className="btn bg-blue-600 text-white hover:bg-blue-700">
                            <Icon name="download" /> Download List
                        </a>
                        <button type="button" className="btn-primary" onClick={() => setEditing('new')}>
                            <Icon name="add" /> Create New Account
                        </button>
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard compact label="Total Accounts" value={users.length} tone="primary" />
                <StatCard compact label="Active" value={users.filter((u) => u.is_active).length} tone="green" />
                <StatCard compact label="Inactive" value={users.filter((u) => !u.is_active).length} tone="red" />
            </div>

            <div className="mb-6 flex flex-col gap-4 sm:flex-row">
                <label className="relative flex-1">
                    <Icon name="search" className="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        className="field-input py-2.5 pl-12"
                        placeholder="Search by name, username, or role…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-label="Search accounts"
                    />
                </label>
                <select className="field-input-sm h-auto sm:w-48" value={role} onChange={(e) => setRole(e.target.value)} aria-label="Filter by role">
                    <option value="">All Roles</option>
                    {roleOptions.map((r) => (
                        <option key={r.value} value={r.value}>
                            {r.label}
                        </option>
                    ))}
                </select>
                <select
                    className="field-input-sm h-auto sm:w-40"
                    value={status}
                    onChange={(e) => setStatus(e.target.value)}
                    aria-label="Filter by status"
                >
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div className="panel overflow-hidden">
                <div className="relative overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="table-head">
                            <tr>
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Username</th>
                                <th className="px-6 py-3 font-medium">Role</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 font-medium">Last Sign-in</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {shown.map((u) => {
                                const locked = u.role === 'admin' && auth.user.role !== 'admin';
                                return (
                                    <tr key={u.id} className="hover:bg-slate-50">
                                        <td className="px-6 py-4 font-medium text-slate-900">{u.name}</td>
                                        <td className="px-6 py-4 font-mono text-slate-600">{u.username}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-slate-600">{u.role_label}</td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={u.is_active ? 'active' : 'inactive'} />
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-slate-600">
                                            {u.last_login_at ? lastLogin.format(new Date(u.last_login_at)) : 'Never'}
                                        </td>
                                        <td className="px-6 py-4">
                                            {!locked && (
                                                <div className="flex justify-end gap-1">
                                                    <button
                                                        type="button"
                                                        className="hover:text-primary rounded-lg p-2 text-slate-400 hover:bg-slate-100"
                                                        onClick={() => setEditing(u)}
                                                    >
                                                        <Icon name="edit" label={`Edit ${u.name}`} />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="hover:text-primary rounded-lg p-2 text-slate-400 hover:bg-slate-100"
                                                        onClick={() => setPending({ kind: 'reset', user: u })}
                                                    >
                                                        <Icon name="lock_reset" label={`Reset password for ${u.name}`} />
                                                    </button>
                                                    {u.id !== auth.user.id && (
                                                        <button
                                                            type="button"
                                                            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-red-600"
                                                            onClick={() => setPending({ kind: 'active', user: u })}
                                                        >
                                                            <Icon
                                                                name={u.is_active ? 'person_off' : 'person_check'}
                                                                label={`${u.is_active ? 'Deactivate' : 'Activate'} ${u.name}`}
                                                            />
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                            {shown.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        No accounts match.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <UserDialog key={editing === 'new' ? 'new' : (editing?.id ?? 'none')} user={editing} roles={roles} onClose={() => setEditing(null)} />

            <ConfirmDialog
                open={pending !== null}
                onClose={() => setPending(null)}
                onConfirm={runPending}
                processing={busy}
                icon={pending?.kind === 'reset' ? 'lock_reset' : pending?.user.is_active ? 'person_off' : 'person_check'}
                tone={pending?.kind === 'active' && pending.user.is_active ? 'danger' : 'primary'}
                title={
                    pending?.kind === 'reset'
                        ? `Reset ${pending.user.name}'s password?`
                        : `${pending?.user.is_active ? 'Deactivate' : 'Activate'} ${pending?.user.name ?? ''}'s account?`
                }
                message={
                    pending?.kind === 'reset'
                        ? 'They will get a temporary password and must choose a new one when they sign in.'
                        : pending?.user.is_active
                          ? 'They will be signed out and will not be able to sign in until the account is activated again.'
                          : 'They will be able to sign in again.'
                }
                confirmLabel={pending?.kind === 'reset' ? 'Reset Password' : pending?.user.is_active ? 'Deactivate' : 'Activate'}
            />
        </AppLayout>
    );
}

function UserDialog({ user, roles, onClose }: { user: UserRow | 'new' | null; roles: Option[]; onClose: () => void }) {
    const isNew = user === 'new';
    const existing = user !== 'new' ? user : null;

    const { data, setData, post, put, processing, errors } = useForm({
        username: existing?.username ?? '',
        first_name: existing?.first_name ?? '',
        last_name: existing?.last_name ?? '',
        email: existing?.email ?? '',
        role: existing?.role ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isNew) post(route('users.store'), options);
        else if (existing) put(route('users.update', existing.id), options);
    };

    return (
        <Modal open={user !== null} onClose={onClose} title={isNew ? 'Create User Account' : 'Edit User Account'} size="sm">
            <form onSubmit={submit} className="space-y-4" noValidate>
                {isNew ? (
                    <Field label="Username" required error={errors.username} hint="Letters, numbers, dots, dashes and underscores.">
                        {(p) => (
                            <TextInput
                                {...p}
                                value={data.username}
                                onChange={(e) => setData('username', e.target.value)}
                                autoCapitalize="none"
                                placeholder="e.g., juan.delacruz"
                                data-autofocus
                            />
                        )}
                    </Field>
                ) : (
                    <p className="text-sm text-slate-600">
                        Username: <span className="font-mono font-semibold">{existing?.username}</span>
                    </p>
                )}
                <Field label="First Name" required error={errors.first_name}>
                    {(p) => <TextInput {...p} value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} />}
                </Field>
                <Field label="Last Name" required error={errors.last_name}>
                    {(p) => <TextInput {...p} value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} />}
                </Field>
                <Field label="Role" required error={errors.role}>
                    {(p) => (
                        <SelectInput {...p} value={data.role} onChange={(e) => setData('role', e.target.value)}>
                            <option value="">Select Role</option>
                            {roles.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </SelectInput>
                    )}
                </Field>
                <Field label="Email (optional)" error={errors.email}>
                    {(p) => <TextInput {...p} type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />}
                </Field>
                {isNew && <p className="text-sm text-slate-500">A temporary password is created for you and shown after saving.</p>}
                <div className="flex gap-3 pt-2">
                    <button type="button" className="btn-outline flex-1 py-3" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className="btn-primary flex-1 py-3" disabled={processing}>
                        {isNew ? 'Create Account' : 'Save Changes'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
