param(
    [ValidateSet('auto', 'bump', 'publish')]
    [string]$Mode = 'auto',
    [ValidateSet('patch', 'minor', 'major', 'custom', 'none')]
    [string]$Bump = 'patch',
    [string]$Version = '',
    [string]$CommitSuffix = 'Release',
    [switch]$Yes
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$pluginFile = Join-Path $repoRoot 'language-learner-tools.php'
$pluginSlug = 'language-learner-tools'
$gitHubApiBase = 'https://api.github.com'

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments
    )

    $previousErrorActionPreference = $ErrorActionPreference
    try {
        # git reports normal push/fetch progress on stderr, so capture it without
        # letting PowerShell treat those lines as terminating errors.
        $ErrorActionPreference = 'Continue'
        $output = & git -C $repoRoot @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    $outputLines = @($output | ForEach-Object {
        if ($_ -is [System.Management.Automation.ErrorRecord]) {
            $_.ToString()
        } else {
            [string]$_
        }
    })

    if ($exitCode -ne 0) {
        if ($outputLines) {
            throw ($outputLines -join [Environment]::NewLine)
        }

        throw "git $($Arguments -join ' ') failed."
    }

    return $outputLines
}

function Get-CurrentBranch {
    return (Invoke-Git -Arguments @('branch', '--show-current') | Select-Object -First 1).Trim()
}

function Get-CurrentVersionData {
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

    if ($RequestedBump -eq 'none') {
        return $CurrentVersion
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

function Prompt-ForBumpPlan {
    param(
        [Parameter(Mandatory = $true)]
        [string]$CurrentVersion
    )

    Write-Host "Current plugin version: $CurrentVersion"
    $choice = Read-Host 'Bump version [patch/minor/major/custom/none] (default: patch)'
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
        'none' {
            return @{
                Bump = 'none'
                Version = ''
            }
        }
        default {
            throw "Unsupported bump choice: $choice"
        }
    }
}

function Get-EffectiveMode {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RequestedMode,
        [Parameter(Mandatory = $true)]
        [string]$BranchName
    )

    if ($RequestedMode -ne 'auto') {
        return $RequestedMode
    }

    if ($BranchName -eq 'main') {
        return 'publish'
    }

    return 'bump'
}

