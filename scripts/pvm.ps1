#!/usr/bin/env pwsh
#Requires -Version 5.1

<#
.SYNOPSIS
    PHP Version Manager for Windows (pvm) - designed for use with phpvenv

.DESCRIPTION
    Downloads PHP binaries from https://downloads.php.net/~windows/releases/,
    installs them in a layout compatible with the official Windows installer,
    and uses directory symbolic links/junctions to expose major.minor aliases
    (e.g. 8.5) pointing to a specific full version directory (e.g. 8.5.12-1).
    This allows phpvenv to create virtual environments from the aliased directory.

.NOTES
    Put this file in the phpvenv project's bin/ directory as pvm.ps1
#>

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('install', 'use', 'link', 'list', 'ls', 'remove', 'uninstall', 'update', 'current', 'path', 'help', '-h', '--help')]
    [string]$Command = 'help',

    [Parameter(Position = 1)]
    [string]$Version,

    [Parameter(Position = 2)]
    [string]$TargetVersion,

    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$RemainingArgs
)

# -------------------- Configuration --------------------
$Script:ReleasesBase = 'https://downloads.php.net/~windows'
$Script:ReleasesIndex = "$Script:ReleasesBase/releases"
$Script:ReleasesJson = "$Script:ReleasesIndex/releases.json"
$Script:ArchivesIndex = "$Script:ReleasesIndex/archives/"

# -------------------- Utilities --------------------
function Write-Info { param([string]$Message) Write-Host "[INFO] $Message" -ForegroundColor Cyan }
function Write-Success { param([string]$Message) Write-Host "[OK] $Message" -ForegroundColor Green }
function Write-Warn { param([string]$Message) Write-Host "[WARN] $Message" -ForegroundColor Yellow }
function Write-Err { param([string]$Message) Write-Host "[ERR] $Message" -ForegroundColor Red }

