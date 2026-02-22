<?php
declare(strict_types=1);

final class MaskedImageProxyModeTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        delete_option('ll_tools_use_masked_image_proxy');
    }

    protected function tearDown(): void
    {
        delete_option('ll_tools_use_masked_image_proxy');
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function test_default_non_local_site_returns_direct_attachment_url(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';

        $attachment_id = $this->createImageAttachment('proxy-mode-default.png');
        $expected = (string) wp_get_attachment_image_url($attachment_id, 'full');
        $actual = ll_tools_get_masked_image_url($attachment_id, 'full');

        $this->assertNotSame('', $expected);
        $this->assertSame($expected, $actual);
        $this->assertStringNotContainsString('lltools-img=', $actual);
    }

    public function test_local_host_auto_enables_masked_proxy_url(): void
    {
        $_SERVER['HTTP_HOST'] = 'starter-english.local';

        $attachment_id = $this->createImageAttachment('proxy-mode-local.png');
        $actual = ll_tools_get_masked_image_url($attachment_id, 'full');

        $this->assertStringContainsString('lltools-img=' . $attachment_id, $actual);
        $this->assertStringContainsString('lltools-size=full', $actual);
    }

    public function test_site_flag_can_enable_masked_proxy_on_non_local_site(): void
    {
        $_SERVER['HTTP_HOST'] = 'zazacaogren.com';
        update_option('ll_tools_use_masked_image_proxy', '1');

        $attachment_id = $this->createImageAttachment('proxy-mode-flag.png');
        $actual = ll_tools_get_masked_image_url($attachment_id, 'full');

        $this->assertStringContainsString('lltools-img=' . $attachment_id, $actual);
    }

    public function test_resolve_image_file_url_rewrites_masked_proxy_to_direct_when_disabled(): void
    {
        $_SERVER['HTTP_HOST'] = 'zazacaogren.com';

        $attachment_id = $this->createImageAttachment('proxy-mode-rewrite.png');
        update_option('ll_tools_use_masked_image_proxy', '1');
        $masked = ll_tools_get_masked_image_url($attachment_id, 'full');

        update_option('ll_tools_use_masked_image_proxy', '0');
        $resolved = ll_tools_resolve_image_file_url($masked);
        $direct = (string) wp_get_attachment_image_url($attachment_id, 'full');

        $this->assertNotSame('', $direct);
        $this->assertSame($direct, $resolved);
        $this->assertStringNotContainsString('lltools-img=', $resolved);
    }

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }
}
