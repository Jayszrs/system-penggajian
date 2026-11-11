param([string]$LaragonPath, [switch]$OpenBrowser)
$ErrorActionPreference = 'Stop'
$projectPath = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$siteName = 'system-penggajian'
$appUrl = "http://localhost/$siteName/"

if (-not (Test-Path -LiteralPath (Join-Path $projectPath '.env') -PathType Leaf)) {
    throw 'File .env belum tersedia. Salin .env.example menjadi .env dan siapkan database sesuai README.md.'
}

if ($LaragonPath) {
    $candidates = @($LaragonPath)
} else {
    $runningLaragon = Get-Process laragon -ErrorAction SilentlyContinue | Select-Object -First 1
    $candidates = @(
        $(if ($runningLaragon -and $runningLaragon.Path) { Split-Path -Parent $runningLaragon.Path })
        (Split-Path -Parent (Split-Path -Parent $projectPath))
        'C:\laragon'
        'D:\laragon'
    )
}
$laragonRoot = $candidates | Where-Object {
    $_ -and (Test-Path -LiteralPath (Join-Path $_ 'laragon.exe') -PathType Leaf) -and
    (Test-Path -LiteralPath (Join-Path $_ 'www') -PathType Container)
} | Select-Object -First 1
if (-not $laragonRoot) {
    throw 'Laragon tidak ditemukan. Jalankan skrip ini dengan -LaragonPath "D:\lokasi\laragon".'
}

$wwwPath = [IO.Path]::GetFullPath((Join-Path $laragonRoot 'www')).TrimEnd('\')
$sitePath = [IO.Path]::GetFullPath((Join-Path $wwwPath $siteName))
if ((Split-Path -Parent $sitePath) -ne $wwwPath) {
    throw 'Lokasi tautan harus berada langsung di dalam folder www Laragon.'
}
if ($sitePath -ne $projectPath) {
    $existing = Get-Item -LiteralPath $sitePath -Force -ErrorAction SilentlyContinue
    if ($existing) {
        if ($existing.LinkType -ne 'Junction') {
            throw "Folder $sitePath sudah dipakai. Pindahkan instalasi lama secara manual sebelum menjalankan launcher."
        }
        $targetPath = [IO.Path]::GetFullPath([string]$existing.Target[0]).TrimEnd('\')
        if ($targetPath -ne $projectPath.TrimEnd('\')) {
            if (Test-Path -LiteralPath $targetPath) {
                throw "Tautan $sitePath masih dipakai proyek lain: $targetPath. Periksa instalasi lama terlebih dahulu."
            }
            # Hapus junction yang putus saja, tanpa menghapus folder proyek tujuannya.
            [IO.Directory]::Delete($sitePath, $false)
            $existing = $null
        }
    }
    if (-not $existing) {
        New-Item -ItemType Junction -Path $sitePath -Target $projectPath | Out-Null
    }
}
Write-Output "Folder Laragon: $sitePath"
Write-Output "Folder proyek : $projectPath"

try {
    $response = Invoke-WebRequest -Uri $appUrl -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -ne 200 -or $response.Content -notmatch 'Mutiara') {
        throw 'Halaman aplikasi tidak terdeteksi.'
    }
} catch {
    throw "Tautan sudah siap. Klik Start All di Laragon, lalu buka $appUrl Jika masih gagal, periksa koneksi database .env dan storage/app.log. Detail: $($_.Exception.Message)"
}
Write-Output "Aplikasi siap: $appUrl"
Write-Output 'Berikutnya cukup Start All di Laragon dan buka alamat tersebut. Database tidak diimpor atau direset oleh launcher.'
if ($OpenBrowser) { Start-Process $appUrl }
