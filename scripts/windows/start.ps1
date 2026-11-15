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

# Prefer Laravel Herd's PHP over an older one, such as XAMPP's PHP 8.2, for this window only.
$herd = @("$env:USERPROFILE\.config\herd\bin", "$env:USERPROFILE\.config\herd-lite\bin") | Where-Object { Test-Path $_ }
foreach ($dir in $herd) { $env:Path = "$dir;$env:Path" }

function Fail($message) {
    Write-Host $message -ForegroundColor Red
    exit 1
}

$php = $null
try { $php = (& php -r "echo PHP_VERSION;" 2>$null) } catch { }
if (-not $php) { Fail "PHP was not found. Install Laravel Herd (herd.laravel.com), open it once, then run this again." }
if ([version]($php -replace '[^0-9.].*$', '') -lt [version]'8.3') {
    Fail "PHP $php was found, but 8.3 or newer is needed. Open the Laravel Herd app once so it installs PHP, then run this again."
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
}

if ($Network) {
    $ip = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.PrefixOrigin -ne 'WellKnown' } | Select-Object -First 1).IPAddress
    Write-Host "Starting. On this computer: http://localhost:8000   On others: http://${ip}:8000   (Ctrl+C stops it)" -ForegroundColor Green
    composer run start:network
} else {
    Write-Host 'Starting at http://localhost:8000   (Ctrl+C stops it)' -ForegroundColor Green
    composer run start
}
