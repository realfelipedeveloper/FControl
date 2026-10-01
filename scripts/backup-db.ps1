param([string]$OutputDirectory = (Join-Path $PSScriptRoot '..\backups'))
$ErrorActionPreference = 'Stop'
$resolvedRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$resolvedOutput = [IO.Path]::GetFullPath($OutputDirectory)
if (-not $resolvedOutput.StartsWith($resolvedRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'O backup deve permanecer em um subdiretório do workspace.' }
New-Item -ItemType Directory -Force -Path $resolvedOutput | Out-Null
$file = Join-Path $resolvedOutput ("fcontrol-{0}.sql" -f (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
Push-Location $resolvedRoot
try {
    docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --routines --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"' | Set-Content -Encoding utf8 -LiteralPath ($file + '.partial')
    if ($LASTEXITCODE -ne 0) { throw 'O backup falhou; o arquivo .partial não é um backup válido.' }
    Move-Item -LiteralPath ($file + '.partial') -Destination $file
} finally { Pop-Location }
Write-Host "Backup criado em $file"
