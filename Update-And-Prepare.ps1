[CmdletBinding()]
param([string]$TargetRoot = 'D:\Nasr-Academy-Mobile\nasr-academy-platform')
$ErrorActionPreference = 'Stop'
try {
    $nasrTarget = (Resolve-Path -LiteralPath $TargetRoot).Path
    & (Join-Path $PSScriptRoot 'Update-Mobile-App.ps1') -TargetRoot $nasrTarget
    Push-Location (Join-Path $nasrTarget 'mobile-app')
    try {
        & npm.cmd ci
        if ($LASTEXITCODE -ne 0) { throw 'npm ci failed.' }
        & npm.cmd run build
        if ($LASTEXITCODE -ne 0) { throw 'Application build failed.' }
        & npx.cmd cap sync android
        if ($LASTEXITCODE -ne 0) { throw 'Android synchronization failed.' }
        $nasrExpected = Get-Content -LiteralPath '.\dist\app-build.json' -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($nasrExpected.version -ne '1.5.0-beta.8') { throw 'The generated application version is incorrect.' }
        foreach ($nasrRelative in @('app-config.js','app.css','app-ui.js','app-auth.js','app-build.json','index.html')) {
            $nasrWebFile = Join-Path '.\dist' $nasrRelative
            $nasrAndroidFile = Join-Path '.\android\app\src\main\assets\public' $nasrRelative
            if ((Get-FileHash -LiteralPath $nasrWebFile -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $nasrAndroidFile -Algorithm SHA256).Hash) {
                throw "Android did not receive the new file: $nasrRelative"
            }
        }
        Write-Host 'READY: 1.5.0-beta.8 - new mobile design and biometric login' -ForegroundColor Green
        Write-Host 'In Android Studio, wait for Sync, then Run to build and install the new APK.'
        & npx.cmd cap open android
        if ($LASTEXITCODE -ne 0) { throw 'Open the mobile-app/android folder in Android Studio manually.' }
    }
    finally { Pop-Location }
}
catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
