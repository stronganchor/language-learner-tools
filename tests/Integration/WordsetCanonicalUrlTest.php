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

    public function test_shortcode_page_with_different_slug_can_explicitly_redirect_to_canonical_route(): void
    {
        update_option('permalink_structure', '/%postname%/');

        $fixture = $this->createWordsetLessonFixture('Explicit Canonical Redirect');
        $wordset = $fixture['wordset'];
        $page_slug = 'retired-vocab-page-' . strtolower(wp_generate_password(4, false));
        $page_id = $this->createLegacyWordsetPage(
            $page_slug,
            '[ll_wordset_page wordset="' . esc_attr((string) $wordset->slug)
                . '" redirect_to_canonical="1"]'
        );

        $page_url = add_query_arg('from', 'legacy-nav', (string) get_permalink($page_id));
        $this->go_to($page_url);
        $this->assertTrue(is_page($page_id));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl($page_url);

        $this->assertSame(
            add_query_arg('from', 'legacy-nav', ll_tools_get_wordset_page_view_url($wordset)),
            ll_tools_get_wordset_page_shortcode_legacy_redirect_url()
        );
    }

    public function test_shortcode_page_with_different_slug_can_explicitly_redirect_to_pretty_canonical_route(): void
    {
        update_option('permalink_structure', '/%postname%/');

        $fixture = $this->createWordsetLessonFixture('Explicit Pretty Redirect');
        $wordset = $fixture['wordset'];
        $slug = sanitize_title((string) $wordset->slug);
        $page_slug = 'retired-pretty-page-' . strtolower(wp_generate_password(4, false));
        $page_id = $this->createLegacyWordsetPage(
            $page_slug,
            '[ll_wordset_page wordset="' . esc_attr($slug)
                . '" redirect_to_canonical="1"]'
        );

        $rewrite_rules_before = get_option('rewrite_rules');
        update_option('rewrite_rules', [
            '^' . preg_quote($slug, '/') . '/?$' => 'index.php?ll_wordset_page=' . $slug,
            '^' . preg_quote($slug, '/') . '/progress/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=progress',
            '^' . preg_quote($slug, '/') . '/hidden-categories/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=hidden-categories',
            '^' . preg_quote($slug, '/') . '/settings/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=settings',
            '^' . preg_quote($slug, '/') . '/games/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=games',
            '^' . preg_quote($slug, '/') . '/classes/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=classes',
        ]);

        try {
            $page_url = add_query_arg('from', 'legacy-nav', (string) get_permalink($page_id));
            $this->go_to($page_url);
            $this->assertTrue(is_page($page_id));

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl($page_url);

            $this->assertSame(
                add_query_arg('from', 'legacy-nav', home_url(user_trailingslashit($slug))),
                ll_tools_get_wordset_page_shortcode_legacy_redirect_url()
            );
        } finally {
            if ($rewrite_rules_before === false) {
                delete_option('rewrite_rules');
            } else {
                update_option('rewrite_rules', $rewrite_rules_before);
            }
        }
    }

    public function test_shortcode_page_with_different_slug_does_not_redirect_for_unknown_flag_value(): void
    {
        update_option('permalink_structure', '/%postname%/');

        $fixture = $this->createWordsetLessonFixture('Invalid Canonical Redirect Flag');
        $wordset = $fixture['wordset'];
        $page_slug = 'embedded-wordset-' . strtolower(wp_generate_password(4, false));
        $page_id = $this->createLegacyWordsetPage(
            $page_slug,
            '[ll_wordset_page wordset="' . esc_attr((string) $wordset->slug)
                . '" redirect_to_canonical="sometimes"]'
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

    public function test_content_lesson_back_link_uses_canonical_wordset_page_url_when_page_slug_conflicts(): void
    {
        update_option('permalink_structure', '/%postname%/');

        $fixture = $this->createContentLessonFixture('Content Back Link Wordset');
        $wordset = $fixture['wordset'];
        $this->createLegacyWordsetPage((string) $wordset->slug, '[wordset_page]');

        $this->go_to('/?post_type=ll_content_lesson&p=' . (int) $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_content_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/content-lesson-template.php';
        $html = (string) ob_get_clean();

        $expected_back = ll_tools_get_wordset_page_view_url($wordset);
        $legacy_back = trailingslashit(home_url((string) $wordset->slug));

        $this->assertStringContainsString('href="' . esc_url($expected_back) . '"', $html);
        $this->assertStringNotContainsString('href="' . esc_url($legacy_back) . '"', $html);
    }

    public function test_corpus_content_lesson_template_uses_one_visible_title_and_labels_the_reader_with_it(): void
    {
        $lesson_title = 'Corpus Heading ' . strtolower(wp_generate_password(5, false));
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => $lesson_title,
            'post_excerpt' => 'Corpus heading regression fixture.',
        ]);
        $payload = [
            'schema' => 'll_tools_text_document.v1',
            'kind' => 'corpus_text',
            'title' => $lesson_title,
            'source_label' => 'Source',
            'translations' => [
                'en' => ['label' => 'English'],
            ],
            'reading_units' => [
                [
                    'id' => 'heading-fixture-line',
                    'source' => 'Corpus source line.',
                    'translations' => ['en' => 'Corpus translation line.'],
                ],
            ],
        ];
        $this->assertNotWPError(ll_tools_interlinear_set_payload($lesson_id, $payload, 'phpunit'));

        $this->go_to('/?post_type=ll_content_lesson&p=' . $lesson_id);
        $this->assertTrue(is_singular('ll_content_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/content-lesson-template.php';
        $html = (string) ob_get_clean();

        $heading_id = 'll-content-lesson-title-' . $lesson_id;
        $this->assertSame(1, substr_count($html, 'class="ll-content-lesson-title"'));
        $this->assertStringContainsString('id="' . esc_attr($heading_id) . '"', $html);
        $this->assertStringContainsString('aria-labelledby="' . esc_attr($heading_id) . '"', $html);
        $this->assertStringNotContainsString('class="ll-text-document__title"', $html);
        $this->assertStringContainsString('Corpus source line.', $html);
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

    /**
     * @return array{wordset:WP_Term,lesson_id:int}
     */
    private function createContentLessonFixture(string $label): array
    {
        $suffix = strtolower(wp_generate_password(4, false));
        $wordset_slug = sanitize_title($label . '-' . $suffix);

        $wordset = wp_insert_term($label . ' ' . $suffix, 'wordset', ['slug' => $wordset_slug]);
        $this->assertIsArray($wordset);
        $wordset_term = get_term((int) $wordset['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => $label . ' Lesson ' . $suffix,
            'post_excerpt' => 'Canonical content lesson back link fixture.',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (int) $wordset_term->term_id);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');

        return [
            'wordset' => $wordset_term,
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
