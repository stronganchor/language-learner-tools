param([switch]$Reset)
$ErrorActionPreference = 'Stop'
$taskRepo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$taskEvidence = Join-Path $taskRepo '_codex_temp/moodle-real-20261125'
$taskCompose = Join-Path $PSScriptRoot 'compose.yml'
if ((Get-Content -LiteralPath $taskCompose -First 1) -ne 'name: ll-tools-real-moodle-20261125') { throw 'Unexpected compose project; refusing to change data.' }
function Invoke-Interop([string[]]$TaskArguments) {
    & docker compose -f $taskCompose @TaskArguments
    if ($LASTEXITCODE -ne 0) { throw ('Interop command failed: ' + ($TaskArguments -join ' ')) }
}
Push-Location $taskRepo
try {
    New-Item -ItemType Directory -Path $taskEvidence -Force | Out-Null
    if ($Reset) {
        $taskArchive = Join-Path $taskEvidence ('archive-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
        New-Item -ItemType Directory -Path $taskArchive -Force | Out-Null
        Get-ChildItem -LiteralPath $taskEvidence -File | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $taskArchive }
        # Only this fixed disposable project's labelled containers and generated volumes are removed.
        Invoke-Interop @('down', '--volumes', '--remove-orphans')
        # The directory belongs exclusively to this harness; clear every prior outcome after archiving.
        Get-ChildItem -LiteralPath $taskEvidence -File | ForEach-Object { Remove-Item -LiteralPath $_.FullName }
    } elseif (Test-Path -LiteralPath (Join-Path $taskEvidence 'moodle-state.json')) {
        throw 'Existing disposable fixtures found. Use -Reset for a fresh acceptance run; prior evidence will be archived.'
    }
    # Include the complete verified runtime, including required vendor assets.
    & git archive --worktree-attributes --format=tar ('--output=' + (Join-Path $taskEvidence 'plugin-runtime.tar')) HEAD
    if ($LASTEXITCODE -ne 0) { throw 'Could not archive the committed release candidate.' }
    $taskHashes = @{}
    $taskHashes['git_commit'] = (& git rev-parse HEAD).Trim()
    foreach ($taskSource in @('language-learner-tools.php','includes/lms/lti.php','includes/lms/lti-accounts.php','includes/lms/assignment-experience.php','includes/admin/lti-integration.php')) {
        $taskHashes[$taskSource] = (& git rev-parse ('HEAD:' + $taskSource)).Trim()
    }
    $taskHashes | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $taskEvidence 'runtime-source-hashes.json') -Encoding utf8
    Invoke-Interop @('up', '-d', '--build', 'db', 'web')
    $taskReady = $false
    for ($taskCheck = 0; $taskCheck -lt 60; $taskCheck++) {
        & docker compose -f $taskCompose exec -T -u www-data web test -r /var/www/moodle/config.php 2>$null
        if ($LASTEXITCODE -eq 0) { $taskReady = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $taskReady) { throw 'Disposable web/database preparation did not finish.' }
    Invoke-Interop @('exec', '-T', '-u', 'www-data', 'web', 'sh', '-c', 'php /harness/install.php moodle > /var/www/moodledata/install.log 2>&1')
    Invoke-Interop @('exec', '-T', '-u', 'www-data', 'web', 'php', '/harness/install.php', 'wordpress')
    foreach ($taskScript in @('seed-moodle.php','seed-wordpress.php','seed-audio.php','bind-moodle.php','probe-ags.php')) {
        Invoke-Interop @('exec', '-T', '-u', 'www-data', 'web', 'php', ('/harness/' + $taskScript))
    }
    Invoke-Interop @('build', 'browser')
    Invoke-Interop @('run', '--rm', 'browser')
    Invoke-Interop @('exec', '-T', '-u', 'www-data', 'web', 'php', '/harness/worker.php')
    Invoke-Interop @('exec', '-T', '-u', 'www-data', 'web', 'php', '/harness/verify.php', 'wordpress')
    Invoke-Interop @('exec', '-T', '-u', 'www-data', 'web', 'php', '/harness/verify.php', 'moodle')
    Invoke-Interop @('run', '--rm', '-e', 'INTEROP_PHASE=gradebook', 'browser')
} finally { Pop-Location }
