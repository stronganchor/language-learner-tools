<?php
declare(strict_types=1);

final class WordsetProgressLoadingShellTest extends LL_Tools_TestCase
{
    public function test_progress_view_renders_visual_loading_shells_before_deferred_analytics(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset_id = $this->createWordsetFixture();
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'progress');
        add_filter('ll_tools_wordset_page_bootstrap_analytics', '__return_false', 99);

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', '__return_false', 99);
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }

        $this->assertNotSame('', $html);

        $previous_libxml_errors = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_libxml_errors);
        $this->assertTrue($loaded);

        $xpath = new DOMXPath($document);
        $graph = $xpath->query('//*[@data-ll-wordset-progress-graph]')->item(0);
        $category_body = $xpath->query('//*[@data-ll-wordset-progress-categories-body]')->item(0);
        $word_body = $xpath->query('//*[@data-ll-wordset-progress-words-body]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $graph);
        $this->assertInstanceOf(DOMElement::class, $category_body);
        $this->assertInstanceOf(DOMElement::class, $word_body);
        $this->assertSame('true', $graph->getAttribute('aria-busy'));
        $this->assertSame('true', $category_body->getAttribute('aria-busy'));
        $this->assertSame('true', $word_body->getAttribute('aria-busy'));
        $this->assertSame(14, $xpath->query('//*[@data-ll-wordset-progress-graph-loading-bar]')->length);
        $this->assertSame(5, $xpath->query('//*[@data-ll-wordset-progress-loading-kind="categories"]')->length);
        $this->assertSame(5, $xpath->query('//*[@data-ll-wordset-progress-loading-kind="words"]')->length);
        $this->assertSame(10, $xpath->query('//*[@data-ll-wordset-progress-loading-row and @aria-hidden="true"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-ll-wordset-progress-loading-row]//a | //*[@data-ll-wordset-progress-loading-row]//button | //*[@data-ll-wordset-progress-loading-row]//img')->length);
        $this->assertStringNotContainsString('No activity yet.', (string) $graph->textContent);
        $this->assertStringNotContainsString('No data yet.', (string) $category_body->textContent);
        $this->assertStringNotContainsString('No data yet.', (string) $word_body->textContent);
    }

    private function createWordsetFixture(): int
    {
        $wordset = wp_insert_term('Progress Loading Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Progress Loading Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Progress Loading Word ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Progress Loading Translation');

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Progress Loading Audio',
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/progress-loading.mp3');

        return $wordset_id;
    }
}
