<?php
declare(strict_types=1);

final class ImageCopyrightGridShortcodeTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['ll_img_q'], $_GET['ll_img_wordset'], $_GET['ll_img_category']);
        parent::tearDown();
    }

    public function test_shortcode_renders_source_urls_as_compact_links(): void
    {
        $source_url = 'https://commons.wikimedia.org/wiki/File%3AYayladere.JPG';
        $this->createImageFixture('Yayladere place image', 'phia; CC BY-SA 4.0; ' . $source_url);

        $html = do_shortcode('[image_copyright_grid posts_per_page="12"]');

        $this->assertStringContainsString('class="ll-image-copyright-source-link"', $html);
        $this->assertStringContainsString('href="' . esc_url($source_url) . '"', $html);
        $this->assertStringContainsString('>Source</a>', $html);
        $this->assertStringNotContainsString('; ' . $source_url . '</div>', $html);
    }

    public function test_shortcode_searches_copyright_meta_and_filters_by_wordset_and_category(): void
    {
        $wordset_alpha = self::factory()->term->create([
            'taxonomy' => 'wordset',
            'name' => 'Alpha Wordset',
            'slug' => 'alpha-wordset',
        ]);
        $wordset_beta = self::factory()->term->create([
            'taxonomy' => 'wordset',
            'name' => 'Beta Wordset',
            'slug' => 'beta-wordset',
        ]);
        $category_alpha = self::factory()->term->create([
            'taxonomy' => 'word-category',
            'name' => 'Alpha Category',
            'slug' => 'alpha-category',
        ]);
        $category_beta = self::factory()->term->create([
            'taxonomy' => 'word-category',
            'name' => 'Beta Category',
            'slug' => 'beta-category',
        ]);

        ll_tools_set_category_wordset_owner((int) $category_alpha, (int) $wordset_alpha);
        ll_tools_set_category_wordset_owner((int) $category_beta, (int) $wordset_beta);

        $this->createImageFixture(
            'Alpha Image',
            'Alpha Photographer; CC BY-SA 4.0; https://example.com/alpha',
            (int) $wordset_alpha,
            (int) $category_alpha
        );
        $this->createImageFixture(
            'Beta Image',
            'Hidden Beta Credit; CC BY-SA 4.0; https://example.com/beta',
            (int) $wordset_beta,
            (int) $category_beta
        );

        $_GET['ll_img_q'] = 'Hidden Beta Credit';
        $search_html = do_shortcode('[image_copyright_grid posts_per_page="12"]');
        $this->assertStringContainsString('Beta Image', $search_html);
        $this->assertStringNotContainsString('Alpha Image', $search_html);

        unset($_GET['ll_img_q']);
        $_GET['ll_img_wordset'] = (string) $wordset_alpha;
        $wordset_html = do_shortcode('[image_copyright_grid posts_per_page="12"]');
        $this->assertStringContainsString('Alpha Image', $wordset_html);
        $this->assertStringNotContainsString('Beta Image', $wordset_html);

        unset($_GET['ll_img_wordset']);
        $_GET['ll_img_category'] = (string) $category_beta;
        $category_html = do_shortcode('[image_copyright_grid posts_per_page="12"]');
        $this->assertStringContainsString('Beta Image', $category_html);
        $this->assertStringNotContainsString('Alpha Image', $category_html);
    }

    private function createImageFixture(string $title, string $copyright, int $wordset_id = 0, int $category_id = 0): int
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => sanitize_title($title),
        ]);

        update_post_meta($post_id, 'copyright_info', $copyright);

        if ($wordset_id > 0) {
            ll_tools_set_word_image_wordset_owner((int) $post_id, $wordset_id);
        }

        if ($category_id > 0) {
            wp_set_object_terms($post_id, [$category_id], 'word-category');
        }

        return (int) $post_id;
    }
}
