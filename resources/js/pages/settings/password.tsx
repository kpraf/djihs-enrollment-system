import { Field, TextInput } from '@/components/form';
import { BusyLabel } from '@/components/spinner';
import SettingsLayout from '@/layouts/settings-layout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Password({ mustChangePassword }: { mustChangePassword: boolean }) {
    const { data, setData, errors, put, reset, processing } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <SettingsLayout>
            <Head title="Password" />
            <h2 className="text-lg font-semibold text-slate-900">Change Password</h2>
            <p className="mb-6 text-sm text-slate-500">Use at least 8 characters with both letters and numbers.</p>

            {mustChangePassword && (
                <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    You are using a temporary password. Choose your own password to continue.
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Field label={mustChangePassword ? 'Temporary Password' : 'Current Password'} required error={errors.current_password}>
                    {(p) => (
                        <TextInput
                            {...p}
                            type="password"
                            autoComplete="current-password"
                            value={data.current_password}
                            onChange={(e) => setData('current_password', e.target.value)}
                        />
                    )}
                </Field>
                <Field label="New Password" required error={errors.password}>
                    {(p) => (
                        <TextInput
                            {...p}
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                    )}
                </Field>
                <Field label="Confirm New Password" required error={errors.password_confirmation}>
                    {(p) => (
                        <TextInput
                            {...p}
                            type="password"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                        />
                    )}
                </Field>
                <button className="btn-primary" disabled={processing}>
                    <BusyLabel busy={processing} busyText="Saving…">
                        Save Password
                    </BusyLabel>
                </button>
            </form>
        </SettingsLayout>
    );
}
