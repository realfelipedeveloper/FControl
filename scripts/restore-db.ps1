param([Parameter(Mandatory = $true)][string]$BackupFile)
$ErrorActionPreference = 'Stop'
$resolvedRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\backups'))
$resolvedFile = (Resolve-Path -LiteralPath $BackupFile).Path
if (-not $resolvedFile.StartsWith($resolvedRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'Somente backups do diretório backups/ podem ser restaurados.' }
Get-Content -Raw -LiteralPath $resolvedFile | docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
if ($LASTEXITCODE -ne 0) { throw 'A restauração falhou.' }
Write-Host 'Banco restaurado com sucesso.'
