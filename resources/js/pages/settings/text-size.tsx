import SettingsLayout from '@/layouts/settings-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

export default function TextSize() {
    const { textSize } = usePage<SharedData>().props;

    const choose = (value: string, percent: number) => {
        document.documentElement.style.fontSize = `${percent}%`;
        router.put(route('text-size.update'), { text_size: value }, { preserveScroll: true });
    };

    return (
        <SettingsLayout>
            <Head title="Text Size" />
            <h2 className="text-lg font-semibold text-slate-900">Text Size</h2>
            <p className="mb-6 text-sm text-slate-500">
                Make every page larger and easier to read. Your choice is saved to your account and used on any computer you sign in from.
            </p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3" role="radiogroup" aria-label="Text size">
                {textSize.options.map((o) => (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={textSize.value === o.value}
                        onClick={() => choose(o.value, o.percent)}
                        className={cn(
                            'flex flex-col items-center gap-2 rounded-xl border-2 p-5 transition-colors',
                            textSize.value === o.value ? 'border-primary bg-green-50' : 'border-slate-200 hover:border-slate-300',
                        )}
                    >
                        <span className="font-bold text-slate-900" style={{ fontSize: `${(o.percent / 100) * 1.5}rem` }}>
                            Aa
                        </span>
                        <span className="text-sm font-medium text-slate-700">{o.label}</span>
                    </button>
                ))}
            </div>
            <p className="mt-6 text-sm text-slate-500">Tip: the A A A buttons at the bottom of the menu change this from any page.</p>
        </SettingsLayout>
    );
}
