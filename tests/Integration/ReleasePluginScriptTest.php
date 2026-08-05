<?php
declare(strict_types=1);

final class ReleasePluginScriptTest extends LL_Tools_TestCase
{
    public function test_release_exports_and_git_ignores_remote_conversation_attachments(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $attributes = (string) file_get_contents($repoRoot . '/.gitattributes');
        $ignore = (string) file_get_contents($repoRoot . '/.gitignore');

        $this->assertStringContainsString('/.codex-remote-attachments export-ignore', $attributes);
        $this->assertStringContainsString('/.codex-remote-attachments/', $ignore);
    }

    public function test_stable_publish_uploads_verified_replacement_before_deleting_existing_asset(): void
    {
        $script = $this->releaseScriptContents();

        $publishWorkflow = $this->extractPowerShellFunction($script, 'Invoke-PublishWorkflow');
        $this->assertStringContainsString('Publish-ReleaseAsset', $publishWorkflow);
        $this->assertStringNotContainsString('Remove-ExistingReleaseAsset', $publishWorkflow);
        $this->assertStringNotContainsString('Remove-ReleaseAssetById', $publishWorkflow);
        $this->assertStringNotContainsString('Upload-ReleaseAsset', $publishWorkflow);

        $safePublish = $this->extractPowerShellFunction($script, 'Publish-ReleaseAsset');
        $temporaryUploadPosition = strpos($safePublish, 'Upload-ReleaseAsset -Release $Release -ZipPath $ZipPath -Headers $Headers -AssetName $temporaryAssetName');
        $temporaryVerifyPosition = strpos($safePublish, 'Assert-UploadedReleaseAssetMatchesArchive -Asset $temporaryAsset -ExpectedName $temporaryAssetName -ZipPath $ZipPath');
        $deletePosition = strpos($safePublish, 'Remove-ReleaseAssetById -RepoSlug $RepoSlug -Headers $Headers -AssetId $existingAsset.id');
        $renamePosition = strpos($safePublish, 'Rename-ReleaseAsset -RepoSlug $RepoSlug -Headers $Headers -AssetId $temporaryAsset.id -AssetName $assetName');
        $finalVerifyPosition = strpos($safePublish, 'Assert-UploadedReleaseAssetMatchesArchive -Asset $renamedAsset -ExpectedName $assetName -ZipPath $ZipPath');

        $this->assertNotFalse($temporaryUploadPosition, 'Replacement publish should upload a temporary asset first.');
        $this->assertNotFalse($temporaryVerifyPosition, 'Replacement publish should verify the temporary upload before deleting the old asset.');
        $this->assertNotFalse($deletePosition, 'Replacement publish should delete the old final-named asset only after the replacement is uploaded.');
        $this->assertNotFalse($renamePosition, 'Replacement publish should rename the verified temporary asset to the final asset name.');
        $this->assertNotFalse($finalVerifyPosition, 'Replacement publish should verify the final renamed asset.');
        $this->assertLessThan($temporaryVerifyPosition, $temporaryUploadPosition);
        $this->assertLessThan($deletePosition, $temporaryVerifyPosition);
        $this->assertLessThan($renamePosition, $deletePosition);
        $this->assertLessThan($finalVerifyPosition, $renamePosition);
    }

    public function test_dev_release_commits_only_a_clean_pre_staged_scope(): void
    {
        $script = $this->releaseScriptContents();
        $bumpWorkflow = $this->extractPowerShellFunction($script, 'Invoke-BumpWorkflow');
        $scopeGuard = $this->extractPowerShellFunction($script, 'Assert-BumpWorkingTreeIsScoped');
        $confirmation = $this->extractPowerShellFunction($script, 'Confirm-Bump');

        $this->assertStringNotContainsString("@('add', '-A')", $script);
        $this->assertStringContainsString('Assert-BumpWorkingTreeIsScoped', $bumpWorkflow);
        $this->assertStringContainsString("@('add', '--', \$pluginFileRelative)", $bumpWorkflow);
        $this->assertStringContainsString('Assert-BumpHasStagedChanges', $bumpWorkflow);
        $this->assertStringContainsString("@('diff', '--name-status', '--')", $scopeGuard);
        $this->assertStringContainsString("@('ls-files', '--others', '--exclude-standard', '--')", $scopeGuard);
        $this->assertStringContainsString("@('diff', '--name-only', '--diff-filter=U', '--')", $scopeGuard);
        $this->assertStringContainsString('Get-GitStagedStatusLines', $confirmation);
    }

    public function test_release_archives_disable_checkout_line_ending_conversion(): void
    {
        $script = $this->releaseScriptContents();
        $archiveCheck = $this->extractPowerShellFunction($script, 'Test-ReleaseArchiveFromRef');
        $archiveBuild = $this->extractPowerShellFunction($script, 'Build-ReleaseZipFromRef');
        $shellBuilder = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/build-release-package.sh');

        $this->assertStringContainsString("'core.autocrlf=false'", $archiveCheck);
        $this->assertStringContainsString("'core.autocrlf=false'", $archiveBuild);
        $this->assertStringContainsString('git -C "${ROOT_DIR}" -c core.autocrlf=false archive', $shellBuilder);
    }

    public function test_release_versions_are_validated_as_three_part_numeric_versions(): void
    {
        $script = $this->releaseScriptContents();
        $validator = $this->extractPowerShellFunction($script, 'Get-ValidatedReleaseVersion');
        $nextVersion = $this->extractPowerShellFunction($script, 'Get-NextVersion');

        $this->assertStringContainsString("'^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$'", $validator);
        $this->assertStringContainsString("-VersionToValidate \$RequestedVersion -Context 'Custom version'", $nextVersion);
        $this->assertStringContainsString('-VersionToValidate $CurrentVersion', $nextVersion);
        $this->assertStringContainsString("if (\$RequestedBump -eq 'custom')", $nextVersion);
        $this->assertStringContainsString('A custom version is required', $nextVersion);
    }

