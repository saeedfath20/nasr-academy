[CmdletBinding()]
param([Parameter(Mandatory = $true)][string]$TargetRoot)

$ErrorActionPreference = 'Stop'
$nasrSource = $PSScriptRoot
$nasrTarget = (Resolve-Path -LiteralPath $TargetRoot).Path
if ($nasrSource.TrimEnd('\') -eq $nasrTarget.TrimEnd('\')) {
    throw 'Extract this release to a separate folder, then specify your existing project with -TargetRoot.'
}
$nasrCopyFiles = @(
    'mobile-app/src/main.js',
    'mobile-app/index.html',
    'mobile-app/package.json',
    'mobile-app/package-lock.json',
    'mobile-app/scripts/prepare-core.mjs',
    'mobile-app/scripts/generate-icons.py',
    'mobile-app/resources/icon.png',
    'mobile-app/public/app-config.js',
    'mobile-app/public/app.css',
    'mobile-app/public/app-ui.js',
    'mobile-app/public/app-auth.js',
    'GUIDE-fa.md',
    'Update-And-Prepare.ps1',
    'Install-Nasr-Android.ps1',
    'Publish-Nasr.ps1',
    'release.json',
    'release/public.pem',
    'scripts/release.mjs',
    'scripts/zip.mjs',
    'UNIFIED-fa.md'
)
$nasrJavaRoot = Join-Path $nasrSource 'mobile-app/android/app/src/main/java/com/nasr/academy'
foreach ($nasrJavaFile in Get-ChildItem -LiteralPath $nasrJavaRoot -File) { $nasrCopyFiles += 'mobile-app/android/app/src/main/java/com/nasr/academy/' + $nasrJavaFile.Name }
foreach ($nasrSharedRoot in @('scripts','tests','.github')) {
    $nasrDirectory = Join-Path $nasrSource $nasrSharedRoot
    foreach ($nasrSharedFile in Get-ChildItem -LiteralPath $nasrDirectory -File -Recurse) { $nasrCopyFiles += $nasrSharedRoot + '/' + $nasrSharedFile.FullName.Substring($nasrDirectory.Length + 1).Replace('\','/') }
}
$nasrCopyFiles += @('AGENTS.md','.gitattributes')
$nasrCopyFiles = @($nasrCopyFiles | Select-Object -Unique)
$nasrNativeRoot = Join-Path $nasrSource 'mobile-app/native-secure-store'
foreach ($nasrNativeFile in Get-ChildItem -LiteralPath $nasrNativeRoot -File -Recurse) {
    $nasrCopyFiles += 'mobile-app/native-secure-store/' + $nasrNativeFile.FullName.Substring($nasrNativeRoot.Length + 1).Replace('\', '/')
}
$nasrPluginRoot = Join-Path $nasrSource 'nasr-academy'
foreach ($nasrPluginFile in Get-ChildItem -LiteralPath $nasrPluginRoot -File -Recurse) {
    $nasrCopyFiles += 'nasr-academy/' + $nasrPluginFile.FullName.Substring($nasrPluginRoot.Length + 1).Replace('\', '/')
}
$nasrResourceRoot = Join-Path $nasrSource 'mobile-app/android/app/src/main/res'
foreach ($nasrIcon in Get-ChildItem -LiteralPath $nasrResourceRoot -File -Recurse | Where-Object { $_.Name -like 'nasr_launcher*' -or $_.Name -eq 'nasr_icon_background.xml' }) {
    $nasrCopyFiles += 'mobile-app/android/app/src/main/res/' + $nasrIcon.FullName.Substring($nasrResourceRoot.Length + 1).Replace('\', '/')
}
$nasrIosIcons = Join-Path $nasrSource 'mobile-app/ios/App/App/Assets.xcassets/AppIcon.appiconset'
foreach ($nasrIcon in Get-ChildItem -LiteralPath $nasrIosIcons -File) {
    $nasrCopyFiles += 'mobile-app/ios/App/App/Assets.xcassets/AppIcon.appiconset/' + $nasrIcon.Name
}
$nasrRemoveFiles = @('nasr-academy/assets/sync.js', 'mobile-app/public/core/sync.js', 'mobile-app/dist/core/sync.js', 'mobile-app/android/app/src/main/assets/public/core/sync.js', 'mobile-app/ios/App/App/public/core/sync.js', 'mobile-app/ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png')
foreach ($nasrRelative in $nasrCopyFiles) {
    if (-not (Test-Path -LiteralPath (Join-Path $nasrSource $nasrRelative) -PathType Leaf)) {
        throw "Release file missing: $nasrRelative"
    }
}
# An interrupted extraction may have left only the Android folder. Restore missing
# source files without replacing existing SDK, Gradle, app identity or signing files.
foreach ($nasrMarker in @('mobile-app/package.json', 'mobile-app/capacitor.config.json', 'nasr-academy/nasr-academy.php')) {
    if (-not (Test-Path -LiteralPath (Join-Path $nasrSource $nasrMarker) -PathType Leaf)) { throw "Release file missing: $nasrMarker" }
}
foreach ($nasrFile in Get-ChildItem -LiteralPath $nasrSource -File -Recurse) {
    $nasrRelative = $nasrFile.FullName.Substring($nasrSource.Length + 1)
    if ($nasrRelative -match '(^|[\\/])(node_modules|build|dist|\.gradle|\.idea)([\\/]|$)' -or $nasrFile.Name -in @('local.properties', 'keystore.properties') -or $nasrFile.Extension -in @('.jks', '.keystore')) { continue }
    $nasrDestination = Join-Path $nasrTarget $nasrRelative
    if (-not (Test-Path -LiteralPath $nasrDestination)) {
        New-Item -ItemType Directory -Path (Split-Path $nasrDestination -Parent) -Force | Out-Null
        Copy-Item -LiteralPath $nasrFile.FullName -Destination $nasrDestination
    }
}
$nasrUtf8 = New-Object System.Text.UTF8Encoding($false)
$nasrEdits = @{}
$nasrConfigRelative = 'mobile-app/capacitor.config.json'
$nasrConfig = Get-Content -LiteralPath (Join-Path $nasrTarget $nasrConfigRelative) -Raw -Encoding UTF8 | ConvertFrom-Json
$nasrConfig | Add-Member -NotePropertyName backgroundColor -NotePropertyValue '#f5f6fc' -Force
# Loading a remote web page bypasses the entire bundled application design.
if ($nasrConfig.server) { $nasrConfig.server.PSObject.Properties.Remove('url') }
$nasrEdits[$nasrConfigRelative] = ($nasrConfig | ConvertTo-Json -Depth 30)
$nasrGradleRelative = 'mobile-app/android/app/build.gradle'
if (Test-Path -LiteralPath (Join-Path $nasrTarget $nasrGradleRelative)) {
    $nasrGradle = Get-Content -LiteralPath (Join-Path $nasrTarget $nasrGradleRelative) -Raw -Encoding UTF8
    $nasrCode = [regex]::Match($nasrGradle, '\bversionCode\s+(\d+)')
    if ($nasrCode.Success) {
        $nasrNextCode = [Math]::Max(1006000, [int]$nasrCode.Groups[1].Value + 1)
        $nasrGradle = [regex]::Replace($nasrGradle, '\bversionCode\s+\d+', "versionCode $nasrNextCode")
        $nasrGradle = [regex]::Replace($nasrGradle, '\bversionName\s+"[^"]+"', 'versionName "1.6.0"')
        $nasrEdits[$nasrGradleRelative] = $nasrGradle
    }
}
$nasrManifestRelative = 'mobile-app/android/app/src/main/AndroidManifest.xml'
if (Test-Path -LiteralPath (Join-Path $nasrTarget $nasrManifestRelative)) {
    $nasrManifest = Get-Content -LiteralPath (Join-Path $nasrTarget $nasrManifestRelative) -Raw -Encoding UTF8
    $nasrManifest = [regex]::Replace($nasrManifest, 'android:icon="[^"]+"', 'android:icon="@mipmap/nasr_launcher"')
    $nasrManifest = [regex]::Replace($nasrManifest, 'android:roundIcon="[^"]+"', 'android:roundIcon="@mipmap/nasr_launcher_round"')
    $nasrEdits[$nasrManifestRelative] = $nasrManifest
}
$nasrIosRelative = 'mobile-app/ios/App/App.xcodeproj/project.pbxproj'
if (Test-Path -LiteralPath (Join-Path $nasrTarget $nasrIosRelative)) {
    $nasrIos = Get-Content -LiteralPath (Join-Path $nasrTarget $nasrIosRelative) -Raw -Encoding UTF8
    $nasrIos = [regex]::Replace($nasrIos, 'CURRENT_PROJECT_VERSION = (\d+);', { param($nasrMatch) 'CURRENT_PROJECT_VERSION = ' + [Math]::Max(1006000, [int]$nasrMatch.Groups[1].Value + 1) + ';' })
    $nasrEdits[$nasrIosRelative] = $nasrIos
}
$nasrPlistRelative = 'mobile-app/ios/App/App/Info.plist'
if (Test-Path -LiteralPath (Join-Path $nasrTarget $nasrPlistRelative)) {
    $nasrPlist = Get-Content -LiteralPath (Join-Path $nasrTarget $nasrPlistRelative) -Raw -Encoding UTF8
    if ($nasrPlist -notmatch '<key>NSFaceIDUsageDescription</key>') {
        $nasrPlist = ([regex]'<dict>').Replace($nasrPlist, '<dict><key>NSFaceIDUsageDescription</key><string>Secure sign-in to Nasr Academy using Face ID.</string>', 1)
        $nasrEdits[$nasrPlistRelative] = $nasrPlist
    }
}
$nasrBackup = Join-Path (Split-Path $nasrTarget -Parent) ('nasr-app-backup-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Path $nasrBackup -ErrorAction Stop | Out-Null
foreach ($nasrRelative in @($nasrCopyFiles) + @($nasrEdits.Keys) + @($nasrRemoveFiles)) {
    $nasrExisting = Join-Path $nasrTarget $nasrRelative
    if (Test-Path -LiteralPath $nasrExisting -PathType Leaf) {
        $nasrBackupFile = Join-Path $nasrBackup $nasrRelative
        New-Item -ItemType Directory -Path (Split-Path $nasrBackupFile -Parent) -Force | Out-Null
        Copy-Item -LiteralPath $nasrExisting -Destination $nasrBackupFile -Force
    }
}
foreach ($nasrRelative in $nasrCopyFiles) {
    $nasrDestination = Join-Path $nasrTarget $nasrRelative
    New-Item -ItemType Directory -Path (Split-Path $nasrDestination -Parent) -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $nasrSource $nasrRelative) -Destination $nasrDestination -Force
}
foreach ($nasrRelative in $nasrEdits.Keys) {
    [System.IO.File]::WriteAllText((Join-Path $nasrTarget $nasrRelative), $nasrEdits[$nasrRelative], $nasrUtf8)
}
foreach ($nasrRelative in $nasrRemoveFiles) {
    $nasrObsolete = Join-Path $nasrTarget $nasrRelative
    if (Test-Path -LiteralPath $nasrObsolete -PathType Leaf) { Remove-Item -LiteralPath $nasrObsolete -Force }
}
Write-Output "Nasr 1.6.0 app updated. Backup: $nasrBackup"
Write-Output 'Next, run npm ci, npm run build and npx cap sync android in your existing mobile-app folder.'
