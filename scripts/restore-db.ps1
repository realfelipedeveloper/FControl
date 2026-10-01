[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'High')]
param([Parameter(Mandatory = $true)][string]$BackupFile)
$ErrorActionPreference = 'Stop'
$resolvedRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\backups'))
$resolvedFile = (Resolve-Path -LiteralPath $BackupFile).Path
if (-not $resolvedFile.StartsWith($resolvedRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetExtension($resolvedFile) -ne '.sql' -or -not (Test-Path -LiteralPath $resolvedFile -PathType Leaf)) { throw 'Somente arquivos .sql do diretório backups/ podem ser restaurados.' }
if (-not $PSCmdlet.ShouldProcess('Banco MySQL da aplicação FControl', "Substituir os dados pelo backup $resolvedFile")) { return }
Push-Location ([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..')))
try {
    & (Join-Path $PSScriptRoot 'backup-db.ps1')
    Get-Content -Raw -LiteralPath $resolvedFile | docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"'
    if ($LASTEXITCODE -ne 0) { throw 'A restauração falhou; preserve o backup preventivo para recuperação.' }
} finally { Pop-Location }
Write-Host 'Banco restaurado com sucesso.'
