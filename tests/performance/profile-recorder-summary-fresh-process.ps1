[CmdletBinding()]
param(
    [string]$PhpPath = $(if ($env:PHP_BIN) { $env:PHP_BIN } else { 'php' }),
    [string]$WpCliPath = 'wp',
    [string]$WpRoot = '',
    [string]$ManagerLogin = $env:LL_E2E_ADMIN_USER
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Resolve-ProfileCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Candidate,
        [Parameter(Mandatory = $true)]
        [string]$Label
    )

    if (Test-Path -LiteralPath $Candidate -PathType Leaf) {
        return (Resolve-Path -LiteralPath $Candidate).Path
    }

    $command = Get-Command -Name $Candidate -ErrorAction Stop | Select-Object -First 1
    if (-not $command -or [string]::IsNullOrWhiteSpace([string]$command.Source)) {
        throw "$Label could not be resolved: $Candidate"
    }
    return [string]$command.Source
}

function ConvertFrom-ProfileJson {
    param(
        [Parameter(Mandatory = $true)]
        [object[]]$Lines,
        [Parameter(Mandatory = $true)]
        [string]$Label
    )

    $json = ($Lines | ForEach-Object { [string]$_ }) -join [Environment]::NewLine
    if ([string]::IsNullOrWhiteSpace($json)) {
        throw "$Label produced no JSON output."
    }
    try {
        return $json | ConvertFrom-Json -ErrorAction Stop
    }
    catch {
        throw "$Label produced invalid JSON. Review its stderr output. $($_.Exception.Message)"
    }
}

function Invoke-ProfileWpCli {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$CommandArguments,
        [Parameter(Mandatory = $true)]
        [string]$Label
    )

    if ([System.IO.Path]::GetExtension($script:resolvedWpCli).Equals('.phar', [System.StringComparison]::OrdinalIgnoreCase)) {
        $lines = @(& $script:resolvedPhp $script:resolvedWpCli @CommandArguments)
    }
    else {
        $lines = @(& $script:resolvedWpCli @CommandArguments)
    }
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with exit code $LASTEXITCODE."
    }
    return $lines
}

