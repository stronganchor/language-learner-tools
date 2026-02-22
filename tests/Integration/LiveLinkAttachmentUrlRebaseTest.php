<?php
declare(strict_types=1);

final class LiveLinkAttachmentUrlRebaseTest extends LL_Tools_TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function test_rebase_local_upload_url_to_request_origin(): void
    {
        $_SERVER['HTTP_HOST'] = 'live-link.localsite.io';
        $_SERVER['HTTPS'] = 'on';
        unset($_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_PORT']);

        $uploads = wp_get_upload_dir();
        $uploads_path = (string) wp_parse_url((string) ($uploads['baseurl'] ?? ''), PHP_URL_PATH);
        $this->assertNotSame('', $uploads_path);

        $legacy = 'http://old-local-host.invalid' . rtrim($uploads_path, '/') . '/2026/02/rebased.jpg';
        $rebased = ll_tools_rebase_local_media_url_to_request_origin($legacy);

        $parts = wp_parse_url($rebased);
        $this->assertIsArray($parts);
        $this->assertSame('https', (string) ($parts['scheme'] ?? ''));
        $this->assertSame('live-link.localsite.io', (string) ($parts['host'] ?? ''));
        $this->assertSame(rtrim($uploads_path, '/') . '/2026/02/rebased.jpg', (string) ($parts['path'] ?? ''));
    }

    public function test_rebase_preserves_masked_proxy_query_params(): void
    {
        $_SERVER['HTTP_HOST'] = 'preview.localsite.io';
        $_SERVER['HTTPS'] = 'on';

        $legacy = 'http://old-origin.invalid/?lltools-img=22&lltools-size=medium&lltools-sig=abc123';
        $rebased = ll_tools_rebase_local_media_url_to_request_origin($legacy);

        $parts = wp_parse_url($rebased);
        $this->assertIsArray($parts);
        $this->assertSame('preview.localsite.io', (string) ($parts['host'] ?? ''));
        $query_args = [];
        wp_parse_str((string) ($parts['query'] ?? ''), $query_args);
        $this->assertSame('22', (string) ($query_args['lltools-img'] ?? ''));
        $this->assertSame('medium', (string) ($query_args['lltools-size'] ?? ''));
        $this->assertSame('abc123', (string) ($query_args['lltools-sig'] ?? ''));
    }

    public function test_rebase_does_not_touch_external_url(): void
    {
        $_SERVER['HTTP_HOST'] = 'preview.localsite.io';
        $_SERVER['HTTPS'] = 'on';

        $external = 'https://images.example-cdn.com/assets/photo.jpg';
        $this->assertSame($external, ll_tools_rebase_local_media_url_to_request_origin($external));
    }

    public function test_non_live_link_request_does_not_rebase_when_forwarded_host_differs(): void
    {
        $home = wp_parse_url(home_url('/'));
        $this->assertIsArray($home);

        $_SERVER['HTTP_HOST'] = (string) ($home['host'] ?? 'example.org');
        $_SERVER['HTTPS'] = (((string) ($home['scheme'] ?? 'http')) === 'https') ? 'on' : '';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'internal-lb.invalid';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = (string) ($home['scheme'] ?? 'http');
        $_SERVER['HTTP_X_FORWARDED_PORT'] = (string) ($home['port'] ?? ((((string) ($home['scheme'] ?? 'http')) === 'https') ? 443 : 80));
        unset($_SERVER['HTTP_X_TUNNEL_UUID'], $_SERVER['HTTP_X_LOCAL_HOST']);

        $uploads = wp_get_upload_dir();
        $uploads_path = (string) wp_parse_url((string) ($uploads['baseurl'] ?? ''), PHP_URL_PATH);
        $this->assertNotSame('', $uploads_path);

        $legacy = 'http://legacy-origin.invalid' . rtrim($uploads_path, '/') . '/2026/02/no-rebase.jpg';
        $this->assertSame($legacy, ll_tools_rebase_local_media_url_to_request_origin($legacy));
        $this->assertFalse(ll_tools_should_rebase_media_urls_to_request_origin());
    }

    public function test_live_link_request_detection_uses_host_and_tunnel_headers(): void
    {
        $_SERVER['HTTP_HOST'] = 'preview.localsite.io';
        $this->assertTrue(ll_tools_is_live_link_request());

        $_SERVER['HTTP_HOST'] = 'example.com';
        unset($_SERVER['HTTP_X_TUNNEL_UUID'], $_SERVER['HTTP_X_LOCAL_HOST']);
        $this->assertFalse(ll_tools_is_live_link_request());

        $_SERVER['HTTP_X_TUNNEL_UUID'] = 'abc123';
        $this->assertFalse(ll_tools_is_live_link_request());
    }

    public function test_live_link_request_detection_can_trust_tunnel_headers_via_filter(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTP_X_TUNNEL_UUID'] = 'abc123';

        $allow_tunnel_headers = static function (bool $trusted, string $host): bool {
            return true;
        };
        add_filter('ll_tools_media_proxy_trust_tunnel_headers', $allow_tunnel_headers, 10, 2);

        try {
            $this->assertTrue(ll_tools_is_live_link_request());
        } finally {
            remove_filter('ll_tools_media_proxy_trust_tunnel_headers', $allow_tunnel_headers, 10);
        }
    }

    public function test_request_origin_ignores_forwarded_headers_when_not_trusted(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = '';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'spoofed.invalid';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '443';

        $origin = ll_tools_get_request_origin_for_media();

        $this->assertSame('example.com', (string) ($origin['host'] ?? ''));
        $this->assertSame('http', (string) ($origin['scheme'] ?? ''));
        $this->assertSame(80, (int) ($origin['port'] ?? 0));
    }
}
