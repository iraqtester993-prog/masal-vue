param(
    [Parameter(Position = 0, ValueFromRemainingArguments = $true)]
    [string[]]$BackendArguments
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$taskProjectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskBackendRoot = Join-Path $taskProjectRoot 'backend'
$taskPhpPath = Resolve-MasalPhp
if (-not $BackendArguments -or $BackendArguments.Count -eq 0) {
    $BackendArguments = @('artisan', 'list')
}
Push-Location -LiteralPath $taskBackendRoot
try {
    & $taskPhpPath @BackendArguments
    $taskBackendExit = $LASTEXITCODE
} finally {
    Pop-Location
}
exit $taskBackendExit
