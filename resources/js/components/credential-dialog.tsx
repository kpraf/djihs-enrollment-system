import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Icon } from './icon';
import { Modal } from './modal';

/**
 * Shows a temporary password once, right after an account is created or
 * reset. It is never shown again, so the dialog stays until dismissed.
 */
export function CredentialDialog() {
    const { flash } = usePage<SharedData>().props;
    const [shown, setShown] = useState(flash.credential);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        setShown(flash.credential);
        setCopied(false);
    }, [flash.credential]);

    if (!shown) return null;

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(shown.password);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <Modal open onClose={() => setShown(null)} size="sm" title={shown.reason === 'created' ? 'Account created' : 'Password reset'}>
            <p className="text-sm text-slate-600">
                Give these sign-in details to the staff member. The password is shown only once. They will be asked to choose their own password when
                they sign in.
            </p>
            <dl className="mt-4 space-y-3 rounded-lg border border-blue-100 bg-blue-50 p-4">
                <div>
                    <dt className="text-xs font-medium text-slate-500 uppercase">Username</dt>
                    <dd className="font-mono text-lg text-slate-900">{shown.username}</dd>
                </div>
                <div>
                    <dt className="text-xs font-medium text-slate-500 uppercase">Temporary password</dt>
                    <dd className="flex items-center gap-2">
                        <span className="font-mono text-lg tracking-wider text-slate-900 select-all" data-testid="temporary-password">
                            {shown.password}
                        </span>
                        <button type="button" onClick={copy} className="btn-outline px-2 py-1 text-xs">
                            <Icon name="content_copy" /> {copied ? 'Copied' : 'Copy'}
                        </button>
                    </dd>
                </div>
            </dl>
            <button type="button" className="btn-primary mt-6 w-full py-3" onClick={() => setShown(null)} data-autofocus>
                Done
            </button>
        </Modal>
    );
}