$performanceRoot = (Resolve-Path -LiteralPath $PSScriptRoot).Path
$pluginRoot = [System.IO.Path]::GetFullPath((Join-Path $performanceRoot '..\..'))
if ([string]::IsNullOrWhiteSpace($WpRoot)) {
    $WpRoot = [System.IO.Path]::GetFullPath((Join-Path $pluginRoot '..\..\..'))
}
$resolvedWpRoot = (Resolve-Path -LiteralPath $WpRoot).Path
$expectedPluginParent = [System.IO.Path]::GetFullPath((Join-Path $resolvedWpRoot 'wp-content\plugins'))
$expectedPluginPrefix = $expectedPluginParent.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
if (-not $pluginRoot.StartsWith($expectedPluginPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "The supplied WordPress root does not contain this plugin checkout: $resolvedWpRoot"
}
if (-not (Test-Path -LiteralPath (Join-Path $resolvedWpRoot 'wp-load.php') -PathType Leaf)) {
    throw "The supplied WordPress root does not contain wp-load.php: $resolvedWpRoot"
}
if ([string]::IsNullOrWhiteSpace($ManagerLogin)) {
    throw 'ManagerLogin is required. Pass -ManagerLogin or set LL_E2E_ADMIN_USER.'
}

$resolvedPhp = Resolve-ProfileCommand -Candidate $PhpPath -Label 'PHP executable'
$resolvedWpCli = Resolve-ProfileCommand -Candidate $WpCliPath -Label 'WP-CLI executable'
$existingProfiler = (Resolve-Path -LiteralPath (Join-Path $performanceRoot 'profile-recorder-summary.php')).Path
$requestProbe = (Resolve-Path -LiteralPath (Join-Path $performanceRoot 'profile-recorder-summary-request.php')).Path

# The existing exact-key reset predates this companion and is not itself
# local-only. Prove the target site's persisted home URL is local before the
# first mutating profiler command can run.
$homeLines = Invoke-ProfileWpCli -CommandArguments @(
    "--path=$resolvedWpRoot",
    'option',
    'get',
    'home'
) -Label 'Local home URL preflight'
$homeUrl = (($homeLines | ForEach-Object { [string]$_ }) -join '').Trim()
try {
    $homeUri = [System.Uri]$homeUrl
}
catch {
    throw "Local home URL preflight returned an invalid URL: $homeUrl"
}
$homeHost = $homeUri.Host.ToLowerInvariant()
$isLocalHome = $homeHost -in @('localhost', '127.0.0.1', '::1') -or
    $homeHost.EndsWith('.localhost', [System.StringComparison]::Ordinal) -or
    $homeHost.EndsWith('.local', [System.StringComparison]::Ordinal) -or
    $homeHost.EndsWith('.test', [System.StringComparison]::Ordinal)
if (-not $isLocalHome) {
    throw "Fresh-process profiling is restricted to a local WordPress home host: $homeHost"
}

# Reuse the existing checksum/tag guard and exact 211-key reset. This must
# complete before any request probe is allowed to call the batch builder.
$resetLines = Invoke-ProfileWpCli -CommandArguments @(
    "--path=$resolvedWpRoot",
    'eval-file',
    $existingProfiler,
    'reset'
) -Label 'Guarded recorder-summary reset'
$reset = ConvertFrom-ProfileJson -Lines $resetLines -Label 'Guarded recorder-summary reset'
if (
    -not [bool]$reset.ok -or
    [string]$reset.phase -ne 'reset' -or
    [int]$reset.fixture.category_count -ne 209 -or
    [int]$reset.cache.exact_keys_deleted -ne 211 -or
    [bool]$reset.cache.broad_flush_used -or
    [bool]$reset.cache.rebuild_started -or
    -not [bool]$reset.browser_selection.verified -or
    [int]$reset.browser_selection.selected_recorder_user_id -ne [int]$reset.fixture.recorder_user_id
) {
    throw 'The existing profiler did not prove the expected fixed fixture and exact-key reset.'
}

$phases = @('first-cold6', 'later-cold6', 'warm6')
$results = @()
foreach ($phase in $phases) {
    $probeArguments = @(
        $requestProbe,
        "--phase=$phase",
        "--wp-root=$resolvedWpRoot",
        "--manager-login=$ManagerLogin",
        "--wordset-id=$([int]$reset.fixture.wordset_id)",
        "--recorder-user-id=$([int]$reset.fixture.recorder_user_id)",
        "--fixture-version=$([string]$reset.fixture.version)",
        "--manifest-sha256=$([string]$reset.fixture.manifest_sha256)",
        "--generation=$([string]$reset.fixture.generation)"
    )
    $probeLines = @(& $resolvedPhp @probeArguments)
    if ($LASTEXITCODE -ne 0) {
        throw "$phase fresh-process probe failed with exit code $LASTEXITCODE."
    }
    $probe = ConvertFrom-ProfileJson -Lines $probeLines -Label "$phase fresh-process probe"
    if (-not [bool]$probe.ok -or [string]$probe.phase -ne $phase -or -not [bool]$probe.request_model.fresh_php_process) {
        throw "$phase fresh-process probe did not return the expected guarded result."
    }
    $results += $probe
}

$output = [ordered]@{
    ok = $true
    profile = 'recorder-summary-fresh-process-v1'
    guard = [ordered]@{
        fixture_version = [string]$reset.fixture.version
        manifest_sha256 = [string]$reset.fixture.manifest_sha256
        wordset_id = [int]$reset.fixture.wordset_id
        recorder_user_id = [int]$reset.fixture.recorder_user_id
        generation = [string]$reset.fixture.generation
        exact_keys_deleted = [int]$reset.cache.exact_keys_deleted
        broad_flush_used = [bool]$reset.cache.broad_flush_used
        rebuild_started = [bool]$reset.cache.rebuild_started
        browser_selection_verified = [bool]$reset.browser_selection.verified
    }
    process_contract = [ordered]@{
        distinct_php_invocations = 3
        ordered_phases = $phases
        full_stream_extrapolation_included = $false
    }
    results = $results
    note = 'Each result is one fresh PHP process. SQL caller buckets attribute database time only; summary_batch_authoritative is the exact batch-builder wall time.'
}

$output | ConvertTo-Json -Depth 12
