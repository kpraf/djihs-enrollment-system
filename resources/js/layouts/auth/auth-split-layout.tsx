interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

/**
 * Split-screen sign-in carried over from the legacy login page:
 * a green school-branded panel on the left, the form on the right.
 */
export default function AuthSplitLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="grid min-h-dvh lg:grid-cols-2">
            <div className="relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-green-700 via-green-800 to-emerald-950 p-10 text-white lg:flex">
                <div className="flex items-center gap-3">
                    <img src="/images/djihs-logo-large.png" alt="" className="size-12 rounded-full bg-white object-contain p-0.5" />
                    <div className="leading-tight">
                        <p className="font-semibold">Don Jose Integrated High School</p>
                        <p className="text-sm text-green-100">Santa Rosa City, Laguna</p>
                    </div>
                </div>
                <div>
                    <p className="text-sm font-medium tracking-widest text-green-200 uppercase">DJIHS</p>
                    <h2 className="mt-2 text-4xl font-bold tracking-tight text-white">Enrollment System</h2>
                    <p className="mt-4 max-w-sm text-green-100">Encode learners, track daily enrollment and prepare school forms in one place.</p>
                </div>
                <p className="text-xs text-green-200">For authorized school staff only.</p>
            </div>

            <div className="flex items-center justify-center px-6 py-12">
                <div className="flex w-full max-w-sm flex-col gap-6">
                    <div className="flex items-center gap-3 lg:hidden">
                        <img src="/images/djihs-logo-large.png" alt="" className="size-10 rounded-full object-contain" />
                        <span className="font-semibold">DJIHS Enrollment System</span>
                    </div>
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                        <p className="text-muted-foreground text-sm">{description}</p>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
