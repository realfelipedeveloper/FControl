param([string]$OutputDirectory = (Join-Path $PSScriptRoot '..\backups'))
$ErrorActionPreference = 'Stop'
$resolvedRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$resolvedOutput = [IO.Path]::GetFullPath($OutputDirectory)
if (-not $resolvedOutput.StartsWith($resolvedRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'O backup deve permanecer dentro do workspace.' }
New-Item -ItemType Directory -Force -Path $resolvedOutput | Out-Null
$file = Join-Path $resolvedOutput ("fcontrol-{0}.sql" -f (Get-Date -Format 'yyyyMMdd-HHmmss'))
docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines "$MYSQL_DATABASE"' | Set-Content -Encoding utf8 -Path $file
Write-Host "Backup criado em $file"
