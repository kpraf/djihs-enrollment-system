import { TextSizeControl } from '@/components/text-size-control';
import { Toasts } from '@/components/toasts';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

// The school's green, laid over the photo dark enough for white text to stay readable.
const greenShade = 'linear-gradient(150deg, rgba(14,74,28,0.86) 0%, rgba(11,61,23,0.90) 45%, rgba(7,42,17,0.95) 100%)';

/**
 * The legacy sign-in card: green branding panel on the left, the form on
 * the right. Text-size buttons sit below so staff can enlarge the screen
 * before signing in.
 */
export default function GuestLayout({ children }: { children: React.ReactNode }) {
    const photo = usePage<SharedData>().props.loginBackground;

    return (
        <div className="bg-background-light flex min-h-dvh flex-col items-center justify-center gap-6 p-4 sm:p-6">
            <div className="flex w-full max-w-5xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl md:flex-row">
                <div
                    className="relative flex flex-col p-8 text-white sm:p-10 md:w-1/2 lg:p-12"
                    style={{
                        backgroundColor: '#0b3d17',
                        backgroundImage: photo
                            ? `${greenShade}, url("${photo}")`
                            : 'radial-gradient(circle at 20% 15%, rgba(255,255,255,0.10), transparent 45%), radial-gradient(circle at 85% 90%, rgba(0,0,0,0.35), transparent 55%), linear-gradient(150deg, #0e4a1c 0%, #0b3d17 45%, #072a11 100%)',
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                    }}
                >
                    <div className="flex flex-grow flex-col items-center justify-center py-6 text-center">
                        <img
                            alt="Don Jose Integrated High School logo"
                            src="/images/djihs-logo-large.png"
                            className="mb-6 size-24 object-contain drop-shadow-md sm:size-28"
                        />
                        <p
                            className="font-serif text-5xl leading-none font-bold tracking-wide sm:text-6xl"
                            style={{ fontFamily: "Georgia, 'Times New Roman', serif" }}
                        >
                            DJIHS
                        </p>
                        <p className="mt-3 text-xs font-semibold tracking-[0.35em] text-white sm:text-sm">ENROLLMENT SYSTEM</p>
                        <p className="mt-8 max-w-xs text-sm leading-relaxed text-green-50/80 sm:text-base">
                            A digital solution for a faster, easier, and more efficient enrollment process.
                        </p>
                    </div>
                </div>

                <div className="flex flex-col justify-center p-8 sm:p-10 md:w-1/2 lg:p-12">{children}</div>
            </div>
            <TextSizeControl />
            <Toasts />
        </div>
    );
}
