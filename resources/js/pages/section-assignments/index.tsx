import { CapacityBar } from '@/components/capacity-bar';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import { RowActionButton } from '@/components/row-action';
import { useReloading } from '@/hooks/use-reloading';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type Option } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Learner {
    id: number;
    name: string;
    lrn: string | null;
    sex: string;
    strand_id: number | null;
    strand: string | null;
}

interface SectionCard {
    id: number;
    name: string;
    strand_id: number | null;
    strand: string | null;
    adviser: string | null;
    capacity: number;
    is_active: boolean;
    learners: { id: number; name: string; sex: string }[];
}

/** Learners being dragged: from the unassigned list (from = null) or from a section. */
interface Drag {
    ids: number[];
    from: number | null;
    strandIds: (number | null)[];
    names: string[];
}

interface Props {
    schoolYear: { id: number; name: string } | null;
    unassigned: Learner[];
    sections: SectionCard[];
    filters: { grade: number; strand?: string; search?: string };
    options: { grades: Option<number>[]; strands: { id: number; code: string }[] };
}

export default function AssignStudents({ schoolYear, unassigned, sections, filters, options }: Props) {
    const reloading = useReloading();
    const [selected, setSelected] = useState<number[]>([]);
    const [target, setTarget] = useState<string>('');
    const [search, setSearch] = useState(filters.search ?? '');
    const [confirmAuto, setConfirmAuto] = useState(false);
    const [clearing, setClearing] = useState<SectionCard | null>(null);
    const [confirmPlace, setConfirmPlace] = useState(false);
    const [removing, setRemoving] = useState<{ id: number; name: string; section: string } | null>(null);
    const [busy, setBusy] = useState(false);
    // Drag and drop: what is being dragged, and which drop target it is over.
    const [drag, setDrag] = useState<Drag | null>(null);
    const [over, setOver] = useState<number | 'unassigned' | null>(null);
    const [moving, setMoving] = useState<{ ids: number[]; names: string[]; from: SectionCard; to: SectionCard } | null>(null);
    const first = useRef(true);

    const apply = (changes: Record<string, string | number | undefined>) =>
        router.get(
            '/section-assignments',
            Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== '' && v !== undefined)),
            {
                preserveState: true,
                replace: true,
            },
        );

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => apply({ search }), 400);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // Drop selections that are no longer in the list after a reload.
    useEffect(() => setSelected((s) => s.filter((id) => unassigned.some((u) => u.id === id))), [unassigned]);

    const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const done = { preserveScroll: true, preserveState: true, onFinish: () => setBusy(false) };

    const place = () => {
        setBusy(true);
        router.post(
            route('section-assignments.assign'),
            { enrollment_ids: selected, section_id: target },
            { ...done, onSuccess: () => setSelected([]), onFinish: () => (setBusy(false), setConfirmPlace(false)) },
        );
    };

    const targetSection = sections.find((s) => String(s.id) === target);
    const picked = unassigned.filter((u) => selected.includes(u.id));
    const wrongStrand = targetSection?.strand_id ? picked.filter((u) => u.strand_id !== targetSection.strand_id) : [];
    const seatsLeft = targetSection ? targetSection.capacity - targetSection.learners.length : 0;

    // A section takes the drop if it's active, has a free seat, isn't where the
    // learners already are, and is for their strand (or for no particular strand).
    const fits = (s: SectionCard, d: Drag) =>
        s.is_active && s.learners.length < s.capacity && d.from !== s.id && (!s.strand_id || d.strandIds.every((id) => id === s.strand_id));

    const startDrag = (e: React.DragEvent, d: Drag) => {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', d.names.join(', '));
        setDrag(d);
    };
    const endDrag = () => {
        setDrag(null);
        setOver(null);
    };
    const dropOnSection = (s: SectionCard) => {
        if (!drag || !fits(s, drag)) return endDrag();
        if (drag.from === null) {
            // Same confirmation as ticking learners and pressing Place.
            setSelected(drag.ids);
            setTarget(String(s.id));
            setConfirmPlace(true);
        } else {
            const from = sections.find((x) => x.id === drag.from);
            if (from) setMoving({ ids: drag.ids, names: drag.names, from, to: s });
        }
        endDrag();
    };
    const dropOnUnassigned = () => {
        const from = drag && drag.from !== null ? sections.find((x) => x.id === drag.from) : undefined;
        if (drag && from) setRemoving({ id: drag.ids[0], name: drag.names[0], section: from.name });
        endDrag();
    };

    return (
        <AppLayout>
            <Head title="Assign to Sections" />
            <PageHeader
                title="Assign to Sections"
                description={
                    schoolYear ? `Place enrolled learners into sections for school year ${schoolYear.name}.` : 'There is no active school year.'
                }
                actions={
                    <button
                        type="button"
                        className="btn-primary"
                        disabled={unassigned.length === 0 || sections.length === 0}
                        onClick={() => setConfirmAuto(true)}
                    >
                        <Icon name="bolt" /> Auto-Assign (FCFS)
                    </button>
                }
            />

            <div className="panel mb-6 flex flex-wrap items-center gap-3 p-4">
                <select
                    className="field-input-sm h-10 w-auto"
                    value={filters.grade}
                    onChange={(e) => apply({ grade: e.target.value })}
                    aria-label="Grade level"
                >
                    {options.grades.map((g) => (
                        <option key={g.value} value={g.value}>
                            {g.label}
                        </option>
                    ))}
                </select>
                <select
                    className="field-input-sm h-10 w-auto"
                    value={filters.strand ?? ''}
                    onChange={(e) => apply({ strand: e.target.value })}
                    aria-label="Strand"
                >
                    <option value="">All Strands</option>
                    {options.strands.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.code}
                        </option>
                    ))}
                </select>
                <input
                    type="search"
                    className="field-input-sm h-10 min-w-0 flex-1"
                    placeholder="Search name or LRN…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    aria-label="Search learners"
                />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section
                    className={cn(
                        'panel flex flex-col overflow-hidden transition duration-150',
                        reloading && 'pointer-events-none opacity-50',
                        drag?.from != null && 'outline-primary/50 outline-2 outline-offset-2 outline-dashed',
                        over === 'unassigned' && 'ring-primary bg-green-50/40 ring-2',
                    )}
                    aria-busy={reloading}
                    onDragOver={(e) => {
                        if (drag?.from == null) return;
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        setOver('unassigned');
                    }}
                    onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(null)}
                    onDrop={(e) => {
                        e.preventDefault();
                        dropOnUnassigned();
                    }}
                >
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">Unassigned Students ({unassigned.length})</h2>
                            <p className="text-xs text-slate-500">Drag a learner onto a section, or tick learners and choose a section below.</p>
                        </div>
                        <label className="flex items-center gap-2 text-sm text-slate-600">
                            <input
                                type="checkbox"
                                className="checkbox"
                                checked={unassigned.length > 0 && selected.length === unassigned.length}
                                onChange={(e) => setSelected(e.target.checked ? unassigned.map((u) => u.id) : [])}
                            />
                            Select all
                        </label>
                    </div>
                    <ul className="max-h-[32rem] divide-y divide-slate-100 overflow-y-auto">
                        {unassigned.map((u) => (
                            <li
                                key={u.id}
                                draggable
                                onDragStart={(e) => {
                                    // Dragging a ticked learner brings every ticked learner along.
                                    const ids = selected.includes(u.id) ? selected : [u.id];
                                    const rows = unassigned.filter((x) => ids.includes(x.id));
                                    startDrag(e, { ids, from: null, strandIds: rows.map((x) => x.strand_id), names: rows.map((x) => x.name) });
                                }}
                                onDragEnd={endDrag}
                                className={cn('cursor-grab active:cursor-grabbing', drag?.from === null && drag.ids.includes(u.id) && 'opacity-50')}
                            >
                                <label className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-slate-50">
                                    <Icon name="drag_indicator" className="-ml-1 hidden text-slate-300 sm:block" />
                                    <input type="checkbox" className="checkbox" checked={selected.includes(u.id)} onChange={() => toggle(u.id)} />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium text-slate-900">{u.name}</span>
                                        <span className="text-xs text-slate-500">
                                            {u.lrn} · {u.sex}
                                            {u.strand ? ` · ${u.strand}` : ''}
                                        </span>
                                    </span>
                                </label>
                            </li>
                        ))}
                        {unassigned.length === 0 && (
                            <li className="px-4 py-10 text-center text-sm text-slate-500">Everyone enrolled in this grade is in a section.</li>
                        )}
                    </ul>
                    <div className="mt-auto flex flex-wrap items-center gap-3 border-t border-slate-200 bg-slate-50 p-4">
                        <span className="text-sm text-slate-600">{selected.length} selected</span>
                        <select
                            className="field-input-sm h-10 min-w-0 flex-1"
                            value={target}
                            onChange={(e) => setTarget(e.target.value)}
                            aria-label="Section to place selected learners in"
                        >
                            <option value="">Choose a section…</option>
                            {sections
                                .filter((s) => s.is_active)
                                .map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                        {s.strand ? ` (${s.strand})` : ''} · {s.capacity - s.learners.length} seats left
                                    </option>
                                ))}
                        </select>
                        <button
                            type="button"
                            className="btn-primary"
                            disabled={busy || selected.length === 0 || !target}
                            onClick={() => setConfirmPlace(true)}
                        >
                            Place
                        </button>
                    </div>
                </section>

                <section
                    className={cn('space-y-4 transition-opacity duration-150', reloading && 'pointer-events-none opacity-50')}
                    aria-busy={reloading}
                >
                    {sections.length > 0 && (
                        <p className="text-xs text-slate-500">
                            Open a section to see its learners. Drag a learner to another section to move them, or back to Unassigned to take them
                            out.
                        </p>
                    )}
                    {sections.length === 0 && (
                        <div className="panel px-6 py-10 text-center text-sm text-slate-500">
                            No sections for this grade yet.{' '}
                            <Link href="/sections" className="text-primary font-medium hover:underline">
                                Create one in Sections
                            </Link>
                            .
                        </div>
                    )}
                    {sections.map((s) => (
                        <details
                            key={s.id}
                            className={cn(
                                'panel group overflow-hidden transition',
                                !s.is_active && 'opacity-60',
                                drag && fits(s, drag) && 'outline-primary/50 outline-2 outline-offset-2 outline-dashed',
                                over === s.id && drag && (fits(s, drag) ? 'ring-primary bg-green-50/60 ring-2' : 'bg-red-50/60 ring-2 ring-red-300'),
                            )}
                            onDragOver={(e) => {
                                if (!drag) return;
                                setOver(s.id);
                                if (!fits(s, drag)) return;
                                e.preventDefault();
                                e.dataTransfer.dropEffect = 'move';
                            }}
                            onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(null)}
                            onDrop={(e) => {
                                e.preventDefault();
                                dropOnSection(s);
                            }}
                        >
                            <summary className="flex cursor-pointer list-none flex-wrap items-center gap-4 p-4">
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold text-slate-900">
                                        {s.name}
                                        {s.strand && <span className="ml-2 text-sm font-normal text-slate-500">{s.strand}</span>}
                                    </p>
                                    <p className="text-xs text-slate-500">{s.adviser ? `Adviser: ${s.adviser}` : 'No adviser'}</p>
                                </div>
                                <CapacityBar filled={s.learners.length} capacity={s.capacity} />
                                <Icon name="keyboard_arrow_down" className="text-slate-400 transition-transform group-open:rotate-180" />
                            </summary>
                            <ul className="max-h-72 divide-y divide-slate-100 overflow-y-auto border-t border-slate-100">
                                {s.learners.map((l) => (
                                    <li
                                        key={l.id}
                                        draggable
                                        onDragStart={(e) => startDrag(e, { ids: [l.id], from: s.id, strandIds: [s.strand_id], names: [l.name] })}
                                        onDragEnd={endDrag}
                                        className={cn(
                                            'flex cursor-grab items-center justify-between gap-3 px-4 py-2 text-sm active:cursor-grabbing',
                                            drag?.from === s.id && drag.ids.includes(l.id) && 'opacity-50',
                                        )}
                                    >
                                        <span className="flex min-w-0 items-center gap-2 truncate">
                                            <Icon name="drag_indicator" className="hidden shrink-0 text-slate-300 sm:block" />
                                            {l.name} <span className="text-xs text-slate-400">{l.sex}</span>
                                        </span>
                                        <RowActionButton
                                            icon="person_remove"
                                            text="Remove"
                                            srSuffix={`${l.name} from ${s.name}`}
                                            tone="danger"
                                            className="px-2 py-1"
                                            onClick={() => setRemoving({ id: l.id, name: l.name, section: s.name })}
                                        />
                                    </li>
                                ))}
                                {s.learners.length === 0 && <li className="px-4 py-3 text-sm text-slate-500">No learners yet.</li>}
                            </ul>
                            {s.learners.length > 0 && (
                                <div className="border-t border-slate-100 p-3 text-right">
                                    <button type="button" className="text-sm font-medium text-red-600 hover:underline" onClick={() => setClearing(s)}>
                                        Clear section
                                    </button>
                                </div>
                            )}
                        </details>
                    ))}
                </section>
            </div>

            <ConfirmDialog
                open={confirmAuto}
                onClose={() => setConfirmAuto(false)}
                onConfirm={() => {
                    setBusy(true);
                    router.post(
                        route('section-assignments.auto'),
                        { grade: filters.grade, strand: filters.strand },
                        { ...done, onSuccess: () => setConfirmAuto(false) },
                    );
                }}
                processing={busy}
                title="Auto-assign learners?"
                message="Learners are placed first come, first served, into the first section of their strand that still has room. Learners already in a section are not moved."
                confirmLabel="Auto-Assign"
                icon="bolt"
                tone="primary"
            />
            <ConfirmDialog
                open={clearing !== null}
                onClose={() => setClearing(null)}
                onConfirm={() =>
                    clearing && router.delete(route('section-assignments.clear', clearing.id), { ...done, onSuccess: () => setClearing(null) })
                }
                title={`Clear ${clearing?.name ?? ''}?`}
                message={`All ${clearing?.learners.length ?? 0} learners go back to the unassigned list.`}
                confirmLabel="Clear Section"
                icon="person_remove"
            />
            <ConfirmDialog
                open={confirmPlace}
                onClose={() => setConfirmPlace(false)}
                onConfirm={place}
                processing={busy}
                title={`Place ${picked.length} learner${picked.length === 1 ? '' : 's'} in ${targetSection?.name ?? ''}?`}
                message={
                    <>
                        {picked.length <= 5
                            ? picked.map((u) => u.name).join(', ')
                            : `${picked
                                  .slice(0, 5)
                                  .map((u) => u.name)
                                  .join(', ')} and ${picked.length - 5} more`}
                        {targetSection?.strand ? ` will join this ${targetSection.strand} section.` : ' will join this section.'}
                        {wrongStrand.length > 0 && (
                            <span className="mt-3 block rounded-lg bg-amber-50 p-3 text-left font-medium text-amber-800">
                                {wrongStrand.length} of them {wrongStrand.length === 1 ? 'is' : 'are'} not in {targetSection?.strand} and will not be
                                placed: {wrongStrand.map((u) => u.name).join(', ')}.
                            </span>
                        )}
                        {picked.length - wrongStrand.length > seatsLeft && (
                            <span className="mt-3 block rounded-lg bg-amber-50 p-3 text-left font-medium text-amber-800">
                                Only {seatsLeft} seat{seatsLeft === 1 ? '' : 's'} left. Learners beyond that will not be placed.
                            </span>
                        )}
                    </>
                }
                confirmLabel="Yes, place them"
                icon="group_add"
                tone="primary"
            />
            <ConfirmDialog
                open={moving !== null}
                onClose={() => setMoving(null)}
                onConfirm={() => {
                    if (!moving) return;
                    setBusy(true);
                    router.post(
                        route('section-assignments.assign'),
                        { enrollment_ids: moving.ids, section_id: moving.to.id },
                        { ...done, onFinish: () => (setBusy(false), setMoving(null)) },
                    );
                }}
                processing={busy}
                title={`Move ${moving?.names.join(', ') ?? ''} to ${moving?.to.name ?? ''}?`}
                message={
                    moving && moving.to.capacity - moving.to.learners.length < moving.ids.length
                        ? `${moving.to.name} is full, so the learner will stay in ${moving.from.name}.`
                        : `From ${moving?.from.name ?? ''} to ${moving?.to.name ?? ''}. The learner stays enrolled; only the section changes.`
                }
                confirmLabel="Yes, move"
                icon="group_add"
                tone="primary"
            />
            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (!removing) return;
                    setBusy(true);
                    router.delete(route('section-assignments.unassign', removing.id), { ...done, onSuccess: () => setRemoving(null) });
                }}
                processing={busy}
                title={`Remove ${removing?.name ?? ''} from ${removing?.section ?? ''}?`}
                message="The learner stays enrolled and goes back to the unassigned list, so you can place them in another section."
                confirmLabel="Yes, remove"
                icon="person_remove"
            />
        </AppLayout>
    );
}
