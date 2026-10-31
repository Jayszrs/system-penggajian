param([switch]$OpenBrowser, [string]$PhpPath)
$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $PSScriptRoot
if (-not $PhpPath) {
    $phpCommand = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($phpCommand) {
        $PhpPath = $phpCommand.Source
    } else {
        $laragonPath = Split-Path -Parent (Split-Path -Parent $projectPath)
        $phpCandidates = Get-ChildItem -Path (Join-Path $laragonPath 'bin\php\*\php.exe') -File -ErrorAction SilentlyContinue
        $PhpPath = ($phpCandidates | Sort-Object @{ Expression = { if ($_.Directory.Name -match '^php-(\d+\.\d+\.\d+)') { [version]$Matches[1] } else { [version]'0.0' } }; Descending = $true } | Select-Object -First 1).FullName
    }
}
$appUrl = 'http://127.0.0.1:8018'
if (-not $PhpPath -or -not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PHP tidak ditemukan. Gunakan terminal Laragon, atau berikan parameter -PhpPath ke php.exe.' }
if (-not (Test-Path -LiteralPath (Join-Path $projectPath '.env'))) { throw 'Konfigurasi .env belum tersedia.' }

$listener = Get-NetTCPConnection -LocalPort 8018 -State Listen -ErrorAction SilentlyContinue
if (-not $listener) {
    $server = Start-Process -FilePath $PhpPath -ArgumentList @('-S', '127.0.0.1:8018', '-t', ('"{0}"' -f $projectPath), ('"{0}\scripts\router.php"' -f $projectPath)) -WorkingDirectory $projectPath -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectPath 'storage/php-local.out.log') -RedirectStandardError (Join-Path $projectPath 'storage/php-local.error.log') -PassThru
    Set-Content -LiteralPath (Join-Path $projectPath 'storage/local-server.pid') -Value $server.Id
}
$ready = $false
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    try {
        $response = Invoke-WebRequest -Uri "$appUrl/setup" -UseBasicParsing -TimeoutSec 2
        if ($response.StatusCode -eq 200 -and $response.Content -match 'Mutiara Hikmah') { $ready = $true; break }
    } catch {}
    Start-Sleep -Milliseconds 300
}
if (-not $ready) { throw 'Aplikasi belum siap. Pastikan MySQL Laragon aktif, port 8018 tersedia, dan periksa storage/app.log serta storage/php-local.error.log.' }
Write-Output "Aplikasi siap: $appUrl"
if ($OpenBrowser) { Start-Process $appUrl }
