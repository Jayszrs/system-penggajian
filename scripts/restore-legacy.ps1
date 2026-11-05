param(
    [string]$BackupPath = (Join-Path $env:USERPROFILE 'Downloads\GAJI-5.dat'),
    [string]$Server,
    [ValidatePattern('^[a-zA-Z0-9_]{1,100}$')][string]$DatabaseName,
    [ValidatePattern('^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,100}$')][string]$ContainerName = 'penggajian-sql-audit',
    [ValidateRange(1,65535)][int]$Port = 11433,
    [Alias('LegacyDir')][string]$RunDirectory,
    [string]$OutputPath,
    [ValidateRange(10,600)][int]$ReadyTimeoutSeconds = 180
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
if (-not $RunDirectory) { $RunDirectory = Join-Path $projectRoot 'storage\legacy' }
$RunDirectory = [IO.Path]::GetFullPath($RunDirectory)
New-Item -ItemType Directory -Force -Path $RunDirectory | Out-Null
if (-not $OutputPath) { $OutputPath = Join-Path $RunDirectory 'export.json' }
$OutputPath = [IO.Path]::GetFullPath($OutputPath)
if (-not $DatabaseName) { $DatabaseName = 'gaji_legacy_' + [Guid]::NewGuid().ToString('N').Substring(0,16) }
$statePath = Join-Path $RunDirectory 'container.json'
if (Test-Path -LiteralPath $statePath) {
    $state = Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($state.container_name -ne $ContainerName) { throw 'Metadata container audit tidak cocok.' }
    if (-not $PSBoundParameters.ContainsKey('Port')) { $Port = [int]$state.port }
}
if (-not $Server) { $Server = "127.0.0.1,$Port" }
$dockerCommand = Get-Command docker -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
$dockerPath = if ($dockerCommand) { $dockerCommand.Source } else { Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe' }
if (-not (Test-Path -LiteralPath $dockerPath -PathType Leaf)) { $dockerPath = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe' }
if (-not (Test-Path -LiteralPath $dockerPath -PathType Leaf)) { throw 'Docker Desktop belum terpasang.' }
$resolvedBackup = (Resolve-Path -LiteralPath $BackupPath).Path
$hash = (Get-FileHash -LiteralPath $resolvedBackup -Algorithm SHA256).Hash.ToLowerInvariant()
if ($state -and $state.source_sha256 -ne $hash) { throw 'File sumber berubah sesudah container audit disiapkan.' }
$secret = Get-Content -LiteralPath (Join-Path $RunDirectory 'sql-secret.json') -Raw -Encoding UTF8 | ConvertFrom-Json
if (-not $secret.password) { throw 'Kredensial audit lokal belum tersedia.' }
$metadata = (& $dockerPath inspect $ContainerName | ConvertFrom-Json)[0]
if ($LASTEXITCODE -ne 0) { throw 'Container audit tidak ditemukan.' }
$mount = @($metadata.Mounts | Where-Object { $_.Destination -eq '/backup/GAJI-5.dat' })
if ($mount.Count -ne 1 -or $mount[0].RW -or -not [string]::Equals([string]$mount[0].Source, $resolvedBackup, [StringComparison]::OrdinalIgnoreCase)) { throw 'Mount backup audit tidak sesuai atau tidak hanya baca.' }
$mountedHash = & $dockerPath exec $ContainerName sha256sum /backup/GAJI-5.dat
if ($LASTEXITCODE -ne 0 -or ("$mountedHash" -split '\s+')[0] -ne $hash) { throw 'Backup container tidak cocok dengan file sumber.' }
$builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
$builder['Data Source'] = $Server; $builder['Initial Catalog'] = 'master'
$builder['User ID'] = 'sa'; $builder['Password'] = $secret.password
$builder['Encrypt'] = $false; $builder['TrustServerCertificate'] = $true; $builder['Connect Timeout'] = 2
$connection = New-Object System.Data.SqlClient.SqlConnection $builder.ConnectionString

function Read-Sql([string]$Sql) {
    $command = $connection.CreateCommand(); $command.CommandText = $Sql; $command.CommandTimeout = 600
    $adapter = New-Object System.Data.SqlClient.SqlDataAdapter $command
    $table = New-Object System.Data.DataTable
    try { [void]$adapter.Fill($table); return ,$table } finally { $adapter.Dispose(); $command.Dispose() }
}
function Execute-Sql([string]$Sql) {
    $command = $connection.CreateCommand(); $command.CommandText = $Sql; $command.CommandTimeout = 600
    try { [void]$command.ExecuteNonQuery() } finally { $command.Dispose() }
}
function Convert-Cell($Value) {
    if ($Value -is [DBNull] -or $null -eq $Value) { return $null }
    if ($Value -is [byte[]]) { return @{__binary = [Convert]::ToBase64String($Value)} }
    if ($Value -is [DateTime]) { return $Value.ToString('yyyy-MM-ddTHH:mm:ss.fffffff', [Globalization.CultureInfo]::InvariantCulture) }
    if ($Value -is [DateTimeOffset]) { return $Value.ToString('o', [Globalization.CultureInfo]::InvariantCulture) }
    if ($Value -is [bool]) { if ($Value) { return '1' } else { return '0' } }
    if ($Value -is [double] -or $Value -is [single]) { return $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture) }
    if ($Value -is [IFormattable]) { return $Value.ToString($null, [Globalization.CultureInfo]::InvariantCulture) }
    return [string]$Value
}
function Convert-Rows([System.Data.DataTable]$Table) {
    $result = New-Object 'Collections.Generic.List[object]'
    foreach ($row in $Table.Rows) {
        $record = [ordered]@{}
        foreach ($column in $Table.Columns) { $record[$column.ColumnName] = Convert-Cell $row[$column] }
        $result.Add($record)
    }
    return ,$result.ToArray()
}

try {
    Write-Output 'Menunggu SQL Server audit siap...'
    $deadline = [DateTime]::UtcNow.AddSeconds($ReadyTimeoutSeconds)
    while ($connection.State -ne [Data.ConnectionState]::Open -and [DateTime]::UtcNow -lt $deadline) {
        try { $connection.Open() } catch { $connection.Close(); Start-Sleep -Seconds 2 }
    }
    if ($connection.State -ne [Data.ConnectionState]::Open) { throw "SQL Server audit belum siap setelah $ReadyTimeoutSeconds detik." }
    $header = Read-Sql "RESTORE HEADERONLY FROM DISK=N'/backup/GAJI-5.dat'"
    $fullBackups = @($header.Rows | Where-Object { [int]$_['BackupType'] -eq 1 -and $_['BackupFinishDate'] -isnot [DBNull] } | Sort-Object @{Expression = { $_['BackupFinishDate'] }; Descending = $true}, @{Expression = { [int]$_['Position'] }; Descending = $true})
    if (-not $fullBackups.Count) { throw 'Tidak ada backup database penuh yang selesai pada file ini.' }
    $selected = $fullBackups[0]; $position = [int]$selected['Position']
    if ($position -lt 1 -or ($header.Columns.Contains('IsDamaged') -and $selected['IsDamaged'] -isnot [DBNull] -and [bool]$selected['IsDamaged'])) { throw 'Backup penuh terbaru ditandai rusak atau posisi tidak valid.' }
    $files = Read-Sql "RESTORE FILELISTONLY FROM DISK=N'/backup/GAJI-5.dat' WITH FILE=$position"
    Execute-Sql "RESTORE VERIFYONLY FROM DISK=N'/backup/GAJI-5.dat' WITH FILE=$position"
    Write-Output "Backup set $position terverifikasi; memulihkan salinan audit baru."
    $exists = Read-Sql "SELECT name FROM sys.databases WHERE name=N'$DatabaseName'"
    if ($exists.Rows.Count) { throw 'Database audit sudah ada. Pemulihan menolak memakai ulang database yang belum terbukti berasal dari proses ini.' }
    $moves = @()
    foreach ($file in $files.Rows) {
        $logical = ([string]$file['LogicalName']).Replace("'", "''")
        $extension = if ($file['Type'] -eq 'L') { 'ldf' } elseif ($file['Type'] -eq 'D') { 'mdf' } else { throw 'Jenis file backup belum didukung untuk pemulihan audit otomatis.' }
        $number = $moves.Count
        $moves += "MOVE N'$logical' TO N'/var/opt/mssql/data/" + $DatabaseName + '_' + $number + '.' + $extension + "'"
    }
    if (-not $moves.Count) { throw 'Daftar file backup kosong.' }
    Execute-Sql ("RESTORE DATABASE [$DatabaseName] FROM DISK=N'/backup/GAJI-5.dat' WITH FILE=$position, " + ($moves -join ',') + ', RECOVERY')
    $connection.ChangeDatabase($DatabaseName)
    Execute-Sql 'DBCC CHECKDB WITH NO_INFOMSGS, ALL_ERRORMSGS'
    Execute-Sql "EXEC sys.sp_addextendedproperty @name=N'legacy_backup_sha256', @value=N'$hash'"
    Execute-Sql "ALTER DATABASE [$DatabaseName] SET READ_ONLY WITH NO_WAIT"
    $tables = Read-Sql 'SELECT s.name AS schema_name,t.name AS table_name,t.object_id FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name NOT IN (''sys'',''INFORMATION_SCHEMA'') ORDER BY s.name,t.name'
    $exportTables = @()
    foreach ($table in $tables.Rows) {
        $schemaName = [string]$table['schema_name']; $tableName = [string]$table['table_name']; $objectId = [int]$table['object_id']
        $columns = Read-Sql "SELECT c.column_id,c.name,ty.name AS sql_type,c.max_length,c.precision,c.scale,c.is_nullable,c.is_identity,c.collation_name,dc.definition AS default_definition,cc.definition AS computed_definition FROM sys.columns c JOIN sys.types ty ON ty.user_type_id=c.user_type_id LEFT JOIN sys.default_constraints dc ON dc.object_id=c.default_object_id LEFT JOIN sys.computed_columns cc ON cc.object_id=c.object_id AND cc.column_id=c.column_id WHERE c.object_id=$objectId ORDER BY c.column_id"
        $indexes = Read-Sql "SELECT i.name,i.type_desc,i.is_unique,i.is_primary_key,i.is_unique_constraint,i.filter_definition,ic.key_ordinal,ic.is_descending_key,ic.is_included_column,c.name AS column_name FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id WHERE i.object_id=$objectId ORDER BY i.index_id,ic.index_column_id"
        $foreignKeys = Read-Sql "SELECT f.name,c.name AS column_name,OBJECT_SCHEMA_NAME(f.referenced_object_id) AS target_schema,OBJECT_NAME(f.referenced_object_id) AS target_table,rc.name AS target_column,f.delete_referential_action_desc,f.update_referential_action_desc FROM sys.foreign_keys f JOIN sys.foreign_key_columns fc ON fc.constraint_object_id=f.object_id JOIN sys.columns c ON c.object_id=fc.parent_object_id AND c.column_id=fc.parent_column_id JOIN sys.columns rc ON rc.object_id=fc.referenced_object_id AND rc.column_id=fc.referenced_column_id WHERE f.parent_object_id=$objectId ORDER BY f.name,fc.constraint_column_id"
        $quotedSchema = $schemaName.Replace(']',']]'); $quotedTable = $tableName.Replace(']',']]')
        $primaryColumns = @($indexes.Rows | Where-Object { [bool]$_['is_primary_key'] -and [int]$_['key_ordinal'] -gt 0 } | Sort-Object { [int]$_['key_ordinal'] } | ForEach-Object { '[' + ([string]$_['column_name']).Replace(']',']]') + ']' })
        $order = if ($primaryColumns.Count) { ' ORDER BY ' + ($primaryColumns -join ',') } else { '' }
        $data = Read-Sql "SELECT * FROM [$quotedSchema].[$quotedTable]$order"
        # Decimal/money values remain invariant strings; no floating point conversion is used.
        # PHP reconciliation hashes canonical row multisets, including duplicate multiplicity.
        $exportTables += [ordered]@{schema = $schemaName; name = $tableName; row_count = $data.Rows.Count; columns = (Convert-Rows $columns); indexes = (Convert-Rows $indexes); foreign_keys = (Convert-Rows $foreignKeys); rows = (Convert-Rows $data)}
        Write-Output ($schemaName + '.' + $tableName + ': ' + $data.Rows.Count + ' baris, ' + $columns.Rows.Count + ' kolom')
    }
    $definitions = Read-Sql 'SELECT s.name AS schema_name,o.name,o.type_desc,m.definition FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id LEFT JOIN sys.sql_modules m ON m.object_id=o.object_id WHERE s.name NOT IN (''sys'',''INFORMATION_SCHEMA'') AND o.type IN (''P'',''V'',''TR'',''FN'',''IF'',''TF'',''C'',''D'') ORDER BY o.type,o.name'
    $finalHash = (Get-FileHash -LiteralPath $resolvedBackup -Algorithm SHA256).Hash.ToLowerInvariant()
    $mountedHash = & $dockerPath exec $ContainerName sha256sum /backup/GAJI-5.dat
    if ($LASTEXITCODE -ne 0 -or $finalHash -ne $hash -or ("$mountedHash" -split '\s+')[0] -ne $hash) { throw 'Backup berubah selama proses audit. Ekspor dibatalkan.' }
    $selectedHeader = [ordered]@{}
    foreach ($column in $header.Columns) { $selectedHeader[$column.ColumnName] = Convert-Cell $selected[$column] }
    $provenance = [ordered]@{format_version = 2; source_sha256 = $hash; source_table_count = $tables.Rows.Count; backup_set_position = $position; selected_backup_header = $selectedHeader; container_name = $ContainerName; audit_database = $DatabaseName; mounted_sha256 = $hash; restore_verifyonly = $true; dbcc_checkdb = $true; audit_read_only = $true}
    $export = [ordered]@{format_version = 2; source_sha256 = $hash; source_file = (Split-Path -Leaf $resolvedBackup); source_database = [string]$selected['DatabaseName']; exported_at = [DateTime]::UtcNow.ToString('o'); integrity = 'RESTORE VERIFYONLY and DBCC CHECKDB passed'; backup_header = (Convert-Rows $header); backup_files = (Convert-Rows $files); provenance = $provenance; definitions = (Convert-Rows $definitions); tables = $exportTables}
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $OutputPath) | Out-Null
    [IO.File]::WriteAllText($OutputPath, ($export | ConvertTo-Json -Depth 100), [Text.UTF8Encoding]::new($false))
    $provenance['export_sha256'] = (Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $provenance['export_path'] = $OutputPath
    [IO.File]::WriteAllText((Join-Path $RunDirectory 'provenance.json'), ($provenance | ConvertTo-Json -Depth 100), [Text.UTF8Encoding]::new($false))
    Write-Output ('Ekspor audit selesai: ' + $OutputPath)
} finally { $connection.Close(); $connection.Dispose() }
