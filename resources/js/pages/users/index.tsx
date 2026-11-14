import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Option, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Power } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'User accounts', href: '/users' }];
const lastLogin = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

export default function UsersIndex({ users, roles }: { users: UserRow[]; roles: Option[] }) {
    const { auth } = usePage<SharedData>().props;
    const [editing, setEditing] = useState<UserRow | 'new' | null>(null);

    const toggle = (user: UserRow) => {
        const verb = user.is_active ? 'Deactivate' : 'Activate';
        if (confirm(`${verb} ${user.name}'s account?`)) {
            router.post(route('users.toggle-active', user.id), {}, { preserveScroll: true });
        }
    };

    const resetPassword = (user: UserRow) => {
        if (confirm(`Reset ${user.name}'s password? They will get a temporary password and must change it when they sign in.`)) {
            router.post(route('users.reset-password', user.id), {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User accounts" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">User accounts</h1>
                        <p className="text-muted-foreground text-sm">
                            Staff sign in with a username. New accounts get a temporary password shown once.
                        </p>
                    </div>
                    <Button onClick={() => setEditing('new')}>
                        <Plus className="size-4" /> New account
                    </Button>
                </div>

                <Card className="gap-0 overflow-hidden py-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="text-left">
                                    <th className="px-3 py-2 font-medium">Name</th>
                                    <th className="px-3 py-2 font-medium">Username</th>
                                    <th className="px-3 py-2 font-medium">Role</th>
                                    <th className="px-3 py-2 font-medium">Status</th>
                                    <th className="px-3 py-2 font-medium">Last sign-in</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {users.map((u) => {
                                    const locked = u.role === 'admin' && auth.user.role !== 'admin';
                                    return (
                                        <tr key={u.id} className={u.is_active ? '' : 'text-muted-foreground'}>
                                            <td className="px-3 py-2 font-medium">{u.name}</td>
                                            <td className="px-3 py-2 font-mono text-xs">{u.username}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">{u.role_label}</td>
                                            <td className="px-3 py-2">
                                                <Badge variant={u.is_active ? 'outline' : 'secondary'}>
                                                    {u.is_active ? 'Active' : 'Deactivated'}
                                                </Badge>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {u.last_login_at ? lastLogin.format(new Date(u.last_login_at)) : 'Never'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {!locked && (
                                                    <div className="flex justify-end gap-1">
                                                        <Button variant="ghost" size="icon" title="Edit" onClick={() => setEditing(u)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button variant="ghost" size="icon" title="Reset password" onClick={() => resetPassword(u)}>
                                                            <KeyRound className="size-4" />
                                                        </Button>
                                                        {u.id !== auth.user.id && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                title={u.is_active ? 'Deactivate' : 'Activate'}
                                                                onClick={() => toggle(u)}
                                                            >
                                                                <Power className="size-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>

            <UserDialog key={editing === 'new' ? 'new' : editing?.id} user={editing} roles={roles} onClose={() => setEditing(null)} />
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
        role: existing?.role ?? roles.find((r) => r.value === 'registrar')?.value ?? roles[0]?.value ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isNew) {
            post(route('users.store'), options);
        } else if (existing) {
            put(route('users.update', existing.id), options);
        }
    };

    return (
        <Dialog open={user !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{isNew ? 'New account' : `Edit ${existing?.name}`}</DialogTitle>
                        <DialogDescription>
                            {isNew ? 'A temporary password will be shown after saving.' : `Username: ${existing?.username}`}
                        </DialogDescription>
                    </DialogHeader>

                    {isNew && (
                        <div className="grid gap-2">
                            <Label htmlFor="username">Username</Label>
                            <Input
                                id="username"
                                value={data.username}
                                onChange={(e) => setData('username', e.target.value)}
                                autoCapitalize="none"
                                required
                            />
                            <p className="text-muted-foreground text-xs">
                                Letters, numbers, dots, dashes and underscores. For example juan.delacruz.
                            </p>
                            <InputError message={errors.username} />
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="first_name">First name</Label>
                            <Input id="first_name" value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} required />
                            <InputError message={errors.first_name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="last_name">Last name</Label>
                            <Input id="last_name" value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} required />
                            <InputError message={errors.last_name} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        <NativeSelect id="role" value={data.role} onChange={(e) => setData('role', e.target.value)}>
                            {roles.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.role} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email (optional)</Label>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {isNew ? 'Create account' : 'Save changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
