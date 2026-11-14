import { Field, TextInput } from '@/components/form';
import SettingsLayout from '@/layouts/settings-layout';
import { type AuthedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Profile() {
    const { auth } = usePage<AuthedData>().props;
    const { data, setData, patch, errors, processing } = useForm({
        first_name: auth.user.first_name,
        last_name: auth.user.last_name,
        email: auth.user.email ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <SettingsLayout>
            <Head title="Profile" />
            <h2 className="text-lg font-semibold text-slate-900">Profile Information</h2>
            <p className="mb-6 text-sm text-slate-500">
                Signed in as <span className="font-mono">{auth.user.username}</span> ({auth.user.role_label}).
            </p>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="First Name" required error={errors.first_name}>
                        {(p) => (
                            <TextInput
                                {...p}
                                value={data.first_name}
                                onChange={(e) => setData('first_name', e.target.value)}
                                autoComplete="given-name"
                            />
                        )}
                    </Field>
                    <Field label="Last Name" required error={errors.last_name}>
                        {(p) => (
                            <TextInput
                                {...p}
                                value={data.last_name}
                                onChange={(e) => setData('last_name', e.target.value)}
                                autoComplete="family-name"
                            />
                        )}
                    </Field>
                </div>
                <Field label="Email (optional)" error={errors.email}>
                    {(p) => (
                        <TextInput {...p} type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} autoComplete="email" />
                    )}
                </Field>
                <button className="btn-primary" disabled={processing}>
                    Save
                </button>
            </form>
        </SettingsLayout>
    );
}