function Get-GitStatusLines {
    return @(Invoke-Git -Arguments @('status', '--short'))
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

function Restore-OriginalVersion {
    param(
        [Parameter(Mandatory = $true)]
        [string]$OriginalContent
    )

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($pluginFile, $OriginalContent, $utf8NoBom)
    Invoke-Git -Arguments @('add', '--', $pluginFile) | Out-Null
}

function Confirm-Bump {
    param(
        [Parameter(Mandatory = $true)]
        [string]$BranchName,
        [Parameter(Mandatory = $true)]
        [string]$ReleaseVersion,
        [Parameter(Mandatory = $true)]
        [bool]$VersionChanged
    )

    $status = Get-GitStatusLines
    if ($status.Count -gt 0) {
        Write-Host ''
        Write-Host 'Files that will be staged and committed:'
        $status | ForEach-Object { Write-Host $_ }
    }

    Write-Host ''
    Write-Host "Branch: $BranchName"
    if ($VersionChanged) {
        Write-Host "New plugin version: $ReleaseVersion"
    } else {
        Write-Host "Plugin version: $ReleaseVersion (unchanged)"
    }
    Write-Host "Commit message: $(Get-ReleaseCommitMessage -ReleaseVersion $ReleaseVersion)"
    Write-Host ''

    $confirmationPrompt = if ($VersionChanged) {
        'Proceed with version bump, commit, and push? [Y/n]'
    } else {
        'Proceed with commit and push without a version bump? [Y/n]'
    }

    $confirmation = Read-Host $confirmationPrompt
    if (-not [string]::IsNullOrWhiteSpace($confirmation) -and $confirmation.Trim().ToLowerInvariant() -notin @('y', 'yes')) {
        throw 'Release cancelled.'
    }
}

function Get-ReleaseCommitMessage {
    param(
        [Parameter(Mandatory = $true)]
        [string]$ReleaseVersion
    )

    if ([string]::IsNullOrWhiteSpace($CommitSuffix)) {
        return $ReleaseVersion
    }

    return "$ReleaseVersion - $CommitSuffix"
}

function Confirm-Publish {
    param(
        [Parameter(Mandatory = $true)]
        [string]$VersionToPublish
    )

    Write-Host ''
    Write-Host 'Stable publish plan:'
    Write-Host "Branch: main"
    Write-Host "Version: $VersionToPublish"
    Write-Host "Tag: v$VersionToPublish"
    Write-Host "Release asset: dist/$pluginSlug-$VersionToPublish.zip"
    Write-Host ''

    $confirmation = Read-Host 'Proceed with stable publish, tag push, GitHub release update, and asset upload? [Y/n]'
    if (-not [string]::IsNullOrWhiteSpace($confirmation) -and $confirmation.Trim().ToLowerInvariant() -notin @('y', 'yes')) {
        throw 'Stable publish cancelled.'
    }
}

function Get-OriginRepoSlug {
    $remoteUrl = (Invoke-Git -Arguments @('remote', 'get-url', 'origin') | Select-Object -First 1).Trim()
    $match = [regex]::Match($remoteUrl, 'github\.com[:/](?<slug>[^/]+/[^/]+?)(?:\.git)?$')
    if (-not $match.Success) {
        throw "Could not parse a GitHub owner/repo slug from origin URL: $remoteUrl"
    }

    return $match.Groups['slug'].Value
}

function Get-GitHubToken {
    $token = $env:LL_TOOLS_GITHUB_TOKEN
    if ([string]::IsNullOrWhiteSpace($token)) {
        $token = $env:GITHUB_TOKEN
    }

    if ([string]::IsNullOrWhiteSpace($token)) {
        throw 'Stable publish requires LL_TOOLS_GITHUB_TOKEN or GITHUB_TOKEN in the Windows environment.'
    }

    return $token.Trim()
}

function New-GitHubHeaders {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Token
    )

    return @{
        Accept                 = 'application/vnd.github+json'
        Authorization          = "Bearer $Token"
        'X-GitHub-Api-Version' = '2022-11-28'
        'User-Agent'           = 'll-tools-release-script'
    }
}

function Get-WebStatusCode {
    param($ErrorRecord)

    if ($null -eq $ErrorRecord.Exception.Response) {
        return $null
    }

    return [int]$ErrorRecord.Exception.Response.StatusCode
}

function Invoke-GitHubJson {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Method,
        [Parameter(Mandatory = $true)]
        [string]$Uri,
        [Parameter(Mandatory = $true)]
        [hashtable]$Headers,
        [object]$Body = $null
    )

    $params = @{
        Method  = $Method
        Uri     = $Uri
        Headers = $Headers
    }

    if ($null -ne $Body) {
        $params.Body = ($Body | ConvertTo-Json -Depth 10)
        $params.ContentType = 'application/json'
    }

    return Invoke-RestMethod @params
}

function Get-OrCreateRelease {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RepoSlug,
        [Parameter(Mandatory = $true)]
        [string]$TagName,
        [Parameter(Mandatory = $true)]
        [string]$VersionToPublish,
        [Parameter(Mandatory = $true)]
        [hashtable]$Headers
    )

    $tagUrl = "$gitHubApiBase/repos/$RepoSlug/releases/tags/$TagName"
    try {
        return Invoke-GitHubJson -Method 'GET' -Uri $tagUrl -Headers $Headers
    }
    catch {
        if ((Get-WebStatusCode $_) -ne 404) {
            throw
        }
    }

    return Invoke-GitHubJson -Method 'POST' -Uri "$gitHubApiBase/repos/$RepoSlug/releases" -Headers $Headers -Body @{
        tag_name         = $TagName
        target_commitish = 'main'
        name             = $TagName
        body             = "Language Learner Tools $VersionToPublish"
        draft            = $false
        prerelease       = $false
    }
}

