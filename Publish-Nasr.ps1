[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$SigningKey)
$ErrorActionPreference='Stop'
$nasrRoot=$PSScriptRoot
$nasrKey=(Resolve-Path -LiteralPath $SigningKey).Path
Push-Location (Join-Path $nasrRoot 'mobile-app')
try {
    & npm.cmd ci --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw 'Dependency installation failed. No release was published.' }
    & npm.cmd run release -- --key $nasrKey
    if ($LASTEXITCODE -ne 0) { throw 'Release build failed. No release was published.' }
    Write-Host 'Release packages are ready in dist-release. Install the WordPress ZIP on your site to publish this release.' -ForegroundColor Green
} finally { Pop-Location }
