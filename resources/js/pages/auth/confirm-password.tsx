import { Field, TextInput } from '@/components/form';
import GuestLayout from '@/layouts/guest-layout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset('password') });
    };

    return (
        <GuestLayout>
            <Head title="Confirm password" />
            <h2 className="text-primary text-3xl font-bold">Confirm your password</h2>
            <p className="mt-1.5 mb-7 text-sm text-slate-500">This is a secure area. Enter your password to continue.</p>
            <form onSubmit={submit} className="space-y-5">
                <Field label="Password" error={errors.password}>
                    {(p) => (
                        <TextInput
                            {...p}
                            type="password"
                            autoComplete="current-password"
                            autoFocus
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                    )}
                </Field>
                <button className="btn-primary w-full py-3.5 text-base" disabled={processing}>
                    Confirm
                </button>
            </form>
        </GuestLayout>
    );
}
