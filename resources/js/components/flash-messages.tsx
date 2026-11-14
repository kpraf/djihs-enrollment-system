import { Alert, AlertDescription } from '@/components/ui/alert';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export function FlashMessages() {
    const { flash } = usePage<SharedData>().props;
    const [dismissed, setDismissed] = useState(false);

    useEffect(() => setDismissed(false), [flash.success, flash.error]);

    if (dismissed || (!flash.success && !flash.error)) {
        return null;
    }

    const isError = Boolean(flash.error);

    return (
        <div className="px-4 pt-4">
            <Alert variant={isError ? 'destructive' : 'default'} className="pr-10">
                {isError ? <CircleAlert className="size-4" /> : <CircleCheck className="size-4 text-emerald-600" />}
                <AlertDescription className="select-all">{flash.error ?? flash.success}</AlertDescription>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    className="absolute top-3 right-3 opacity-60 hover:opacity-100"
                    aria-label="Dismiss"
                >
                    <X className="size-4" />
                </button>
            </Alert>
        </div>
    );
}