function Remove-ExistingReleaseAsset {
    param(
        [Parameter(Mandatory = $true)]
        [object]$Release,
        [Parameter(Mandatory = $true)]
        [string]$AssetName,
        [Parameter(Mandatory = $true)]
        [string]$RepoSlug,
        [Parameter(Mandatory = $true)]
        [hashtable]$Headers
    )

    $existingAsset = @($Release.assets | Where-Object { $_.name -eq $AssetName } | Select-Object -First 1)
    if ($existingAsset.Count -eq 0) {
        return
    }

    $assetId = $existingAsset[0].id
    Invoke-GitHubJson -Method 'DELETE' -Uri "$gitHubApiBase/repos/$RepoSlug/releases/assets/$assetId" -Headers $Headers | Out-Null
}

function Upload-ReleaseAsset {
    param(
        [Parameter(Mandatory = $true)]
        [object]$Release,
        [Parameter(Mandatory = $true)]
        [string]$ZipPath,
        [Parameter(Mandatory = $true)]
        [hashtable]$Headers
    )

    $assetName = [System.IO.Path]::GetFileName($ZipPath)
    $uploadUrl = ($Release.upload_url -replace '\{.*$', '') + '?name=' + [System.Uri]::EscapeDataString($assetName)

    Invoke-RestMethod -Method 'POST' -Uri $uploadUrl -Headers $Headers -InFile $ZipPath -ContentType 'application/zip' | Out-Null
}

function Test-LocalTagExists {
    param(
        [Parameter(Mandatory = $true)]
        [string]$TagName
    )

    & git -C $repoRoot rev-parse -q --verify "refs/tags/$TagName" *> $null
    return ($LASTEXITCODE -eq 0)
}

function Get-CommitForRef {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RefName
    )

    return (Invoke-Git -Arguments @('rev-list', '-n', '1', $RefName) | Select-Object -First 1).Trim()
}

function Build-ReleaseZipFromRef {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RefName,
        [Parameter(Mandatory = $true)]
        [string]$VersionToPublish
    )

    $distDir = Join-Path $repoRoot 'dist'
    if (-not (Test-Path -LiteralPath $distDir)) {
        [System.IO.Directory]::CreateDirectory($distDir) | Out-Null
    }

    $zipPath = Join-Path $distDir "$pluginSlug-$VersionToPublish.zip"
    if (Test-Path -LiteralPath $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    Invoke-Git -Arguments @(
        'archive',
        '--format=zip',
        "--prefix=$pluginSlug/",
        "--output=$zipPath",
        $RefName
    ) | Out-Null

    return $zipPath
}

function Invoke-BumpWorkflow {
    param(
        [Parameter(Mandatory = $true)]
        [string]$BranchName
    )

    $versionData = Get-CurrentVersionData
    $currentVersion = $versionData.Version
    $currentContent = $versionData.Content
    $releaseVersion = Get-NextVersion -CurrentVersion $currentVersion -RequestedBump $Bump -RequestedVersion $Version
    $versionChanged = ($releaseVersion -ne $currentVersion)

    $commitCreated = $false
    if ($versionChanged) {
        Write-UpdatedVersion -OriginalContent $currentContent -NewVersion $releaseVersion
    }

    try {
        if ((Get-GitStatusLines).Count -eq 0) {
            throw 'Nothing to release. Make changes first or choose a version bump.'
        }

        if (-not $Yes) {
            Confirm-Bump -BranchName $BranchName -ReleaseVersion $releaseVersion -VersionChanged $versionChanged
        }

        Invoke-Git -Arguments @('add', '-A') | Out-Null

        $commitMessage = Get-ReleaseCommitMessage -ReleaseVersion $releaseVersion

        Invoke-Git -Arguments @('commit', '-m', $commitMessage) | Out-Null
        $commitCreated = $true
        Invoke-Git -Arguments @('push', 'origin', $BranchName) | Out-Null

        Write-Host ''
        if ($versionChanged) {
            Write-Host "Version bump pushed successfully on $BranchName."
            Write-Host "New version: $releaseVersion"
        } else {
            Write-Host "Release pushed successfully on $BranchName without a version bump."
            Write-Host "Version unchanged: $releaseVersion"
        }
    }
    catch {
        if (-not $commitCreated -and $versionChanged) {
            Restore-OriginalVersion -OriginalContent $currentContent
        }

        throw
    }
}

