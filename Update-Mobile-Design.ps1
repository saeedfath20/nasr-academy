[CmdletBinding()]
param([Parameter(Mandatory = $true)][string]$TargetRoot)
& (Join-Path $PSScriptRoot 'Update-Mobile-App.ps1') -TargetRoot $TargetRoot
