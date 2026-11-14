import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type LoginForm = {
    username: string;
    password: string;
    remember: boolean;
};

export default function Login({ status }: { status?: string }) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        username: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Sign in" description="Use the account given to you by the ICT Coordinator.">
            <Head title="Sign in" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-2">
                    <Label htmlFor="username">Username</Label>
                    <Input
                        id="username"
                        required
                        autoFocus
                        autoCapitalize="none"
                        autoComplete="username"
                        value={data.username}
                        onChange={(e) => setData('username', e.target.value)}
                    />
                    <InputError message={errors.username} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox id="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} />
                    <Label htmlFor="remember">Keep me signed in on this device</Label>
                </div>

                <Button type="submit" className="w-full bg-green-700 hover:bg-green-800" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Sign in
                </Button>

                <p className="text-muted-foreground text-center text-sm">Forgot your password? Ask the ICT Coordinator to reset it.</p>
            </form>

            {status && <div className="text-center text-sm font-medium text-green-600">{status}</div>}
        </AuthLayout>
    );
}
