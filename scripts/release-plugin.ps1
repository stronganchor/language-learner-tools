param(
    [ValidateSet('patch', 'minor', 'major', 'custom')]
    [string]$Bump = 'patch',
    [string]$Version = '',
    [string]$CommitSuffix = 'Release',
    [switch]$Yes
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$pluginFile = Join-Path $repoRoot 'language-learner-tools.php'
$pluginSlug = 'language-learner-tools'

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments
    )

    $output = & git -C $repoRoot @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        if ($output) {
            throw ($output -join [Environment]::NewLine)
        }

        throw "git $($Arguments -join ' ') failed."
    }

    return $output
}

function Get-CurrentVersion {
    $content = [System.IO.File]::ReadAllText($pluginFile)
    $match = [regex]::Match($content, '(?m)^Version:\s*([0-9]+(?:\.[0-9]+){2,})\s*$')
    if (-not $match.Success) {
        throw "Could not find a Version header in $pluginFile."
    }

    return @{
        Content = $content
        Version = $match.Groups[1].Value
    }
}

function Get-NextVersion {
    param(
        [Parameter(Mandatory = $true)]
        [string]$CurrentVersion,
        [Parameter(Mandatory = $true)]
        [string]$RequestedBump,
        [string]$RequestedVersion = ''
    )

    if ($RequestedVersion -ne '') {
        return $RequestedVersion.Trim()
    }

    $parts = $CurrentVersion.Split('.')
    if ($parts.Count -ne 3) {
        throw "Automatic version bumps require a three-part version like 5.8.0. Current version: $CurrentVersion"
    }

    $major = [int]$parts[0]
    $minor = [int]$parts[1]
    $patch = [int]$parts[2]

    switch ($RequestedBump) {
        'major' {
            $major += 1
            $minor = 0
            $patch = 0
        }
        'minor' {
            $minor += 1
            $patch = 0
        }
        default {
            $patch += 1
        }
    }

    return "$major.$minor.$patch"
}

function Prompt-ForReleasePlan {
    param(
        [Parameter(Mandatory = $true)]
        [string]$CurrentVersion
    )

    Write-Host "Current plugin version: $CurrentVersion"
    $choice = Read-Host 'Bump version [patch/minor/major/custom] (default: patch)'
    if ([string]::IsNullOrWhiteSpace($choice)) {
        $choice = 'patch'
    }

    $choice = $choice.Trim().ToLowerInvariant()
    switch ($choice) {
        'patch' {
            return @{
                Bump = 'patch'
                Version = ''
            }
        }
        'minor' {
            return @{
                Bump = 'minor'
                Version = ''
            }
        }
        'major' {
            return @{
                Bump = 'major'
                Version = ''
            }
        }
        'custom' {
            $customVersion = Read-Host 'Enter the new version'
            if ([string]::IsNullOrWhiteSpace($customVersion)) {
                throw 'A custom version is required when you choose custom.'
            }

            return @{
                Bump = 'custom'
                Version = $customVersion.Trim()
            }
        }
        default {
            throw "Unsupported bump choice: $choice"
        }
    }
}

function Write-UpdatedVersion {
    param(
        [Parameter(Mandatory = $true)]
        [string]$OriginalContent,
        [Parameter(Mandatory = $true)]
        [string]$NewVersion
    )

    $updatedContent = [regex]::Replace(
        $OriginalContent,
        '(?m)^Version:\s*([0-9]+(?:\.[0-9]+){2,})\s*$',
        "Version: $NewVersion",
        1
    )

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($pluginFile, $updatedContent, $utf8NoBom)
}

function Confirm-OrAbort {
    param(
        [Parameter(Mandatory = $true)]
        [string]$NewVersion
    )

    $status = Invoke-Git -Arguments @('status', '--short')
    if ($status) {
        Write-Host ''
        Write-Host 'Files that will be staged and committed:'
        $status | ForEach-Object { Write-Host $_ }
    }

    Write-Host ''
    Write-Host "New plugin version: $NewVersion"
    Write-Host "Commit message: $NewVersion - $CommitSuffix"
    Write-Host "Zip output: dist/$pluginSlug-$NewVersion.zip"
    Write-Host ''

    $confirmation = Read-Host 'Proceed with version bump, zip build, commit, and push? [Y/n]'
    if (-not [string]::IsNullOrWhiteSpace($confirmation) -and $confirmation.Trim().ToLowerInvariant() -notin @('y', 'yes')) {
        throw 'Release cancelled.'
    }
}

$versionData = Get-CurrentVersion
$currentVersion = $versionData.Version
$currentContent = $versionData.Content
$stagedAll = $false
$commitCreated = $false

if (-not $PSBoundParameters.ContainsKey('Bump') -and -not $PSBoundParameters.ContainsKey('Version')) {
    $releasePlan = Prompt-ForReleasePlan -CurrentVersion $currentVersion
    $Bump = $releasePlan.Bump
    $Version = $releasePlan.Version
}

$newVersion = Get-NextVersion -CurrentVersion $currentVersion -RequestedBump $Bump -RequestedVersion $Version
if ($newVersion -eq $currentVersion) {
    throw "The new version matches the current version ($currentVersion)."
}

Write-UpdatedVersion -OriginalContent $currentContent -NewVersion $newVersion

try {
    if (-not $Yes) {
        Confirm-OrAbort -NewVersion $newVersion
    }

    Invoke-Git -Arguments @('add', '-A') | Out-Null
    $stagedAll = $true
    $treeHash = (Invoke-Git -Arguments @('write-tree') | Select-Object -First 1).Trim()
    if ([string]::IsNullOrWhiteSpace($treeHash)) {
        throw 'Could not determine the staged tree hash.'
    }

    $distDir = Join-Path $repoRoot 'dist'
    if (-not (Test-Path -LiteralPath $distDir)) {
        [System.IO.Directory]::CreateDirectory($distDir) | Out-Null
    }

    $zipPath = Join-Path $distDir "$pluginSlug-$newVersion.zip"
    if (Test-Path -LiteralPath $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    Invoke-Git -Arguments @(
        'archive',
        '--format=zip',
        "--prefix=$pluginSlug/",
        "--output=$zipPath",
        $treeHash
    ) | Out-Null

    $commitMessage = if ([string]::IsNullOrWhiteSpace($CommitSuffix)) {
        $newVersion
    } else {
        "$newVersion - $CommitSuffix"
    }

    Invoke-Git -Arguments @('commit', '-m', $commitMessage) | Out-Null
    $commitCreated = $true
    Invoke-Git -Arguments @('push') | Out-Null

    Write-Host ''
    Write-Host "Release commit pushed successfully."
    Write-Host "Version: $newVersion"
    Write-Host "Zip: $zipPath"
    Write-Host ''
    Write-Host 'Main-channel release note: you still need to create a matching Git tag and upload this zip to the GitHub release asset.'
}
catch {
    if (-not $commitCreated) {
        $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($pluginFile, $currentContent, $utf8NoBom)

        if ($stagedAll) {
            Invoke-Git -Arguments @('add', '--', $pluginFile) | Out-Null
        }
    }

    throw
}