function Edit-PathForCompare([string]$p) {
    if ([string]::IsNullOrWhiteSpace($p)) { return '' }
    return ($p.Trim().Trim('"').TrimEnd('\')).ToLowerInvariant()
}

function Send-EnvironmentChangeBroadcast {
    try {
        $sig = @'
using System;
using System.Runtime.InteropServices;
public static class NativeMethods {
  [DllImport("user32.dll", SetLastError=true, CharSet=CharSet.Auto)]
  public static extern IntPtr SendMessageTimeout(
    IntPtr hWnd, uint Msg, UIntPtr wParam, string lParam,
    uint fuFlags, uint uTimeout, out UIntPtr lpdwResult);
}
'@
        Add-Type -TypeDefinition $sig -ErrorAction SilentlyContinue | Out-Null
        $HWND_BROADCAST = [IntPtr]0xffff
        $WM_SETTINGCHANGE = 0x001A
        $SMTO_ABORTIFHUNG = 0x0002
        [UIntPtr]$result = [UIntPtr]::Zero
        [NativeMethods]::SendMessageTimeout($HWND_BROADCAST, $WM_SETTINGCHANGE, [UIntPtr]::Zero, "Environment", $SMTO_ABORTIFHUNG, 5000, [ref]$result) | Out-Null
    } catch { }
}

function Get-File {
    param (
        [Parameter(Mandatory = $true)][string]$Url,
        [Parameter(Mandatory = $false)][string]$FallbackUrl,
        [Parameter(Mandatory = $false)][string]$OutFile = '',
        [Parameter(Mandatory = $false)][int]$Retries = 3,
        [Parameter(Mandatory = $false)][int]$TimeoutSec = 300
    )
    for ($i = 0; $i -lt $Retries; $i++) {
        try {
            if ($OutFile -ne '') {
                Invoke-WebRequest -Uri $Url -OutFile $OutFile -TimeoutSec $TimeoutSec -UseBasicParsing -ErrorAction Stop
                return
            } else {
                return Invoke-WebRequest -Uri $Url -TimeoutSec $TimeoutSec -UseBasicParsing -ErrorAction Stop
            }
        } catch {
            if ($i -eq ($Retries - 1)) {
                if ($FallbackUrl) {
                    try {
                        if ($OutFile -ne '') {
                            Invoke-WebRequest -Uri $FallbackUrl -OutFile $OutFile -TimeoutSec $TimeoutSec -UseBasicParsing -ErrorAction Stop
                            return
                        } else {
                            return Invoke-WebRequest -Uri $FallbackUrl -TimeoutSec $TimeoutSec -UseBasicParsing -ErrorAction Stop
                        }
                    } catch {
                        throw "Failed to download from $Url and $FallbackUrl"
                    }
                } else {
                    throw "Failed to download from $Url"
                }
            }
            Start-Sleep -Seconds 2
        }
    }
}

# -------------------- Path Resolution --------------------
function Get-PvmRoot {
    return (Join-Path $env:LOCALAPPDATA 'Programs\PHP')
}

# -------------------- Version mapping --------------------
function Get-VSVersion {
    param([Parameter(Mandatory = $true)][string]$MajorMinor)
    $map = @{
        '5.2' = 'VC6'
        '5.3' = 'VC9'; '5.4' = 'VC9'
        '5.5' = 'VC11'; '5.6' = 'VC11'
        '7.0' = 'VC14'; '7.1' = 'VC14'
        '7.2' = 'VC15'; '7.3' = 'VC15'; '7.4' = 'vc15'
        '8.0' = 'vs16'; '8.1' = 'vs16'; '8.2' = 'vs16'; '8.3' = 'vs16'
        '8.4' = 'vs17'; '8.5' = 'vs17'
    }
    if ($map.ContainsKey($MajorMinor)) { return $map[$MajorMinor] }
    throw "Unsupported PHP series: $MajorMinor"
}

function Get-ReleaseType {
    param([Parameter(Mandatory = $true)][string]$Semver)
    if ($Semver -match "[a-zA-Z]") { return "qa" } else { return "releases" }
}

function Get-SemverForSeries {
    param([Parameter(Mandatory = $true)][string]$MajorMinor)
    try {
        $resp = Get-File -Url $Script:ReleasesJson
        $releases = ($resp.Content | ConvertFrom-Json)
        $semver = $releases.$MajorMinor.version
        if ($null -ne $semver -and $semver -ne '') { return [string]$semver }
    } catch { }
    try {
        $resp = Get-File -Url $Script:ArchivesIndex
        $html = $resp.Content
        $rx = [regex]"php-($([regex]::Escape($MajorMinor))\.[0-9]+(?:-[0-9]+)?)"
        $found = $rx.Matches($html) | ForEach-Object { $_.Groups[1].Value } |
            Sort-Object { [version]($_ -replace '-', '.') } -Descending |
            Select-Object -First 1
        if ($null -ne $found) { return [string]$found }
    } catch { }
    throw "Could not resolve latest version for series $MajorMinor. Check https://downloads.php.net/~windows/releases/"
}

function Get-PhpZipName {
    param(
        [string]$MajorMinor,
        [string]$Semver,
        [string]$Arch = 'x64',
        [bool]$ThreadSafe = $false
    )
    $vs = Get-VSVersion $MajorMinor
    if ($ThreadSafe) {
        return "php-$Semver-Win32-$vs-$Arch.zip"
    } else {
        $ts = 'nts'
        return "php-$Semver-$ts-Win32-$vs-$Arch.zip"
    }
}

function Get-PhpDownloadUrl {
    param(
        [string]$MajorMinor,
        [string]$Semver,
        [string]$Arch = 'x64',
        [bool]$ThreadSafe = $false
    )
    $zipName = Get-PhpZipName -MajorMinor $MajorMinor -Semver $Semver -Arch $Arch -ThreadSafe $ThreadSafe
    $type = Get-ReleaseType -Semver $Semver
    $base = "$Script:ReleasesBase/$type"
    return @("$base/$zipName", "$base/archives/$zipName")
}

# -------------------- Symlink/Junction --------------------
function New-DirLink {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$LinkPath,
        [Parameter(Mandatory = $true)][string]$TargetPath
    )
    if (Test-Path -LiteralPath $LinkPath) {
        try {
            Remove-Item -LiteralPath $LinkPath -Force -Recurse -ErrorAction Stop
        } catch {
            throw "Failed to remove existing link/path: $LinkPath"
        }
    }
    $parent = Split-Path -Parent $LinkPath
    if (-not (Test-Path -LiteralPath $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
    try {
        New-Item -ItemType SymbolicLink -Path $LinkPath -Target $TargetPath -ErrorAction Stop | Out-Null
        return 'SymbolicLink'
    } catch {
        try {
            New-Item -ItemType Junction -Path $LinkPath -Target $TargetPath -ErrorAction Stop | Out-Null
            return 'Junction'
        } catch {
            throw "Failed to create link $LinkPath -> $TargetPath. Try running as Administrator for SymbolicLink."
        }
    }
}

function Get-LinkTarget {
    param([string]$Path)
    try {
        $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            try {
                return $item.Target
            } catch {
                return $item.ResolveTarget
            }
        }
    } catch { }
    return $null
}

# -------------------- Install/Link/List --------------------
function Install-PHPVersion {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$MajorMinor,
        [string]$Arch = 'x64',
        [bool]$ThreadSafe = $false,
        [string]$Timezone = 'UTC',
        [switch]$Force
    )
    if ($MajorMinor -notmatch '^\d+\.\d+$') { throw "Version must be major.minor like 8.5 or 8.4" }

    $installRoot = Get-PvmRoot
    if (-not (Test-Path -LiteralPath $installRoot)) { New-Item -ItemType Directory -Path $installRoot -Force | Out-Null }

    if ([version]$MajorMinor -lt [version]'5.5' -and $Arch -eq 'x64') {
        Write-Warn "PHP $MajorMinor has limited x64 support on Windows; using x86"
        $Arch = 'x86'
    }

    $Semver = Get-SemverForSeries -MajorMinor $MajorMinor
    $tsTag = if ($ThreadSafe) { 'ts' } else { 'nts' }
    $variantLabel = if ($ThreadSafe) { 'Thread Safe (ts)' } else { 'Non Thread Safe (nts)' }
    $fullBase = Join-Path $installRoot $Semver
    $installDir = Join-Path (Join-Path $fullBase $tsTag) $Arch

    if ((Test-Path -LiteralPath $installDir) -and -not $Force) {
        Write-Info "Already installed: $installDir [$variantLabel]"
    } else {
        if (Test-Path -LiteralPath $installDir) {
            Write-Warn "Removing existing installation due to -Force: $installDir [$variantLabel]"
            Remove-Item -LiteralPath $installDir -Recurse -Force -ErrorAction SilentlyContinue
        }
        New-Item -ItemType Directory -Path $installDir -Force | Out-Null

        $urls = Get-PhpDownloadUrl -MajorMinor $MajorMinor -Semver $Semver -Arch $Arch -ThreadSafe $ThreadSafe
        $tempFile = [IO.Path]::ChangeExtension([IO.Path]::GetTempFileName(), '.zip')
        try {
            Write-Info "Downloading PHP $Semver ($Arch, $tsTag) [$variantLabel]"
            $downloaded = $false
            foreach ($u in $urls) {
                try {
                    Get-File -Url $u -OutFile $tempFile
                    $downloaded = $true
                    break
                } catch {
                    Write-Warn "Failed: $u"
                }
            }
            if (-not $downloaded) { throw "Could not download PHP $Semver zip" }
            Write-Info "Extracting to $installDir"
            Expand-Archive -Path $tempFile -DestinationPath $installDir -Force -ErrorAction Stop
        } finally {
            if (Test-Path $tempFile) { Remove-Item $tempFile -Force -ErrorAction SilentlyContinue }
        }

        $phpIniProd = Join-Path $installDir "php.ini-production"
        if (-not (Test-Path $phpIniProd)) { $phpIniProd = Join-Path $installDir "php.ini-recommended" }
        $phpIni = Join-Path $installDir "php.ini"
        if (Test-Path $phpIniProd) {
            Copy-Item $phpIniProd $phpIni -Force
            $extensionDir = Join-Path $installDir "ext"
            try {
                (Get-Content $phpIni) -replace '^extension_dir = "./"', "extension_dir = `"$extensionDir`"" |
                    Set-Content $phpIni -Encoding ASCII
            } catch { }
            try {
                (Get-Content $phpIni) -replace ';\s?extension_dir = "ext"', "extension_dir = `"$extensionDir`"" |
                    Set-Content $phpIni -Encoding ASCII
            } catch { }
            try {
                (Get-Content $phpIni) -replace ';\s?date.timezone =', "date.timezone = `"$Timezone`"" |
                    Set-Content $phpIni -Encoding ASCII
            } catch { }
        }
    }

    $aliasDir = Join-Path $installRoot $MajorMinor
    $linkType = New-DirLink -LinkPath $aliasDir -TargetPath $installDir
    Write-Success "Linked $MajorMinor ($linkType) -> $installDir [$variantLabel]"

    if (-not (Test-Path -LiteralPath (Join-Path $installRoot 'current'))) {
        $currentDir = Join-Path $installRoot 'current'
        try {
            $currentLinkType = New-DirLink -LinkPath $currentDir -TargetPath $aliasDir
            Write-Success "Updated current ($currentLinkType) -> $aliasDir"
        } catch {
            Write-Warn "Failed to update 'current' symlink: $($_.Exception.Message)"
        }
    }

    Write-Success "Installed PHP $Semver ($Arch, $tsTag) to $installDir"
    Write-Info "Alias available at: $aliasDir (phpvenv can use this path)"
    return @{ Root = $installRoot; Alias = $aliasDir; Full = $installDir; Semver = $Semver; ThreadSafe = $ThreadSafe }
}

function Use-PHPVersion {
    param(
        [Parameter(Mandatory = $true)][string]$MajorMinor,
        [string]$Semver = $null,
        [string]$Arch = $null,
        [bool]$ThreadSafe = $false,
        [switch]$NoPath
    )
    if ($MajorMinor -notmatch '^\d+\.\d+$') { throw "Version must be major.minor like 8.5" }
    $Root = Get-PvmRoot

    if (-not $Semver) {
        $Semver = Get-SemverForSeries -MajorMinor $MajorMinor
    }
    if (-not $Arch) { $Arch = 'x64' }
    $tsTag = if ($ThreadSafe) { 'ts' } else { 'nts' }
    $variantLabel = if ($ThreadSafe) { 'Thread Safe (ts)' } else { 'Non Thread Safe (nts)' }
    $fullBase = Join-Path $Root $Semver
    $installDir = Join-Path (Join-Path $fullBase $tsTag) $Arch
    if (-not (Test-Path -LiteralPath $installDir)) {
        throw "Installation not found: $installDir [$variantLabel]. Run 'pvm install $MajorMinor -ThreadSafe $($ThreadSafe.ToString())' first."
    }
    $aliasDir = Join-Path $Root $MajorMinor
    $linkType = New-DirLink -LinkPath $aliasDir -TargetPath $installDir
    Write-Success "Switched $MajorMinor ($linkType) -> $installDir [$variantLabel]"

    # Also update 'current' symlink like nvm
    $currentDir = Join-Path $Root 'current'
    try {
        $currentLinkType = New-DirLink -LinkPath $currentDir -TargetPath $aliasDir
        Write-Success "Updated current ($currentLinkType) -> $aliasDir"
    } catch {
        Write-Warn "Failed to update 'current' symlink: $($_.Exception.Message)"
    }

    if (-not $NoPath) {
        Write-Warn "Note: pvm does not globally override system PATH like some managers. For phpvenv, use the alias directory: $aliasDir"
    }
}

function List-PHPVersions {
    $Root = Get-PvmRoot
    if (-not $Root -or -not (Test-Path -LiteralPath $Root)) {
        Write-Warn "PVM root not found. Install something first or set root."
        return
    }
    Write-Info "PVM Root: $Root"
    Write-Host ""
    $currentTarget = Get-LinkTarget -Path (Join-Path $Root 'current')
    $currentVersion = $null
    if ($currentTarget -match '(\\|/)(\d+\.\d+)(\\|/|$)') { $currentVersion = $Matches[2] }
    $aliases = Get-ChildItem -LiteralPath $Root -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^\d+\.\d+$' }
    if ($aliases) {
        Write-Host "Aliases (major.minor):" -ForegroundColor Yellow
        foreach ($a in $aliases) {
            $t = Get-LinkTarget -Path $a.FullName
            $mark = if ($currentVersion -eq $a.Name) { '*' } else { ' ' }
            Write-Host " $mark $($a.Name) -> $(if($t){$t}else{$a.FullName})"
        }
        Write-Host ""
    }
    $fulls = Get-ChildItem -LiteralPath $Root -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^\d+\.\d+\.\d+' }
    if ($fulls) {
        Write-Host "Installed full versions:" -ForegroundColor Yellow
        foreach ($f in $fulls | Sort-Object Name -Descending) {
            Write-Host "   $($f.Name)"
            $tsDirs = Get-ChildItem -LiteralPath $f.FullName -Directory -ErrorAction SilentlyContinue
            foreach ($ts in $tsDirs) {
                $archDirs = Get-ChildItem -LiteralPath $ts.FullName -Directory -ErrorAction SilentlyContinue
                foreach ($arch in $archDirs) {
                    $hasPhp = Test-Path (Join-Path $arch.FullName 'php.exe')
                    Write-Host "     - $($ts.Name)/$($arch.Name)$(if($hasPhp){' [php.exe]'}else{''})"
                }
            }
        }
        Write-Host ""
    }
    if (-not $aliases -and -not $fulls) {
        Write-Warn "No PHP installations found in $Root"
    }
}

function Show-Help {
    Write-Host @"
PHP Version Manager for Windows (pvm) - for phpvenv

Usage:
  pvm.ps1 <command> [args] [options]

Commands:
  install <major.minor>   Download and install latest patch for series (e.g. 8.5, 8.4)
                          Creates alias <major.minor> pointing to installed full version
  use <major.minor>       Switch alias <major.minor> to installed variant for that series
                          Use -ThreadSafe True to select ts; default nts
                          Also updates 'current' symlink/junction to this version
  link <major.minor> [fullver] [ts/nts] [x64/x86]
                          Manually link alias to a specific installed full version
                          Also updates 'current' symlink/junction
  list | ls               List aliases and installed versions
  update <major.minor>    Install latest patch and relink alias
  remove <major.minor>    Remove alias (does not delete full install by default)
  path <major.minor>      Show path to alias/full for phpvenv usage
  current                 Show currently active version (from 'current' link)
  help                    Show this help

Options (for install/use):
  -Arch x64|x86           Architecture (default: x64)
  -ThreadSafe <true|false> Thread safe build (default: false for nts)
  -Timezone <zone>        Set timezone in php.ini (default: UTC)
  -Force                  Force reinstall

Examples:
  .\bin\pvm.ps1 install 8.5
  .\bin\pvm.ps1 install 8.4 -Arch x64 -ThreadSafe false
  .\bin\pvm.ps1 use 8.5
  .\bin\pvm.ps1 list
  .\bin\pvm.ps1 path 8.5
"@
}

function Get-PathForAlias {
    param([string]$MajorMinor)
    $Root = Get-PvmRoot
    $alias = Join-Path $Root $MajorMinor
    if (-not (Test-Path -LiteralPath $alias)) { throw "Alias not found: $alias" }
    $t = Get-LinkTarget -Path $alias
    if ($t) { return @{ Alias = $alias; Target = $t } } else { return @{ Alias = $alias; Target = $alias } }
}

# -------------------- Argument parsing --------------------
function Parse-Args {
    $opts = @{
        Arch = 'x64'
        ThreadSafe = $false
        Timezone = 'UTC'
        Force = $false
        NoPath = $false
    }
    for ($i = 0; $i -lt $RemainingArgs.Count; $i++) {
        $a = $RemainingArgs[$i]
        switch -Regex ($a) {
            '^(-Arch|--arch)$' { if ($i+1 -lt $RemainingArgs.Count) { $i++; $opts.Arch = $RemainingArgs[$i] }; continue }
            '^(-ThreadSafe|--ts|--threadsafe)$' {
                if ($i+1 -lt $RemainingArgs.Count -and $RemainingArgs[$i+1] -notmatch '^-' ) {
                    $i++; $v = $RemainingArgs[$i].Trim().ToLower()
                    if ($v -match '^(true|1|yes|y|ts)$') { $opts.ThreadSafe = $true } elseif ($v -match '^(false|0|no|n|nts)$') { $opts.ThreadSafe = $false } else { $opts.ThreadSafe = [bool]::Parse($RemainingArgs[$i]) }
                } else {
                    $opts.ThreadSafe = $true
                }
                continue
            }
            '^(-Timezone|--timezone)$' { if ($i+1 -lt $RemainingArgs.Count) { $i++; $opts.Timezone = $RemainingArgs[$i] }; continue }
            '^(-Force|--force)$' { $opts.Force = $true; continue }
            '^(-NoPath|--no-path)$' { $opts.NoPath = $true; continue }
            default {
                if ($a -match '^-') { throw "Unknown option: $a" }
                continue
            }
        }
    }
    return $opts
}

# -------------------- Main --------------------
try {
    $opts = Parse-Args
    switch ($Command) {
        'help' { Show-Help; break }
        '-h' { Show-Help; break }
        '--help' { Show-Help; break }
        'install' {
            if (-not $Version) { throw "Usage: pvm.ps1 install <major.minor>" }
            Install-PHPVersion -MajorMinor $Version -Arch $opts.Arch -ThreadSafe $opts.ThreadSafe -Timezone $opts.Timezone -Force:$opts.Force | Out-Null
            break
        }
        'use' {
            if (-not $Version) { throw "Usage: pvm.ps1 use <major.minor> [-ThreadSafe true|false] [-Arch x64|x86]" }
            Use-PHPVersion -MajorMinor $Version -Arch $opts.Arch -ThreadSafe $opts.ThreadSafe -NoPath:$opts.NoPath
            break
        }
        'update' {
            if (-not $Version) { throw "Usage: pvm.ps1 update <major.minor>" }
            Install-PHPVersion -MajorMinor $Version -Arch $opts.Arch -ThreadSafe $opts.ThreadSafe -Timezone $opts.Timezone -Force:$true | Out-Null
            break
        }
        'link' {
            if (-not $Version) { throw "Usage: pvm.ps1 link <major.minor> [fullver] [ts/nts] [x64/x86]" }
            $opts.Root = Get-PvmRoot
            $fullver = $TargetVersion
            $tsTag = $RemainingArgs | Where-Object { $_ -eq 'ts' -or $_ -eq 'nts' } | Select-Object -First 1
            if (-not $tsTag) { $tsTag = if ($opts.ThreadSafe) { 'ts' } else { 'nts' } }
            $arch = $RemainingArgs | Where-Object { $_ -eq 'x86' -or $_ -eq 'x64' } | Select-Object -First 1
            if (-not $arch) { $arch = $opts.Arch }
            if (-not $fullver) {
                $fullver = Get-SemverForSeries -MajorMinor $Version
            }
            $variantLabel = if ($tsTag -eq 'ts') { 'Thread Safe (ts)' } else { 'Non Thread Safe (nts)' }
            $installDir = Join-Path (Join-Path (Join-Path $opts.Root $fullver) $tsTag) $arch
            if (-not (Test-Path -LiteralPath $installDir)) { throw "Target not found: $installDir [$variantLabel]" }
            $aliasDir = Join-Path $opts.Root $Version
            $linkType = New-DirLink -LinkPath $aliasDir -TargetPath $installDir
            Write-Success "Linked $Version ($linkType) -> $installDir [$variantLabel]"
            $currentDir = Join-Path $opts.Root 'current'
            try {
                $currentLinkType = New-DirLink -LinkPath $currentDir -TargetPath $aliasDir
                Write-Success "Updated current ($currentLinkType) -> $aliasDir"
            } catch {
                Write-Warn "Failed to update 'current' symlink: $($_.Exception.Message)"
            }
            break
        }
        'list' { List-PHPVersions; break }
        'ls' { List-PHPVersions; break }
        'path' {
            if (-not $Version) { throw "Usage: pvm.ps1 path <major.minor>" }
            $res = Get-PathForAlias -MajorMinor $Version
            Write-Host $res.Alias
            break
        }
        'remove' {
            if (-not $Version) { throw "Usage: pvm.ps1 remove <major.minor>" }
            $opts.Root = Get-PvmRoot
            $alias = Join-Path $opts.Root $Version
            if (Test-Path -LiteralPath $alias) {
                Remove-Item -LiteralPath $alias -Force -Recurse -ErrorAction SilentlyContinue
                Write-Success "Removed alias $alias (full installs unchanged)"
            } else {
                Write-Warn "Alias not found: $alias"
            }
            break
        }
        'uninstall' {
            if (-not $Version) { throw "Usage: pvm.ps1 uninstall <major.minor>" }
            $opts.Root = Get-PvmRoot
            $alias = Join-Path $opts.Root $Version
            if (Test-Path -LiteralPath $alias) { Remove-Item -LiteralPath $alias -Force -Recurse -ErrorAction SilentlyContinue }
            Write-Warn "Uninstall of full version not implemented here; remove files under $opts.Root manually if needed."
            break
        }
        'current' {
            $root = Get-PvmRoot
            if ($root) {
                $currentDir = Join-Path $root 'current'
                if (Test-Path -LiteralPath $currentDir) {
                    $t = Get-LinkTarget -Path $currentDir
                    if ($t) {
                        $base = Split-Path -Leaf $t
                        if ($base -match '^\d+\.\d+$') {
                            Write-Host $base
                            break
                        }
                        if ($t -match '(\\|/)(\d+\.\d+)(\\|/|$)') {
                            Write-Host $Matches[2]
                            break
                        }
                    }
                    Write-Warn "Could not resolve current PHP version from link: $currentDir"
                    break
                }
            }
            Write-Warn "No current PHP version link found at $currentDir"
            break
        }
        default { Show-Help; break }
    }
} catch {
    Write-Err $_.Exception.Message
    exit 1
}
