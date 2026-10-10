param(
    [Parameter(Position = 0, ValueFromRemainingArguments = $true)]
    [string[]]$BackendArguments
)

$ErrorActionPreference = 'Stop'
$taskProjectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskBackendRoot = Join-Path $taskProjectRoot 'backend'
$taskPhpPath = $env:MASAL_PHP
if (-not $taskPhpPath) {
    $taskRuntimeRoot = Join-Path $env:USERPROFILE '.codex\runtimes\masal-php'
    $taskPhpCandidates = @(Get-ChildItem -LiteralPath $taskRuntimeRoot -Filter php.exe -Recurse -ErrorAction SilentlyContinue)
    if ($taskPhpCandidates.Count -eq 1) {
        $taskPhpPath = $taskPhpCandidates[0].FullName
    } else {
        $taskPhpCommand = Get-Command php -ErrorAction SilentlyContinue
        if ($taskPhpCommand) { $taskPhpPath = $taskPhpCommand.Source }
    }
}
if (-not $taskPhpPath -or -not (Test-Path -LiteralPath $taskPhpPath)) {
    throw 'Set MASAL_PHP to a PHP 8.4.1+ executable or install the documented portable runtime.'
}
& $taskPhpPath -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { throw 'The locked backend dependencies require PHP 8.4.1+. Set MASAL_PHP; the system PHP was not modified.' }
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
