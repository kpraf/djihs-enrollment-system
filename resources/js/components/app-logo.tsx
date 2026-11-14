export default function AppLogo() {
    return (
        <>
            <img src="/images/djihs-logo.png" alt="" className="size-8 shrink-0 rounded-full bg-white object-contain" />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-semibold">DJIHS Enrollment</span>
                <span className="text-muted-foreground truncate text-xs leading-tight">Don Jose Integrated HS</span>
            </div>
        </>
    );
}
