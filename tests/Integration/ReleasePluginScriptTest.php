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
