param([string]$BackupPath=(Join-Path $env:USERPROFILE 'Downloads\GAJI-5.dat'))
$ErrorActionPreference='Stop'
$resolvedBackup=(Resolve-Path -LiteralPath $BackupPath).Path
$projectRoot=Split-Path -Parent $PSScriptRoot
$legacyDir=Join-Path $projectRoot 'storage\legacy'
New-Item -ItemType Directory -Force -Path $legacyDir | Out-Null
$secretPath=Join-Path $legacyDir 'sql-secret.json'
docker info --format '{{.ServerVersion}}' | Out-Null
if($LASTEXITCODE -ne 0){throw 'Jalankan Docker Desktop terlebih dahulu.'}
$existing=docker ps -a --filter 'name=^penggajian-sql-audit$' --format '{{.Names}}'
if($existing){
    if(!(Test-Path -LiteralPath $secretPath)){throw 'Container audit ada tetapi konfigurasi kredensial lokal tidak ditemukan.'}
    $metadata=docker inspect penggajian-sql-audit | ConvertFrom-Json
    $backupMount=$metadata[0].Mounts | Where-Object {$_.Destination -eq '/backup/GAJI-5.dat'}
    if(!$backupMount -or $backupMount.RW){throw 'Container audit tidak menggunakan mount backup hanya baca yang diharapkan.'}
    docker start penggajian-sql-audit
    exit $LASTEXITCODE
}
if(!(Test-Path -LiteralPath $secretPath)){
    $randomBytes=New-Object byte[] 30
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($randomBytes)
    @{password=('Mh!7'+[Convert]::ToBase64String($randomBytes))} | ConvertTo-Json | Set-Content -LiteralPath $secretPath -Encoding UTF8
}
$env:MSSQL_SA_PASSWORD=(Get-Content -LiteralPath $secretPath -Raw | ConvertFrom-Json).password
docker run --detach --name penggajian-sql-audit --env ACCEPT_EULA=Y --env MSSQL_SA_PASSWORD --publish 127.0.0.1:11433:1433 --mount "type=bind,source=$resolvedBackup,target=/backup/GAJI-5.dat,readonly" --mount 'type=volume,source=penggajian-sql-audit-data,target=/var/opt/mssql' mcr.microsoft.com/mssql/server:2022-latest
if($LASTEXITCODE -ne 0){throw 'SQL Server audit belum berhasil dijalankan.'}
Write-Output 'Tunggu SQL Server siap, lalu jalankan scripts/restore-legacy.ps1. Backup dipasang hanya baca.'
