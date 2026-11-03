param(
    [string]$BackupPath = (Join-Path $env:USERPROFILE 'Downloads\GAJI-5.dat'),
    [string]$PhpPath,
    [ValidatePattern('^[a-zA-Z0-9_]+$')][string]$TargetDatabase,
    [switch]$CheckOnly,
    [ValidateRange(10,600)][int]$DockerTimeoutSeconds = 180,
    [ValidateRange(10,600)][int]$SqlTimeoutSeconds = 180
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot '.env') -PathType Leaf)) { throw 'Konfigurasi .env belum tersedia. Target migrasi belum dapat dipastikan.' }
$resolvedBackup = (Resolve-Path -LiteralPath $BackupPath).Path
if (-not (Test-Path -LiteralPath $resolvedBackup -PathType Leaf)) { throw 'File backup SQL Server tidak ditemukan.' }
if (-not $PhpPath) {
    $phpCommand = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($phpCommand) { $PhpPath = $phpCommand.Source } else {
        $laragonPath = Split-Path -Parent (Split-Path -Parent $projectRoot)
        $candidates = Get-ChildItem -Path (Join-Path $laragonPath 'bin\php\*\php.exe') -File -ErrorAction SilentlyContinue
        $PhpPath = ($candidates | Sort-Object @{Expression = { if ($_.Directory.Name -match '^php-(\d+\.\d+\.\d+)') { [version]$Matches[1] } else { [version]'0.0' } }; Descending = $true} | Select-Object -First 1).FullName
    }
}
if (-not $PhpPath -or -not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PHP tidak ditemukan. Gunakan terminal Laragon atau parameter -PhpPath ke php.exe.' }
$hash = (Get-FileHash -LiteralPath $resolvedBackup -Algorithm SHA256).Hash.ToLowerInvariant()
$runId = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ') + '-' + [Guid]::NewGuid().ToString('N').Substring(0,8)
$runDirectory = Join-Path (Join-Path (Join-Path $projectRoot 'storage\legacy') $hash) $runId
New-Item -ItemType Directory -Force -Path $runDirectory | Out-Null
$containerName = 'penggajian-audit-' + $hash.Substring(0,12) + '-' + $runId.ToLowerInvariant()
$auditDatabase = 'gaji_audit_' + [Guid]::NewGuid().ToString('N')
$exportPath = Join-Path $runDirectory 'export.json'
$exitCode = 1
try {
    Write-Output 'Memeriksa backup SQL Server dan menyiapkan proses migrasi terisolasi.'
    & (Join-Path $PSScriptRoot 'start-audit.ps1') -BackupPath $resolvedBackup -ContainerName $containerName -Port 0 -RunDirectory $runDirectory -DockerTimeoutSeconds $DockerTimeoutSeconds
    $state = Get-Content -LiteralPath (Join-Path $runDirectory 'container.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    & (Join-Path $PSScriptRoot 'restore-legacy.ps1') -BackupPath $resolvedBackup -DatabaseName $auditDatabase -ContainerName $containerName -Port ([int]$state.port) -RunDirectory $runDirectory -OutputPath $exportPath -ReadyTimeoutSeconds $SqlTimeoutSeconds
    $arguments = @((Join-Path $PSScriptRoot 'migrate-legacy.php'), $exportPath, ('--run-dir=' + $runDirectory))
    if ($TargetDatabase) { $arguments += '--database=' + $TargetDatabase }
    if ($CheckOnly) { $arguments += '--check' }
    & $PhpPath @arguments
    $exitCode = $LASTEXITCODE
    if ($exitCode -eq 2) { Write-Warning 'Rekonsiliasi selesai dengan masalah pemetaan yang dicatat otomatis pada laporan privat.' }
    elseif ($exitCode -ne 0) { throw "Migrasi melaporkan kegagalan (kode $exitCode). Periksa laporan lokal: $runDirectory" }
    Write-Output ('Bukti backup, ekspor, dan rekonsiliasi disimpan lokal: ' + $runDirectory)
} finally {
    # Only this invocation's unique, labelled container is stopped. Other workloads remain untouched.
    $dockerCommand = Get-Command docker -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    $dockerPath = if ($dockerCommand) { $dockerCommand.Source } else { Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe' }
    if (-not (Test-Path -LiteralPath $dockerPath -PathType Leaf)) { $dockerPath = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe' }
    if (Test-Path -LiteralPath $dockerPath -PathType Leaf) {
        try {
            $owned = & $dockerPath ps -a --filter ('name=^' + [Regex]::Escape($containerName) + '$') --filter ('label=mh.legacy.run_directory=' + $runDirectory) --format '{{.Names}}'
            if ($LASTEXITCODE -eq 0 -and $owned -eq $containerName) {
                & $dockerPath stop --time 15 $containerName | Out-Null
                if ($LASTEXITCODE -ne 0) { Write-Warning "Container audit proses ini belum berhasil dihentikan: $containerName" }
            }
        } catch { Write-Warning 'Status akhir container audit tidak dapat diperiksa; container lain tidak diubah.' }
    }
}
exit $exitCode
