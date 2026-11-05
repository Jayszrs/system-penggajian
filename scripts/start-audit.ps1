param(
    [string]$BackupPath = (Join-Path $env:USERPROFILE 'Downloads\GAJI-5.dat'),
    [ValidatePattern('^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,100}$')][string]$ContainerName = 'penggajian-sql-audit',
    [ValidateRange(0,65535)][int]$Port = 11433,
    [Alias('LegacyDir')][string]$RunDirectory,
    [ValidateRange(10,600)][int]$DockerTimeoutSeconds = 180
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
if (-not $RunDirectory) { $RunDirectory = Join-Path $projectRoot 'storage\legacy' }
$RunDirectory = [IO.Path]::GetFullPath($RunDirectory)
$resolvedBackup = (Resolve-Path -LiteralPath $BackupPath).Path
if (-not (Test-Path -LiteralPath $resolvedBackup -PathType Leaf)) { throw 'File backup tidak ditemukan.' }
$sourceHash = (Get-FileHash -LiteralPath $resolvedBackup -Algorithm SHA256).Hash.ToLowerInvariant()
New-Item -ItemType Directory -Force -Path $RunDirectory | Out-Null
$secretPath = Join-Path $RunDirectory 'sql-secret.json'
$dockerCommand = Get-Command docker -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
$dockerPath = if ($dockerCommand) { $dockerCommand.Source } else { Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe' }
if (-not (Test-Path -LiteralPath $dockerPath -PathType Leaf)) { $dockerPath = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe' }
if (-not (Test-Path -LiteralPath $dockerPath -PathType Leaf)) { throw 'Docker Desktop belum terpasang.' }

function Test-DockerReady {
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $dockerPath; $info.Arguments = 'info --format {{.ServerVersion}}'
    $info.UseShellExecute = $false; $info.CreateNoWindow = $true
    $info.RedirectStandardOutput = $true; $info.RedirectStandardError = $true
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $info
    try {
        [void]$process.Start()
        $stdout = $process.StandardOutput.ReadToEndAsync(); $stderr = $process.StandardError.ReadToEndAsync()
        if (-not $process.WaitForExit(5000)) { $process.Kill(); $process.WaitForExit(); return $false }
        return $process.ExitCode -eq 0 -and -not [string]::IsNullOrWhiteSpace($stdout.GetAwaiter().GetResult())
    } catch { return $false } finally { $process.Dispose() }
}

if (-not (Test-DockerReady)) {
    $desktopCandidates = @(
        (Join-Path (Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $dockerPath))) 'Docker Desktop.exe'),
        (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\Docker Desktop.exe'),
        (Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe')
    )
    $desktopPath = $desktopCandidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
    if (-not $desktopPath) { throw 'Docker Desktop tidak ditemukan.' }
    if (-not (Get-Process -Name 'Docker Desktop' -ErrorAction SilentlyContinue)) { Start-Process -FilePath $desktopPath -WindowStyle Hidden | Out-Null }
    Write-Output 'Menunggu Docker Desktop siap...'
    $deadline = [DateTime]::UtcNow.AddSeconds($DockerTimeoutSeconds)
    $ready = $false
    while ([DateTime]::UtcNow -lt $deadline) {
        if (Test-DockerReady) { $ready = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $ready) { throw "Docker belum siap setelah $DockerTimeoutSeconds detik. Data aplikasi belum diubah." }
}
$osType = & $dockerPath info --format '{{.OSType}}'
if ($LASTEXITCODE -ne 0 -or "$osType".Trim() -ne 'linux') { throw 'Audit SQL Server membutuhkan Docker dengan Linux containers.' }
$existing = & $dockerPath ps -a --filter ('name=^' + [Regex]::Escape($ContainerName) + '$') --format '{{.Names}}'
if ($LASTEXITCODE -ne 0) { throw 'Daftar container Docker tidak dapat diperiksa.' }
$created = $false
if ($existing) {
    if (-not (Test-Path -LiteralPath $secretPath)) { throw 'Container audit ada tetapi kredensial lokalnya tidak tersedia.' }
    $metadata = (& $dockerPath inspect $ContainerName | ConvertFrom-Json)[0]
    if ($LASTEXITCODE -ne 0) { throw 'Container audit tidak dapat diperiksa.' }
    $mount = @($metadata.Mounts | Where-Object { $_.Destination -eq '/backup/GAJI-5.dat' })
    if ($mount.Count -ne 1 -or $mount[0].RW -or -not [string]::Equals([string]$mount[0].Source, $resolvedBackup, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Container yang sudah ada tidak memasang file sumber yang sama secara hanya baca.'
    }
    & $dockerPath start $ContainerName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Container audit belum berhasil dijalankan.' }
} else {
    if (-not (Test-Path -LiteralPath $secretPath)) {
        $randomBytes = New-Object byte[] 36
        $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        try { $rng.GetBytes($randomBytes) } finally { $rng.Dispose() }
        $json = @{password = ('Mh!7' + [Convert]::ToBase64String($randomBytes))} | ConvertTo-Json
        [IO.File]::WriteAllText($secretPath, $json, [Text.UTF8Encoding]::new($false))
    }
    $previousPassword = [Environment]::GetEnvironmentVariable('MSSQL_SA_PASSWORD', 'Process')
    try {
        $env:MSSQL_SA_PASSWORD = (Get-Content -LiteralPath $secretPath -Raw -Encoding UTF8 | ConvertFrom-Json).password
        $publication = if ($Port -eq 0) { '127.0.0.1::1433' } else { '127.0.0.1:' + $Port + ':1433' }
        & $dockerPath run --detach --name $ContainerName --label 'mh.legacy.audit=true' --label "mh.legacy.source_sha256=$sourceHash" --label "mh.legacy.run_directory=$RunDirectory" --env ACCEPT_EULA=Y --env MSSQL_SA_PASSWORD --publish $publication --mount "type=bind,source=$resolvedBackup,target=/backup/GAJI-5.dat,readonly" mcr.microsoft.com/mssql/server:2022-latest | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'SQL Server audit belum berhasil dijalankan.' }
        $created = $true
    } finally { [Environment]::SetEnvironmentVariable('MSSQL_SA_PASSWORD', $previousPassword, 'Process') }
}
$mountedHash = & $dockerPath exec $ContainerName sha256sum /backup/GAJI-5.dat
if ($LASTEXITCODE -ne 0 -or ("$mountedHash" -split '\s+')[0] -ne $sourceHash) { throw 'Checksum backup yang terpasang pada container berbeda dari sumber.' }
$metadata = (& $dockerPath inspect $ContainerName | ConvertFrom-Json)[0]
if ($LASTEXITCODE -ne 0) { throw 'Port SQL Server audit tidak dapat diperiksa.' }
$bindings = @($metadata.NetworkSettings.Ports.'1433/tcp')
if ($bindings.Count -ne 1 -or $bindings[0].HostIp -ne '127.0.0.1') { throw 'Port audit tidak dibatasi ke loopback.' }
$state = [ordered]@{container_name = $ContainerName; port = [int]$bindings[0].HostPort; source_sha256 = $sourceHash; backup_path = $resolvedBackup; created_by_start = $created; run_directory = $RunDirectory}
[IO.File]::WriteAllText((Join-Path $RunDirectory 'container.json'), ($state | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
Write-Output ("Container audit siap: $ContainerName (127.0.0.1:" + $state.port + ').')
