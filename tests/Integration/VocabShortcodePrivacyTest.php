<?php
declare(strict_types=1);

final class VocabShortcodePrivacyTest extends LL_Tools_TestCase
{
    private int $savedStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $status = http_response_code();
        $this->savedStatus = is_int($status) ? $status : 200;
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        if (!headers_sent()) {
            http_response_code($this->savedStatus);
        }
        parent::tearDown();
    }

    public function test_audio_selectors_hide_published_words_in_private_scopes(): void
    {
        foreach (['wordset', 'word-category'] as $privateTaxonomy) {
            [$wordId, $audioId, $wordsetId, $categoryId, $title] = $this->createWordFixture();
            update_term_meta(
                $privateTaxonomy === 'wordset' ? $wordsetId : $categoryId,
                $privateTaxonomy === 'wordset' ? LL_TOOLS_WORDSET_VISIBILITY_META_KEY : LL_TOOLS_CATEGORY_VISIBILITY_META_KEY,
                'private'
            );
            $this->completeLlToolsSimulatedRequest();
            $shortcodes = [
                '[word_audio]' . $title . '[/word_audio]',
                '[word_audio id="' . $wordId . '"]Public label[/word_audio]',
                '[word_audio word_audio_id="' . $audioId . '"]Public label[/word_audio]',
            ];
            foreach ($shortcodes as $shortcode) {
                $output = do_shortcode($shortcode);
                $this->assertStringNotContainsString('ll-word-audio__button', $output);
                $this->assertStringNotContainsString('privacy-audio-' . $wordId . '.mp3', $output);
                $this->assertStringNotContainsString('Private meaning marker', $output);
            }
        }
    }

    public function test_grid_checks_explicit_wordset_and_unscoped_word_ids(): void
    {
        [$wordId, , $wordsetId, $categoryId, $title] = $this->createWordFixture();
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        $this->completeLlToolsSimulatedRequest();
        $this->assertSame('', do_shortcode('[word_grid category="' . $categoryId . '" wordset="' . $wordsetId . '"]'));
        $unscoped = do_shortcode('[word_grid word_ids="' . $wordId . '"]');
        $this->assertStringNotContainsString($title, $unscoped);
        $this->assertStringNotContainsString('Private meaning marker', $unscoped);

        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $adminOutput = do_shortcode('[word_grid category="' . $categoryId . '" wordset="' . $wordsetId . '"]');
        $this->assertStringContainsString($title, $adminOutput);
        $this->assertStringContainsString('ll-word-audio__button', do_shortcode('[word_audio id="' . $wordId . '"]Label[/word_audio]'));
    }

    public function test_shared_words_keep_accessible_memberships_in_both_taxonomies(): void
    {
        [$wordId, , $wordsetId, $categoryId, $title] = $this->createWordFixture();
        $otherWordset = wp_insert_term('Private shared set', 'wordset', ['slug' => 'private-shared-set']);
        $otherCategory = wp_insert_term('Private shared category', 'word-category', ['slug' => 'private-shared-category']);
        $this->assertIsArray($otherWordset);
        $this->assertIsArray($otherCategory);
        update_term_meta((int) $otherWordset['term_id'], LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        update_term_meta((int) $otherCategory['term_id'], LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        wp_set_post_terms($wordId, [$wordsetId, (int) $otherWordset['term_id']], 'wordset', false);
        wp_set_post_terms($wordId, [$categoryId, (int) $otherCategory['term_id']], 'word-category', false);
        $this->completeLlToolsSimulatedRequest();
        $complete = true;
        $this->assertTrue(ll_tools_user_can_view_vocab_post($wordId, 0, $complete));
        $this->assertTrue($complete);
        $this->assertStringContainsString('ll-word-audio__button', do_shortcode('[word_audio id="' . $wordId . '"]Label[/word_audio]'));
        $this->assertStringContainsString($title, do_shortcode('[word_grid category="' . $categoryId . '" wordset="' . $wordsetId . '"]'));
        $this->assertSame('', do_shortcode('[word_grid wordset="' . $otherWordset['term_id'] . '" word_ids="' . $wordId . '"]'));
    }

    public function test_assigned_nonadmin_can_still_render_private_wordset_and_category(): void
    {
        [$wordId, , $wordsetId, $categoryId, $title] = $this->createWordFixture();
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        update_term_meta($categoryId, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        $viewerId = (int) self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewerId);
        $viewer->add_cap('view_ll_tools');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_MANAGER_USER_IDS_META_KEY, [$viewerId]);
        update_term_meta($categoryId, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, [$viewerId]);
        $this->completeLlToolsSimulatedRequest();
        wp_set_current_user($viewerId);
        $this->assertFalse(current_user_can('manage_options'));
        $this->assertTrue(ll_tools_user_can_view_vocab_post($wordId));
        $this->assertStringContainsString('ll-word-audio__button', do_shortcode('[word_audio id="' . $wordId . '"]Label[/word_audio]'));
        $this->assertStringContainsString($title, do_shortcode('[word_grid category="' . $categoryId . '" wordset="' . $wordsetId . '"]'));
    }

    public function test_membership_error_object_fails_closed_and_can_retry(): void
    {
        [$wordId, , $wordsetId, $categoryId] = $this->createWordFixture();
        $fault = static function ($terms, array $objectIds, array $taxonomies, array $args) use ($wordId) {
            return in_array($wordId, array_map('intval', $objectIds), true)
                && in_array('wordset', $taxonomies, true)
                && !empty($args['suppress_filter'])
                && ($args['fields'] ?? '') === 'all_with_object_id'
                ? new WP_Error('privacy-test-membership-unavailable')
                : $terms;
        };
        // Fault only the canonical raw read, not WP_Query's term-cache priming.
        add_filter('get_object_terms', $fault, 10, 4);
        $statuses = [];
        $observeStatus = static function (string $header, int $code) use (&$statuses): string {
            $statuses[] = $code;
            return $header;
        };
        add_filter('status_header', $observeStatus, 10, 2);
        try {
            $complete = true;
            $this->assertFalse(ll_tools_user_can_view_vocab_post($wordId, 0, $complete));
            $this->assertFalse($complete);
            $this->assertStringNotContainsString('privacy-audio-', do_shortcode('[word_audio id="' . $wordId . '"]Label[/word_audio]'));
            $this->assertSame('', do_shortcode('[word_grid word_ids="' . $wordId . '"]'));
            $gridComplete = true;
            $this->assertSame('', ll_tools_word_grid_shortcode(['word_ids' => (string) $wordId], null, 'word_grid', $gridComplete));
            $this->assertFalse($gridComplete, 'Callers must not cache a temporary visibility-read failure as a completed empty grid.');
            $this->assertContains(503, $statuses, 'An incomplete rendered page must request an unavailable status so static capture skips it.');
        } finally {
            remove_filter('get_object_terms', $fault, 10);
            remove_filter('status_header', $observeStatus, 10);
        }
        $complete = false;
        $this->assertTrue(ll_tools_user_can_view_vocab_post($wordId, 0, $complete));
        $this->assertTrue($complete);
        $gridComplete = false;
        $this->assertStringContainsString('privacy-audio-', ll_tools_word_grid_shortcode([
            'word_ids' => (string) $wordId,
            'wordset' => (string) $wordsetId,
            'category' => (string) $categoryId,
        ], null, 'word_grid', $gridComplete));
        $this->assertTrue($gridComplete);
    }

    public function test_visibility_error_does_not_become_unassigned_or_poison_a_retry(): void
    {
        global $wpdb;
        [$wordId] = $this->createWordFixture();
        $fault = static function ($terms, array $objectIds, array $taxonomies, array $args) use ($wordId, $wpdb) {
            if (in_array($wordId, array_map('intval', $objectIds), true)
                && in_array('wordset', $taxonomies, true)
                && !empty($args['suppress_filter'])
                && ($args['fields'] ?? '') === 'all_with_object_id') {
                $wpdb->last_error = 'privacy-test-membership-read-failed';
                return [];
            }
            return $terms;
        };
        add_filter('get_object_terms', $fault, 10, 4);
        try {
            $complete = true;
            $this->assertFalse(ll_tools_user_can_view_vocab_post($wordId, 0, $complete));
            $this->assertFalse($complete);
            $this->assertStringNotContainsString('privacy-audio-', do_shortcode('[word_audio id="' . $wordId . '"]Label[/word_audio]'));
            $this->assertSame('', do_shortcode('[word_grid word_ids="' . $wordId . '"]'));
        } finally {
            remove_filter('get_object_terms', $fault, 10);
            $wpdb->last_error = '';
        }
        $complete = false;
        $this->assertTrue(ll_tools_user_can_view_vocab_post($wordId, 0, $complete));
        $this->assertTrue($complete);
    }

    public function test_term_visibility_metadata_failure_is_not_public(): void
    {
        global $wpdb;
        [$wordId, , $wordsetId] = $this->createWordFixture();
        $fault = static function ($check, int $objectId, string $metaKey) use ($wordsetId, $wpdb) {
            if ($objectId === $wordsetId && $metaKey === LL_TOOLS_WORDSET_VISIBILITY_META_KEY) {
                $wpdb->last_error = 'privacy-test-visibility-read-failed';
                return ['public'];
            }
            return $check;
        };
        add_filter('get_term_metadata', $fault, 10, 3);
        try {
            $complete = true;
            $this->assertFalse(ll_tools_user_can_view_vocab_post($wordId, 0, $complete));
            $this->assertFalse($complete);
        } finally {
            remove_filter('get_term_metadata', $fault, 10);
            $wpdb->last_error = '';
        }
    }

    public function test_bulk_visibility_reads_one_relationship_batch_for_selected_words(): void
    {
        $posts = [];
        for ($index = 0; $index < 6; $index++) {
            [$wordId] = $this->createWordFixture();
            $posts[] = get_post($wordId);
        }
        $calls = [];
        $observe = static function ($terms, array $objectIds, array $taxonomies) use (&$calls) {
            $calls[] = ['ids' => $objectIds, 'taxonomies' => $taxonomies];
            return $terms;
        };
        add_filter('get_object_terms', $observe, 10, 3);
        try {
            $complete = true;
            $visible = ll_tools_filter_viewable_vocab_posts($posts, 0, $complete);
        } finally {
            remove_filter('get_object_terms', $observe, 10);
        }
        $this->assertTrue($complete);
        $this->assertSame($posts, $visible);
        $this->assertCount(1, $calls);
        $this->assertCount(6, $calls[0]['ids']);
    }

    private function createWordFixture(): array
    {
        $suffix = strtolower(wp_generate_password(8, false, false));
        $wordset = wp_insert_term('Privacy set ' . $suffix, 'wordset', ['slug' => 'privacy-set-' . $suffix]);
        $category = wp_insert_term('Privacy category ' . $suffix, 'word-category', ['slug' => 'privacy-category-' . $suffix]);
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_translation');
        $title = 'Privacy vocabulary ' . $suffix;
        $wordId = (int) self::factory()->post->create([
            'post_type' => 'words', 'post_status' => 'publish', 'post_title' => $title,
        ]);
        wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
        update_post_meta($wordId, 'word_translation', 'Private meaning marker');
        update_post_meta($wordId, 'word_english_meaning', 'Private meaning marker');
        $audioId = (int) self::factory()->post->create([
            'post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $wordId,
        ]);
        update_post_meta($audioId, 'audio_file_path', '/wp-content/uploads/privacy-audio-' . $wordId . '.mp3');
        wp_set_post_terms($audioId, ['isolation'], 'recording_type', false);
        return [$wordId, $audioId, $wordsetId, $categoryId, $title];
    }
}
