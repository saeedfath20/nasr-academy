[CmdletBinding()]
param(
    [string]$TargetRoot = 'D:\Nasr-Academy-Mobile\nasr-academy-platform',
    [string]$SourceZip = '',
    [string]$JdkPath = '',
    [string]$DeviceSerial = '',
    [switch]$DiagnoseBuild
)
$ErrorActionPreference = 'Stop'
$nasrRelease = '1.6.0'
$nasrOldJava = $env:JAVA_HOME
$nasrOldPath = $env:PATH
$nasrStage = ''

function Invoke-NasrCommand([string]$Program, [string[]]$Arguments, [bool]$Show = $false) {
    $null = Get-Command $Program -ErrorAction Stop
    $ErrorActionPreference = 'Continue'
    $PSNativeCommandUseErrorActionPreference = $false
    $nasrLines = @(& $Program @Arguments 2>&1 | ForEach-Object {
        if ($Show) { Write-Host ([string]$_) }
        [string]$_
    })
    [pscustomobject]@{ Code = $LASTEXITCODE; Text = ($nasrLines -join "`n") }
}
function Invoke-NasrStep([string]$Program, [string[]]$Arguments, [string]$Failure) {
    $nasrRun = Invoke-NasrCommand $Program $Arguments $true
    if ($nasrRun.Code -ne 0) { throw $Failure }
}
function Invoke-NasrGradle([string]$AndroidRoot, [string]$JavaRoot, [string]$LogFile, [bool]$Fresh = $true) {
    $nasrArgs = @("-Dorg.gradle.java.home=$JavaRoot", ':app:assembleDebug', '--console=plain', '--stacktrace')
    if ($Fresh) { $nasrArgs += @('--rerun-tasks', '--no-build-cache') }
    Push-Location $AndroidRoot
    try { $nasrRun = Invoke-NasrCommand (Join-Path $AndroidRoot 'gradlew.bat') $nasrArgs $true }
    finally { Pop-Location }
    [System.IO.File]::WriteAllText($LogFile, $nasrRun.Text, [System.Text.UTF8Encoding]::new($false))
    if ($nasrRun.Code -ne 0) {
        $nasrLines = @($nasrRun.Text -split '\r?\n')
        $nasrCompiler = @($nasrLines | Where-Object { $_ -match '\berror:|^e: |Could not (resolve|find)|requires Java|invalid source release|Unsupported class file|SDK location not found|Failed to find' } | Select-Object -First 12)
        $nasrIndex = -1
        for ($nasrI = 0; $nasrI -lt $nasrLines.Count; $nasrI++) {
            if ($nasrLines[$nasrI] -match '^\* What went wrong:') { $nasrIndex = $nasrI; break }
        }
        $nasrSummary = @()
        if ($nasrIndex -ge 0) {
            for ($nasrI = $nasrIndex; $nasrI -lt [Math]::Min($nasrLines.Count, $nasrIndex + 35); $nasrI++) {
                if ($nasrI -gt $nasrIndex -and $nasrLines[$nasrI] -match '^\* (Try|Exception is):') { break }
                $nasrSummary += $nasrLines[$nasrI]
            }
        } else { $nasrSummary = @($nasrLines | Select-Object -Last 35) }
        throw ("Gradle build failed. APK was not installed.`n" + (($nasrCompiler + $nasrSummary) -join "`n") + "`nFULL GRADLE LOG: $LogFile")
    }
    Write-Host "FULL GRADLE LOG: $LogFile"
}
function Read-NasrProperty([string]$Path, [string]$Name) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return '' }
    $nasrMatch = [regex]::Match((Get-Content -LiteralPath $Path -Raw), '(?m)^\s*' + [regex]::Escape($Name) + '\s*=\s*(.+?)\s*$')
    if (-not $nasrMatch.Success) { return '' }
    $nasrMatch.Groups[1].Value.Trim().Trim('"').Replace('\:', ':').Replace('\\', '\')
}
function Get-NasrJdk([string]$AppRoot) {
    $nasrChoices = @($JdkPath)
    $nasrChoices += Read-NasrProperty (Join-Path $AppRoot 'android/gradle.properties') 'org.gradle.java.home'
    $nasrChoices += Read-NasrProperty (Join-Path $AppRoot 'android/.gradle/config.properties') 'java.home'
    $nasrChoices += $env:JAVA_HOME
    if ($env:USERPROFILE) {
        $nasrJdks = Join-Path $env:USERPROFILE '.jdks'
        if (Test-Path -LiteralPath $nasrJdks) { $nasrChoices += @(Get-ChildItem -LiteralPath $nasrJdks -Directory | Select-Object -ExpandProperty FullName) }
    }
    foreach ($nasrFolder in @('Java', 'Eclipse Adoptium', 'Microsoft', 'Amazon Corretto')) {
        if ($env:ProgramFiles) {
            $nasrParent = Join-Path $env:ProgramFiles $nasrFolder
            if (Test-Path -LiteralPath $nasrParent) { $nasrChoices += @(Get-ChildItem -LiteralPath $nasrParent -Directory | Select-Object -ExpandProperty FullName) }
        }
    }
    $nasrJavaCommand = Get-Command java.exe -ErrorAction SilentlyContinue
    if ($nasrJavaCommand) { $nasrChoices += Split-Path (Split-Path $nasrJavaCommand.Source -Parent) -Parent }
    foreach ($nasrCandidate in ($nasrChoices | Where-Object { $_ } | Select-Object -Unique)) {
        $nasrVersion = Read-NasrProperty (Join-Path $nasrCandidate 'release') 'JAVA_VERSION'
        if ($nasrVersion -match '^(21|22|23|24)(\.|-|$)' -and
            (Test-Path -LiteralPath (Join-Path $nasrCandidate 'bin/java.exe')) -and
            (Test-Path -LiteralPath (Join-Path $nasrCandidate 'bin/javac.exe'))) { return $nasrCandidate }
    }
    throw 'Compatible JDK not found. Run this script with -JdkPath followed by the existing JDK 21 folder selected in Android Studio.'
}
function Read-NasrZipText($Archive, [string]$Name) {
    $nasrEntry = $Archive.GetEntry($Name)
    if (-not $nasrEntry) { throw "Required archive entry missing: $Name" }
    $nasrReader = [System.IO.StreamReader]::new($nasrEntry.Open())
    try { $nasrReader.ReadToEnd() } finally { $nasrReader.Dispose() }
}
function Get-NasrEntryHash($Archive, [string]$Name) {
    $nasrEntry = $Archive.GetEntry($Name)
    if (-not $nasrEntry) { throw "Required APK entry missing: $Name" }
    $nasrStream = $nasrEntry.Open()
    $nasrHasher = [System.Security.Cryptography.SHA256]::Create()
    try { ([BitConverter]::ToString($nasrHasher.ComputeHash($nasrStream))).Replace('-', '').ToLowerInvariant() }
    finally { $nasrStream.Dispose(); $nasrHasher.Dispose() }
}
try {
    $nasrTarget = (Resolve-Path -LiteralPath $TargetRoot).Path
    if ($DiagnoseBuild) {
        $nasrApp = Join-Path $nasrTarget 'mobile-app'
        $nasrAndroid = Join-Path $nasrApp 'android'
        $nasrJdk = Get-NasrJdk $nasrApp
        $env:JAVA_HOME = $nasrJdk
        $env:PATH = (Join-Path $nasrJdk 'bin') + [System.IO.Path]::PathSeparator + $nasrOldPath
        $nasrLog = Join-Path $nasrTarget 'nasr-gradle-error.log'
        Write-Host "Diagnosing the existing Android project with JDK: $nasrJdk" -ForegroundColor Cyan
        Invoke-NasrGradle $nasrAndroid $nasrJdk $nasrLog $false
        Write-Host 'BUILD SUCCEEDED. Diagnostic mode did not install an APK.' -ForegroundColor Green
        return
    }
    $null = Get-Command npm.cmd -ErrorAction Stop
    $null = Get-Command npx.cmd -ErrorAction Stop
    if (-not $SourceZip) {
        Add-Type -AssemblyName System.Windows.Forms
        $nasrPicker = New-Object System.Windows.Forms.OpenFileDialog
        $nasrPicker.Title = 'Select Nasr Mobile Source 1.6.0 ZIP'
        $nasrPicker.Filter = 'ZIP files (*.zip)|*.zip'
        if ($nasrPicker.ShowDialog() -ne 'OK') { throw 'No source ZIP was selected. Nothing was installed.' }
        $SourceZip = $nasrPicker.FileName
    }
    $SourceZip = (Resolve-Path -LiteralPath $SourceZip).Path
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $nasrArchive = [System.IO.Compression.ZipFile]::OpenRead($SourceZip)
    try {
        $nasrPackage = Read-NasrZipText $nasrArchive 'nasr-academy-platform/mobile-app/package.json' | ConvertFrom-Json
        if ($nasrPackage.version -ne $nasrRelease) { throw "Wrong source ZIP version: $($nasrPackage.version). Select the complete $nasrRelease Mobile-Source ZIP." }
        $nasrNativeBuild = Read-NasrZipText $nasrArchive 'nasr-academy-platform/mobile-app/native-secure-store/android/build.gradle'
        if ($nasrNativeBuild -notmatch 'implementation\s+["'']androidx\.appcompat:appcompat:' -or
            $nasrNativeBuild -notmatch 'sourceCompatibility\s+JavaVersion\.VERSION_21' -or
            $nasrNativeBuild -notmatch 'targetCompatibility\s+JavaVersion\.VERSION_21') {
            throw 'This source ZIP is missing the Android AppCompat build fix. Select the newly downloaded complete Mobile-Source ZIP. The existing project has not been changed.'
        }
        $nasrSourceManifest = Read-NasrZipText $nasrArchive 'nasr-academy-platform/mobile-app/public/app-build.json' | ConvertFrom-Json
        if ($nasrSourceManifest.version -ne $nasrRelease) { throw 'The ZIP contains an old application manifest.' }
        foreach ($nasrFile in @('app-config.js', 'app.css', 'app-ui.js', 'app-auth.js')) {
            $nasrHash = Get-NasrEntryHash $nasrArchive ('nasr-academy-platform/mobile-app/public/' + $nasrFile)
            if ($nasrHash -ne $nasrSourceManifest.files.$nasrFile) { throw "Source ZIP hash mismatch: $nasrFile" }
        }
        foreach ($nasrEntry in $nasrArchive.Entries) {
            if ($nasrEntry.FullName -match '(^|[\\/])\.\.([\\/]|$)|^[\\/]|^[A-Za-z]:') { throw 'Unsafe archive path. Select the original Mobile-Source ZIP.' }
        }
    } finally { $nasrArchive.Dispose() }
    $nasrStage = Join-Path ([System.IO.Path]::GetTempPath()) ('nasr-install-' + [guid]::NewGuid().ToString('N'))
    [System.IO.Compression.ZipFile]::ExtractToDirectory($SourceZip, $nasrStage)
    $nasrSource = Join-Path $nasrStage 'nasr-academy-platform'
    Write-Host '[1/6] Applying the verified source update...' -ForegroundColor Cyan
    & (Join-Path $nasrSource 'Update-Mobile-App.ps1') -TargetRoot $nasrTarget
    $nasrApp = Join-Path $nasrTarget 'mobile-app'
    $nasrAndroid = Join-Path $nasrApp 'android'
    $nasrJdk = Get-NasrJdk $nasrApp
    $env:JAVA_HOME = $nasrJdk
    $env:PATH = (Join-Path $nasrJdk 'bin') + [System.IO.Path]::PathSeparator + $nasrOldPath
    $nasrSdk = Read-NasrProperty (Join-Path $nasrAndroid 'local.properties') 'sdk.dir'
    if (-not $nasrSdk) { $nasrSdk = $env:ANDROID_HOME }
    if (-not $nasrSdk) { $nasrSdk = $env:ANDROID_SDK_ROOT }
    if (-not $nasrSdk -or -not (Test-Path -LiteralPath $nasrSdk -PathType Container)) { throw 'Existing SDK path was not found. Check mobile-app/android/local.properties.' }
    $nasrAdb = Join-Path $nasrSdk 'platform-tools/adb.exe'
    if (-not (Test-Path -LiteralPath $nasrAdb)) { throw "ADB was not found: $nasrAdb" }
    $nasrConfig = Get-Content -LiteralPath (Join-Path $nasrApp 'capacitor.config.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($nasrConfig.webDir -ne 'dist') { throw 'capacitor.config.json webDir must be dist for this release.' }
    if ($nasrConfig.server.url) { throw 'Remote server.url is still configured; the bundled app would be bypassed.' }
    Write-Host "JDK: $nasrJdk"
    Write-Host "SDK: $nasrSdk"
    $nasrDevices = Invoke-NasrCommand $nasrAdb @('devices')
    if ($nasrDevices.Code -ne 0) { throw $nasrDevices.Text }
    $nasrReady = @([regex]::Matches($nasrDevices.Text, '(?m)^([^\s]+)\s+device\s*$') | ForEach-Object { $_.Groups[1].Value })
    if ($DeviceSerial -and $DeviceSerial -notin $nasrReady) { throw 'The selected phone is not ready. Enable USB debugging and accept the phone authorization prompt.' }
    if (-not $DeviceSerial -and $nasrReady.Count -eq 1) { $DeviceSerial = $nasrReady[0] }
    if ($DeviceSerial -and $DeviceSerial -notmatch '^[A-Za-z0-9._:-]+$') { throw 'Invalid device serial.' }
    # Preserve the existing app identity and avoid installing a lower version code.
    if ($DeviceSerial -and $nasrConfig.appId -match '^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$') {
        $nasrInstalled = Invoke-NasrCommand $nasrAdb @('-s', $DeviceSerial, 'shell', 'dumpsys', 'package', $nasrConfig.appId)
        $nasrOldCode = [regex]::Match($nasrInstalled.Text, '\bversionCode=(\d+)')
        $nasrGradleFile = Join-Path $nasrAndroid 'app/build.gradle'
        $nasrGradle = Get-Content -LiteralPath $nasrGradleFile -Raw -Encoding UTF8
        $nasrCurrentCode = [regex]::Match($nasrGradle, '\bversionCode\s+(\d+)')
        if ($nasrInstalled.Code -eq 0 -and $nasrOldCode.Success -and $nasrCurrentCode.Success -and [long]$nasrOldCode.Groups[1].Value -ge [long]$nasrCurrentCode.Groups[1].Value) {
            $nasrNextCode = [long]$nasrOldCode.Groups[1].Value + 1
            $nasrGradle = [regex]::Replace($nasrGradle, '\bversionCode\s+\d+', "versionCode $nasrNextCode")
            [System.IO.File]::WriteAllText($nasrGradleFile, $nasrGradle, [System.Text.UTF8Encoding]::new($false))
        }
    }
    Push-Location $nasrApp
    try {
        Write-Host '[2/6] Building the application interface...' -ForegroundColor Cyan
        Invoke-NasrStep 'npm.cmd' @('ci') 'npm ci failed. APK was not installed.'
        Invoke-NasrStep 'npm.cmd' @('run', 'build') 'Interface build failed. APK was not installed.'
        Invoke-NasrStep 'npx.cmd' @('cap', 'sync', 'android') 'Android synchronization failed. APK was not installed.'
    } finally { Pop-Location }
    $nasrBuild = Get-Content -LiteralPath (Join-Path $nasrApp 'dist/app-build.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($nasrBuild.version -ne $nasrRelease) { throw 'Wrong interface version was built.' }
    foreach ($nasrFile in @('app-config.js', 'app.css', 'app-ui.js', 'app-auth.js')) {
        if ($nasrBuild.files.$nasrFile -ne $nasrSourceManifest.files.$nasrFile) { throw "The built interface does not match the selected source ZIP: $nasrFile" }
    }
    Write-Host '[3/6] Building a fresh APK with Gradle. This can take several minutes...' -ForegroundColor Cyan
    Invoke-NasrGradle $nasrAndroid $nasrJdk (Join-Path $nasrTarget 'nasr-gradle-error.log') $true
    $nasrOutput = Join-Path $nasrAndroid 'app/build/outputs/apk/debug'
    $nasrMetadata = Get-Content -LiteralPath (Join-Path $nasrOutput 'output-metadata.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $nasrElements = @($nasrMetadata.elements | Where-Object { @($_.filters).Count -eq 0 })
    if ($nasrElements.Count -ne 1) { throw 'A single universal debug APK was not produced. Nothing was installed.' }
    $nasrElement = $nasrElements[0]
    $nasrId = [string]$nasrMetadata.applicationId
    if ($nasrId -notmatch '^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$' -or $nasrId -ne $nasrConfig.appId -or $nasrElement.versionName -ne $nasrRelease) { throw 'Native APK identity/version does not match the new release.' }
    if ([System.IO.Path]::GetFileName($nasrElement.outputFile) -ne $nasrElement.outputFile -or $nasrElement.outputFile -notlike '*.apk') { throw 'Invalid APK output path.' }
    $nasrApk = Join-Path $nasrOutput $nasrElement.outputFile
    Write-Host '[4/6] Checking the actual files inside the APK...' -ForegroundColor Cyan
    $nasrArchive = [System.IO.Compression.ZipFile]::OpenRead($nasrApk)
    try {
        $nasrEmbedded = Read-NasrZipText $nasrArchive 'assets/public/app-build.json' | ConvertFrom-Json
        if ($nasrEmbedded.version -ne $nasrRelease) { throw 'The APK contains the old interface.' }
        foreach ($nasrFile in @('app-config.js', 'app.css', 'app-ui.js', 'app-auth.js')) {
            $nasrHash = Get-NasrEntryHash $nasrArchive ('assets/public/' + $nasrFile)
            if ($nasrHash -ne $nasrBuild.files.$nasrFile -or $nasrHash -ne $nasrEmbedded.files.$nasrFile) { throw "APK contains the wrong file: $nasrFile" }
        }
        $nasrDistRoot = (Resolve-Path -LiteralPath (Join-Path $nasrApp 'dist')).Path
        foreach ($nasrDistFile in Get-ChildItem -LiteralPath $nasrDistRoot -File -Recurse) {
            $nasrRelative = $nasrDistFile.FullName.Substring($nasrDistRoot.Length + 1).Replace('\', '/')
            if ((Get-NasrEntryHash $nasrArchive ('assets/public/' + $nasrRelative)) -ne (Get-FileHash -LiteralPath $nasrDistFile.FullName -Algorithm SHA256).Hash.ToLowerInvariant()) { throw "APK entry mismatch: $nasrRelative" }
        }
        $nasrEmbeddedConfig = Read-NasrZipText $nasrArchive 'assets/capacitor.config.json' | ConvertFrom-Json
        if ($nasrEmbeddedConfig.server.url -or $nasrEmbeddedConfig.appId -ne $nasrConfig.appId) { throw 'The APK would open a remote web panel or a different app.' }
    } finally { $nasrArchive.Dispose() }
    Write-Host "APK VERIFIED: $nasrRelease" -ForegroundColor Green
    Write-Host "APK: $nasrApk"
    if (-not $DeviceSerial) {
        if ($nasrReady.Count -gt 1) { Write-Host 'NOT INSTALLED: multiple devices are connected. Run again with -DeviceSerial followed by the phone serial.' -ForegroundColor Yellow }
        else { Write-Host 'NOT INSTALLED: connect the phone with USB debugging enabled and accept its authorization prompt, then run again. You can also install the verified APK shown above manually.' -ForegroundColor Yellow }
        return
    }
    Write-Host '[5/6] Replacing the existing app on the selected phone...' -ForegroundColor Cyan
    $nasrInstall = Invoke-NasrCommand $nasrAdb @('-s', $DeviceSerial, 'install', '-r', $nasrApk) $true
    if ($nasrInstall.Code -ne 0 -or $nasrInstall.Text -notmatch '(?m)^Success\s*$') {
        if ($nasrInstall.Text -match 'INSTALL_FAILED_UPDATE_INCOMPATIBLE') { throw 'The old app uses a different signing key. Build with its original signing key. The old app has not been removed.' }
        throw 'Android did not confirm installation. See the ADB error above.'
    }
    Write-Host '[6/6] Verifying the APK that is installed on the phone...' -ForegroundColor Cyan
    $nasrPaths = Invoke-NasrCommand $nasrAdb @('-s', $DeviceSerial, 'shell', 'pm', 'path', $nasrId)
    $nasrBase = @($nasrPaths.Text -split '\r?\n' | Where-Object { $_ -match '^package:/.+/base\.apk\s*$' } | Select-Object -First 1)
    if ($nasrPaths.Code -ne 0 -or $nasrBase.Count -ne 1) { throw 'Cannot verify the installed base APK. Installation was not confirmed by this script.' }
    $nasrRemote = $nasrBase[0].Substring(8).Trim()
    $nasrPulled = Join-Path $nasrStage 'installed-base.apk'
    Invoke-NasrStep $nasrAdb @('-s', $DeviceSerial, 'pull', $nasrRemote, $nasrPulled) 'Cannot read back the installed APK. Installation verification is incomplete.'
    if ((Get-FileHash -LiteralPath $nasrPulled -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $nasrApk -Algorithm SHA256).Hash) { throw 'The APK on the phone is different from the newly built APK.' }
    $nasrInstalled = Invoke-NasrCommand $nasrAdb @('-s', $DeviceSerial, 'shell', 'dumpsys', 'package', $nasrId)
    if ($nasrInstalled.Code -ne 0 -or $nasrInstalled.Text -notmatch ('\bversionName=' + [regex]::Escape($nasrRelease) + '(\s|$)')) { throw 'Installed package version could not be confirmed.' }
    Invoke-NasrStep $nasrAdb @('-s', $DeviceSerial, 'shell', 'am', 'force-stop', $nasrId) 'The app could not be restarted.'
    Invoke-NasrStep $nasrAdb @('-s', $DeviceSerial, 'shell', 'am', 'start', '-a', 'android.intent.action.MAIN', '-c', 'android.intent.category.LAUNCHER', '-p', $nasrId) 'The new app was installed but could not be opened.'
    Write-Host "INSTALLED AND VERIFIED: $nasrRelease" -ForegroundColor Green
    Write-Host 'The login screen should now show 1.6.0.'
}
catch {
    Write-Host ('STOPPED: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
finally {
    $env:JAVA_HOME = $nasrOldJava
    $env:PATH = $nasrOldPath
    if ($nasrStage -and (Test-Path -LiteralPath $nasrStage)) { Remove-Item -LiteralPath $nasrStage -Recurse -Force -ErrorAction SilentlyContinue }
}
