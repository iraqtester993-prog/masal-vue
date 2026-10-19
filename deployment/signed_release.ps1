param(
    [string]$VaultPath = (Join-Path $PSScriptRoot '../artifacts/private/android-signing-vault.json'),
    [string]$UnlockFile,
    [switch]$Build
)

$ErrorActionPreference = 'Stop'
$taskProjectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskVault = Get-Content -Raw -LiteralPath $VaultPath | ConvertFrom-Json
if ($taskVault.format -ne 'dananeer-signing-vault-v1' -or $taskVault.iterations -ne 600000 -or $taskVault.cipher -ne 'AES-256-GCM' -or $taskVault.kdf -ne 'PBKDF2-SHA256') { throw 'Unsupported signing vault.' }
if ($UnlockFile) {
    $taskPassphrase = (Get-Content -Raw -LiteralPath $UnlockFile).Trim()
} else {
    $taskSecure = Read-Host 'Signing vault passphrase' -AsSecureString
    $taskPassphrase = [pscredential]::new('vault', $taskSecure).GetNetworkCredential().Password
}
$taskSalt = [Convert]::FromBase64String($taskVault.salt)
$taskNonce = [Convert]::FromBase64String($taskVault.nonce)
$taskTag = [Convert]::FromBase64String($taskVault.tag)
$taskCipher = [Convert]::FromBase64String($taskVault.ciphertext)
if ($taskSalt.Length -ne 32 -or $taskNonce.Length -ne 12 -or $taskTag.Length -ne 16 -or $taskCipher.Length -gt 1048576) { throw 'Invalid signing vault.' }
$taskKdf = [Security.Cryptography.Rfc2898DeriveBytes]::new($taskPassphrase, $taskSalt, 600000, [Security.Cryptography.HashAlgorithmName]::SHA256)
$taskKey = $taskKdf.GetBytes(32)
$taskPlain = [byte[]]::new($taskCipher.Length)
$taskAes = [Security.Cryptography.AesGcm]::new($taskKey)
$taskTemporaryDirectory = $null
$taskCertificate = $null
$taskEnvBefore = @{}
try {
    $taskAes.Decrypt($taskNonce, $taskCipher, $taskTag, $taskPlain)
    $taskSecrets = [Text.Encoding]::UTF8.GetString($taskPlain) | ConvertFrom-Json
    $taskP12 = [Convert]::FromBase64String($taskSecrets.keystore_base64)
    $taskCertificate = [Security.Cryptography.X509Certificates.X509Certificate2]::new($taskP12, $taskSecrets.store_password, [Security.Cryptography.X509Certificates.X509KeyStorageFlags]::EphemeralKeySet)
    $taskArtifact = Get-Content -Raw -LiteralPath (Join-Path $taskProjectRoot 'artifacts/android/manifest.json') | ConvertFrom-Json
    $taskFingerprint = $taskCertificate.GetCertHashString([Security.Cryptography.HashAlgorithmName]::SHA256).ToLowerInvariant()
    if (-not $taskCertificate.HasPrivateKey -or $taskFingerprint -ne $taskArtifact.signer_sha256 -or $taskFingerprint -ne $taskSecrets.certificate_sha256) { throw 'Signing certificate does not match the approved application.' }
    if (-not $Build) {
        [pscustomobject]@{passed=$true;certificate_sha256=$taskFingerprint;private_key_verified=$true;files_written=0;release_built=$false} | ConvertTo-Json
        return
    }
    $taskTemporaryDirectory = Join-Path ([IO.Path]::GetTempPath()) ('dananeer-signing-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $taskTemporaryDirectory -ErrorAction Stop | Out-Null
    $taskStore = Join-Path $taskTemporaryDirectory 'release.p12'
    [IO.File]::WriteAllBytes($taskStore, $taskP12)
    $taskValues = @{DANANEER_SIGNING_STORE=$taskStore;DANANEER_SIGNING_STORE_PASSWORD=$taskSecrets.store_password;DANANEER_SIGNING_KEY_ALIAS=$taskSecrets.key_alias;DANANEER_SIGNING_KEY_PASSWORD=$taskSecrets.key_password}
    foreach ($taskName in $taskValues.Keys) {
        $taskEnvBefore[$taskName] = [Environment]::GetEnvironmentVariable($taskName, 'Process')
        [Environment]::SetEnvironmentVariable($taskName, $taskValues[$taskName], 'Process')
    }
    & (Join-Path $taskProjectRoot 'android-pos/scripts/build.ps1') -Configuration Release
} finally {
    foreach ($taskName in $taskEnvBefore.Keys) {
        if ($null -eq $taskEnvBefore[$taskName]) { Remove-Item -LiteralPath ('Env:'+$taskName) -ErrorAction SilentlyContinue }
        else { [Environment]::SetEnvironmentVariable($taskName, $taskEnvBefore[$taskName], 'Process') }
    }
    if ($taskTemporaryDirectory) {
        $taskStore = Join-Path $taskTemporaryDirectory 'release.p12'
        if (Test-Path -LiteralPath $taskStore) { Remove-Item -LiteralPath $taskStore -Force }
        if (-not (Get-ChildItem -Force -LiteralPath $taskTemporaryDirectory)) { Remove-Item -LiteralPath $taskTemporaryDirectory -Force }
    }
    if ($taskCertificate) { $taskCertificate.Dispose() }
    $taskAes.Dispose()
    $taskKdf.Dispose()
    [Array]::Clear($taskKey, 0, $taskKey.Length)
    [Array]::Clear($taskPlain, 0, $taskPlain.Length)
    if ($taskP12) { [Array]::Clear($taskP12, 0, $taskP12.Length) }
    $taskPassphrase = $null
    $taskSecrets = $null
}
