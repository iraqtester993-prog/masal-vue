param([string]$Archive='E:/sdk rovo.zip', [string]$ZcsRoot='E:/New folder/SDK')
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$taskAndroidRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskLibraryPath=Join-Path $taskAndroidRoot 'app/libs/rovoo-1.0.5.jar'
$taskExpectedHash='8801FB6CE2A61C6BFFC4AC2374A48DE7DECF7E1530A7CC73F1F4CD7306A8151E'
$taskZip=[IO.Compression.ZipFile]::OpenRead((Resolve-Path -LiteralPath $Archive).Path)
try {
    $taskEntry=$taskZip.GetEntry('sdk rovo/Rovoo SDK/Rovoo SDK/CpaySDKLib_Q_V1.0.5_202402226.jar')
    if(-not $taskEntry){throw 'The reviewed ROVOO SDK library was not found.'}
    New-Item -ItemType Directory -Force -Path (Split-Path $taskLibraryPath) | Out-Null
    [IO.Compression.ZipFileExtensions]::ExtractToFile($taskEntry,$taskLibraryPath,$true)
} finally {$taskZip.Dispose()}
if((Get-FileHash -LiteralPath $taskLibraryPath -Algorithm SHA256).Hash -ne $taskExpectedHash){throw 'SDK checksum differs from the reviewed library. Review the new version before building.'}
Write-Output 'Reviewed ROVOO 1.0.5 library prepared.'
$taskZcsFiles=@(
    @('lib/SmartPos_1.3.5_R200601.jar','app/libs/zcs-1.3.5.jar','389F0216B06E2155CA5665ED12AF90260F9F7180B3B6FB1EE5CB04E087EC2E51'),
    @('demo/SmartPosTest/zxing/libs/core-3.2.1.jar','app/libs/zxing-core-3.2.1.jar','B4D82452E7A6BF6EC2698904B332431717ED8F9A850224F295AEC89DE80F2259'),
    @('lib/arm64-v8a/libSmartPos.so','app/src/main/jniLibs/arm64-v8a/libSmartPos.so','13450F84735BD47987661630A57DFE736D4AC4B5E535D0847F18C828AEDEC807'),
    @('lib/arm64-v8a/libSmartPosJni.so','app/src/main/jniLibs/arm64-v8a/libSmartPosJni.so','59B8AC152291E164A5A12248C27A5649C84F08E55F76D92B12C8440B474F4795'),
    @('lib/arm64-v8a/libEmvCoreJni.so','app/src/main/jniLibs/arm64-v8a/libEmvCoreJni.so','B04227C549EA8FBF77376DAEEFE9106CDE8BE990752C8E03F918EEB6ED2092EF'),
    @('lib/armeabi-v7a/libSmartPos.so','app/src/main/jniLibs/armeabi-v7a/libSmartPos.so','7C746FDF6FE5B103D5C1357E6D5163DA02F7CB682A55E4221BF77F31453EE287'),
    @('lib/armeabi-v7a/libSmartPosJni.so','app/src/main/jniLibs/armeabi-v7a/libSmartPosJni.so','4B64B14D1BA3B06F7EB65A50EF10D529784FD865A2010523CE3C857E87581100'),
    @('lib/armeabi-v7a/libEmvCoreJni.so','app/src/main/jniLibs/armeabi-v7a/libEmvCoreJni.so','D7AE89F0F08853D2FFF8FC65B27CDEE0BD0267B4CFB15B5929ADA4D45D9F13D4')
)
foreach($taskSdkFile in $taskZcsFiles) {
    $taskSource=Join-Path $ZcsRoot $taskSdkFile[0]
    if((Get-FileHash -LiteralPath $taskSource -Algorithm SHA256).Hash -ne $taskSdkFile[2]) {throw 'ZCS SDK checksum differs from reviewed files.'}
    $taskTarget=Join-Path $taskAndroidRoot $taskSdkFile[1]
    New-Item -ItemType Directory -Force -Path (Split-Path $taskTarget) | Out-Null
    Copy-Item -LiteralPath $taskSource -Destination $taskTarget -Force
}
Write-Output 'Reviewed ZCS 1.3.5 native libraries and matching ZXing library prepared.'
