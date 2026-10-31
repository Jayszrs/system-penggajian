param(
    [string]$BackupPath = (Join-Path $env:USERPROFILE 'Downloads\GAJI-5.dat'),
    [string]$Server = '127.0.0.1,11433',
    [string]$DatabaseName = 'gaji_legacy_audit'
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$legacyDir = Join-Path $projectRoot 'storage\legacy'
New-Item -ItemType Directory -Force -Path $legacyDir | Out-Null
if ($DatabaseName -notmatch '^[a-zA-Z0-9_]+$') { throw 'Invalid audit database name.' }
$secret = Get-Content -LiteralPath (Join-Path $legacyDir 'sql-secret.json') -Raw | ConvertFrom-Json
$builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
$builder['Data Source'] = $Server
$builder['Initial Catalog'] = 'master'
$builder['User ID'] = 'sa'
$builder['Password'] = $secret.password
$builder['Encrypt'] = $false
$builder['TrustServerCertificate'] = $true
$builder['Connect Timeout'] = 10
$connection = New-Object System.Data.SqlClient.SqlConnection $builder.ConnectionString
$connection.Open()
function Read-Sql([string]$Sql) {
    $command = $connection.CreateCommand(); $command.CommandText = $Sql; $command.CommandTimeout = 180
    $adapter = New-Object System.Data.SqlClient.SqlDataAdapter $command
    $table = New-Object System.Data.DataTable
    [void]$adapter.Fill($table)
    return ,$table
}
function Execute-Sql([string]$Sql) {
    $command = $connection.CreateCommand(); $command.CommandText = $Sql; $command.CommandTimeout = 180
    [void]$command.ExecuteNonQuery()
}
function Convert-Cell($Value) {
    if ($Value -is [DBNull] -or $null -eq $Value) { return $null }
    if ($Value -is [byte[]]) { return @{__binary = [Convert]::ToBase64String($Value)} }
    if ($Value -is [DateTime]) { return $Value.ToString('yyyy-MM-ddTHH:mm:ss.fffffff', [Globalization.CultureInfo]::InvariantCulture) }
    if ($Value -is [IFormattable]) { return $Value.ToString($null, [Globalization.CultureInfo]::InvariantCulture) }
    return [string]$Value
}
function Convert-Rows([System.Data.DataTable]$Table) {
    $result = @()
    foreach ($row in $Table.Rows) {
        $record = [ordered]@{}
        foreach ($column in $Table.Columns) { $record[$column.ColumnName] = Convert-Cell $row[$column] }
        $result += $record
    }
    return ,$result
}
try {
    $hash = (Get-FileHash -LiteralPath $BackupPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $mountedHashOutput = docker exec penggajian-sql-audit sha256sum /backup/GAJI-5.dat
    if ($LASTEXITCODE -ne 0 -or ($mountedHashOutput -split '\s+')[0] -ne $hash) { throw 'Backup yang dipasang pada container tidak cocok dengan file sumber. Restore dibatalkan.' }
    $header = Read-Sql "RESTORE HEADERONLY FROM DISK=N'/backup/GAJI-5.dat'"
    $files = Read-Sql "RESTORE FILELISTONLY FROM DISK=N'/backup/GAJI-5.dat'"
    Execute-Sql "RESTORE VERIFYONLY FROM DISK=N'/backup/GAJI-5.dat'"
    Write-Output ('Backup verified: ' + $header.Rows[0]['DatabaseName'] + '; engine ' + $header.Rows[0]['SoftwareVersionMajor'] + '.' + $header.Rows[0]['SoftwareVersionMinor'])
    $exists = Read-Sql "SELECT name FROM sys.databases WHERE name=N'$DatabaseName'"
    if (!$exists.Rows.Count) {
        $moves = @()
        foreach ($file in $files.Rows) {
            $logical = ([string]$file['LogicalName']).Replace("'", "''")
            $extension = if ($file['Type'] -eq 'L') { 'ldf' } else { 'mdf' }
            $number = $moves.Count
            $moves += "MOVE N'$logical' TO N'/var/opt/mssql/data/${DatabaseName}_${number}.$extension'"
        }
        Execute-Sql ("RESTORE DATABASE [$DatabaseName] FROM DISK=N'/backup/GAJI-5.dat' WITH " + ($moves -join ',') + ', RECOVERY')
    } else {
        $existingManifest = Join-Path $legacyDir 'export.json'
        if (Test-Path -LiteralPath $existingManifest) {
            $existingHash = (Get-Content -LiteralPath $existingManifest -Raw | ConvertFrom-Json).source_sha256
            if ($existingHash -ne $hash) { throw 'Audit database already exists for a different backup. Use a distinct audit database name.' }
        }
    }
    $connection.ChangeDatabase($DatabaseName)
    Execute-Sql 'DBCC CHECKDB WITH NO_INFOMSGS, ALL_ERRORMSGS'
    Execute-Sql "ALTER DATABASE [$DatabaseName] SET READ_ONLY WITH NO_WAIT"
    $tables = Read-Sql 'SELECT s.name AS schema_name,t.name AS table_name,t.object_id FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name NOT IN (''sys'',''INFORMATION_SCHEMA'') ORDER BY s.name,t.name'
    $exportTables = @()
    foreach ($table in $tables.Rows) {
        $schemaName = [string]$table['schema_name']; $tableName = [string]$table['table_name']; $objectId = [int]$table['object_id']
        $columns = Read-Sql "SELECT c.column_id,c.name,ty.name AS sql_type,c.max_length,c.precision,c.scale,c.is_nullable,c.is_identity,c.collation_name,dc.definition AS default_definition,cc.definition AS computed_definition FROM sys.columns c JOIN sys.types ty ON ty.user_type_id=c.user_type_id LEFT JOIN sys.default_constraints dc ON dc.object_id=c.default_object_id LEFT JOIN sys.computed_columns cc ON cc.object_id=c.object_id AND cc.column_id=c.column_id WHERE c.object_id=$objectId ORDER BY c.column_id"
        $indexes = Read-Sql "SELECT i.name,i.type_desc,i.is_unique,i.is_primary_key,i.is_unique_constraint,i.filter_definition,ic.key_ordinal,ic.is_descending_key,ic.is_included_column,c.name AS column_name FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id WHERE i.object_id=$objectId ORDER BY i.index_id,ic.index_column_id"
        $foreignKeys = Read-Sql "SELECT f.name,c.name AS column_name,OBJECT_SCHEMA_NAME(f.referenced_object_id) AS target_schema,OBJECT_NAME(f.referenced_object_id) AS target_table,rc.name AS target_column,f.delete_referential_action_desc,f.update_referential_action_desc FROM sys.foreign_keys f JOIN sys.foreign_key_columns fc ON fc.constraint_object_id=f.object_id JOIN sys.columns c ON c.object_id=fc.parent_object_id AND c.column_id=fc.parent_column_id JOIN sys.columns rc ON rc.object_id=fc.referenced_object_id AND rc.column_id=fc.referenced_column_id WHERE f.parent_object_id=$objectId ORDER BY f.name,fc.constraint_column_id"
        $quotedSchema = $schemaName.Replace(']',']]'); $quotedTable = $tableName.Replace(']',']]')
        $data = Read-Sql "SELECT * FROM [$quotedSchema].[$quotedTable]"
        $records = Convert-Rows $data
        $exportTables += [ordered]@{schema=$schemaName;name=$tableName;row_count=$data.Rows.Count;columns=(Convert-Rows $columns);indexes=(Convert-Rows $indexes);foreign_keys=(Convert-Rows $foreignKeys);rows=$records}
        Write-Output ($tableName + ': ' + $data.Rows.Count + ' rows, ' + $columns.Rows.Count + ' columns')
    }
    $definitions = Read-Sql 'SELECT s.name AS schema_name,o.name,o.type_desc,m.definition FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id LEFT JOIN sys.sql_modules m ON m.object_id=o.object_id WHERE s.name NOT IN (''sys'',''INFORMATION_SCHEMA'') AND o.type IN (''P'',''V'',''TR'',''FN'',''IF'',''TF'',''C'',''D'') ORDER BY o.type,o.name'
    $export = [ordered]@{source_sha256=$hash;source_file=(Split-Path -Leaf $BackupPath);source_database=[string]$header.Rows[0]['DatabaseName'];exported_at=[DateTime]::UtcNow.ToString('o');integrity='RESTORE VERIFYONLY and DBCC CHECKDB passed';backup_header=(Convert-Rows $header);backup_files=(Convert-Rows $files);definitions=(Convert-Rows $definitions);tables=$exportTables}
    $output = Join-Path $legacyDir 'export.json'
    [IO.File]::WriteAllText($output,($export | ConvertTo-Json -Depth 100),[Text.UTF8Encoding]::new($false))
    Write-Output ('Export complete: ' + $output)
} finally { $connection.Close() }
