<?php
declare(strict_types=1);

final class LiveLinkImageUrlNormalizationTest extends LL_Tools_TestCase
{
    public function test_resolve_image_file_url_rebases_legacy_upload_origin(): void
    {
        $uploads = wp_get_upload_dir();
        $base_url = (string) ($uploads['baseurl'] ?? '');
        $base_path = (string) wp_parse_url($base_url, PHP_URL_PATH);

        $this->assertNotSame('', $base_url);
        $this->assertNotSame('', $base_path);

        $legacy = 'https://legacy-host.invalid' . rtrim($base_path, '/') . '/2026/02/sample-image.jpg';
        $expected = trailingslashit($base_url) . '2026/02/sample-image.jpg';

        $this->assertSame($expected, ll_tools_resolve_image_file_url($legacy));
    }

    public function test_normalize_words_audio_urls_repairs_cached_image_urls(): void
    {
        $uploads = wp_get_upload_dir();
        $base_url = (string) ($uploads['baseurl'] ?? '');
        $base_path = (string) wp_parse_url($base_url, PHP_URL_PATH);

        $this->assertNotSame('', $base_url);
        $this->assertNotSame('', $base_path);

        $legacy = 'http://stale-origin.invalid' . rtrim($base_path, '/') . '/2026/02/cached-image.jpg';
        $expected = trailingslashit($base_url) . '2026/02/cached-image.jpg';

        $rows = [
            [
                'id' => 123,
                'image' => $legacy,
                'audio' => '',
                'audio_files' => [],
            ],
        ];

        $normalized = ll_tools_normalize_words_audio_urls($rows);
        $this->assertSame($expected, $normalized[0]['image']);
    }

    public function test_resolve_image_file_url_preserves_masked_proxy_query_params(): void
    {
        $legacy = 'https://old-origin.invalid/?lltools-img=17&lltools-size=medium&lltools-sig=testsig';
        $resolved = ll_tools_resolve_image_file_url($legacy);

        $resolved_parts = wp_parse_url($resolved);
        $home_parts = wp_parse_url(home_url('/'));
        $query_args = [];
        wp_parse_str((string) ($resolved_parts['query'] ?? ''), $query_args);

        $this->assertIsArray($resolved_parts);
        $this->assertIsArray($home_parts);
        $this->assertSame((string) ($home_parts['host'] ?? ''), (string) ($resolved_parts['host'] ?? ''));
        $this->assertSame('/', (string) ($resolved_parts['path'] ?? ''));
        $this->assertSame('17', (string) ($query_args['lltools-img'] ?? ''));
        $this->assertSame('medium', (string) ($query_args['lltools-size'] ?? ''));
        $this->assertSame('testsig', (string) ($query_args['lltools-sig'] ?? ''));
    }
}

