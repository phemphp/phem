#Requires -Version 5.1
<#
.SYNOPSIS
    Downloads the phemc binary of the latest release for Windows.
.DESCRIPTION
    This script downloads the latest phemc Windows binary from GitHub to the install directory
    (~\.phem by default). With Composer, you do not need it: `composer require phem/phem`
    gives vendor/bin/phemc.

    Usage as a one-liner:
        irm https://github.com/phemphp/phem/raw/refs/heads/main/install.ps1 | iex
    Custom install directory:
        $env:PHEMC_INSTALL = 'C:\phem'; irm https://github.com/phemphp/phem/raw/refs/heads/main/install.ps1 | iex
#>

$ErrorActionPreference = "Stop"

$Repo = "phemphp/phem"

if ($env:PHEMC_INSTALL) {
    $BinDir = $env:PHEMC_INSTALL
} else {
    $BinDir = Join-Path $HOME ".phem"
}

if (-not (Test-Path $BinDir)) {
    New-Item -ItemType Directory -Path $BinDir -Force | Out-Null
}

$Dest = Join-Path $BinDir "phemc.exe"

Write-Host "Downloading phemc for Windows (x64)..." -ForegroundColor Cyan

try {
    Invoke-WebRequest -Uri "https://github.com/$Repo/releases/latest/download/phemc-windows-x86_64.exe" -OutFile $Dest
} catch {
    Write-Host "Download failed: $_" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "phemc downloaded to $Dest" -ForegroundColor Green

# Check if the directory is in PATH.
$inPath = $env:PATH -split ";" | Where-Object { $_ -eq $BinDir -or $_ -eq "$BinDir\" }
if (-not $inPath) {
    Write-Host "Add $BinDir to your PATH to use phemc.exe globally:" -ForegroundColor Yellow
    Write-Host "  [Environment]::SetEnvironmentVariable('PATH', `"$BinDir;`" + [Environment]::GetEnvironmentVariable('PATH', 'User'), 'User')" -ForegroundColor Gray
}
