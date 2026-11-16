# Runs the DJIHS Enrollment System on a Windows computer.
#
# From the project folder:
#   powershell -ExecutionPolicy Bypass -File scripts\windows\start.ps1
#
# The first run installs everything and creates the database with demo data.
# Later runs just start the app at http://localhost:8000.
# Add -Network to let other computers on the same network open it.
param([switch]$Network)

$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..\..")

# Prefer a newer PHP (Laravel Herd's) over an older one, such as XAMPP's 8.2, for this window only.
# Herd keeps PHP in different places depending on its version, so look in all of them
# and use the newest php.exe that is 8.3 or later.
$places = @(
    "$env:USERPROFILE\.config\herd\bin",
    "$env:USERPROFILE\.config\herd-lite\bin",
    "$env:USERPROFILE\.config\herd",
    "$env:LOCALAPPDATA\Programs\Herd",
    "$env:ProgramFiles\Herd",
    "C:\php"
) | Where-Object { Test-Path $_ }
$best = $null
foreach ($place in $places) {
    foreach ($exe in Get-ChildItem -Path $place -Recurse -Filter php.exe -ErrorAction SilentlyContinue) {
        try { $v = (& $exe.FullName -r "echo PHP_VERSION;" 2>$null) } catch { continue }
        if (-not $v) { continue }
        $ver = [version]($v -replace '[^0-9.].*$', '')
        if ($ver -ge [version]'8.3' -and (-not $best -or $ver -gt $best.Version)) { $best = @{ Version = $ver; Dir = $exe.DirectoryName } }
    }
}
if ($best) {
    $env:Path = "$($best.Dir);$env:Path"
} elseif (Test-Path "$env:USERPROFILE\.config\herd\bin") {
    $env:Path = "$env:USERPROFILE\.config\herd\bin;$env:Path"
}

function Fail($message) {
    Write-Host $message -ForegroundColor Red
    exit 1
}

$php = $null
try { $php = (& php -r "echo PHP_VERSION;" 2>$null) } catch { }
if (-not $php) { Fail "PHP was not found. Install Laravel Herd (herd.laravel.com), open it once, then run this again." }
if ([version]($php -replace '[^0-9.].*$', '') -lt [version]'8.3') {
    Fail ("PHP $php was found, but 8.3 or newer is needed, and no newer PHP was found in Herd's folders.`n" +
          "Run the Herd installer if you only downloaded it, open the Herd app, and in Settings > PHP install PHP 8.4. Then run this again.")
}
Write-Host "Using PHP $php" -ForegroundColor Green

foreach ($tool in 'composer', 'node', 'npm') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        Fail "$tool was not found. Install it (Composer comes with Herd; Node from nodejs.org), open a new window, then run this again."
    }
}

$ready = (Test-Path 'vendor\autoload.php') -and (Test-Path 'public\build\manifest.json') -and (Test-Path 'database\database.sqlite')
if (-not $ready) {
    Write-Host 'First run: installing and setting up. This takes a few minutes.' -ForegroundColor Green
    composer run setup
    if ($LASTEXITCODE -ne 0) { Fail 'Setup stopped with an error. Copy the messages above and ask for help.' }
} else {
    # After a "git pull", bring this copy up to date: only the steps whose
    # files changed run, so a normal start stays quick.
    function Newer($file, $than) { (Test-Path $file) -and ((-not (Test-Path $than)) -or ((Get-Item $file).LastWriteTime -gt (Get-Item $than).LastWriteTime)) }

    if (Newer 'composer.lock' 'vendor\autoload.php') {
        Write-Host 'Updating PHP packages...' -ForegroundColor Green
        composer install --no-interaction
        if ($LASTEXITCODE -ne 0) { Fail 'Updating PHP packages failed. Copy the messages above and ask for help.' }
    }

    $nodeStamp = 'node_modules\.package-lock.json'
    $packagesChanged = Newer 'package-lock.json' $nodeStamp
    if ($packagesChanged) {
        Write-Host 'Updating JavaScript packages...' -ForegroundColor Green
        npm install
        if ($LASTEXITCODE -ne 0) { Fail 'Updating JavaScript packages failed. Copy the messages above and ask for help.' }
    }

    $built = (Get-Item 'public\build\manifest.json').LastWriteTime
    $sources = Get-ChildItem -Path 'resources\js', 'resources\css', 'vite.config.js', 'package.json' -Recurse -File -ErrorAction SilentlyContinue
    if ($packagesChanged -or ($sources | Where-Object { $_.LastWriteTime -gt $built } | Select-Object -First 1)) {
        Write-Host 'Building the updated screens...' -ForegroundColor Green
        npm run build
        if ($LASTEXITCODE -ne 0) { Fail 'Building failed. Copy the messages above and ask for help.' }
    }

    # New database changes, if any; does nothing when there are none.
    php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { Fail 'Updating the database failed. Copy the messages above and ask for help.' }
    # Forget settings saved under an old folder location.
    php artisan optimize:clear | Out-Null
}

if ($Network) {
    $ip = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.PrefixOrigin -ne 'WellKnown' } | Select-Object -First 1).IPAddress
    Write-Host "Starting. On this computer: http://localhost:8000   On others: http://${ip}:8000   (Ctrl+C stops it)" -ForegroundColor Green
    composer run start:network
} else {
    Write-Host 'Starting at http://localhost:8000   (Ctrl+C stops it)' -ForegroundColor Green
    composer run start
}
