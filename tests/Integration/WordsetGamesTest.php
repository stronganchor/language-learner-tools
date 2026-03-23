<?php
declare(strict_types=1);

final class WordsetGamesTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    protected function tearDown(): void
    {
        set_query_var('ll_wordset_view', null);
        parent::tearDown();
    }

    public function test_games_view_url_normalization_and_rewrite_detection_work(): void
    {
        $term = wp_insert_term('Games View Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $slug = (string) $wordset->slug;
        update_option('rewrite_rules', [
            '^' . preg_quote($slug, '/') . '/?$' => 'index.php?ll_wordset_page=' . $slug,
            '^' . preg_quote($slug, '/') . '/progress/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=progress',
            '^' . preg_quote($slug, '/') . '/hidden-categories/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=hidden-categories',
            '^' . preg_quote($slug, '/') . '/settings/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=settings',
            '^' . preg_quote($slug, '/') . '/games/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=games',
        ]);

        set_query_var('ll_wordset_view', 'games');

        $gamesUrl = (string) ll_tools_get_wordset_page_view_url($wordset, 'games');
        $this->assertStringContainsString('ll_wordset_page=' . rawurlencode($slug), $gamesUrl);
        $this->assertStringContainsString('ll_wordset_view=games', $gamesUrl);
        $this->assertSame('games', ll_tools_get_wordset_page_view());
        $this->assertTrue(ll_tools_wordset_page_has_rewrite_routes($slug));
    }

    public function test_wordset_page_render_outputs_games_navigation_and_games_view_shell(): void
    {
        $term = wp_insert_term('Rendered Games Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        set_query_var('ll_wordset_view', '');
        $mainHtml = ll_tools_render_wordset_page_content((int) $term['term_id']);
        $this->assertStringContainsString('ll-wordset-link-chip--games', $mainHtml);
        $this->assertStringContainsString('ll_wordset_view=games', $mainHtml);

        set_query_var('ll_wordset_view', 'games');
        $gamesHtml = ll_tools_render_wordset_page_content((int) $term['term_id']);
        $this->assertStringContainsString('data-ll-wordset-games-root', $gamesHtml);
        $this->assertStringContainsString('data-game-slug="space-shooter"', $gamesHtml);
    }

    public function test_space_shooter_pool_only_includes_studied_words_with_images_and_isolation_in_scope(): void
    {
        $fixture = $this->createGamesFixture(5);
        wp_set_current_user((int) $fixture['user_id']);

        $pool = ll_tools_wordset_games_build_space_shooter_pool((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $returnedIds = array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($pool['words'] ?? []));
        sort($returnedIds);

        $expectedIds = array_map('intval', $fixture['eligible_word_ids']);
        sort($expectedIds);

        $this->assertSame(5, (int) ($pool['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($pool['launchable'] ?? false));
        $this->assertSame($expectedIds, $returnedIds);
        $this->assertNotContains((int) $fixture['missing_image_word_id'], $returnedIds);
        $this->assertNotContains((int) $fixture['no_isolation_word_id'], $returnedIds);
        $this->assertNotContains((int) $fixture['mastered_word_id'], $returnedIds);
        $this->assertNotContains((int) $fixture['new_word_id'], $returnedIds);
        $this->assertNotContains((int) $fixture['out_of_scope_word_id'], $returnedIds);

        $firstWord = (array) (($pool['words'][0] ?? []));
        $this->assertArrayHasKey('option_blocked_ids', $firstWord);
        $this->assertArrayHasKey('similar_word_id', $firstWord);
        $this->assertArrayHasKey('practice_recording_types', $firstWord);
        $this->assertContains('isolation', (array) ($firstWord['practice_recording_types'] ?? []));
    }

    public function test_space_shooter_pool_is_unavailable_below_minimum_count(): void
    {
        $fixture = $this->createGamesFixture(4);
        wp_set_current_user((int) $fixture['user_id']);

        $pool = ll_tools_wordset_games_build_space_shooter_pool((int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertSame(4, (int) ($pool['available_word_count'] ?? 0));
        $this->assertFalse((bool) ($pool['launchable'] ?? true));
        $this->assertSame(5, (int) ($pool['minimum_word_count'] ?? 0));
    }

    public function test_games_bootstrap_ajax_enforces_login_and_permission_and_returns_catalog(): void
    {
        $fixture = $this->createGamesFixture(5);
        $nonce = wp_create_nonce('ll_user_study');

        wp_set_current_user(0);
        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => (int) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        try {
            $loggedOut = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_games_bootstrap_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($loggedOut['success'] ?? true));
        $this->assertSame('Login required.', (string) ($loggedOut['data']['message'] ?? ''));

        wp_set_current_user((int) $fixture['user_id']);
        $loggedInNonce = wp_create_nonce('ll_user_study');
        $denyAccess = static function (): bool {
            return false;
        };
        add_filter('ll_tools_allow_basic_user_study_access', $denyAccess);

        $_POST = [
            'nonce' => $loggedInNonce,
            'wordset_id' => (int) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        try {
            $forbidden = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_games_bootstrap_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_allow_basic_user_study_access', $denyAccess);
        }

        $this->assertFalse((bool) ($forbidden['success'] ?? true));
        $this->assertSame('You do not have permission.', (string) ($forbidden['data']['message'] ?? ''));

        $_POST = [
            'nonce' => $loggedInNonce,
            'wordset_id' => (int) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        try {
            $allowed = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_games_bootstrap_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($allowed['success'] ?? false));
        $this->assertSame((int) $fixture['wordset_id'], (int) ($allowed['data']['wordset_id'] ?? 0));
        $this->assertIsArray($allowed['data']['games']['space-shooter'] ?? null);
        $this->assertSame(5, (int) ($allowed['data']['games']['space-shooter']['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($allowed['data']['games']['space-shooter']['launchable'] ?? false));
    }

    /**
     * @return array{
     *   user_id:int,
     *   wordset_id:int,
     *   eligible_word_ids:int[],
     *   missing_image_word_id:int,
     *   no_isolation_word_id:int,
     *   mastered_word_id:int,
     *   new_word_id:int,
     *   out_of_scope_word_id:int
     * }
     */
    private function createGamesFixture(int $eligibleCount): array
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);

        $wordset = wp_insert_term('Games Fixture Wordset ' . wp_generate_password(6, false), 'wordset');
        $otherWordset = wp_insert_term('Games Fixture Other Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Games Fixture Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($otherWordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($otherWordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $otherWordsetId = (int) $otherWordset['term_id'];
        $categoryId = (int) $category['term_id'];

        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');

        $eligibleWordIds = [];
        for ($index = 1; $index <= $eligibleCount; $index++) {
            $wordId = $this->createWordWithGameMedia(
                'Eligible Game Word ' . $index,
                'Eligible Translation ' . $index,
                $categoryId,
                $wordsetId,
                true,
                ['isolation' => 'Eligible ' . $index]
            );
            $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
                'total_coverage' => 3,
                'coverage_practice' => 3,
                'correct_clean' => 1,
                'incorrect' => 1,
                'lapse_count' => 1,
                'stage' => 1,
            ]);
            $eligibleWordIds[] = $wordId;
        }

        $missingImageWordId = $this->createWordWithGameMedia(
            'Missing Image Word',
            'Missing Image Translation',
            $categoryId,
            $wordsetId,
            false,
            ['isolation' => 'Missing image']
        );
        $this->seedWordProgressRow($userId, $missingImageWordId, $categoryId, $wordsetId, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        $noIsolationWordId = $this->createWordWithGameMedia(
            'No Isolation Word',
            'No Isolation Translation',
            $categoryId,
            $wordsetId,
            true,
            ['question' => 'Question only']
        );
        $this->seedWordProgressRow($userId, $noIsolationWordId, $categoryId, $wordsetId, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        $masteredWordId = $this->createWordWithGameMedia(
            'Mastered Word',
            'Mastered Translation',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'Mastered']
        );
        $this->seedWordProgressRow($userId, $masteredWordId, $categoryId, $wordsetId, [
            'total_coverage' => 6,
            'coverage_practice' => 6,
            'correct_clean' => 4,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 6,
        ]);

        $newWordId = $this->createWordWithGameMedia(
            'New Word',
            'New Translation',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'New word']
        );

        $outOfScopeWordId = $this->createWordWithGameMedia(
            'Other Wordset Word',
            'Other Wordset Translation',
            $categoryId,
            $otherWordsetId,
            true,
            ['isolation' => 'Other wordset']
        );
        $this->seedWordProgressRow($userId, $outOfScopeWordId, $categoryId, $otherWordsetId, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        return [
            'user_id' => $userId,
            'wordset_id' => $wordsetId,
            'eligible_word_ids' => $eligibleWordIds,
            'missing_image_word_id' => $missingImageWordId,
            'no_isolation_word_id' => $noIsolationWordId,
            'mastered_word_id' => $masteredWordId,
            'new_word_id' => $newWordId,
            'out_of_scope_word_id' => $outOfScopeWordId,
        ];
    }

    /**
     * @param array<string,string> $recordingTypes
     */
    private function createWordWithGameMedia(
        string $title,
        string $translation,
        int $categoryId,
        int $wordsetId,
        bool $withImage,
        array $recordingTypes
    ): int {
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);

        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
        wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
        update_post_meta($wordId, 'word_translation', $translation);

        if ($withImage) {
            $attachmentId = $this->createImageAttachment(sanitize_title($title) . '.jpg');
            set_post_thumbnail($wordId, $attachmentId);
        }

        foreach ($recordingTypes as $slug => $recordingText) {
            $this->ensureRecordingTypeTerm($slug);
            $audioPostId = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $wordId,
                'post_title' => 'Audio ' . $slug . ' ' . $title,
            ]);
            update_post_meta($audioPostId, 'audio_file_path', '/wp-content/uploads/' . sanitize_title($title) . '-' . $slug . '.mp3');
            update_post_meta($audioPostId, 'recording_text', $recordingText);
            wp_set_post_terms($audioPostId, [$slug], 'recording_type', false);
        }

        return (int) $wordId;
    }

    private function createImageAttachment(string $filename): int
    {
        $attachmentId = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'post_status' => 'inherit',
            'post_title' => $filename,
        ]);

        update_post_meta($attachmentId, '_wp_attached_file', '2026/03/' . $filename);

        return (int) $attachmentId;
    }

    private function ensureRecordingTypeTerm(string $slug): void
    {
        $existing = get_term_by('slug', $slug, 'recording_type');
        if ($existing instanceof WP_Term) {
            return;
        }

        $term = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), 'recording_type', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($term));
    }

    /**
     * @param array<string,int|string> $overrides
     */
    private function seedWordProgressRow(int $userId, int $wordId, int $categoryId, int $wordsetId, array $overrides): void
    {
        global $wpdb;
        $table = ll_tools_user_progress_table_names()['words'];
        $now = gmdate('Y-m-d H:i:s');

        $data = array_merge([
            'user_id' => $userId,
            'word_id' => $wordId,
            'category_id' => $categoryId,
            'wordset_id' => $wordsetId,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 0,
            'coverage_learning' => 0,
            'coverage_practice' => 0,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 0,
            'updated_at' => $now,
        ], $overrides);

        $wpdb->replace($table, $data);
    }

    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
