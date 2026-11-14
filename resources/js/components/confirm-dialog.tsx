import { Icon } from './icon';
import { type IconName } from './icons';
import { Modal } from './modal';

interface ConfirmDialogProps {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: string;
    message: React.ReactNode;
    confirmLabel: string;
    icon?: IconName;
    tone?: 'danger' | 'primary';
    processing?: boolean;
    children?: React.ReactNode;
}

/**
 * The legacy logout-style confirmation: a round icon, a question, and two
 * equal buttons. Replaces the browser's plain confirm() boxes.
 */
export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    message,
    confirmLabel,
    icon = 'warning',
    tone = 'danger',
    processing,
    children,
}: ConfirmDialogProps) {
    return (
        <Modal open={open} onClose={onClose} size="sm" bare>
            <div className="flex flex-col items-center p-2 text-center">
                <div
                    className={
                        tone === 'danger'
                            ? 'mb-5 flex size-16 items-center justify-center rounded-full bg-red-50 text-red-500'
                            : 'text-primary mb-5 flex size-16 items-center justify-center rounded-full bg-green-50'
                    }
                >
                    <Icon name={icon} className="text-4xl" />
                </div>
                <h3 className="text-xl leading-tight font-bold text-slate-900">{title}</h3>
                <div className="mt-3 text-sm leading-relaxed text-slate-500">{message}</div>
            </div>
            {children}
            <div className="mt-8 flex flex-col gap-3 sm:flex-row-reverse">
                <button
                    type="button"
                    onClick={onConfirm}
                    disabled={processing}
                    className={tone === 'danger' ? 'btn-danger flex-1 py-3' : 'btn-primary flex-1 py-3'}
                    data-autofocus
                >
                    {confirmLabel}
                </button>
                <button type="button" onClick={onClose} className="btn-outline flex-1 py-3 font-bold">
                    Cancel
                </button>
            </div>
        </Modal>
    );
}
