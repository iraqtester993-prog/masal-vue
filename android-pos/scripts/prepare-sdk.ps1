param([string]$Archive, [string]$ZcsRoot)

$ErrorActionPreference = 'Stop'
$taskAndroidRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskManifest = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'sdk-manifest.json') -Raw | ConvertFrom-Json
if ($taskManifest.schema -ne 1 -or @($taskManifest.artifacts).Count -ne 9) { throw 'Unexpected vendor SDK manifest.' }

function Assert-MasalSdkFile {
    param([string]$Path, $Artifact)
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Missing bundled SDK file: $($Artifact.path). Restore the matching vendor library before building." }
    if ((Get-Item -LiteralPath $Path).Length -ne $Artifact.bytes -or (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Artifact.sha256) {
        throw "SDK checksum mismatch: $($Artifact.path). Review a new vendor version before replacing the pinned library."
    }
}

$taskImports = @()
$taskStaging = $null
try {
    if ($Archive) {
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        $taskStaging = Join-Path ([IO.Path]::GetTempPath()) ('masal-sdk-' + [Guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $taskStaging | Out-Null
        $taskRovooStage = Join-Path $taskStaging 'rovoo-1.0.5.jar'
        $taskZip = [IO.Compression.ZipFile]::OpenRead((Resolve-Path -LiteralPath $Archive).Path)
        try {
            $taskEntry = $taskZip.GetEntry('sdk rovo/Rovoo SDK/Rovoo SDK/CpaySDKLib_Q_V1.0.5_202402226.jar')
            if (-not $taskEntry) { throw 'The pinned ROVOO 1.0.5 library was not found in the supplied archive.' }
            [IO.Compression.ZipFileExtensions]::ExtractToFile($taskEntry, $taskRovooStage, $false)
        } finally { $taskZip.Dispose() }
        $taskArtifact = $taskManifest.artifacts | Where-Object path -eq 'app/libs/rovoo-1.0.5.jar'
        Assert-MasalSdkFile -Path $taskRovooStage -Artifact $taskArtifact
        $taskImports += @{ Source = $taskRovooStage; Artifact = $taskArtifact }
    }
    if ($ZcsRoot) {
        $taskZcsSources = @{
            'app/libs/zcs-1.3.5.jar' = 'lib/SmartPos_1.3.5_R200601.jar'
            'app/libs/zxing-core-3.2.1.jar' = 'demo/SmartPosTest/zxing/libs/core-3.2.1.jar'
            'app/src/main/jniLibs/arm64-v8a/libSmartPos.so' = 'lib/arm64-v8a/libSmartPos.so'
            'app/src/main/jniLibs/arm64-v8a/libSmartPosJni.so' = 'lib/arm64-v8a/libSmartPosJni.so'
            'app/src/main/jniLibs/arm64-v8a/libEmvCoreJni.so' = 'lib/arm64-v8a/libEmvCoreJni.so'
            'app/src/main/jniLibs/armeabi-v7a/libSmartPos.so' = 'lib/armeabi-v7a/libSmartPos.so'
            'app/src/main/jniLibs/armeabi-v7a/libSmartPosJni.so' = 'lib/armeabi-v7a/libSmartPosJni.so'
            'app/src/main/jniLibs/armeabi-v7a/libEmvCoreJni.so' = 'lib/armeabi-v7a/libEmvCoreJni.so'
        }
        foreach ($taskArtifact in $taskManifest.artifacts) {
            if ($taskZcsSources.ContainsKey($taskArtifact.path)) {
                $taskSource = Join-Path $ZcsRoot $taskZcsSources[$taskArtifact.path]
                Assert-MasalSdkFile -Path $taskSource -Artifact $taskArtifact
                $taskImports += @{ Source = $taskSource; Artifact = $taskArtifact }
            }
        }
    }
    foreach ($taskImport in $taskImports) {
        $taskTarget = Join-Path $taskAndroidRoot $taskImport.Artifact.path
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $taskTarget) | Out-Null
        Copy-Item -LiteralPath $taskImport.Source -Destination $taskTarget -Force
    }
    foreach ($taskArtifact in $taskManifest.artifacts) {
        Assert-MasalSdkFile -Path (Join-Path $taskAndroidRoot $taskArtifact.path) -Artifact $taskArtifact
    }
    Write-Output 'All 9 bundled vendor SDK files match the pinned checksums.'
} finally {
    if ($taskStaging) {
        $taskResolvedStaging = [IO.Path]::GetFullPath($taskStaging)
        $taskTempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
        if (-not $taskResolvedStaging.StartsWith($taskTempRoot, [StringComparison]::OrdinalIgnoreCase) -or (Split-Path -Leaf $taskResolvedStaging) -notmatch '^masal-sdk-[a-f0-9]{32}$') {
            throw 'Refusing cleanup outside the SDK staging directory.'
        }
        Remove-Item -LiteralPath $taskResolvedStaging -Recurse -Force
    }
}
