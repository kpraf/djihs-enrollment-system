import { InputError } from '@/components/form';
import { Icon } from '@/components/icon';
import GuestLayout from '@/layouts/guest-layout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type LoginForm = {
    username: string;
    password: string;
    remember: boolean;
};

export default function Login() {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        username: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <GuestLayout>
            <Head title="Sign in" />

            <div className="mb-7">
                <h2 className="text-primary text-3xl font-bold">Welcome Back!</h2>
                <p className="mt-1.5 text-sm text-slate-500">Sign in to continue to the DJIHS Enrollment System</p>
            </div>

            <form className="space-y-5" onSubmit={submit} noValidate>
                <div>
                    <label htmlFor="username" className="mb-2 block text-sm font-semibold text-slate-700">
                        Username
                    </label>
                    <div className="relative">
                        <Icon name="person" className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-slate-400" />
                        <input
                            id="username"
                            className="field-input pl-11"
                            placeholder="Enter your username"
                            autoComplete="username"
                            autoCapitalize="none"
                            autoFocus
                            required
                            aria-invalid={Boolean(errors.username)}
                            value={data.username}
                            onChange={(e) => setData('username', e.target.value)}
                        />
                    </div>
                    <InputError message={errors.username} />
                </div>

                <div>
                    <label htmlFor="password" className="mb-2 block text-sm font-semibold text-slate-700">
                        Password
                    </label>
                    <div className="relative">
                        <Icon name="lock" className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-slate-400" />
                        <input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            className="field-input pr-12 pl-11"
                            placeholder="Enter your password"
                            autoComplete="current-password"
                            required
                            aria-invalid={Boolean(errors.password)}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((v) => !v)}
                            className="absolute top-1/2 right-3 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                        >
                            <Icon name={showPassword ? 'visibility_off' : 'visibility'} />
                        </button>
                    </div>
                    <InputError message={errors.password} />
                    <p className="mt-2 text-right text-sm text-slate-500">Forgot password? Ask the ICT Coordinator to reset it.</p>
                </div>

                <label className="flex items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" className="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                    Remember me
                </label>

                <button type="submit" disabled={processing} className="btn-primary w-full py-3.5 text-base">
                    {processing ? 'Signing in…' : 'Sign In'}
                    {!processing && <Icon name="arrow_forward" />}
                </button>

                <p className="pt-2 text-center text-xs text-slate-400">
                    © {new Date().getFullYear()} Don Jose Integrated High School. All rights reserved. ·{' '}
                    <a href={route('privacy.notice')} className="underline hover:text-slate-600">
                        Privacy Notice
                    </a>
                </p>
            </form>
        </GuestLayout>
    );
}
