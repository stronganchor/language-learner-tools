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

    public function test_shortcode_does_not_expose_private_wordset_images_or_filter_terms(): void
    {
        $public_wordset = self::factory()->term->create([
            'taxonomy' => 'wordset',
            'name' => 'Public Copyright Wordset',
            'slug' => 'public-copyright-wordset',
        ]);
        $private_wordset = self::factory()->term->create([
            'taxonomy' => 'wordset',
            'name' => 'Private Copyright Wordset',
            'slug' => 'private-copyright-wordset',
        ]);
        update_term_meta((int) $private_wordset, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');

        $this->createImageFixture(
            'Public Copyright Image',
            'Public source credit',
            (int) $public_wordset
        );
        $this->createImageFixture(
            'Private Copyright Image',
            'HiddenSource credit',
            (int) $private_wordset
        );

        wp_set_current_user(0);
        $_GET['ll_img_q'] = 'HiddenSource';

        $html = do_shortcode('[image_copyright_grid posts_per_page="12"]');

        $this->assertStringNotContainsString('Private Copyright Image', $html);
        $this->assertStringNotContainsString('Private Copyright Wordset', $html);
        $this->assertStringContainsString('Public Copyright Wordset', $html);
        $this->assertStringContainsString('No word images found with matching copyright info.', $html);
    }

    public function test_shortcode_does_not_expose_private_category_images_or_filter_terms(): void
    {
        $wordset = self::factory()->term->create([
            'taxonomy' => 'wordset',
            'name' => 'Category Privacy Wordset',
            'slug' => 'category-privacy-wordset',
        ]);
        $public_category = self::factory()->term->create([
            'taxonomy' => 'word-category',
            'name' => 'Public Copyright Category',
            'slug' => 'public-copyright-category',
        ]);
        $private_category = self::factory()->term->create([
            'taxonomy' => 'word-category',
            'name' => 'Private Copyright Category',
            'slug' => 'private-copyright-category',
        ]);
        update_term_meta((int) $private_category, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        ll_tools_set_category_wordset_owner((int) $public_category, (int) $wordset);
        ll_tools_set_category_wordset_owner((int) $private_category, (int) $wordset);

        $this->createImageFixture(
            'Public Category Copyright Image',
            'Public category source credit',
            (int) $wordset,
            (int) $public_category
        );
        $this->createImageFixture(
            'Private Category Copyright Image',
            'HiddenCategorySource credit',
            (int) $wordset,
            (int) $private_category
        );

        wp_set_current_user(0);
        $_GET['ll_img_wordset'] = (string) $wordset;
        $_GET['ll_img_q'] = 'HiddenCategorySource';

        $html = do_shortcode('[image_copyright_grid posts_per_page="12"]');

        $this->assertStringNotContainsString('Private Category Copyright Image', $html);
        $this->assertStringNotContainsString('Private Copyright Category', $html);
        $this->assertStringContainsString('Public Copyright Category', $html);
        $this->assertStringContainsString('No word images found with matching copyright info.', $html);
    }

    public function test_shortcode_caps_public_posts_per_page(): void
    {
        foreach (range(1, 3) as $index) {
            $this->createImageFixture('Capped Image ' . $index, 'Capped source ' . $index);
        }

        $cap = static function (): int {
            return 2;
        };

        add_filter('ll_image_copyright_grid_posts_per_page_cap', $cap);
        try {
            $html = do_shortcode('[image_copyright_grid posts_per_page="999"]');
        } finally {
            remove_filter('ll_image_copyright_grid_posts_per_page_cap', $cap);
        }

        $this->assertSame(2, substr_count($html, 'class="ll-image-copyright-grid-item"'));
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
