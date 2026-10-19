param(
    [ValidateSet('all', 'frontend', 'backend', 'android')][string]$Target = 'all',
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$taskProjectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($Target -in @('all', 'frontend')) {
    $taskNode = Resolve-MasalNode
    $taskNpm = Resolve-MasalNpm
    Push-Location -LiteralPath (Join-Path $taskProjectRoot 'frontend')
    try {
        if (-not $SkipTests) { Invoke-MasalNpm -Node $taskNode -Npm $taskNpm -Arguments @('test') -Description 'Frontend tests' }
        Invoke-MasalNpm -Node $taskNode -Npm $taskNpm -Arguments @('run', 'build') -Description 'Admin, agents, and POS builds'
        Invoke-MasalNpm -Node $taskNode -Npm $taskNpm -Arguments @('run', 'build:public') -Description 'Public website build'
    } finally { Pop-Location }
}
if ($Target -in @('all', 'backend')) {
    $taskPhp = Resolve-MasalPhp
    Push-Location -LiteralPath (Join-Path $taskProjectRoot 'backend')
    try {
        if (-not (Test-Path -LiteralPath 'vendor/autoload.php' -PathType Leaf)) { throw 'Run scripts/setup.ps1 -Target backend first.' }
        Invoke-MasalIsolatedBackend {
            Invoke-MasalComposer -Php $taskPhp -Arguments @('check-platform-reqs', '--no-interaction', '--no-ansi')
            if (-not $SkipTests) {
                Invoke-MasalCommand -FilePath $taskPhp -Arguments @('-d', 'memory_limit=1024M', 'vendor/bin/phpunit', '--no-progress') -Description 'Backend tests on isolated in-memory SQLite'
            }
        }
    } finally { Pop-Location }
}
if ($Target -in @('all', 'android')) {
    & (Join-Path $taskProjectRoot 'android-pos/scripts/build.ps1') -Configuration Debug -SkipTests:$SkipTests
}
Write-Output 'Build verification completed.'
