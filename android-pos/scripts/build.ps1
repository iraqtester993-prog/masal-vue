param(
    [ValidateSet('Debug', 'Release')][string]$Configuration = 'Debug',
    [switch]$SkipTests,
    [switch]$CheckOnly
)

$ErrorActionPreference = 'Stop'
$taskAndroidRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $taskAndroidRoot '../scripts/common.ps1')
& (Join-Path $PSScriptRoot 'prepare-sdk.ps1')
$taskWrapper = Join-Path $taskAndroidRoot 'gradle/wrapper/gradle-wrapper.jar'
$taskWrapperSha = '81a82aaea5abcc8ff68b3dfcb58b3c3c429378efd98e7433460610fecd7ae45f'
if (-not (Test-Path -LiteralPath $taskWrapper -PathType Leaf) -or (Get-FileHash -LiteralPath $taskWrapper -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskWrapperSha) {
    throw 'The Gradle 8.13 Wrapper JAR is missing or its checksum does not match the official release.'
}
$taskJavaRoot = $env:JAVA_HOME
if (-not $taskJavaRoot) {
    $taskJavac = Get-Command javac -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($taskJavac) { $taskJavaRoot = Split-Path -Parent (Split-Path -Parent $taskJavac.Source) }
}
if (-not $taskJavaRoot) { throw 'Set JAVA_HOME to JDK 17 or JDK 21, or add its javac executable to PATH.' }
$taskJavaName = if ($env:OS -eq 'Windows_NT') { 'java.exe' } else { 'java' }
$taskJava = Join-Path $taskJavaRoot ('bin/' + $taskJavaName)
if (-not (Test-Path -LiteralPath $taskJava -PathType Leaf)) { throw 'JAVA_HOME does not contain a Java executable.' }
$taskJavaVersion = & $taskJava -version 2>&1 | Out-String
if ($LASTEXITCODE -ne 0 -or $taskJavaVersion -notmatch 'version "(17|21)\.') { throw 'The supported build JDK versions are 17 and 21.' }
$taskSdkRoot = if ($env:ANDROID_HOME) { $env:ANDROID_HOME } else { $env:ANDROID_SDK_ROOT }
if ($env:ANDROID_HOME -and $env:ANDROID_SDK_ROOT -and [IO.Path]::GetFullPath($env:ANDROID_HOME).TrimEnd([IO.Path]::DirectorySeparatorChar) -ne [IO.Path]::GetFullPath($env:ANDROID_SDK_ROOT).TrimEnd([IO.Path]::DirectorySeparatorChar)) {
    throw 'ANDROID_HOME and ANDROID_SDK_ROOT point to different SDK installations.'
}
if (-not $taskSdkRoot -and $env:OS -eq 'Windows_NT' -and $env:LOCALAPPDATA) { $taskSdkRoot = Join-Path $env:LOCALAPPDATA 'Android/Sdk' }
if (-not $taskSdkRoot -or -not (Test-Path -LiteralPath (Join-Path $taskSdkRoot 'platforms/android-35/android.jar') -PathType Leaf) -or -not (Test-Path -LiteralPath (Join-Path $taskSdkRoot 'build-tools/35.0.0/source.properties') -PathType Leaf)) {
    throw 'Install Android SDK platform android-35 and build-tools 35.0.0. Set ANDROID_HOME to the SDK directory.'
}
if ($Configuration -eq 'Release' -and -not $CheckOnly) {
    foreach ($taskSigningName in @('DANANEER_SIGNING_STORE', 'DANANEER_SIGNING_STORE_PASSWORD', 'DANANEER_SIGNING_KEY_ALIAS', 'DANANEER_SIGNING_KEY_PASSWORD')) {
        if (-not [Environment]::GetEnvironmentVariable($taskSigningName, 'Process')) { throw "Release signing requires $taskSigningName. The signing values are never printed by this script." }
    }
    if (-not (Test-Path -LiteralPath $env:DANANEER_SIGNING_STORE -PathType Leaf)) { throw 'The release signing keystore was not found.' }
}
$taskPreviousJava = $env:JAVA_HOME
$taskPreviousAndroid = $env:ANDROID_HOME
$taskPreviousAndroidSdk = $env:ANDROID_SDK_ROOT
Push-Location -LiteralPath $taskAndroidRoot
try {
    $env:JAVA_HOME = [IO.Path]::GetFullPath($taskJavaRoot)
    $env:ANDROID_HOME = [IO.Path]::GetFullPath($taskSdkRoot)
    $env:ANDROID_SDK_ROOT = $env:ANDROID_HOME
    $taskGradle = if ($env:OS -eq 'Windows_NT') { Join-Path $taskAndroidRoot 'gradlew.bat' } else { 'bash' }
    $taskGradleArguments = if ($env:OS -eq 'Windows_NT') { @() } else { @((Join-Path $taskAndroidRoot 'gradlew')) }
    if ($CheckOnly) {
        Invoke-MasalCommand -FilePath $taskGradle -Arguments ($taskGradleArguments + @('--version', '--no-daemon')) -Description 'Gradle Wrapper verification'
    } else {
        $taskTasks = @()
        if (-not $SkipTests) { $taskTasks += @(':app:testDebugUnitTest', ':app:lintDebug') }
        $taskTasks += ':app:assemble' + $Configuration
        Invoke-MasalCommand -FilePath $taskGradle -Arguments ($taskGradleArguments + $taskTasks + @('--no-daemon', '--console=plain')) -Description 'Android build'
    }
} finally {
    Pop-Location
    Restore-MasalEnvironment -Name JAVA_HOME -Value $taskPreviousJava
    Restore-MasalEnvironment -Name ANDROID_HOME -Value $taskPreviousAndroid
    Restore-MasalEnvironment -Name ANDROID_SDK_ROOT -Value $taskPreviousAndroidSdk
}
