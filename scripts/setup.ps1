param([ValidateSet('all', 'frontend', 'backend', 'android')][string]$Target = 'all')

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$taskProjectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($Target -in @('all', 'frontend')) {
    $taskNode = Resolve-MasalNode
    $taskNpm = Resolve-MasalNpm
    Push-Location -LiteralPath (Join-Path $taskProjectRoot 'frontend')
    try {
        Invoke-MasalNpm -Node $taskNode -Npm $taskNpm -Arguments @('ci', '--no-audit', '--no-fund') -Description 'Frontend dependency installation'
    } finally { Pop-Location }
}
if ($Target -in @('all', 'backend')) {
    $taskPhp = Resolve-MasalPhp
    foreach ($taskRuntimeDirectory in @('bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs')) {
        New-Item -ItemType Directory -Force -Path (Join-Path $taskProjectRoot ('backend/' + $taskRuntimeDirectory)) | Out-Null
    }
    Push-Location -LiteralPath (Join-Path $taskProjectRoot 'backend')
    try {
        Invoke-MasalIsolatedBackend {
            Invoke-MasalComposer -Php $taskPhp -Arguments @('install', '--prefer-dist', '--no-interaction', '--no-progress', '--no-ansi', '--no-scripts')
            Invoke-MasalCommand -FilePath $taskPhp -Arguments @('artisan', 'package:discover', '--no-interaction', '--no-ansi') -Description 'Backend package discovery'
        }
    } finally { Pop-Location }
}
if ($Target -in @('all', 'android')) {
    & (Join-Path $taskProjectRoot 'android-pos/scripts/build.ps1') -CheckOnly
}
Write-Output 'Dependency setup completed. No environment file, database migration, or production deployment was created.'
