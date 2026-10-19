<?php
declare(strict_types=1);

final class VocabLessonActivitiesTest extends LL_Tools_TestCase
{
    private function fixture(): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $wordset = (int) self::factory()->term->create(['taxonomy' => 'wordset']);
        $category = (int) self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($category, $wordset, $category);
        update_term_meta($category, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category, 'll_quiz_option_type', 'text_translation');
        $lesson = (int) self::factory()->post->create(['post_type' => 'll_vocab_lesson', 'post_status' => 'publish']);
        update_post_meta($lesson, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset);
        update_post_meta($lesson, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category);
        for ($i = 0; $i < 5; $i++) {
            $word = (int) self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Exercise prompt ' . $i]);
            wp_set_object_terms($word, [$wordset], 'wordset');
            wp_set_object_terms($word, [$category], 'word-category');
            update_post_meta($word, 'word_translation', 'PRIVATE ANSWER KEY ' . $i);
            update_post_meta($word, 'translation', 'PRIVATE ANSWER KEY ' . $i);
        }
        return compact('wordset', 'category', 'lesson');
    }

    private function request(array $f, array $extra = []): array
    {
        return array_merge([
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $f['lesson'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $f['wordset'],
            'll_vocab_lesson_category_settings_category_id' => (string) $f['category'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $f['lesson']),
            'll_vocab_lesson_quiz_prompt_type' => 'text_title',
            'll_vocab_lesson_quiz_option_type' => 'text_translation',
            'll_vocab_lesson_activity_settings_present' => '1',
            'll_vocab_lesson_enabled_modes' => ['practice'],
            'll_vocab_lesson_embedded_mode' => 'practice',
        ], $extra);
    }

    public function test_defaults_keep_overview_but_no_audio_hides_listen(): void
    {
        $f = $this->fixture();
        $this->assertSame('', ll_tools_get_vocab_lesson_embedded_mode($f['lesson']));
        $this->assertContains('learning', ll_tools_get_vocab_lesson_activity_settings($f['lesson'])['enabled_modes']);
        $this->assertContains('practice', ll_tools_get_vocab_lesson_available_modes($f['lesson']));
        $this->assertNotContains('listening', ll_tools_get_vocab_lesson_available_modes($f['lesson']));
    }

    public function test_scoped_settings_save_and_legacy_requests_preserve_them(): void
    {
        $f = $this->fixture();
        $result = ll_tools_save_vocab_lesson_category_settings_from_request($this->request($f));
        $this->assertIsArray($result);
        $this->assertSame(['enabled_modes' => ['practice'], 'embedded_mode' => 'practice'], ll_tools_get_vocab_lesson_activity_settings($f['lesson']));
        $this->assertSame(['practice'], $result['available_modes']);
        $this->assertStringContainsString('lesson_id=' . $f['lesson'], $result['embedded_url']);
        $legacy = $this->request($f);
        unset($legacy['ll_vocab_lesson_activity_settings_present'], $legacy['ll_vocab_lesson_enabled_modes'], $legacy['ll_vocab_lesson_embedded_mode']);
        $this->assertIsArray(ll_tools_save_vocab_lesson_category_settings_from_request($legacy));
        $this->assertSame('practice', ll_tools_get_vocab_lesson_embedded_mode($f['lesson']));
    }

    public function test_embed_url_uses_canonical_permalink_slash_before_query_arguments(): void
    {
        global $wp_rewrite;
        $f = $this->fixture();
        $category = get_term($f['category'], 'word-category');
        $previous = $wp_rewrite->use_trailing_slashes;
        try {
            foreach ([true, false] as $trailing_slash) {
                $wp_rewrite->use_trailing_slashes = $trailing_slash;
                $url = ll_tools_get_vocab_lesson_activity_embed_url($f['lesson'], 'practice');
                $expected = home_url('/embed/' . $category->slug . ($trailing_slash ? '/' : ''));
                $this->assertSame(wp_parse_url($expected, PHP_URL_PATH), wp_parse_url($url, PHP_URL_PATH));
                parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame('practice', $query['mode']);
                $this->assertSame((string) $f['lesson'], $query['lesson_id']);
                $this->assertSame('vocab_lesson', $query['ll_context']);
                $this->assertSame(get_term($f['wordset'], 'wordset')->slug, $query['wordset']);
            }
        } finally {
            $wp_rewrite->use_trailing_slashes = $previous;
        }
    }

    public function test_invalid_modes_and_disabled_primary_are_rejected_before_writes(): void
    {
        $f = $this->fixture();
        foreach ([[], ['practice', 'unknown'], [['practice']]] as $modes) {
            $this->assertWPError(ll_tools_save_vocab_lesson_category_settings_from_request($this->request($f, ['ll_vocab_lesson_enabled_modes' => $modes])));
        }
        $this->assertWPError(ll_tools_save_vocab_lesson_category_settings_from_request($this->request($f, ['ll_vocab_lesson_embedded_mode' => 'listening'])));
        $this->assertSame('', get_post_meta($f['lesson'], LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META, true));
    }

    public function test_nonce_permission_and_exact_lesson_scope_are_required(): void
    {
        $f = $this->fixture();
        $this->assertWPError(ll_tools_save_vocab_lesson_category_settings_from_request($this->request($f, ['ll_vocab_lesson_category_settings_nonce' => 'bad'])));
        $this->assertWPError(ll_tools_save_vocab_lesson_category_settings_from_request($this->request($f, ['ll_vocab_lesson_category_settings_wordset_id' => '987654'])));
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertWPError(ll_tools_save_vocab_lesson_category_settings_from_request($this->request($f)));
        $this->assertSame('', get_post_meta($f['lesson'], LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META, true));
    }

    public function test_nontransactional_postmeta_fails_before_category_or_lesson_write(): void
    {
        $f = $this->fixture();
        add_filter('ll_tools_vocab_lesson_activity_settings_storage_is_transactional', '__return_false');
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($this->request($f, ['ll_vocab_lesson_quiz_prompt_type' => 'text_translation']));
            $this->assertWPError($result);
            $this->assertSame('transaction_storage', ll_tools_get_vocab_lesson_category_settings_error_code($result));
            $this->assertSame('text_title', get_term_meta($f['category'], 'll_quiz_prompt_type', true));
            $this->assertSame('', get_post_meta($f['lesson'], LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META, true));
        } finally { remove_filter('ll_tools_vocab_lesson_activity_settings_storage_is_transactional', '__return_false'); }
    }

    public function test_failed_lesson_write_rolls_back_category_changes(): void
    {
        $f = $this->fixture();
        $block = static function ($value, $object_id, $meta_key) use ($f) {
            return $object_id === $f['lesson'] && $meta_key === LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META ? false : $value;
        };
        add_filter('update_post_metadata', $block, 10, 3);
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($this->request($f, ['ll_vocab_lesson_quiz_prompt_type' => 'text_translation']));
            $this->assertWPError($result);
            $this->assertSame('text_title', get_term_meta($f['category'], 'll_quiz_prompt_type', true));
            $this->assertSame('', get_post_meta($f['lesson'], LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META, true));
        } finally { remove_filter('update_post_metadata', $block, 10); }
    }

    public function test_embedded_page_omits_answer_grid_and_retains_staff_controls(): void
    {
        $f = $this->fixture();
        update_post_meta($f['lesson'], LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META, ['enabled_modes' => ['practice'], 'embedded_mode' => 'practice']);
        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $f['lesson']);
        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('data-ll-lesson-activity-frame', $html);
        $this->assertStringContainsString('data-iframe-state="loading"', $html);
        $this->assertStringContainsString('ll-tools-iframe-state ll-tools-iframe-state--loading', $html);
        $this->assertStringContainsString('ll-tools-iframe-loading-status screen-reader-text', $html);
        $this->assertContains('ll-quiz-pages-js', wp_scripts()->registered['ll-tools-vocab-lesson-page']->deps);
        $this->assertSame(1, preg_match('/<iframe[^>]+data-ll-lesson-activity-frame[^>]+src="([^"]+)"/', $html, $frame));
        $iframe_url = html_entity_decode($frame[1], ENT_QUOTES, 'UTF-8');
        $this->assertSame(ll_tools_get_vocab_lesson_activity_embed_url($f['lesson'], 'practice'), $iframe_url);
        $this->assertSame(
            wp_parse_url(home_url(user_trailingslashit('/embed/' . get_term($f['category'], 'word-category')->slug)), PHP_URL_PATH),
            wp_parse_url($iframe_url, PHP_URL_PATH)
        );
        $this->assertStringContainsString('lesson_id=' . $f['lesson'], $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_enabled_modes[]"', $html);
        $this->assertStringNotContainsString('data-ll-vocab-lesson-grid-shell', $html);
        $this->assertStringNotContainsString('PRIVATE ANSWER KEY', $html);
        $this->assertStringNotContainsString('data-mode="listening"', $html);
        $this->assertFalse(ll_tools_vocab_lesson_should_preload_flashcards());
    }

    public function test_unavailable_embedded_activity_does_not_fall_back_to_answer_overview(): void
    {
        $f = $this->fixture();
        update_post_meta($f['lesson'], LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META, ['enabled_modes' => ['listening'], 'embedded_mode' => 'listening']);
        $this->assertSame([], ll_tools_get_vocab_lesson_available_modes($f['lesson']));
        $this->assertSame('listening', ll_tools_get_vocab_lesson_embedded_mode($f['lesson']));
        $this->assertSame('', ll_tools_get_vocab_lesson_activity_embed_url($f['lesson'], 'listening'));
        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $f['lesson']);
        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('This activity is currently unavailable.', $html);
        $this->assertStringNotContainsString('data-ll-vocab-lesson-grid-shell', $html);
        $this->assertStringNotContainsString('PRIVATE ANSWER KEY', $html);
    }

    public function test_embed_access_checks_exact_scope_password_and_draft_status(): void
    {
        $f = $this->fixture();
        $this->assertTrue(ll_tools_vocab_lesson_activity_context_is_accessible($f['lesson'], $f['category'], $f['wordset']));
        $this->assertFalse(ll_tools_vocab_lesson_activity_context_is_accessible($f['lesson'], $f['category'] + 100, $f['wordset']));
        wp_update_post(['ID' => $f['lesson'], 'post_password' => 'protected']);
        $this->assertFalse(ll_tools_vocab_lesson_activity_context_is_accessible($f['lesson'], $f['category'], $f['wordset']));
        wp_update_post(['ID' => $f['lesson'], 'post_password' => '', 'post_status' => 'draft']);
        wp_set_current_user(0);
        $this->assertFalse(ll_tools_vocab_lesson_activity_context_is_accessible($f['lesson'], $f['category'], $f['wordset']));
    }
}