function Invoke-PublishWorkflow {
    param(
        [Parameter(Mandatory = $true)]
        [string]$BranchName
    )

    if ($BranchName -ne 'main') {
        throw "Publish mode is intended for the main branch. Current branch: $BranchName"
    }

    $status = Get-GitStatusLines
    if ($status.Count -gt 0) {
        throw "Publish mode requires a clean working tree on main. Commit or stash changes first.`n$($status -join [Environment]::NewLine)"
    }

    $versionData = Get-CurrentVersionData
    $versionToPublish = $versionData.Version
    $tagName = "v$versionToPublish"
    $repoSlug = Get-OriginRepoSlug
    $token = Get-GitHubToken
    $headers = New-GitHubHeaders -Token $token

    if (-not $Yes) {
        Confirm-Publish -VersionToPublish $versionToPublish
    }

    Invoke-Git -Arguments @('push', 'origin', $BranchName) | Out-Null

    $headCommit = Get-CommitForRef -RefName 'HEAD'
    if (Test-LocalTagExists -TagName $tagName) {
        $tagCommit = Get-CommitForRef -RefName $tagName
        if ($tagCommit -ne $headCommit) {
            throw "Local tag $tagName already exists but does not point at HEAD."
        }
    } else {
        Invoke-Git -Arguments @('tag', '-a', $tagName, '-m', $tagName) | Out-Null
    }

    $zipPath = Build-ReleaseZipFromRef -RefName 'HEAD' -VersionToPublish $versionToPublish
    Invoke-Git -Arguments @('push', 'origin', $tagName) | Out-Null

    $release = Get-OrCreateRelease -RepoSlug $repoSlug -TagName $tagName -VersionToPublish $versionToPublish -Headers $headers
    $assetName = [System.IO.Path]::GetFileName($zipPath)
    Remove-ExistingReleaseAsset -Release $release -AssetName $assetName -RepoSlug $repoSlug -Headers $headers

    $release = Get-OrCreateRelease -RepoSlug $repoSlug -TagName $tagName -VersionToPublish $versionToPublish -Headers $headers
    Upload-ReleaseAsset -Release $release -ZipPath $zipPath -Headers $headers

    Write-Host ''
    Write-Host 'Stable publish completed successfully.'
    Write-Host "Version: $versionToPublish"
    Write-Host "Tag: $tagName"
    Write-Host "Zip: $zipPath"
    Write-Host "GitHub release: https://github.com/$repoSlug/releases/tag/$tagName"
}

$currentBranch = Get-CurrentBranch
$effectiveMode = Get-EffectiveMode -RequestedMode $Mode -BranchName $currentBranch

if ($effectiveMode -eq 'bump' -and -not $PSBoundParameters.ContainsKey('Bump') -and -not $PSBoundParameters.ContainsKey('Version')) {
    $versionData = Get-CurrentVersionData
    $bumpPlan = Prompt-ForBumpPlan -CurrentVersion $versionData.Version
    $Bump = $bumpPlan.Bump
    $Version = $bumpPlan.Version
}

switch ($effectiveMode) {
    'publish' {
        Invoke-PublishWorkflow -BranchName $currentBranch
    }
    default {
        Invoke-BumpWorkflow -BranchName $currentBranch
    }
}
