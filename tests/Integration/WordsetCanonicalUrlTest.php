<?php
declare(strict_types=1);

final class WordsetCanonicalUrlTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_SERVER = $this->serverBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);
        parent::tearDown();
    }

    public function test_legacy_shortcode_page_redirects_to_canonical_query_route_when_slug_matches_wordset(): void
    {
        update_option('permalink_structure', '/%postname%/');

        $fixture = $this->createWordsetLessonFixture('Legacy Redirect Wordset');
        $wordset = $fixture['wordset'];
        $page_id = $this->createLegacyWordsetPage((string) $wordset->slug, '[wordset_page]');

        $legacy_url = add_query_arg('ll_tools_auth', 'login', (string) get_permalink($page_id));
        $this->go_to($legacy_url);
        $this->assertTrue(is_page($page_id));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl($legacy_url);

        $redirect_url = ll_tools_get_wordset_page_shortcode_legacy_redirect_url();
        $expected_url = add_query_arg('ll_tools_auth', 'login', ll_tools_get_wordset_page_view_url($wordset));

        $this->assertStringContainsString('ll_wordset_page=' . rawurlencode((string) $wordset->slug), $redirect_url);
        $this->assertSame($expected_url, $redirect_url);
    }

    public function test_shortcode_page_with_different_slug_does_not_redirect(): void
    {
        update_option('permalink_structure', '/%postname%/');

        $fixture = $this->createWordsetLessonFixture('Custom Wordset Page');
        $wordset = $fixture['wordset'];
        $page_slug = 'custom-wordset-page-' . strtolower(wp_generate_password(4, false));
        $page_id = $this->createLegacyWordsetPage(
            $page_slug,
            '[wordset_page wordset="' . esc_attr((string) $wordset->slug) . '"]'
        );

        $page_url = (string) get_permalink($page_id);
        $this->go_to($page_url);
        $this->assertTrue(is_page($page_id));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl($page_url);

        $this->assertSame('', ll_tools_get_wordset_page_shortcode_legacy_redirect_url());
    }

    public function test_vocab_lesson_back_link_uses_canonical_wordset_page_url_when_page_slug_conflicts(): void
    {
        update_option('permalink_structure', '/%postname%/');

        $fixture = $this->createWordsetLessonFixture('Lesson Back Link Wordset');
        $wordset = $fixture['wordset'];
        $this->createLegacyWordsetPage((string) $wordset->slug, '[wordset_page]');

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . (int) $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $expected_back = ll_tools_get_wordset_page_view_url($wordset);
        $legacy_back = trailingslashit(home_url((string) $wordset->slug));

        $this->assertStringContainsString('href="' . esc_url($expected_back) . '"', $html);
        $this->assertStringNotContainsString('href="' . esc_url($legacy_back) . '"', $html);
    }

    /**
     * @return array{wordset:WP_Term,category:WP_Term,lesson_id:int}
     */
    private function createWordsetLessonFixture(string $label): array
    {
        $suffix = strtolower(wp_generate_password(4, false));
        $wordset_slug = sanitize_title($label . '-' . $suffix);
        $category_slug = sanitize_title($label . '-category-' . $suffix);

        $wordset = wp_insert_term($label . ' ' . $suffix, 'wordset', ['slug' => $wordset_slug]);
        $this->assertIsArray($wordset);
        $wordset_term = get_term((int) $wordset['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $category = wp_insert_term($label . ' Category ' . $suffix, 'word-category', ['slug' => $category_slug]);
        $this->assertIsArray($category);
        $category_term = get_term((int) $category['term_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);

        if (function_exists('ll_tools_set_category_wordset_owner')) {
            ll_tools_set_category_wordset_owner((int) $category_term->term_id, (int) $wordset_term->term_id, (int) $category_term->term_id);
        }

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => $label . ' Lesson ' . $suffix,
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (int) $wordset_term->term_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (int) $category_term->term_id);

        return [
            'wordset' => $wordset_term,
            'category' => $category_term,
            'lesson_id' => $lesson_id,
        ];
    }

    private function createLegacyWordsetPage(string $slug, string $shortcode): int
    {
        return self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Legacy Page ' . $slug,
            'post_name' => sanitize_title($slug),
            'post_content' => $shortcode,
        ]);
    }

    private function requestUriFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        return $path . ($query !== '' ? ('?' . $query) : '');
    }
}
