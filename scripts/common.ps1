function Resolve-MasalExecutable {
    param([string]$Name, [string]$EnvironmentVariable)

    $taskConfigured = if ($EnvironmentVariable) { [Environment]::GetEnvironmentVariable($EnvironmentVariable, 'Process') } else { $null }
    if ($taskConfigured) {
        if (-not (Test-Path -LiteralPath $taskConfigured -PathType Leaf)) {
            throw "The executable configured in $EnvironmentVariable was not found."
        }
        return [IO.Path]::GetFullPath($taskConfigured)
    }
    $taskCommand = Get-Command $Name -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $taskCommand) { throw "Install $Name and add it to PATH, or configure $EnvironmentVariable when documented." }
    return $taskCommand.Source
}

function Invoke-MasalCommand {
    param([string]$FilePath, [string[]]$Arguments, [string]$Description)

    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Description failed with exit code $LASTEXITCODE."
    }
}

function Resolve-MasalPhp {
    $taskPhp = Resolve-MasalExecutable -Name php -EnvironmentVariable MASAL_PHP
    & $taskPhp -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);'
    if ($LASTEXITCODE -ne 0) { throw 'PHP 8.4.1 or newer is required. Set MASAL_PHP to the correct executable.' }
    return $taskPhp
}

function Resolve-MasalNode {
    $taskNode = Resolve-MasalExecutable -Name node -EnvironmentVariable MASAL_NODE
    & $taskNode -e 'const [major,minor]=process.versions.node.split(".").map(Number);process.exit(major>22||(major===22&&minor>=12)?0:1);'
    if ($LASTEXITCODE -ne 0) { throw 'Node.js 22.12.0 or newer is required.' }
    return $taskNode
}

function Resolve-MasalNpm {
    $taskNpmName = if ($env:OS -eq 'Windows_NT') { 'npm.cmd' } else { 'npm' }
    return Resolve-MasalExecutable -Name $taskNpmName -EnvironmentVariable MASAL_NPM
}

function Invoke-MasalNpm {
    param([string]$Node, [string]$Npm, [string[]]$Arguments, [string]$Description)

    $taskPreviousPath = $env:PATH
    try {
        $env:PATH = (Split-Path -Parent $Node) + [IO.Path]::PathSeparator + $taskPreviousPath
        Invoke-MasalCommand -FilePath $Npm -Arguments $Arguments -Description $Description
    } finally { $env:PATH = $taskPreviousPath }
}

function Invoke-MasalComposer {
    param([string]$Php, [string[]]$Arguments)

    $taskComposer = $env:MASAL_COMPOSER
    if ($taskComposer) {
        if (-not (Test-Path -LiteralPath $taskComposer -PathType Leaf)) { throw 'The file configured in MASAL_COMPOSER was not found.' }
        $taskComposer = [IO.Path]::GetFullPath($taskComposer)
    } else {
        $taskComposer = Resolve-MasalExecutable -Name composer -EnvironmentVariable MASAL_COMPOSER
        $taskAdjacentPhar = Join-Path (Split-Path -Parent $taskComposer) 'composer.phar'
        if (Test-Path -LiteralPath $taskAdjacentPhar -PathType Leaf) { $taskComposer = $taskAdjacentPhar }
    }
    $taskCommandFile = $taskComposer
    $taskPrefix = @()
    if ([IO.Path]::GetExtension($taskComposer) -eq '.phar') {
        $taskCommandFile = $Php
        $taskPrefix = @($taskComposer)
    }
    $taskPreviousPath = $env:PATH
    $taskPreviousRootVersion = [Environment]::GetEnvironmentVariable('COMPOSER_ROOT_VERSION', 'Process')
    try {
        $env:PATH = (Split-Path -Parent $Php) + [IO.Path]::PathSeparator + $taskPreviousPath
        $env:COMPOSER_ROOT_VERSION = '1.0.0'
        $taskVersionOutput = & $taskCommandFile @taskPrefix --version --no-ansi --no-interaction | Out-String
        if ($LASTEXITCODE -ne 0 -or $taskVersionOutput -notmatch 'Composer version (\d+)\.(\d+)\.(\d+)') { throw 'Unable to identify the Composer version.' }
        $taskComposerVersion = [version]($Matches[1] + '.' + $Matches[2] + '.' + $Matches[3])
        if ($taskComposerVersion -lt [version]'2.10.0') { throw 'Composer 2.10 or newer is required so installation cannot silently fall back to a source checkout.' }
        if ($taskComposerVersion -lt [version]'2.11.0') {
            $taskFallback = & $taskCommandFile @taskPrefix config source-fallback --no-ansi --no-interaction | Out-String
            if ($LASTEXITCODE -ne 0 -or $taskFallback.Trim() -ne 'false') { throw 'Composer automatic source fallback must be disabled. Use the default source-fallback=false configuration.' }
        }
        Invoke-MasalCommand -FilePath $taskCommandFile -Arguments ($taskPrefix + $Arguments) -Description 'Composer'
    } finally {
        $env:PATH = $taskPreviousPath
        Restore-MasalEnvironment -Name COMPOSER_ROOT_VERSION -Value $taskPreviousRootVersion
    }
}

function Restore-MasalEnvironment {
    param([string]$Name, [AllowNull()]$Value)

    if ($null -eq $Value) {
        Remove-Item -LiteralPath ('Env:' + $Name) -ErrorAction SilentlyContinue
    } else {
        [Environment]::SetEnvironmentVariable($Name, $Value, 'Process')
    }
}

function Invoke-MasalIsolatedBackend {
    param([scriptblock]$Action)

    $taskCacheProbe = Join-Path ([IO.Path]::GetTempPath()) ('masal-test-' + [Guid]::NewGuid().ToString('N'))
    $taskValues = @{
        APP_ENV = 'testing'
        APP_KEY = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='
        APP_CONFIG_CACHE = $taskCacheProbe + '-config.php'
        APP_ROUTES_CACHE = $taskCacheProbe + '-routes.php'
        APP_MAINTENANCE_DRIVER = 'file'
        DB_CONNECTION = 'sqlite'
        DB_DATABASE = ':memory:'
        DB_URL = ''
        DATABASE_URL = ''
        DB_HOST = '127.0.0.1'
        DB_PORT = '0'
        DB_USERNAME = ''
        DB_PASSWORD = ''
        DB_FOREIGN_KEYS = 'true'
        CACHE_STORE = 'array'
        SESSION_DRIVER = 'array'
        QUEUE_CONNECTION = 'sync'
        MAIL_MAILER = 'array'
        MAPS_REQUIRE_LOCATION = 'false'
    }
    $taskPreviousValues = @{}
    try {
        foreach ($taskName in $taskValues.Keys) {
            $taskPreviousValues[$taskName] = [Environment]::GetEnvironmentVariable($taskName, 'Process')
            [Environment]::SetEnvironmentVariable($taskName, $taskValues[$taskName], 'Process')
        }
        & $Action
    } finally {
        foreach ($taskName in $taskValues.Keys) {
            Restore-MasalEnvironment -Name $taskName -Value $taskPreviousValues[$taskName]
        }
    }
}