    public function test_stable_publish_builds_and_validates_the_archive_before_pushing_main(): void
    {
        $publishWorkflow = $this->extractPowerShellFunction($this->releaseScriptContents(), 'Invoke-PublishWorkflow');
        $buildPosition = strpos($publishWorkflow, "Build-ReleaseZipFromRef -RefName 'HEAD'");
        $pushPosition = strpos($publishWorkflow, "@('push', '--atomic', 'origin', \$BranchName, \$tagName)");

        $this->assertNotFalse($buildPosition, 'Stable publish should build the release archive.');
        $this->assertNotFalse($pushPosition, 'Stable publish should push main.');
        $this->assertLessThan($pushPosition, $buildPosition, 'Archive validation must finish before main is pushed.');
        $this->assertStringContainsString('$tagCreated = $false', $publishWorkflow);
        $this->assertMatchesRegularExpression('/catch\s*\{.*?if \(\$tagCreated\).*?@\(\x27tag\x27, \x27-d\x27, \$tagName\)/s', $publishWorkflow);
    }

    public function test_release_builders_reject_repository_only_paths_and_remove_unvalidated_output(): void
    {
        $script = $this->releaseScriptContents();
        $archiveValidator = $this->extractPowerShellFunction($script, 'Assert-ReleaseZipContainsRequiredAssets');
        $archiveBuild = $this->extractPowerShellFunction($script, 'Build-ReleaseZipFromRef');
        $shellBuilder = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/build-release-package.sh');

        $this->assertStringContainsString("'offline-app-builder/'", $archiveValidator);
        $this->assertStringContainsString("'bin/'", $archiveValidator);
        $this->assertStringContainsString("'docs/'", $archiveValidator);
        $this->assertStringContainsString("'tests/'", $archiveValidator);
        $this->assertStringContainsString("'build-offline-app-apk.bat'", $archiveValidator);
        $this->assertStringContainsString('Release archive contains an invalid root or repository-only path', $archiveValidator);
        $this->assertMatchesRegularExpression('/catch\s*\{.*?Remove-Item -LiteralPath \$zipPath -Force/s', $archiveBuild);

        $validatorPosition = strpos($shellBuilder, 'if command -v unzip');
        $archivePosition = strpos($shellBuilder, 'git -C "${ROOT_DIR}" -c core.autocrlf=false archive');
        $this->assertNotFalse($validatorPosition);
        $this->assertNotFalse($archivePosition);
        $this->assertLessThan($archivePosition, $validatorPosition, 'A validator must be available before the shell builder writes a zip.');
        $this->assertStringContainsString("python3 -c 'import zipfile'", $shellBuilder);
        $this->assertStringContainsString("python -c 'import zipfile'", $shellBuilder);
        $this->assertStringContainsString('Required runtime asset manifest is empty', $shellBuilder);
        $this->assertStringContainsString('required_assets=()', $shellBuilder);
        $this->assertStringContainsString('command -v cygpath', $shellBuilder);
        $this->assertStringContainsString('A native Windows output path requires cygpath', $shellBuilder);
        $this->assertStringContainsString('ARCHIVE_VALIDATED=0', $shellBuilder);
        $this->assertStringContainsString('OUTPUT_OWNED == 1 && ARCHIVE_VALIDATED == 0', $shellBuilder);
        $this->assertStringContainsString("'offline-app-builder/'", $shellBuilder);
        $this->assertStringContainsString("'bin/'", $shellBuilder);
        $this->assertStringContainsString("'docs/'", $shellBuilder);
        $this->assertStringContainsString("'build-offline-app-apk.bat'", $shellBuilder);
        $this->assertStringContainsString("VERSION_PATTERN='^(0|[1-9][0-9]*)", $shellBuilder);
        $this->assertStringContainsString('INTERNAL_VERSION=', $shellBuilder);
        $this->assertStringContainsString('if [[ "${INTERNAL_VERSION}" != "${VERSION}" ]]', $shellBuilder);
    }

    public function test_release_exports_exclude_development_only_tools_and_docs(): void
    {
        $attributes = (string) file_get_contents(dirname(__DIR__, 2) . '/.gitattributes');

        $this->assertStringContainsString('/bin export-ignore', $attributes);
        $this->assertStringContainsString('/build-offline-app-apk.bat export-ignore', $attributes);
        $this->assertStringContainsString('/docs export-ignore', $attributes);
    }

    public function test_codex_temp_is_ignored_and_export_ignored(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $attributes = (string) file_get_contents($repoRoot . '/.gitattributes');
        $ignore = (string) file_get_contents($repoRoot . '/.gitignore');

        $this->assertStringContainsString('/_codex_temp export-ignore', $attributes);
        $this->assertStringContainsString('/_codex_temp/', $ignore);
    }

    private function releaseScriptContents(): string
    {
        $path = dirname(__DIR__, 2) . '/scripts/release-plugin.ps1';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function extractPowerShellFunction(string $script, string $functionName): string
    {
        $pattern = '/function\s+' . preg_quote($functionName, '/') . '\s*\{(?P<body>.*?)(?=\Rfunction\s+|\z)/s';
        $this->assertMatchesRegularExpression($pattern, $script, 'Expected to find PowerShell function ' . $functionName . '.');
        preg_match($pattern, $script, $matches);

        return (string) ($matches['body'] ?? '');
    }
}
