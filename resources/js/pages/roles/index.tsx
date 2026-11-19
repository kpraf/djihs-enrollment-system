import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, SelectInput } from '@/components/form';
import { Icon } from '@/components/icon';
import { Modal } from '@/components/modal';
import { PageHeader } from '@/components/page-header';
import { BusyLabel } from '@/components/spinner';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useState } from 'react';

interface RoleColumn {
    value: string;
    label: string;
    accounts: number;
    is_retired: boolean;
    can_retire: boolean;
    abilities: string[];
    defaults: string[];
    locked: string[];
}

interface AbilityRow {
    value: string;
    label: string;
    group: string;
    note: string | null;
}

interface Change {
    role: string;
    ability: string;
    granted: boolean;
}

type Grid = Record<string, Set<string>>;

const copy = (grid: Grid): Grid => Object.fromEntries(Object.entries(grid).map(([k, v]) => [k, new Set(v)]));

/**
 * What each role can do, as a grid of checkboxes. Nothing changes until
 * Save, which lists every change for a last look first.
 */
export default function Roles({ roles, abilities }: { roles: RoleColumn[]; abilities: AbilityRow[] }) {
    const saved = useMemo<Grid>(() => Object.fromEntries(roles.map((r) => [r.value, new Set(r.abilities)])), [roles]);
    const [draft, setDraft] = useState<Grid>(() => copy(saved));
    const [confirming, setConfirming] = useState(false);
    const [resetting, setResetting] = useState(false);
    const [retiring, setRetiring] = useState<RoleColumn | null>(null);
    const [restoring, setRestoring] = useState<RoleColumn | null>(null);

    // After a save the page reloads with what is now stored.
    useEffect(() => setDraft(copy(saved)), [saved]);

    const changes: Change[] = roles.flatMap((r) =>
        abilities
            .filter((a) => draft[r.value].has(a.value) !== saved[r.value].has(a.value))
            .map((a) => ({ role: r.value, ability: a.value, granted: draft[r.value].has(a.value) })),
    );
    const abilityLabel = (value: string) => abilities.find((a) => a.value === value)?.label ?? value;

    const toggle = (role: string, ability: string) =>
        setDraft((d) => {
            const next = copy(d);
            if (next[role].has(ability)) next[role].delete(ability);
            else next[role].add(ability);
            return next;
        });

    const save = () =>
        router.put(
            route('roles.update'),
            { changes: changes.map((c) => ({ ...c })) },
            { preserveScroll: true, onSuccess: () => setConfirming(false) },
        );

    return (
        <AppLayout>
            <Head title="Roles and Permissions" />
            <PageHeader
                title="Roles and Permissions"
                description="Choose what each role can do. Changes apply from the next page anyone opens, and every change is recorded in the Audit Log."
                actions={
                    <button type="button" className="btn-outline" onClick={() => setResetting(true)}>
                        <Icon name="restart_alt" /> Reset to defaults
                    </button>
                }
            />

            <div className="panel overflow-hidden">
                <div className="relative overflow-x-auto">
                    <table className="w-full min-w-[60rem] text-left text-sm">
                        <thead className="table-head">
                            <tr>
                                <th className="sticky left-0 z-10 min-w-64 bg-slate-50 px-4 py-3 font-medium">Permission</th>
                                {roles.map((r) => (
                                    <th key={r.value} className={cn('px-3 py-3 text-center align-top font-medium', r.is_retired && 'bg-slate-100')}>
                                        <span className="block font-semibold text-slate-900 normal-case">{r.label}</span>
                                        <span className="block text-xs font-normal text-slate-500 normal-case">
                                            {r.accounts === 1 ? '1 account' : `${r.accounts} accounts`}
                                        </span>
                                        {r.is_retired ? (
                                            <span className="mt-1.5 flex flex-col items-center gap-1 normal-case">
                                                <span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                                    Retired
                                                </span>
                                                <button
                                                    type="button"
                                                    className="text-primary text-xs font-semibold hover:underline"
                                                    onClick={() => setRestoring(r)}
                                                >
                                                    Restore<span className="sr-only"> {r.label}</span>
                                                </button>
                                            </span>
                                        ) : (
                                            r.can_retire && (
                                                <button
                                                    type="button"
                                                    className="mt-1.5 text-xs font-semibold text-slate-500 normal-case hover:text-red-700 hover:underline"
                                                    onClick={() => setRetiring(r)}
                                                >
                                                    Retire<span className="sr-only"> {r.label}</span>
                                                </button>
                                            )
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {abilities.map((a, i) => (
                                <Fragment key={a.value}>
                                    {a.group !== abilities[i - 1]?.group && (
                                        <tr className="bg-slate-50/70">
                                            <th
                                                scope="colgroup"
                                                colSpan={roles.length + 1}
                                                className="sticky left-0 px-4 pt-4 pb-1.5 text-xs font-semibold tracking-wider text-slate-500 uppercase"
                                            >
                                                {a.group}
                                            </th>
                                        </tr>
                                    )}
                                    <tr className="hover:bg-slate-50">
                                        <th scope="row" className="sticky left-0 z-10 bg-white px-4 py-3 text-left font-normal">
                                            <span className="block text-slate-900">{a.label}</span>
                                            {a.note && (
                                                <span className="mt-0.5 flex items-start gap-1 text-xs text-amber-800">
                                                    <Icon name="info" className="mt-px shrink-0 text-sm" />
                                                    {a.note}
                                                </span>
                                            )}
                                        </th>
                                        {roles.map((r) => {
                                            const locked = r.locked.includes(a.value);
                                            const changed = draft[r.value].has(a.value) !== saved[r.value].has(a.value);
                                            return (
                                                <td
                                                    key={r.value}
                                                    className={cn('px-3 py-3 text-center', r.is_retired && 'bg-slate-50', changed && 'bg-amber-50')}
                                                >
                                                    <label
                                                        className={cn(
                                                            'inline-flex items-center justify-center gap-1',
                                                            locked || r.is_retired ? 'cursor-not-allowed' : 'cursor-pointer',
                                                        )}
                                                        title={
                                                            locked
                                                                ? `Always on for the ${r.label}, so nobody is locked out`
                                                                : r.is_retired
                                                                  ? 'Restore this role to change it'
                                                                  : undefined
                                                        }
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            className="checkbox size-5 disabled:opacity-60"
                                                            checked={draft[r.value].has(a.value)}
                                                            disabled={locked || r.is_retired}
                                                            onChange={() => toggle(r.value, a.value)}
                                                            aria-label={`${r.label}: ${a.label}`}
                                                        />
                                                        {locked && <Icon name="lock" className="text-sm text-slate-400" />}
                                                    </label>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <p className="mt-3 flex items-center gap-1.5 text-xs text-slate-500">
                <Icon name="lock" className="text-sm" /> Locked: always on for the Administrator, so nobody can be locked out of accounts and
                permissions.
            </p>

            {changes.length > 0 && (
                <div className="sticky bottom-4 z-20 mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 shadow-lg">
                    <p className="text-sm font-medium text-amber-950">
                        {changes.length === 1 ? '1 unsaved change' : `${changes.length} unsaved changes`}
                    </p>
                    <div className="flex gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setDraft(copy(saved))}>
                            Discard
                        </button>
                        <button type="button" className="btn-primary" onClick={() => setConfirming(true)}>
                            <Icon name="save" /> Save changes
                        </button>
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={save}
                title="Save these permission changes?"
                message="People with these roles will see the change from the next page they open."
                confirmLabel={changes.length === 1 ? 'Save 1 change' : `Save ${changes.length} changes`}
                icon="admin_panel_settings"
                tone="primary"
                busyText="Saving…"
            >
                <ul className="mt-4 max-h-64 space-y-1.5 overflow-auto text-left text-sm">
                    {changes.map((c) => (
                        <li key={`${c.role}-${c.ability}`} className="flex gap-2">
                            <Icon
                                name={c.granted ? 'add' : 'remove'}
                                className={cn('mt-0.5 shrink-0', c.granted ? 'text-green-700' : 'text-red-700')}
                            />
                            <span>
                                <span className="font-semibold">{roles.find((r) => r.value === c.role)?.label}</span>{' '}
                                {c.granted ? 'can now' : 'can no longer'}:{' '}
                                {abilityLabel(c.ability).charAt(0).toLowerCase() + abilityLabel(c.ability).slice(1)}
                            </span>
                        </li>
                    ))}
                </ul>
            </ConfirmDialog>

            <ConfirmDialog
                open={resetting}
                onClose={() => setResetting(false)}
                onConfirm={() => router.post(route('roles.reset'), {}, { preserveScroll: true, onSuccess: () => setResetting(false) })}
                title="Reset every role to its default permissions?"
                message="Every role gets back the permissions the system started with. Retired roles stay retired."
                confirmLabel="Reset to defaults"
                icon="restart_alt"
                busyText="Resetting…"
            />

            <ConfirmDialog
                open={restoring !== null}
                onClose={() => setRestoring(null)}
                onConfirm={() => restoring && router.post(route('roles.restore', restoring.value), {}, { onSuccess: () => setRestoring(null) })}
                title={`Restore ${restoring?.label ?? ''}?`}
                message="The role can be given to accounts again, with the permissions shown in its column."
                confirmLabel="Restore role"
                icon="undo"
                tone="primary"
                busyText="Restoring…"
            />

            <RetireDialog role={retiring} roles={roles} onClose={() => setRetiring(null)} />
        </AppLayout>
    );
}

function RetireDialog({ role, roles, onClose }: { role: RoleColumn | null; roles: RoleColumn[]; onClose: () => void }) {
    const form = useForm({ move_to: '' });
    const targets = roles.filter((r) => r.value !== role?.value && !r.is_retired && r.value !== 'admin');

    useEffect(() => {
        if (role) form.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [role]);

    const submit = () => role && form.post(route('roles.retire', role.value), { preserveScroll: true, onSuccess: onClose });

    return (
        <Modal
            open={role !== null}
            onClose={onClose}
            title={`Retire ${role?.label ?? ''}?`}
            footer={
                <>
                    <button type="button" className="btn-secondary" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn-danger"
                        onClick={submit}
                        disabled={form.processing || (!!role?.accounts && !form.data.move_to)}
                    >
                        <BusyLabel busy={form.processing} busyText="Retiring…">
                            Retire {role?.label}
                        </BusyLabel>
                    </button>
                </>
            }
        >
            <div className="space-y-4 text-sm text-slate-700">
                <p>No account can be given this role any more. You can restore it later.</p>
                {role && role.accounts > 0 ? (
                    <Field
                        label={`Move its ${role.accounts === 1 ? 'account' : `${role.accounts} accounts`} to`}
                        required
                        error={form.errors.move_to}
                    >
                        {(p) => (
                            <SelectInput {...p} value={form.data.move_to} onChange={(e) => form.setData('move_to', e.target.value)}>
                                <option value="">Choose a role…</option>
                                {targets.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {r.label}
                                    </option>
                                ))}
                            </SelectInput>
                        )}
                    </Field>
                ) : (
                    <p className="text-slate-500">No account has this role, so nobody needs to move.</p>
                )}
            </div>
        </Modal>
    );
}
